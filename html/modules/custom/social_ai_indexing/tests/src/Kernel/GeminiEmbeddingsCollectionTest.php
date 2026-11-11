<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\Core\Http\ClientFactory;
use Drupal\key\Entity\Key;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\social_ai_indexing_test\Plugin\VdbProvider\InMemoryQdrantProvider;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Gemini batch embeddings for indexing (22.2-11, criterion 6, D-07).
 *
 * drupal/ai 1.5 added EmbeddingsCollectionInterface, and ai_search 1.3.0-alpha6
 * uses it when the embeddings provider implements it: all chunks of an item
 * go out in one request. gemini_provider doesn't implement it, so
 * patches/gemini-provider-embeddings-collection.patch adds
 * GeminiProvider::embeddingsCollection() over Gemini's batchEmbedContents.
 *
 * Only the network is a double. The real GeminiProvider, the real google-
 * gemini-php client and the real ai_search backend, strategy and
 * ThrottledQdrantProvider logic run; Drupal's own ClientFactory builds the
 * Guzzle client (so its defaults, http_errors included, are production's),
 * and a handler on it answers like Gemini does. No network call is made, the
 * key is fake and every text is fictional.
 *
 * @group phase222
 * @group phase22
 * @group social_ai_indexing
 */
final class GeminiEmbeddingsCollectionTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_usage_limits',
    'ai_vdb_provider_qdrant',
    'gemini_provider',
    'localnodes_platform',
  ];

  /**
   * The Gemini embeddings model the shipped site uses.
   */
  private const MODEL = 'models/gemini-embedding-001';

  /**
   * The fixture items: letter, a token that is in the item's text.
   */
  private const TOKENS = ['A' => 'Aqua1', 'B' => 'Bora2'];

  /**
   * The vector index.
   */
  private IndexInterface $posts;

  /**
   * Search API item id by letter.
   *
   * @var array<string, string>
   */
  private array $itemIds = [];

  /**
   * The points after the first, clean run: item id => list of generations.
   *
   * @var array<string, list<int>>
   */
  private array $baseline = [];

  /**
   * The requests the Gemini double has seen (Guzzle history entries).
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * Decides the answer to a request, or NULL for the normal 200.
   *
   * Called with the URL path, the decoded JSON body and the 1-based request
   * number since the last resetHistory().
   *
   * @var callable|null
   */
  private $respond = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['ai_usage_limits', 'gemini_provider']);

    // A fake key, as the provider reads it in production (key module).
    $key = Key::create([
      'id' => 'gemini_test_key',
      'label' => 'Gemini test key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
    ]);
    $key->setKeyValue('fake-key-never-sent-anywhere');
    $key->save();
    $this->config('gemini_provider.settings')->set('api_key', 'gemini_test_key')->save();
    $this->config('ai.settings')
      ->set('default_providers.embeddings', ['provider_id' => 'gemini', 'model_id' => self::MODEL])
      ->save();

    // The shipped storage swap, then the test's in-memory storage under it.
    $state = $this->container->get('state');
    $state->set('social_ai_indexing_test.in_memory_qdrant', TRUE);
    $state->set('social_ai_indexing_test.qdrant_generation', 1);
    $this->container->get('ai.vdb_provider')->clearCachedDefinitions();

    // The network double goes in before the first Gemini provider is created.
    // A drupal/ai provider builds its Guzzle client from the
    // http_client_factory service (not the http_client service) when it is
    // created, so the factory is the seam. Drupal's ClientFactory adds its
    // usual defaults (http_errors included) on top of the stack given here.
    $stack = HandlerStack::create($this->geminiHandler());
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client_factory', new ClientFactory($stack));

    $this->installShippedConfig('localnodes_platform', ['ai_search.index.social_posts']);
    $this->posts = $this->createShippedIndex('social_posts', $this->createQdrantServer());

    $group = $this->createFlexibleGroup('Fictional batch group', 'public');
    foreach (self::TOKENS as $letter => $token) {
      // B is long, so it has more than one chunk.
      $body = $letter === 'B'
        ? trim(str_repeat("$token is a fictional note about the batch group's shared tool library and its opening hours. ", 20))
        : "$token is a fictional short note for the batch group.";
      $node = $this->createGroupTopic($group, "Fictional $token note", $body, 'public');
      $this->itemIds[$letter] = 'entity:node/' . $node->id() . ':' . $node->language()->getId();
    }
    $this->container->get('search_api.post_request_indexing')->destruct();
    $this->posts->indexItems();
    $this->assertSame([], $this->remaining(), 'The first run indexes everything.');
    $this->baseline = $this->points();
    $this->assertSame([1], array_values(array_unique($this->baseline[$this->itemIds['A']] ?? [])), 'A has generation-1 points.');
    $this->assertCount(3, $this->baseline[$this->itemIds['B']] ?? [], 'B has three chunks, so three generation-1 points.');
    $this->resetHistory();
  }

  /**
   * A search_api_ai_search server on the 'qdrant' VDB with Gemini embeddings.
   *
   * The shipped ai_knowledge_garden server's settings. 'qdrant' is the real
   * plugin id; the test module swaps its storage.
   */
  private function createQdrantServer(): string {
    Server::create([
      'id' => 'phase222_gemini_qdrant',
      'name' => 'Phase 22.2 Gemini batch test server',
      'status' => TRUE,
      'backend' => 'search_api_ai_search',
      'backend_config' => [
        'chat_model' => 'gemini__models/gemini-3.8-flash',
        'database' => 'qdrant',
        'database_settings' => [
          'database_name' => 'default',
          'collection' => 'phase222_gemini',
          'metric' => 'cosine_similarity',
        ],
        'embeddings_engine' => 'gemini__' . self::MODEL,
        'embeddings_engine_configuration' => [
          'set_dimensions' => FALSE,
          'dimensions' => 4,
        ],
        'embedding_strategy' => 'contextual_chunks',
        'embedding_strategy_configuration' => [
          'chunk_size' => '384',
          'chunk_min_overlap' => '50',
          'contextual_content_max_percentage' => '30',
          'skip_moderation' => TRUE,
        ],
        'embedding_strategy_details' => '',
        'include_raw_embedding_vector' => FALSE,
      ],
    ])->save();
    return 'phase222_gemini_qdrant';
  }

  /**
   * The Guzzle handler that answers like Gemini.
   *
   * A 200 for embedContent and batchEmbedContents (one vector per text, whose
   * first number is the text's length, so order can be checked), unless
   * $this->respond has another answer. Guzzle's own http_errors middleware
   * turns a 4xx or 5xx answer into an exception, as it does in production.
   */
  private function geminiHandler(): callable {
    return function (RequestInterface $request, array $options) {
      $path = $request->getUri()->getPath();
      $body = json_decode((string) $request->getBody(), TRUE) ?? [];
      $response = $this->respond !== NULL ? ($this->respond)($path, $body, count($this->history) + 1) : NULL;
      return Create::promiseFor($response ?? $this->okResponse($path, $body));
    };
  }

  /**
   * Gemini's 200 answer to a request.
   *
   * @param string $path
   *   The URL path, ending in :embedContent or :batchEmbedContents.
   * @param array<string, mixed> $body
   *   The decoded request body.
   */
  private function okResponse(string $path, array $body): ResponseInterface {
    $vector = static fn(string $text): array => [(float) strlen($text), 0.25, 0.5, 0.75];
    if (str_ends_with($path, ':batchEmbedContents')) {
      $data = ['embeddings' => array_map(
        static fn(array $request): array => ['values' => $vector((string) ($request['content']['parts'][0]['text'] ?? ''))],
        $body['requests'] ?? [],
      )];
    }
    elseif (str_ends_with($path, ':embedContent')) {
      $data = ['embedding' => ['values' => $vector((string) ($body['content']['parts'][0]['text'] ?? ''))]];
    }
    else {
      return self::errorResponse(404, 'NOT_FOUND', 'Fictional: the double has no answer for this path');
    }
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($data));
  }

  /**
   * A Gemini error answer, in the shape the API sends.
   *
   * @param int $code
   *   The HTTP status.
   * @param string $status
   *   Gemini's status word, such as RESOURCE_EXHAUSTED.
   * @param string $message
   *   The message.
   * @param array<int, array<string, mixed>> $details
   *   The google.rpc detail objects.
   */
  private static function errorResponse(int $code, string $status, string $message, array $details = []): ResponseInterface {
    $error = ['code' => $code, 'message' => $message, 'status' => $status];
    if ($details !== []) {
      $error['details'] = $details;
    }
    return new Response($code, ['Content-Type' => 'application/json'], json_encode(['error' => $error]));
  }

  /**
   * A Gemini 429 answer; the quota id says whether it is per minute or day.
   */
  private static function quotaResponse(string $quota_id): ResponseInterface {
    return self::errorResponse(429, 'RESOURCE_EXHAUSTED', 'You exceeded your current quota, please check your plan and billing details.', [[
      '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
      'violations' => [[
        'quotaMetric' => 'generativelanguage.googleapis.com/embed_content_requests',
        'quotaId' => $quota_id,
        'quotaDimensions' => ['model' => 'gemini-embedding-001'],
        'quotaValue' => '100',
      ]],
    ]]);
  }

  /**
   * Forgets the requests seen so far.
   */
  private function resetHistory(): void {
    $this->history = [];
  }

  /**
   * The requests seen so far: URL path and decoded body.
   *
   * @return list<array{path: string, body: array<string, mixed>}>
   *   In request order.
   */
  private function requests(): array {
    return array_map(static fn(array $entry): array => [
      'path' => $entry['request']->getUri()->getPath(),
      'body' => json_decode((string) $entry['request']->getBody(), TRUE) ?? [],
    ], $this->history);
  }

  /**
   * The stored points: item id => list of generations (one per point).
   *
   * @return array<string, list<int>>
   *   Sorted by item id.
   */
  private function points(): array {
    $by_item = [];
    foreach (\Drupal::keyValue(InMemoryQdrantProvider::POINTS)->getAll() as $point) {
      $by_item[$point['drupal_entity_id']][] = $point['generation'];
    }
    foreach ($by_item as &$generations) {
      sort($generations);
    }
    ksort($by_item);
    return $by_item;
  }

  /**
   * Item ids still waiting to be indexed, sorted.
   *
   * @return list<string>
   *   Item ids.
   */
  private function remaining(): array {
    $ids = Index::load('social_posts')->getTrackerInstance()->getRemainingItems();
    sort($ids);
    return $ids;
  }

  /**
   * The Gemini provider as ai_search gets it (through the provider proxy).
   *
   * @return \Drupal\ai\AiProviderInterface|\Drupal\ai\Plugin\ProviderProxy
   *   The proxy.
   */
  private function gemini(): object {
    return $this->container->get('ai.provider')->createInstance('gemini');
  }

  /**
   * One Gemini call with 3 texts is one request, and the vectors keep order.
   */
  public function testBatchIsOneRequest(): void {
    $provider = $this->gemini();
    $this->assertInstanceOf(EmbeddingsCollectionInterface::class, $provider->getPlugin(), 'The Gemini provider implements the batch interface.');

    $output = $provider->embeddingsCollection(new EmbeddingsCollectionInput(['a', 'bb', 'cccc']), self::MODEL, ['phase222']);

    $requests = $this->requests();
    $this->assertCount(1, $requests, 'Three texts make exactly one HTTP request.');
    $this->assertStringEndsWith(':batchEmbedContents', $requests[0]['path']);
    $this->assertCount(3, $requests[0]['body']['requests'], 'The request body carries three embed requests.');
    $this->assertSame(
      ['a', 'bb', 'cccc'],
      array_map(static fn(array $request): string => $request['content']['parts'][0]['text'], $requests[0]['body']['requests']),
      'The texts go out in input order.',
    );
    $vectors = $output->getNormalized();
    $this->assertCount(3, $vectors);
    // The double's first number is the text's length: 1, 2, 4, in input order.
    $this->assertSame([1.0, 2.0, 4.0], array_map(static fn(array $vector): float => $vector[0], $vectors), 'The vectors come back in input order.');
  }

  /**
   * Indexing an item with several chunks sends one Gemini request.
   */
  public function testIndexingUsesBatch(): void {
    $b = $this->itemIds['B'];
    $baseline_chunks = count($this->points()[$b] ?? []);
    $this->assertGreaterThanOrEqual(3, $baseline_chunks, 'Item B has several chunks.');
    $this->assertLessThanOrEqual(10, $baseline_chunks, 'Item B fits one slice of 10 chunks.');

    // The item changed: tracked again, its chunk progress reset.
    $this->posts->trackItemsUpdated('entity:node', [substr($b, strlen('entity:node/'))]);
    $this->assertSame([$b], $this->remaining());
    $this->resetHistory();
    $this->posts->indexItems();

    $requests = $this->requests();
    $this->assertCount(1, $requests, 'An item with several chunks is embedded in one HTTP request.');
    $this->assertStringEndsWith(':batchEmbedContents', $requests[0]['path']);
    $this->assertCount($baseline_chunks, $requests[0]['body']['requests'], 'The request carries every chunk of the item.');
    $this->assertCount($baseline_chunks, $this->points()[$b] ?? [], 'Every chunk is stored.');
    $this->assertSame([], $this->remaining(), 'The item is indexed.');
  }

  /**
   * A 429 on the batch call is typed like a single call's: per minute or day.
   */
  public function testRateLimitOnBatchIsTyped(): void {
    $cases = [
      'per minute' => ['EmbedContentRequestsPerMinutePerProjectPerModel-FreeTier', AiRateLimitException::class],
      'per day' => ['EmbedContentRequestsPerDayPerProjectPerModel-FreeTier', AiQuotaException::class],
    ];
    foreach ($cases as $label => [$quota_id, $class]) {
      $this->respond = static fn(): ResponseInterface => self::quotaResponse($quota_id);
      $thrown = NULL;
      try {
        $this->gemini()->embeddingsCollection(new EmbeddingsCollectionInput(['a', 'bb']), self::MODEL, ['phase222']);
      }
      catch (\Throwable $e) {
        $thrown = $e;
      }
      $this->assertInstanceOf($class, $thrown, "A 429 ($label) on the batch call is a $class.");
      $this->assertSame(429, $thrown->getCode());
      $this->assertStringNotContainsString('fake-key', $thrown->getMessage(), 'The message carries no key.');
    }
  }

  /**
   * Marks one item as changed, so the next index run embeds it again.
   */
  private function markChanged(string $letter): void {
    $this->posts->trackItemsUpdated('entity:node', [substr($this->itemIds[$letter], strlen('entity:node/'))]);
  }

  /**
   * Log lines on the social_ai_indexing channel that contain a needle.
   *
   * @return list<string>
   *   The filled-in lines.
   */
  private function loggedLines(string $needle): array {
    $lines = [];
    foreach ($this->container->get('social_ai_indexing_test.spy_logger')->records() as $record) {
      if ($record['channel'] === 'social_ai_indexing' && str_contains($record['message'], $needle)) {
        $lines[] = strtr($record['message'], array_filter($record['context'], 'is_scalar'));
      }
    }
    return $lines;
  }

  /**
   * Re-indexes one item while Gemini answers every request with $answer.
   *
   * The new points would be generation 2, so a point still at generation 1
   * is an old one.
   */
  private function reindexWhileGeminiAnswers(string $letter, ResponseInterface $answer): void {
    $this->container->get('state')->set('social_ai_indexing_test.qdrant_generation', 2);
    $this->respond = static fn(): ResponseInterface => $answer;
    $this->markChanged($letter);
    $this->resetHistory();
    $this->container->get('social_ai_indexing_test.spy_logger')->reset();
    $this->posts->indexItems();
  }

  /**
   * Asserts the item kept its old points, stays tracked, and a backoff is set.
   *
   * @return list<string>
   *   The index_backoff log lines.
   */
  private function assertKeptAndBackedOff(string $letter): array {
    $id = $this->itemIds[$letter];
    $this->assertSame($this->baseline[$id], $this->points()[$id] ?? [], "$letter keeps exactly its generation-1 points.");
    $this->assertSame([$id], $this->remaining(), "$letter stays tracked.");
    $this->assertGreaterThan(time(), (int) $this->container->get('state')->get('social_ai_indexing.embedding_backoff_until', 0), 'A backoff is stored.');
    $this->assertSame([], $this->loggedLines('index_item_skipped'), 'The item was not treated as a stuck one (the skip branch).');
    $lines = $this->loggedLines('index_backoff');
    $this->assertCount(1, $lines, 'One index_backoff line (the backoff branch ran).');
    return $lines;
  }

  /**
   * A real Gemini 503 on a single call reaches the backoff branch.
   *
   * Gemini's client only turns a 4xx into its ErrorException. Guzzle throws
   * a ServerException for a 5xx before the client reads the answer, and the
   * client wraps that in a TransporterException. If the provider does not map
   * that, the item is skipped as if it were stuck and retried at once on every
   * run, instead of backing off.
   */
  public function testUnavailableThroughRealMapping(): void {
    $this->reindexWhileGeminiAnswers('A', self::errorResponse(503, 'UNAVAILABLE', 'Fictional: the model is overloaded. Please try again later.'));

    $this->assertCount(1, $this->history, 'One request: nothing retries a 503 inside the run.');
    $lines = $this->assertKeptAndBackedOff('A');
    $this->assertStringContainsString('index=social_posts reason=http_503 retry_after=30', $lines[0]);
  }

  /**
   * A real Gemini 429 on a single call reaches the backoff branch.
   */
  public function testRateLimitThroughRealMapping(): void {
    $this->reindexWhileGeminiAnswers('A', self::quotaResponse('EmbedContentRequestsPerMinutePerProjectPerModel-FreeTier'));

    $this->assertCount(1, $this->history, 'One request.');
    $lines = $this->assertKeptAndBackedOff('A');
    $this->assertStringContainsString('index=social_posts reason=rate_limit retry_after=60', $lines[0]);
  }

  /**
   * A rate-limited batch call costs one request: no call per chunk after it.
   *
   * ai_search alpha6 answered a failed batch call with one call per chunk
   * (3 more requests for a 3-chunk item, into the same limit). The rethrow
   * patch from 22.2-10 stops that; the item keeps its old points.
   */
  public function testRateLimitedBatchDoesNotFallBack(): void {
    $this->reindexWhileGeminiAnswers('B', self::quotaResponse('EmbedContentRequestsPerMinutePerProjectPerModel-FreeTier'));

    $requests = $this->requests();
    $this->assertCount(1, $this->history, 'Exactly one HTTP request: the batch call, and no per-chunk fallback.');
    $this->assertStringEndsWith(':batchEmbedContents', $requests[0]['path']);
    $this->assertCount(3, $requests[0]['body']['requests'], 'The one request carried the item\'s three chunks.');
    $lines = $this->assertKeptAndBackedOff('B');
    $this->assertStringContainsString('index=social_posts reason=rate_limit retry_after=60', $lines[0]);
  }

  /**
   * A 503 on the batch call is typed like a single call's, with its code.
   */
  public function testUnavailableOnBatchIsTyped(): void {
    $this->respond = static fn(): ResponseInterface => self::errorResponse(503, 'UNAVAILABLE', 'Fictional: the model is overloaded. Please try again later.');
    $thrown = NULL;
    try {
      $this->gemini()->embeddingsCollection(new EmbeddingsCollectionInput(['a', 'bb']), self::MODEL, ['phase222']);
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertInstanceOf(AiRequestErrorException::class, $thrown, 'A 503 on the batch call is an AiRequestErrorException.');
    $this->assertSame(503, $thrown->getCode());
    $this->assertStringContainsString('overloaded', $thrown->getMessage(), 'The message is Gemini\'s own.');
    $this->assertStringNotContainsString('fake-key', $thrown->getMessage(), 'The message carries no key.');
  }

  /**
   * Gemini's RetryInfo delay is carried on the exception (22.2-12, D-07).
   *
   * The message ends with "retry_after=<seconds>", which the indexing backoff
   * reads. A fraction rounds up. A per-day quota error carries its (long)
   * delay too. A 429 with no RetryInfo gets no suffix. The message holds no
   * request content either way.
   */
  public function testRetryInfoCarried(): void {
    $retry_info = static fn(string $delay): array => ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $delay];
    $quota = static fn(string $quota_id): array => [
      '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
      'violations' => [['quotaMetric' => 'generativelanguage.googleapis.com/embed_content_requests', 'quotaId' => $quota_id]],
    ];
    $per_minute = 'EmbedContentRequestsPerMinutePerProjectPerModel-FreeTier';
    $per_day = 'EmbedContentRequestsPerDayPerProjectPerModel-FreeTier';
    $cases = [
      'whole seconds' => [[$quota($per_minute), $retry_info('37s')], AiRateLimitException::class, 'retry_after=37'],
      'a fraction rounds up' => [[$quota($per_minute), $retry_info('37.5s')], AiRateLimitException::class, 'retry_after=38'],
      'RetryInfo first' => [[$retry_info('12s'), $quota($per_minute)], AiRateLimitException::class, 'retry_after=12'],
      'per day' => [[$quota($per_day), $retry_info('69840s')], AiQuotaException::class, 'retry_after=69840'],
    ];
    foreach ($cases as $label => [$details, $class, $suffix]) {
      $this->respond = static fn(): ResponseInterface => self::errorResponse(429, 'RESOURCE_EXHAUSTED', 'You exceeded your current quota, please check your plan and billing details.', $details);
      $thrown = NULL;
      try {
        $this->gemini()->embeddingsCollection(new EmbeddingsCollectionInput(['a', 'bb']), self::MODEL, ['phase222']);
      }
      catch (\Throwable $e) {
        $thrown = $e;
      }
      $this->assertInstanceOf($class, $thrown, "$label: the exception class.");
      $this->assertStringEndsWith($suffix, $thrown->getMessage(), "$label: the message ends with the delay.");
      $this->assertStringNotContainsString('fake-key', $thrown->getMessage(), "$label: no key in the message.");
    }

    // The same on a single embeddings call.
    $this->respond = static fn(): ResponseInterface => self::errorResponse(429, 'RESOURCE_EXHAUSTED', 'Fictional: slow down.', [$quota($per_minute), $retry_info('37s')]);
    $thrown = NULL;
    try {
      $this->gemini()->embeddings(new EmbeddingsInput('a'), self::MODEL, ['phase222']);
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertInstanceOf(AiRateLimitException::class, $thrown);
    $this->assertStringEndsWith('retry_after=37', $thrown->getMessage(), 'A single call carries the delay too.');

    // No RetryInfo, no suffix.
    $this->respond = static fn(): ResponseInterface => self::quotaResponse($per_minute);
    $thrown = NULL;
    try {
      $this->gemini()->embeddingsCollection(new EmbeddingsCollectionInput(['a', 'bb']), self::MODEL, ['phase222']);
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertInstanceOf(AiRateLimitException::class, $thrown);
    $this->assertStringNotContainsString('retry_after', $thrown->getMessage(), 'No RetryInfo, no suffix.');
  }

}
