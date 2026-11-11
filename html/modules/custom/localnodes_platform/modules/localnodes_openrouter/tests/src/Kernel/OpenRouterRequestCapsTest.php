<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_openrouter\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * OpenRouter chat calls carry an output cap and a low reasoning effort.
 *
 * The OpenRouter twin of social_ai_indexing's GeminiRequestConfigSubscriber
 * (orchestrator's answer to the 23-16 gate miss, 2026-11-08): the group
 * assistant, the AI overview and the citation support check each get an
 * output cap (max_tokens) keyed on the caller's tag. An untagged call and
 * other providers are untouched.
 *
 * Reasoning effort (23-16 follow-up, 2026-11-08): the wider model gate found
 * that no caller's configuration carries a reasoning effort, so the first
 * version, which only lowered an effort already there, never applied. A
 * capped call now always goes out with reasoning {"effort": "low"} when its
 * model is listed in localnodes_openrouter.settings low_reasoning_models and
 * the base URL is openrouter.ai, and with no reasoning field otherwise. The
 * caller's own reasoning fields are dropped on a capped call, so a caller
 * cannot raise the effort.
 *
 * @group localnodes_openrouter
 * @group phase23
 */
final class OpenRouterRequestCapsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'ai_provider_openrouter',
    'localnodes_openrouter',
  ];

  /**
   * Requests seen by the mock HTTP client.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * The queued mock responses.
   */
  private MockHandler $mock;

  /**
   * A model listed for low reasoning effort.
   */
  private const LISTED = 'openai/gpt-oss-120b';

  /**
   * A model not listed: reasoning off by default, efforts high and none only.
   */
  private const UNLISTED = 'mistralai/mistral-small-2603';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'ai']);
    $this->installEntitySchema('key');
    $this->container->get('config.installer')->installDefaultConfig('module', 'localnodes_openrouter');
    $key = Key::create([
      'id' => 'openrouter_test_key',
      'label' => 'OpenRouter test key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
    ]);
    $key->setKeyValue('lnor-test-key-caps');
    $key->save();
    $this->config('ai_provider_openrouter.settings')
      ->set('api_key', 'openrouter_test_key')
      ->set('base_url', 'https://openrouter.ai/api/v1')
      ->save();

    $this->mock = new MockHandler();
    $stack = HandlerStack::create($this->mock);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * Each capped caller gets its own max_tokens.
   *
   * @dataProvider cappedCallers
   */
  public function testCappedCallerGetsMaxTokens(string $tag, int $cap): void {
    $body = $this->chat(['some_other_tag', $tag]);
    $this->assertSame($cap, $body['max_tokens']);
  }

  /**
   * The capped callers and their caps (the same as Gemini's).
   *
   * @return array<string, array{0: string, 1: int}>
   *   Tag and cap.
   */
  public static function cappedCallers(): array {
    return [
      'group assistant' => ['ai_agents_group_assistant', 2048],
      'overview' => ['social_ai_overview', 1024],
      'support check' => ['social_ai_support_check', 2048],
    ];
  }

  /**
   * Each capped caller sends a listed model a low reasoning effort.
   *
   * No caller passes a reasoning setting (none of the 540 chat calls in the
   * 23-16 and model-gate runs had one), so the effort must be added, not
   * only lowered. The privacy fields and the pin stay as the decorator sets
   * them.
   *
   * @dataProvider cappedCallers
   */
  public function testCappedCallerSendsLowEffort(string $tag, int $cap): void {
    $body = $this->chat(['some_other_tag', $tag]);
    $this->assertSame(['effort' => 'low'], $body['reasoning'] ?? NULL);
    $this->assertArrayNotHasKey('reasoning_effort', $body);
    $this->assertSame($cap, $body['max_tokens']);
    $this->assertTrue($body['provider']['zdr']);
    $this->assertSame('deny', $body['provider']['data_collection']);
    $this->assertSame($this->pinOnly(self::LISTED), $body['provider']['only']);
  }

  /**
   * A caller cannot raise the effort, by either field.
   *
   * @param array<string, mixed> $configuration
   *   The caller's provider configuration.
   *
   * @dataProvider raisingConfigurations
   */
  public function testCallerCannotRaiseEffort(array $configuration): void {
    $body = $this->chat(['ai_agents_group_assistant'], $configuration);
    $this->assertSame(['effort' => 'low'], $body['reasoning'] ?? NULL);
    $this->assertArrayNotHasKey('reasoning_effort', $body);
  }

  /**
   * Caller configurations that would raise the effort above low.
   *
   * @return array<string, array{0: array<string, mixed>}>
   *   The configurations.
   */
  public static function raisingConfigurations(): array {
    return [
      'reasoning_effort high' => [['reasoning_effort' => 'high']],
      'reasoning.effort high' => [['reasoning' => ['effort' => 'high']]],
      'reasoning token budget' => [['reasoning' => ['max_tokens' => 8000]]],
      'both fields' => [['reasoning_effort' => 'max', 'reasoning' => ['effort' => 'xhigh']]],
    ];
  }

  /**
   * A model not in the list gets no reasoning field.
   */
  public function testNoReasoningAddedForOtherModels(): void {
    $body = $this->chat(['ai_agents_group_assistant'], [], self::UNLISTED);
    $this->assertArrayNotHasKey('reasoning', $body);
    $this->assertArrayNotHasKey('reasoning_effort', $body);
  }

  /**
   * A model not in the list keeps no caller reasoning field either.
   *
   * mistral-small-2603 reasons only when asked, and an effort it does not
   * list ("low") maps to the nearest one it does, so any effort sent could
   * switch its reasoning on.
   */
  public function testCallerReasoningDroppedForOtherModels(): void {
    $body = $this->chat(['ai_agents_group_assistant'], [
      'reasoning_effort' => 'high',
      'reasoning' => ['effort' => 'high'],
    ], self::UNLISTED);
    $this->assertArrayNotHasKey('reasoning', $body);
    $this->assertArrayNotHasKey('reasoning_effort', $body);
  }

  /**
   * A self-run gateway gets no reasoning field: it is OpenRouter's own.
   */
  public function testGatewayGetsNoReasoning(): void {
    $this->config('ai_provider_openrouter.settings')
      ->set('base_url', 'http://gateway.test/v1')
      ->save();
    $body = $this->chat(['social_ai_support_check']);
    $this->assertSame(2048, $body['max_tokens']);
    $this->assertArrayNotHasKey('reasoning', $body);
    $this->assertArrayNotHasKey('reasoning_effort', $body);
    $this->assertArrayNotHasKey('provider', $body);
  }

  /**
   * The shipped list has gpt-oss-120b and never mistral-small-2603.
   *
   * Low effort switched mistral-small's reasoning on in the live check (0 to
   * 361 reasoning tokens), so it must stay off whatever the model roles are.
   */
  public function testShippedListHasCheckedModelNotMistral(): void {
    $listed = $this->config('localnodes_openrouter.settings')->get('low_reasoning_models');
    $this->assertIsArray($listed);
    $this->assertContains(self::LISTED, $listed);
    $this->assertNotContains(self::UNLISTED, $listed);
  }

  /**
   * An untagged call is left alone.
   */
  public function testUntaggedCallIsUntouched(): void {
    $body = $this->chat(['some_other_tag'], ['reasoning_effort' => 'high']);
    $this->assertArrayNotHasKey('max_tokens', $body);
    $this->assertSame(['effort' => 'high'], $body['reasoning']);
  }

  /**
   * Returns the configured provider.only list for a model.
   *
   * @return string[]
   *   The endpoint tags.
   */
  private function pinOnly(string $model): array {
    foreach ($this->config('localnodes_openrouter.settings')->get('provider_pins') ?? [] as $entry) {
      if (($entry['model'] ?? NULL) === $model) {
        return $entry['only'] ?? [];
      }
    }
    $this->fail("No pin entry for $model.");
  }

  /**
   * Sends one chat through the provider and returns the request body.
   *
   * @param string[] $tags
   *   The caller's tags.
   * @param array<string, mixed> $configuration
   *   The provider configuration.
   * @param string $model
   *   The model id.
   *
   * @return array<string, mixed>
   *   The JSON body sent to OpenRouter.
   */
  private function chat(array $tags, array $configuration = [], string $model = self::LISTED): array {
    $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'id' => 'gen-test',
      'object' => 'chat.completion',
      'created' => 1,
      'model' => $model,
      'choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => 'ok'],
        'finish_reason' => 'stop',
      ]],
      'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ])));
    $provider = $this->container->get('ai.provider')->createInstance('openrouter');
    if ($configuration !== []) {
      $provider->setConfiguration($configuration);
    }
    $provider->chat('Hello', $model, $tags);
    $bodies = [];
    foreach ($this->history as $entry) {
      if (str_ends_with($entry['request']->getUri()->getPath(), '/chat/completions')) {
        $bodies[] = json_decode((string) $entry['request']->getBody(), TRUE);
      }
    }
    $this->assertCount(1, $bodies);
    return $bodies[0];
  }

}
