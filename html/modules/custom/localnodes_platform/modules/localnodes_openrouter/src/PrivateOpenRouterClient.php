<?php

declare(strict_types=1);

namespace Drupal\localnodes_openrouter;

use Drupal\ai_provider_openrouter\Service\OpenRouterClient;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenRouter client that forces zero data retention and ZDR-only hosts.
 *
 * Decorates ai_provider_openrouter.client. D-21 and ONB-07: an OpenRouter
 * site's content goes out with provider.zdr TRUE and provider.data_collection
 * "deny" on every chat, streamed chat and embeddings request. The
 * orchestrator's 23-06 model ruling of 2026-11-08 (item 5) adds that the
 * request-level zdr flag follows the account's Safety Retention setting, so
 * each fixed model is also pinned to its ZDR-only endpoints, with the list in
 * localnodes_openrouter.settings provider_pins. Whatever a caller passes, it
 * cannot switch the privacy fields off or widen the pin: the config list
 * replaces provider.only, and a caller's provider.ignore can only add to it.
 *
 * A model with no pin entry (an admin-picked one) still gets zdr and deny,
 * plus one warning per request naming the model.
 *
 * When base_url is not openrouter.ai (a self-run OpenAI-compatible gateway
 * set with OPENROUTER_BASE_URL), no provider object is sent: those fields are
 * OpenRouter's own.
 */
final class PrivateOpenRouterClient extends OpenRouterClient {

  /**
   * The base URL ai_provider_openrouter uses when none is configured.
   */
  private const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';

  /**
   * The only host the provider object is sent to.
   */
  private const OPENROUTER_HOST = 'openrouter.ai';

  /**
   * Constructs the client.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   * @param \Psr\Log\LoggerInterface $logger
   *   The ai_provider_openrouter logger channel.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   The cache for the model list.
   * @param \Psr\Log\LoggerInterface $privacyLogger
   *   The localnodes_openrouter logger channel.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    KeyRepositoryInterface $key_repository,
    LoggerInterface $logger,
    ClientInterface $http_client,
    ?CacheBackendInterface $cache,
    private readonly LoggerInterface $privacyLogger,
  ) {
    parent::__construct($config_factory, $key_repository, $logger, $http_client, $cache);
  }

  /**
   * {@inheritdoc}
   */
  public function chatCompletion(array $options): array {
    return parent::chatCompletion($this->withPrivacy($options));
  }

  /**
   * {@inheritdoc}
   */
  public function chatCompletionStream(array $options): \Traversable {
    return parent::chatCompletionStream($this->withPrivacy($options));
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(array $options): array {
    return parent::embeddings($this->withPrivacy($options));
  }

  /**
   * Whether requests go to openrouter.ai rather than a self-run gateway.
   *
   * OpenRouter's own request fields (the provider object here, the reasoning
   * object in OpenRouterRequestConfigSubscriber) are sent only then.
   */
  public static function sendsToOpenRouter(ConfigFactoryInterface $config_factory): bool {
    $base_url = (string) ($config_factory->get('ai_provider_openrouter.settings')->get('base_url') ?: self::DEFAULT_BASE_URL);
    return strtolower((string) parse_url($base_url, PHP_URL_HOST)) === self::OPENROUTER_HOST;
  }

  /**
   * Sets the privacy fields and the model's pin on a request body.
   *
   * @param array<string, mixed> $options
   *   The request body.
   *
   * @return array<string, mixed>
   *   The body as it is sent.
   */
  private function withPrivacy(array $options): array {
    if (!self::sendsToOpenRouter($this->configFactory)) {
      unset($options['provider']);
      return $options;
    }

    $provider = is_array($options['provider'] ?? NULL) ? $options['provider'] : [];
    $provider['zdr'] = TRUE;
    $provider['data_collection'] = 'deny';

    $model = (string) ($options['model'] ?? '');
    $pin = $this->pinFor($model);
    if ($pin === NULL) {
      $this->privacyLogger->warning('OpenRouter model @model has no ZDR-only provider pin in localnodes_openrouter.settings; the request is sent with zero data retention and data collection denied, but any ZDR endpoint may serve it.', ['@model' => $model]);
    }
    else {
      // The caller's list is never used for a pinned model: the config list
      // is the whole allow-list.
      unset($provider['only']);
      if ($pin['only'] !== []) {
        $provider['only'] = $pin['only'];
      }
      $caller_ignore = is_array($provider['ignore'] ?? NULL) ? array_values(array_filter($provider['ignore'], 'is_string')) : [];
      $ignore = array_values(array_unique(array_merge($pin['ignore'], $caller_ignore)));
      unset($provider['ignore']);
      if ($ignore !== []) {
        $provider['ignore'] = $ignore;
      }
    }

    $options['provider'] = $provider;
    return $options;
  }

  /**
   * Returns the configured pin for a model, or NULL when it has none.
   *
   * @return array{only: list<string>, ignore: list<string>}|null
   *   The endpoint tags to allow and to ignore; NULL when the model has no
   *   entry or its entry lists no tag.
   */
  private function pinFor(string $model): ?array {
    $pins = $this->configFactory->get('localnodes_openrouter.settings')->get('provider_pins');
    foreach (is_array($pins) ? $pins : [] as $entry) {
      if (is_array($entry) && ($entry['model'] ?? NULL) === $model) {
        $pin = [
          'only' => array_values(array_filter((array) ($entry['only'] ?? []), 'is_string')),
          'ignore' => array_values(array_filter((array) ($entry['ignore'] ?? []), 'is_string')),
        ];
        // An entry with nothing in it pins nothing.
        return $pin['only'] === [] && $pin['ignore'] === [] ? NULL : $pin;
      }
    }
    return NULL;
  }

}
