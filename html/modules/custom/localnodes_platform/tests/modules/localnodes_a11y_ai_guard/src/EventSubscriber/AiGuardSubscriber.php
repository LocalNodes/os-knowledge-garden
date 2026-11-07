<?php

declare(strict_types=1);

namespace Drupal\localnodes_a11y_ai_guard\EventSubscriber;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Denies and counts AI provider calls on the a11y lane (22-30).
 *
 * Every provider call goes through the AI module's ProviderProxy, which
 * dispatches PreGenerateResponseEvent before it invokes the provider and
 * returns the event's forced output instead when a listener set one. So:
 * - deny() runs first (priority 1000). It counts the call as "attempted"
 *   and, when settings.php sets $settings['localnodes_a11y_ai_deny'], answers
 *   it with a canned output ("denied"): the provider is never invoked, and
 *   no request leaves the site.
 * - reach() runs last (priority -1000). A call that still has no forced
 *   output at that point would invoke its provider: it's counted as
 *   "reached", with its provider, operation and path, and stopped there, so
 *   even with the deny off nothing leaves the site. The a11y suite requires
 *   "reached" to stay 0 (server-ai.spec.ts, global-teardown.ts).
 *
 * The counts live in state, so the suite reads them after a page loads.
 */
final class AiGuardSubscriber implements EventSubscriberInterface {

  /**
   * The state key holding the counts.
   */
  public const STATE_KEY = 'localnodes_a11y_ai_guard.counts';

  /**
   * Dimensions of a canned embedding (Gemini's, the lane's index).
   */
  private const DIMENSIONS = 3072;

  public function __construct(
    private readonly StateInterface $state,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => [
        ['deny', 1000],
        ['reach', -1000],
      ],
    ];
  }

  /**
   * Counts the call and, with the deny on, answers it with a canned output.
   */
  public function deny(PreGenerateResponseEvent $event): void {
    $this->count('attempted');
    if (!Settings::get('localnodes_a11y_ai_deny', FALSE)) {
      return;
    }
    $output = match ($event->getOperationType()) {
      'chat' => new ChatOutput(new ChatMessage('assistant', 'Canned answer from the a11y lane: AI calls are denied on this site.'), [], ['localnodes_a11y_ai_guard' => TRUE]),
      'embeddings' => new EmbeddingsOutput(array_fill(0, (int) Settings::get('localnodes_a11y_ai_deny_dimensions', self::DIMENSIONS), 0.01), [], ['localnodes_a11y_ai_guard' => TRUE]),
      default => NULL,
    };
    if ($output === NULL) {
      // No canned shape for this operation: stop it rather than let it out.
      $this->count('denied');
      throw new \RuntimeException('AI calls are denied on the a11y lane (localnodes_a11y_ai_guard).');
    }
    $event->setForcedOutputObject($output);
    $this->count('denied');
  }

  /**
   * Counts a call that no listener answered: it would invoke a provider.
   */
  public function reach(PreGenerateResponseEvent $event): void {
    if ($event->getForcedOutputObject() !== NULL) {
      return;
    }
    $request = $this->requestStack->getCurrentRequest();
    $this->count('reached', [
      'provider' => $event->getProviderId(),
      'operation' => $event->getOperationType(),
      'path' => $request ? $request->getPathInfo() : 'cli',
    ]);
    // Fail closed: the call is counted, never sent.
    throw new \RuntimeException('An AI provider call got past the a11y lane deny (localnodes_a11y_ai_guard); it was stopped.');
  }

  /**
   * Adds one to a count, and keeps the last few "reached" calls.
   */
  private function count(string $key, ?array $call = NULL): void {
    $counts = $this->state->get(self::STATE_KEY, []);
    $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
    if ($call !== NULL) {
      $counts['calls'] = array_slice(array_merge($counts['calls'] ?? [], [$call]), -20);
    }
    $this->state->set(self::STATE_KEY, $counts);
  }

}
