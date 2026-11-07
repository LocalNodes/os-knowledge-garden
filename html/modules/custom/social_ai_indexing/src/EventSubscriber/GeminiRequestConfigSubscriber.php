<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Gemini\Data\ThinkingConfig;
use Gemini\Enums\ThinkingLevel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enforces per-caller Gemini generation config (AI-SPEC G6, pitfalls 13-17).
 *
 * The group assistant's llm_configuration never reaches Gemini on the agent
 * path (pitfall 13), and temperature/topP/topK 0 are dropped by
 * GenerationConfig::toArray() anyway (pitfall 14). So the caps live here,
 * keyed on the caller's tag:
 * - maxOutputTokens per caller, as an int (GeminiProvider spreads the config
 *   into a strict_types constructor, pitfall 17),
 * - thinkingLevel low (Gemini 3 Flash defaults to high; thinking is billed
 *   as output),
 * - no temperature, topP or topK (Google: keep the Gemini 3 defaults), and
 *   no string-typed responseSchema/responseMimeType (a TypeError there).
 */
final class GeminiRequestConfigSubscriber implements EventSubscriberInterface {

  /**
   * Output token cap per caller tag.
   */
  private const CAPS = [
    // Tag added by ai_agents (AiAgentEntityWrapper: 'ai_agents_' . id).
    'ai_agents_group_assistant' => 2048,
    // Tag passed by AiOverviewService.
    'social_ai_overview' => 1024,
    // Tag passed by SupportCheck (22-27 option B): one JSON verdict per
    // cited sentence; uncapped, thinking ran to ~900 tokens a call and the
    // check alone took 4-8 s (rerun 3, 2026-11-07).
    'social_ai_support_check' => 2048,
  ];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', 0]];
  }

  /**
   * Caps a tagged Gemini chat request.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if ($event->getProviderId() !== 'gemini' || $event->getOperationType() !== 'chat') {
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
    // Already normalised by GeminiProvider::setConfiguration() (stopSequences
    // is an array), so only the keys below change.
    $config = $event->getConfiguration();
    unset($config['temperature'], $config['topP'], $config['topK'], $config['responseSchema'], $config['responseMimeType']);
    $config['maxOutputTokens'] = $cap;
    $config['thinkingConfig'] = new ThinkingConfig(includeThoughts: FALSE, thinkingLevel: ThinkingLevel::LOW);
    // Applied by ProviderProxy right after dispatch.
    $event->setConfiguration($config);
  }

}
