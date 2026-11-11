<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_openrouter\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Every OpenRouter request carries zero data retention and the model's pin.
 *
 * D-21 and ONB-07, with the orchestrator's 23-06 model ruling of 2026-11-08:
 * an OpenRouter site sends each chat, streamed chat and embeddings request
 * with provider.zdr TRUE, provider.data_collection "deny" and, for each fixed
 * model, the ZDR-only endpoint list from localnodes_openrouter.settings
 * provider_pins. A caller cannot turn the privacy fields off or widen the
 * pin. A self-run gateway (OPENROUTER_BASE_URL) gets no provider object.
 *
 * The test drives the real path: the ai.provider plugin manager creates the
 * openrouter provider, whose ai_provider_openrouter.client is our decorator.
 * http_client is a Guzzle client with a MockHandler and a history middleware;
 * OpenRouterClient keeps the injected handler for exactly this.
 *
 * @group localnodes_openrouter
 * @group phase23
 */
final class PrivateOpenRouterClientTest extends KernelTestBase implements LoggerInterface {

  use RfcLoggerTrait;

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
   * A made-up key value; it must never reach a log record.
   */
  private const KEY_VALUE = 'lnor-test-key-6f2b9d1c7e3a';

  /**
   * The fixed models: the 23-06 ruling as amended on 2026-11-08 (mistral
   * primary after the citation gate; glm-5.3 backup after the wider model
   * gate, replacing gpt-oss-120b).
   */
  private const CHAT = 'mistralai/mistral-small-2603';
  private const BACKUP = 'z-ai/glm-5.3';
  private const EMBED = 'openai/text-embedding-3-large';

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
   * Log records from every channel.
   *
   * @var array<int, array{level: mixed, message: string, channel: string}>
   */
  private array $records = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'ai', 'ai_provider_openrouter']);
    $this->installEntitySchema('key');
    // Only the module's settings object: the Key entity config is covered
    // by the provider-choice test.
    $this->container->get('config.installer')->installDefaultConfig('module', 'localnodes_openrouter');

    $key = Key::create([
      'id' => 'openrouter_test_key',
      'label' => 'OpenRouter test key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
    ]);
    $key->setKeyValue(self::KEY_VALUE);
    $key->save();

    $this->config('ai_provider_openrouter.settings')
      ->set('api_key', 'openrouter_test_key')
      ->set('base_url', 'https://openrouter.ai/api/v1')
      ->save();

    $this->mock = new MockHandler();
    $stack = HandlerStack::create($this->mock);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $this->container->get('logger.factory')->addLogger($this);
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $text = (string) $message;
    foreach ($context as $placeholder => $value) {
      if (is_string($placeholder) && is_scalar($value)) {
        $text = str_replace($placeholder, (string) $value, $text);
      }
    }
    $this->records[] = [
      'level' => $level,
      'message' => $text,
      'channel' => (string) ($context['channel'] ?? ''),
    ];
  }

  /**
   * The plain chat request to the primary model carries zdr, deny and pin.
   */
  public function testChatCarriesPrivacyAndPin(): void {
    $this->mock->append($this->chatResponse(self::CHAT));
    $this->provider()->chat('Hello', self::CHAT);

    $body = $this->onlyRequestBody('/api/v1/chat/completions');
    $this->assertPrivacy($body, self::CHAT);
    $this->assertArrayNotHasKey('stream', $body);
    $this->assertKeyNeverLogged();
  }

  /**
   * The streamed chat request carries the same fields.
   */
  public function testStreamedChatCarriesPrivacyAndPin(): void {
    $this->mock->append(new Response(200, ['Content-Type' => 'text/event-stream'], $this->streamBody(self::CHAT)));
    $provider = $this->provider();
    $provider->streamedOutput(TRUE);
    $provider->chat('Hello', self::CHAT);

    $body = $this->onlyRequestBody('/api/v1/chat/completions');
    $this->assertTrue($body['stream'] ?? NULL, 'The request is a streamed one.');
    $this->assertPrivacy($body, self::CHAT);
    $this->assertKeyNeverLogged();
  }

  /**
   * The backup chat model gets its own pin, not the primary's.
   */
  public function testBackupModelUsesItsOwnPin(): void {
    $this->mock->append($this->chatResponse(self::BACKUP));
    $this->provider()->chat('Hello', self::BACKUP);

    $body = $this->onlyRequestBody('/api/v1/chat/completions');
    $this->assertPrivacy($body, self::BACKUP);
    $this->assertNotSame($this->pin(self::CHAT)['only'], $body['provider']['only']);
  }

  /**
   * The embeddings request carries zdr, deny and its own pin.
   */
  public function testEmbeddingsCarryPrivacyAndPin(): void {
    $this->mock->append($this->embeddingResponse());
    $this->provider()->embeddings('Hello', self::EMBED);

    $body = $this->onlyRequestBody('/api/v1/embeddings');
    $this->assertPrivacy($body, self::EMBED);
    $this->assertKeyNeverLogged();
  }

  /**
   * A caller can neither switch the privacy fields off nor widen the pin.
   */
  public function testCallerCannotLoosenPrivacyOrWidenPin(): void {
    $loose = [
      'zdr' => FALSE,
      'data_collection' => 'allow',
      'only' => ['not-pinned'],
      'ignore' => ['caller-ignored'],
    ];

    // Chat: the provider configuration is merged into the request body.
    $this->mock->append($this->chatResponse(self::CHAT));
    $provider = $this->provider();
    $provider->setConfiguration(['provider' => $loose]);
    $provider->chat('Hello', self::CHAT);
    $chat = $this->onlyRequestBody('/api/v1/chat/completions');
    $this->assertPrivacy($chat, self::CHAT);
    $this->assertNotContains('not-pinned', $chat['provider']['only']);
    // A caller's ignore may only add to the config's ignore list.
    $this->assertContains('caller-ignored', $chat['provider']['ignore']);
    foreach ($this->pin(self::CHAT)['ignore'] as $slug) {
      $this->assertContains($slug, $chat['provider']['ignore']);
    }

    // Embeddings: the provider passes no configuration through, so call the
    // client service directly with the loose object.
    $this->history = [];
    $this->mock->append($this->embeddingResponse());
    $this->container->get('ai_provider_openrouter.client')->embeddings([
      'model' => self::EMBED,
      'input' => 'Hello',
      'provider' => $loose,
    ]);
    $embed = $this->onlyRequestBody('/api/v1/embeddings');
    $this->assertPrivacy($embed, self::EMBED);
    $this->assertNotContains('not-pinned', $embed['provider']['only']);
  }

  /**
   * A self-run gateway gets no provider object at all.
   */
  public function testGatewayBaseUrlGetsNoProviderObject(): void {
    $this->config('ai_provider_openrouter.settings')
      ->set('base_url', 'http://gateway.test/v1')
      ->save();

    $this->mock->append($this->chatResponse(self::CHAT));
    $provider = $this->provider();
    $provider->setConfiguration(['provider' => ['zdr' => TRUE, 'only' => ['groq']]]);
    $provider->chat('Hello', self::CHAT);
    $this->mock->append($this->embeddingResponse());
    $provider->embeddings('Hello', self::EMBED);

    $this->assertCount(2, $this->history);
    foreach ($this->history as $entry) {
      $request = $entry['request'];
      $this->assertSame('gateway.test', $request->getUri()->getHost());
      $body = json_decode((string) $request->getBody(), TRUE);
      $this->assertArrayNotHasKey('provider', $body, 'No OpenRouter-only field goes to a gateway.');
    }
  }

  /**
   * A model with no pin entry still gets the D-21 floor, and one warning.
   */
  public function testUnpinnedModelGetsFloorAndOneWarning(): void {
    $model = 'example/unpinned-model-7b';
    $this->mock->append($this->chatResponse($model));
    $this->provider()->chat('Hello', $model);

    $body = $this->onlyRequestBody('/api/v1/chat/completions');
    $this->assertTrue($body['provider']['zdr']);
    $this->assertSame('deny', $body['provider']['data_collection']);
    $this->assertArrayNotHasKey('only', $body['provider']);

    $warnings = array_filter($this->records, fn (array $r): bool => $r['channel'] === 'localnodes_openrouter' && $r['level'] === 4);
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString($model, reset($warnings)['message']);
  }

  /**
   * A pin entry with empty lists counts as no pin: floor plus the warning.
   */
  public function testEmptyPinEntryCountsAsNoPin(): void {
    $model = 'example/empty-pin-model';
    $pins = $this->config('localnodes_openrouter.settings')->get('provider_pins');
    $pins[] = ['model' => $model, 'only' => [], 'ignore' => []];
    $this->config('localnodes_openrouter.settings')->set('provider_pins', $pins)->save();

    $this->mock->append($this->chatResponse($model));
    $provider = $this->provider();
    $provider->setConfiguration(['provider' => ['zdr' => FALSE]]);
    $provider->chat('Hello', $model);

    $body = $this->onlyRequestBody('/api/v1/chat/completions');
    $this->assertTrue($body['provider']['zdr']);
    $this->assertSame('deny', $body['provider']['data_collection']);
    $warnings = array_filter($this->records, fn (array $r): bool => $r['channel'] === 'localnodes_openrouter' && $r['level'] === 4);
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString($model, reset($warnings)['message']);
  }

  /**
   * The key never reaches a log record, also on an error response.
   */
  public function testKeyNeverReachesALogOnError(): void {
    $this->mock->append(new Response(401, ['Content-Type' => 'application/json'], json_encode([
      'error' => ['message' => 'No auth credentials found', 'code' => 401],
    ])));
    try {
      $this->provider()->chat('Hello', self::CHAT);
      $this->fail('A 401 answer must raise an exception.');
    }
    catch (\Throwable $e) {
      $this->assertStringNotContainsString(self::KEY_VALUE, $e->getMessage());
    }
    // The error path did log, so the check below is not vacuous.
    $this->assertNotEmpty(array_filter($this->records, fn (array $r): bool => $r['channel'] === 'ai_provider_openrouter'));
    $this->assertKeyNeverLogged();
    // The key went only to the Authorization header.
    $request = $this->history[0]['request'];
    $this->assertSame('Bearer ' . self::KEY_VALUE, $request->getHeaderLine('Authorization'));
    $this->assertStringNotContainsString(self::KEY_VALUE, (string) $request->getBody());
  }

  /**
   * The shipped pin list covers the three fixed models.
   */
  public function testShippedPinsCoverTheFixedModels(): void {
    foreach ([self::CHAT, self::BACKUP, self::EMBED] as $model) {
      $this->assertNotEmpty($this->pin($model)['only'], "$model has a ZDR-only pin.");
    }
  }

  /**
   * Every shipped chat-model pin is narrowed to two or three hosts.
   *
   * OpenRouter spreads calls over every host in a pin at that host's own
   * price, so a long pin costs about what its dearer hosts charge (the wider
   * model gate: qwen3.8-27b cost about 7 times its cheapest host). The
   * orchestrator's model-gate answer (C), 2026-11-08: the cheapest ZDR hosts,
   * at least 2 so one host's outage does not take the model down. Three is
   * the most taken (23-MODEL-GATE.md, "Pins (narrowed 2026-11-08)").
   */
  public function testShippedChatPinsAreNarrowed(): void {
    $embed = (string) $this->config('localnodes_openrouter.settings')->get('embedding_model');
    $chat_pins = 0;
    foreach ($this->config('localnodes_openrouter.settings')->get('provider_pins') ?? [] as $entry) {
      if (($entry['model'] ?? '') === $embed) {
        continue;
      }
      $chat_pins++;
      $count = count($entry['only'] ?? []);
      $this->assertGreaterThanOrEqual(2, $count, "{$entry['model']} keeps at least two ZDR hosts.");
      $this->assertLessThanOrEqual(3, $count, "{$entry['model']} is pinned to its cheapest hosts only.");
    }
    $this->assertGreaterThanOrEqual(2, $chat_pins, 'The primary and the backup are both pinned.');
  }

  /**
   * Creates the openrouter provider through the plugin manager.
   */
  private function provider(): object {
    return $this->container->get('ai.provider')->createInstance('openrouter');
  }

  /**
   * Returns the decoded body of the single request sent to $path.
   *
   * @return array<string, mixed>
   *   The JSON body.
   */
  private function onlyRequestBody(string $path): array {
    $this->assertCount(1, $this->history, 'Exactly one request was sent.');
    $request = $this->history[0]['request'];
    $this->assertSame('openrouter.ai', $request->getUri()->getHost());
    $this->assertSame($path, $request->getUri()->getPath());
    return json_decode((string) $request->getBody(), TRUE);
  }

  /**
   * Asserts zdr, deny and exactly the model's configured pin.
   *
   * @param array<string, mixed> $body
   *   The request body.
   */
  private function assertPrivacy(array $body, string $model): void {
    $this->assertSame($model, $body['model']);
    $this->assertIsArray($body['provider'] ?? NULL, 'A provider object is sent.');
    $this->assertTrue($body['provider']['zdr']);
    $this->assertSame('deny', $body['provider']['data_collection']);
    $pin = $this->pin($model);
    $this->assertNotEmpty($pin['only']);
    $this->assertSame($pin['only'], $body['provider']['only']);
  }

  /**
   * Returns the configured pin entry for a model.
   *
   * @return array{only: array<int, string>, ignore: array<int, string>}
   *   The pin entry.
   */
  private function pin(string $model): array {
    foreach ($this->config('localnodes_openrouter.settings')->get('provider_pins') ?? [] as $entry) {
      if (($entry['model'] ?? NULL) === $model) {
        return ['only' => $entry['only'] ?? [], 'ignore' => $entry['ignore'] ?? []];
      }
    }
    $this->fail("No pin entry for $model.");
  }

  /**
   * Asserts that no log record holds the key value.
   */
  private function assertKeyNeverLogged(): void {
    foreach ($this->records as $record) {
      $this->assertStringNotContainsString(self::KEY_VALUE, $record['message']);
    }
  }

  /**
   * A minimal chat completion answer.
   */
  private function chatResponse(string $model): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
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
    ]));
  }

  /**
   * A minimal server-sent-events body for a streamed answer.
   */
  private function streamBody(string $model): string {
    $chunk = json_encode([
      'id' => 'gen-test',
      'object' => 'chat.completion.chunk',
      'created' => 1,
      'model' => $model,
      'choices' => [[
        'index' => 0,
        'delta' => ['role' => 'assistant', 'content' => 'ok'],
        'finish_reason' => NULL,
      ]],
    ]);
    return "data: $chunk\n\ndata: [DONE]\n\n";
  }

  /**
   * A minimal embeddings answer.
   */
  private function embeddingResponse(): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'object' => 'list',
      'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
      'model' => self::EMBED,
      'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
    ]));
  }

}
