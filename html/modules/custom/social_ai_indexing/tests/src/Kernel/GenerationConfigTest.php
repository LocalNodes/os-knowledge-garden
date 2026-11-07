<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Gemini\Data\ThinkingConfig;
use Gemini\Enums\ThinkingLevel;

/**
 * Per-caller Gemini generation caps (AI-SPEC guardrail G6, E11).
 *
 * The group assistant's llm_configuration never reaches Gemini on the agent
 * path (pitfall 13) and temperature 0 is dropped by GenerationConfig's
 * array_filter (pitfall 14). So the caps are enforced at
 * PreGenerateResponseEvent, keyed on the caller's tag. The event is
 * dispatched directly: the subscriber acts only on provider 'gemini', which
 * Kernel tests never call.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class GenerationConfigTest extends GroupAccessKernelTestBase {

  /**
   * Configuration the way GeminiProvider normalises the assistant's YAML.
   */
  private const INCOMING = [
    'stopSequences' => [],
    'maxOutputTokens' => 1024,
    'temperature' => 0.0,
    'topP' => 0.0,
    'topK' => 0,
    'responseSchema' => 'string',
    'responseMimeType' => 'text/plain',
  ];

  /**
   * Dispatches a PreGenerateResponseEvent and returns it.
   *
   * @param string $provider
   *   Provider plugin id.
   * @param string $operation
   *   Operation type.
   * @param string[] $tags
   *   Tags, the operation type first, as ProviderProxy sets them.
   */
  private function dispatch(string $provider, string $operation, array $tags): PreGenerateResponseEvent {
    $event = new PreGenerateResponseEvent(
      requestThreadId: 'test-thread',
      providerId: $provider,
      operationType: $operation,
      configuration: self::INCOMING,
      input: NULL,
      modelId: 'models/gemini-3-flash-preview',
      tags: $tags,
    );
    $this->container->get('event_dispatcher')->dispatch($event, PreGenerateResponseEvent::EVENT_NAME);
    return $event;
  }

  /**
   * Asserts the capped shape: an int cap, thinking low, no sampling keys.
   */
  private function assertCapped(array $config, int $cap): void {
    $this->assertSame($cap, $config['maxOutputTokens'] ?? NULL, 'maxOutputTokens is the caller cap, as an int.');
    $this->assertArrayHasKey('thinkingConfig', $config);
    $this->assertInstanceOf(ThinkingConfig::class, $config['thinkingConfig']);
    $this->assertSame(ThinkingLevel::LOW, $config['thinkingConfig']->thinkingLevel);
    $this->assertFalse($config['thinkingConfig']->includeThoughts);
    foreach (['temperature', 'topP', 'topK', 'responseSchema', 'responseMimeType'] as $key) {
      $this->assertArrayNotHasKey($key, $config, "$key is never sent.");
    }
    $this->assertSame([], $config['stopSequences'], 'Other keys are kept as they were.');
  }

  /**
   * The group assistant's agent loop gets 2048 tokens and thinking low.
   */
  public function testGroupAssistantIsCapped(): void {
    $event = $this->dispatch('gemini', 'chat', ['chat', 'ai_agents_group_assistant']);
    $this->assertCapped($event->getConfiguration(), 2048);
  }

  /**
   * The search-page AI overview gets 1024 tokens and thinking low.
   */
  public function testOverviewIsCapped(): void {
    $event = $this->dispatch('gemini', 'chat', ['chat', 'social_ai_overview']);
    $this->assertCapped($event->getConfiguration(), 1024);
  }

  /**
   * The runtime support check gets 2048 tokens and thinking low.
   */
  public function testSupportCheckIsCapped(): void {
    $event = $this->dispatch('gemini', 'chat', ['chat', 'social_ai_indexing', 'social_ai_support_check']);
    $this->assertCapped($event->getConfiguration(), 2048);
  }

  /**
   * Untagged calls, other providers and other operations are left alone.
   */
  public function testOtherCallsAreUnchanged(): void {
    $this->assertSame(self::INCOMING, $this->dispatch('gemini', 'chat', ['chat'])->getConfiguration(), 'An untagged Gemini chat is unchanged.');
    $this->assertSame(self::INCOMING, $this->dispatch('echoai', 'chat', ['chat', 'ai_agents_group_assistant'])->getConfiguration(), 'A non-Gemini provider is unchanged.');
    $this->assertSame(self::INCOMING, $this->dispatch('gemini', 'embeddings', ['embeddings', 'ai_agents_group_assistant'])->getConfiguration(), 'Embeddings are unchanged.');
  }

}
