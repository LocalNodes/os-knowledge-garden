<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_openrouter\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * An OpenRouter chat that hits a rate limit is answered by the backup model.
 *
 * 23-10 with the 23-16 hand-offs, through the real provider stack (only the
 * HTTP client is mocked): the primary model gets HTTP 429, and
 * localnodes_platform's AiFailoverSubscriber sends the same chat once to the
 * backup named in localnodes_ai_backup, which for an OpenRouter site is
 * localnodes_openrouter.settings backup_chat_model. The backup request keeps
 * the caller's tag, so OpenRouterRequestConfigSubscriber caps it, and goes
 * through PrivateOpenRouterClient, so it carries zero data retention and the
 * backup model's own ZDR-only pin.
 *
 * @group localnodes_openrouter
 * @group phase23
 */
final class OpenRouterFailoverTest extends KernelTestBase {

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
    'localnodes_platform',
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
    $key->setKeyValue('lnor-test-key-failover');
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
   * A 429 on the primary is answered by the capped, pinned backup.
   */
  public function testRateLimitedChatFailsOverToPinnedCappedBackup(): void {
    $settings = $this->config('localnodes_openrouter.settings');
    $primary = (string) $settings->get('chat_model');
    $backup = (string) $settings->get('backup_chat_model');
    $this->assertNotSame($primary, $backup);
    $this->setSetting('localnodes_ai_backup', ['provider' => 'openrouter', 'model' => $backup]);

    $this->mock->append(new Response(429, ['Content-Type' => 'application/json'], json_encode([
      'error' => ['code' => 429, 'message' => 'Rate limit exceeded'],
    ])));
    $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'id' => 'gen-backup',
      'object' => 'chat.completion',
      'created' => 1,
      'model' => $backup,
      'choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => 'Backup answer'],
        'finish_reason' => 'stop',
      ]],
      'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ])));

    $input = new ChatInput([new ChatMessage('user', 'Hello')]);
    $output = $this->container->get('ai.provider')
      ->createInstance('openrouter')
      ->chat($input, $primary, ['ai_agents_group_assistant']);

    $this->assertSame('Backup answer', $output->getNormalized()->getText());
    $bodies = $this->chatBodies();
    $this->assertCount(2, $bodies);
    $this->assertSame($primary, $bodies[0]['model']);
    $this->assertSame($backup, $bodies[1]['model']);
    // The caller's tag reached OpenRouterRequestConfigSubscriber.
    $this->assertSame(2048, $bodies[1]['max_tokens']);
    // PrivateOpenRouterClient pinned the backup to its own ZDR-only hosts.
    $this->assertTrue($bodies[1]['provider']['zdr']);
    $this->assertSame('deny', $bodies[1]['provider']['data_collection']);
    $this->assertSame($this->pinFor($backup), $bodies[1]['provider']['only']);
    // Each request carries low effort exactly when its model is listed in
    // low_reasoning_models (23-16 follow-up, 2026-11-08).
    $listed = $settings->get('low_reasoning_models') ?? [];
    foreach ([$primary, $backup] as $i => $model) {
      if (in_array($model, $listed, TRUE)) {
        $this->assertSame(['effort' => 'low'], $bodies[$i]['reasoning'] ?? NULL, "$model is sent low effort.");
      }
      else {
        $this->assertArrayNotHasKey('reasoning', $bodies[$i], "$model is sent no reasoning field.");
      }
    }
  }

  /**
   * The request bodies sent to /chat/completions.
   *
   * @return array<int, array<string, mixed>>
   *   One decoded body per request.
   */
  private function chatBodies(): array {
    $bodies = [];
    foreach ($this->history as $entry) {
      if (str_ends_with($entry['request']->getUri()->getPath(), '/chat/completions')) {
        $bodies[] = json_decode((string) $entry['request']->getBody(), TRUE);
      }
    }
    return $bodies;
  }

  /**
   * The configured ZDR-only pin list of a model.
   *
   * @return string[]
   *   The endpoint tags.
   */
  private function pinFor(string $model): array {
    foreach ($this->config('localnodes_openrouter.settings')->get('provider_pins') as $pin) {
      if ($pin['model'] === $model) {
        return $pin['only'];
      }
    }
    $this->fail("No pin for $model.");
  }

}
