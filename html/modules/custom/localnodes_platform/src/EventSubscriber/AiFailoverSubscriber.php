<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\EventSubscriber;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Event\AiExceptionEvent;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Answers a failed chat from the site's backup model on the same provider.
 *
 * D-21, ONB-07 (23-10). drupal/ai's ProviderProxy dispatches AiExceptionEvent
 * when a provider call throws, and returns the event's forced output instead
 * of the exception when a subscriber sets one. When a chat call fails with a
 * quota, rate-limit or request error, this subscriber sends the same input
 * once to the backup model in the localnodes_ai_backup setting
 * (settings.php, from AI_BACKUP_PROVIDER and AI_BACKUP_MODEL; 23-08) and
 * hands that answer to the caller, so the member gets an answer instead of an
 * error.
 *
 * Rules:
 * - Chat only. Embeddings never fail over (research Pitfall 6): a second
 *   model writes vectors into a different space in the same collection,
 *   which silently breaks search. Indexing pauses and retries instead.
 * - Same provider only. The backup runs on the same AI service and account
 *   the organizer chose. A backup on another provider than the one that
 *   failed is refused and logged once, so a site's content never goes to a
 *   service or account the organizer did not choose (D-21; 22-AI-SPEC
 *   failure mode 4; wording per Tom's ruling, 2026-11-08).
 * - Once. The backup call carries the localnodes_failover tag, and a failed
 *   call with that tag is never failed over again; if the backup fails too,
 *   the original exception stands.
 * - The backup call goes through the provider proxy with the caller's tags,
 *   so tag-keyed subscribers (output caps, usage limits) and the provider's
 *   own privacy decorator (OpenRouter ZDR pins) apply to it as well.
 * - OpenRouter's rate limit (429) and server errors (5xx) count too: they
 *   reach the event as AiResponseErrorException with openai-php's
 *   RateLimitException or ServerException behind them. Other response
 *   errors (for example 402, no credit on the account) do not: a model on
 *   the same account cannot help.
 * - A backup equal to the failed model is skipped and logged once: it would
 *   hit the same limit.
 * - No backup configured (both keys needed) means nothing happens.
 * - Logs name providers, models and exception classes only, never the
 *   input or the exception message (which can echo it).
 */
final class AiFailoverSubscriber implements EventSubscriberInterface {

  /**
   * The tag that marks a backup request.
   */
  public const TAG = 'localnodes_failover';

  /**
   * The setting that names the backup (provider, model).
   */
  public const SETTING = 'localnodes_ai_backup';

  /**
   * The drupal/ai exceptions a backup can help with.
   */
  private const FAILOVER_EXCEPTIONS = [
    AiQuotaException::class,
    AiRateLimitException::class,
    AiRequestErrorException::class,
  ];

  /**
   * openai-php exceptions that mean a rate limit or a server error.
   *
   * ai_provider_openrouter 1.1.9 sends chat through openai-php. Its
   * RateLimitException (HTTP 429) and ServerException (HTTP 5xx) match none
   * of the provider's message checks, so the provider throws
   * AiResponseErrorException with one of these as the previous exception.
   * By name, so this module needs no openai-php dependency.
   */
  private const CLIENT_CAUSES = [
    'OpenAI\\Exceptions\\RateLimitException',
    'OpenAI\\Exceptions\\ServerException',
  ];

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Refusals already logged in this request, keyed by reason.
   *
   * @var array<string, true>
   */
  private array $logged = [];

  /**
   * Constructs an AiFailoverSubscriber.
   */
  public function __construct(
    // Optional so kernel tests that enable localnodes_platform without the
    // ai module can build the container; on a site, ai is a hard dependency.
    private readonly ?AiProviderPluginManager $providerManager,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly Settings $settings,
  ) {
    $this->logger = $loggerFactory->get('localnodes_platform');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // ProviderProxy dispatches the event under its class name.
    return [AiExceptionEvent::class => ['onAiException', 0]];
  }

  /**
   * Sets the backup's answer as the forced output of a failed chat.
   */
  public function onAiException(AiExceptionEvent $event): void {
    if ($this->providerManager === NULL || $event->getOperationType() !== 'chat' || $event->getForcedOutputObject() !== NULL) {
      return;
    }
    $tags = $event->getTags();
    if (in_array(self::TAG, $tags, TRUE) || !$this->isFailoverException($event->exception)) {
      return;
    }
    $backup = $this->settings->get(self::SETTING);
    $provider_id = is_array($backup) ? trim((string) ($backup['provider'] ?? '')) : '';
    $model_id = is_array($backup) ? trim((string) ($backup['model'] ?? '')) : '';
    if ($provider_id === '' || $model_id === '') {
      return;
    }
    if ($provider_id !== $event->getProviderId()) {
      $this->logOnce('cross_provider', 'AI failover refused: the backup provider @backup differs from the failed provider @provider, so the site would send content to a provider the organizer did not choose.', [
        '@backup' => $provider_id,
        '@provider' => $event->getProviderId(),
      ]);
      return;
    }
    if ($model_id === $event->getModelId()) {
      $this->logOnce('same_model', 'AI failover skipped: the backup model @model on @provider is the model that failed, so it would hit the same limit.', [
        '@model' => $model_id,
        '@provider' => $provider_id,
      ]);
      return;
    }
    $input = $event->getInput();
    if ($input === NULL) {
      return;
    }

    // The proxy puts the operation type first, then the caller's tags.
    if (($tags[0] ?? NULL) === $event->getOperationType()) {
      array_shift($tags);
    }
    $tags[] = self::TAG;

    $context = [
      '@provider' => $provider_id,
      '@model' => $event->getModelId(),
      '@backup_model' => $model_id,
      '@class' => get_class($event->exception),
    ];
    try {
      $output = $this->providerManager->createInstance($provider_id)->chat($input, $model_id, $tags);
    }
    catch (\Throwable $backup_exception) {
      $context['@backup_class'] = get_class($backup_exception);
      $this->logger->warning('AI failover failed: @provider @model failed with @class and the backup @backup_model failed with @backup_class; the original error stands.', $context);
      return;
    }
    if (!$output instanceof ChatOutput) {
      return;
    }
    $event->setForcedOutputObject($output);
    $this->logger->notice('AI chat failed over: @provider @model failed with @class; answered by the backup @backup_model.', $context);
  }

  /**
   * Whether a backup model can help with this exception.
   */
  private function isFailoverException(\Throwable $exception): bool {
    foreach (self::FAILOVER_EXCEPTIONS as $class) {
      if ($exception instanceof $class) {
        return TRUE;
      }
    }
    if ($exception instanceof AiResponseErrorException) {
      for ($cause = $exception->getPrevious(); $cause !== NULL; $cause = $cause->getPrevious()) {
        foreach (self::CLIENT_CAUSES as $class) {
          if (is_a($cause, $class)) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Logs a warning once per request for each reason.
   *
   * @param string $reason
   *   The refusal reason.
   * @param string $message
   *   The message, with placeholders.
   * @param array<string, string> $context
   *   The placeholders (provider and model ids only).
   */
  private function logOnce(string $reason, string $message, array $context): void {
    if (isset($this->logged[$reason])) {
      return;
    }
    $this->logged[$reason] = TRUE;
    $this->logger->warning($message, $context);
  }

}
