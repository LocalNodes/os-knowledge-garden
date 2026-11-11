<?php

declare(strict_types=1);

namespace Drupal\localnodes_openrouter\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\localnodes_openrouter\PrivateOpenRouterClient;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Caps OpenRouter chat calls per caller, as Gemini's calls are capped.
 *
 * The OpenRouter twin of social_ai_indexing's GeminiRequestConfigSubscriber
 * (orchestrator's answer to the 23-16 citation-gate miss, 2026-11-08): an
 * uncapped request is a cost and latency risk. Keyed on the caller's tag:
 * - max_tokens, the same caps Gemini gets (OpenRouter counts reasoning
 *   tokens inside the completion, so the cap covers both);
 * - reasoning {"effort": "low"}, OpenRouter's unified reasoning object, when
 *   the model is listed in localnodes_openrouter.settings
 *   low_reasoning_models and requests go to openrouter.ai.
 *
 * 23-16 follow-up (2026-11-08): the first version only lowered a
 * reasoning_effort already in the configuration. No caller sets one, and
 * ai_provider_openrouter fills one in only on its settings form, never at
 * request time, so the effort was never sent. Now the subscriber owns the
 * reasoning fields of a capped call: it drops the caller's reasoning and
 * reasoning_effort (a caller cannot raise the effort) and sets the low
 * effort only for a listed model. A model that is not listed gets no
 * reasoning field, since OpenRouter maps an effort a model does not support
 * to the nearest one it does, and for a model that reasons only when asked
 * (mistral-small-2603) any effort can switch reasoning on. A self-run
 * gateway gets none either: the object is OpenRouter's own.
 */
final class OpenRouterRequestConfigSubscriber implements EventSubscriberInterface {

  /**
   * Output token cap per caller tag (the values Gemini's calls get).
   */
  public const CAPS = [
    'ai_agents_group_assistant' => 2048,
    'social_ai_overview' => 1024,
    'social_ai_support_check' => 2048,
  ];

  /**
   * The reasoning effort for capped calls to a listed model.
   */
  public const REASONING_EFFORT = 'low';

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', 0]];
  }

  /**
   * Caps a tagged OpenRouter chat request.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if ($event->getProviderId() !== 'openrouter' || $event->getOperationType() !== 'chat') {
      return;
    }
    $cap = NULL;
    foreach ($event->getTags() as $tag) {
      if (is_string($tag) && isset(self::CAPS[$tag])) {
        $cap = self::CAPS[$tag];
      }
    }
    if ($cap === NULL) {
      return;
    }
    $config = $event->getConfiguration();
    $config['max_tokens'] = $cap;
    // ai_provider_openrouter turns reasoning_effort into reasoning.effort,
    // but a reasoning object already in the configuration wins there, so
    // both caller fields go.
    unset($config['reasoning_effort'], $config['reasoning']);
    if ($this->sendsLowEffort($event->getModelId())) {
      $config['reasoning'] = ['effort' => self::REASONING_EFFORT];
    }
    // Applied by ProviderProxy right after dispatch.
    $event->setConfiguration($config);
  }

  /**
   * Whether a capped call to this model carries the low effort.
   */
  private function sendsLowEffort(string $model): bool {
    if (!PrivateOpenRouterClient::sendsToOpenRouter($this->configFactory)) {
      return FALSE;
    }
    $listed = $this->configFactory->get('localnodes_openrouter.settings')->get('low_reasoning_models');
    return is_array($listed) && in_array($model, $listed, TRUE);
  }

}
