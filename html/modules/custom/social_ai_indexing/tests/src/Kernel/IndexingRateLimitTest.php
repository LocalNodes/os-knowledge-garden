<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\social_ai_indexing_test\EventSubscriber\TestableEmbeddingThrottleSubscriber;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\social_ai_indexing_test\Plugin\VdbProvider\InMemoryQdrantProvider;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;

/**
 * Rate limits and outages in the middle of AI indexing (22.2-10, D-07).
 *
 * On a free Gemini key one 503 in the middle of a batch deleted the batch's
 * vectors (live, 22-26: Qdrant went from 106 points to 48), because the stock
 * Qdrant provider deletes a batch's old points before it embeds anything.
 * ThrottledQdrantProvider embeds first, deletes second, and stops cleanly when
 * the provider says "slow down" (429) or "unavailable" (5xx).
 *
 * The tests run the real search_api_ai_search backend, its real
 * contextual_chunks strategy and the shipped ThrottledQdrantProvider logic.
 * Only the storage (InMemoryQdrantProvider) and the embeddings provider (the
 * echoai test provider, with an inline listener that counts and fails
 * embeddings calls) are doubles. No network call is made. Every fixture is
 * fictional.
 *
 * @group phase222
 * @group phase22
 * @group social_ai_indexing
 */
final class IndexingRateLimitTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'ai_usage_limits',
    'ai_vdb_provider_qdrant',
    'gemini_provider',
    'localnodes_platform',
  ];

  /**
   * The echo provider and one of its models (embeddings).
   */
  private const PROVIDER = 'echoai';
  private const MODEL = 'gpt-test';

  /**
   * The fixture items: letter, a token that is in the item's text.
   */
  private const TOKENS = ['A' => 'Aqua1', 'B' => 'Bora2', 'C' => 'Cedr3', 'D' => 'Dune4'];

  /**
   * The throttle service (22.2-12), and its State keys.
   */
  private const THROTTLE_SERVICE = 'social_ai_indexing.embedding_throttle_subscriber';
  private const WINDOW_KEY = 'social_ai_indexing.embedding_window';
  private const BACKOFF_KEY = 'social_ai_indexing.embedding_backoff_until';

  /**
   * The vector index.
   */
  private IndexInterface $posts;

  /**
   * The group the fixture Topics belong to.
   */
  private GroupInterface $group;

  /**
   * Embeddings calls that got past every listener, as [operation, units].
   *
   * The first listener (priority 10000) sees every attempt, also the ones
   * the throttle then refuses. This one runs after the throttle (priority
   * 35), so it sees only the calls that would reach the provider.
   *
   * @var list<array{string, int}>
   */
  private array $passedCalls = [];

  /**
   * Search API item id by letter.
   *
   * @var array<string, string>
   */
  private array $itemIds = [];

  /**
   * The fixture Topics by letter.
   *
   * @var array<string, \Drupal\node\NodeInterface>
   */
  private array $nodes = [];

  /**
   * The points after the first, clean run: item id => list of generations.
   *
   * @var array<string, list<int>>
   */
  private array $baseline = [];

  /**
   * Every embeddings call so far: the input text.
   *
   * @var list<string>
   */
  private array $embedCalls = [];

  /**
   * Decides whether an embeddings call fails: text => exception or NULL.
   *
   * @var callable|null
   */
  private $failWith = NULL;

  /**
   * {@inheritdoc}
   *
   * Swaps the throttle for the test subclass (a controllable context and a
   * clock that a wait moves forward). Guarded, so a build without the throttle
   * still boots.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    if ($container->hasDefinition(self::THROTTLE_SERVICE)) {
      $container->getDefinition(self::THROTTLE_SERVICE)->setClass(TestableEmbeddingThrottleSubscriber::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('ai_mock_provider_result');
    $this->installConfig(['ai_usage_limits', 'social_ai_indexing']);

    // The shipped storage swap, then the test's in-memory storage under it.
    $state = $this->container->get('state');
    $state->set('social_ai_indexing_test.in_memory_qdrant', TRUE);
    $state->set('social_ai_indexing_test.qdrant_generation', 1);
    $this->container->get('ai.vdb_provider')->clearCachedDefinitions();

    $this->installShippedConfig('localnodes_platform', ['ai_search.index.social_posts']);
    $this->posts = $this->createShippedIndex('social_posts', $this->createQdrantServer());

    $group = $this->createFlexibleGroup('Fictional rate-limit group', 'public');
    $this->group = $group;
    foreach (self::TOKENS as $letter => $token) {
      // B is long, so it has more than one chunk (the multi-chunk cases).
      $body = $letter === 'B'
        ? trim(str_repeat("$token is a fictional note about the rate-limit group's shared tool library and its opening hours. ", 27))
        : "$token is a fictional short note for the rate-limit group.";
      $node = $this->createGroupTopic($group, "Fictional $token note", $body, 'public');
      $this->nodes[$letter] = $node;
      $this->itemIds[$letter] = 'entity:node/' . $node->id() . ':' . $node->language()->getId();
    }
    // Flush the post-request operations the fixture saves registered
    // (social_posts has index_directly), then index whatever is left.
    $this->container->get('search_api.post_request_indexing')->destruct();
    $this->posts->indexItems();
    $this->baseline = $this->points();

    foreach ($this->itemIds as $letter => $id) {
      $this->assertNotSame([], $this->baseline[$id] ?? [], "Item $letter has points after the first run.");
      $this->assertSame([1], array_values(array_unique($this->baseline[$id])), "Item $letter's points are generation 1.");
    }
    $b_chunks = count($this->baseline[$this->itemIds['B']]);
    $this->assertGreaterThan(2, $b_chunks, 'Item B is multi-chunk.');
    $this->assertLessThanOrEqual(10, $b_chunks, 'Item B fits in one slice of 10 chunks, so one run embeds it whole.');
    $this->assertSame([], $this->remaining(), 'Everything is indexed.');

    $this->config('ai.settings')
      ->set('default_providers.embeddings', ['provider_id' => self::PROVIDER, 'model_id' => self::MODEL])
      ->save();

    // Counts every embeddings attempt, and fails the ones a test picks.
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      function (PreGenerateResponseEvent $event): void {
        // A multi-chunk item goes out as one 'embeddings_collection' request
        // (echoai implements EmbeddingsCollectionInterface), a single chunk as
        // an 'embeddings' request.
        if (!in_array($event->getOperationType(), ['embeddings', 'embeddings_collection'], TRUE)) {
          return;
        }
        $text = self::textOf($event->getInput());
        $this->embedCalls[] = $text;
        if ($this->failWith !== NULL && ($exception = ($this->failWith)($text)) !== NULL) {
          throw $exception;
        }
      },
      10000,
    );
    // Counts the calls that were not refused by the throttle (22.2-12).
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      function (PreGenerateResponseEvent $event): void {
        if (in_array($event->getOperationType(), ['embeddings', 'embeddings_collection'], TRUE)) {
          $input = $event->getInput();
          $this->passedCalls[] = [$event->getOperationType(), $input instanceof EmbeddingsCollectionInput ? $input->getPromptCount() : 1];
        }
      },
      35,
    );
  }

  /**
   * A search_api_ai_search server on the 'qdrant' VDB with echoai embeddings.
   *
   * The shipped ai_knowledge_garden server's settings, with Gemini swapped
   * for ai_test's echo provider. 'qdrant' is the real plugin id; the test
   * module swaps its storage.
   */
  private function createQdrantServer(): string {
    Server::create([
      'id' => 'phase222_qdrant',
      'name' => 'Phase 22.2 qdrant test server',
      'status' => TRUE,
      'backend' => 'search_api_ai_search',
      'backend_config' => [
        'chat_model' => self::PROVIDER . '__' . self::MODEL,
        'database' => 'qdrant',
        'database_settings' => [
          'database_name' => 'default',
          'collection' => 'phase222_rate',
          'metric' => 'cosine_similarity',
        ],
        'embeddings_engine' => self::PROVIDER . '__' . self::MODEL,
        'embeddings_engine_configuration' => [
          'set_dimensions' => FALSE,
          'dimensions' => 1,
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
    return 'phase222_qdrant';
  }

  /**
   * An embeddings input as text.
   */
  private static function textOf(mixed $input): string {
    if (is_string($input)) {
      return $input;
    }
    if (is_object($input) && method_exists($input, 'getPrompts')) {
      return implode("\n", $input->getPrompts());
    }
    if (is_object($input) && method_exists($input, 'getPrompt')) {
      return (string) $input->getPrompt();
    }
    return '';
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
   * Marks every item for reindexing (what "Queue all items" does).
   */
  private function markAllForReindex(): void {
    Index::load('social_posts')->reindex();
  }

  /**
   * Sets which generation the next inserted points carry.
   */
  private function setGeneration(int $generation): void {
    $this->container->get('state')->set('social_ai_indexing_test.qdrant_generation', $generation);
  }

  /**
   * The ids of the given letters, sorted.
   *
   * @return list<string>
   *   Item ids.
   */
  private function ids(string ...$letters): array {
    $ids = array_map(fn(string $letter): string => $this->itemIds[$letter], $letters);
    sort($ids);
    return $ids;
  }

  /**
   * Embeddings calls whose text contains an item's token.
   */
  private function callsFor(string $letter): int {
    return count(array_filter($this->embedCalls, static fn(string $text): bool => str_contains($text, self::TOKENS[$letter])));
  }

  /**
   * Runs the second pass with a 429 on item C's embeddings call.
   */
  private function runWithRateLimitOnC(): void {
    $this->setGeneration(2);
    $this->failWith = static fn(string $text): ?\Throwable => str_contains($text, self::TOKENS['C']) ? new AiRateLimitException('Fictional: too many requests') : NULL;
    $this->markAllForReindex();
    $this->embedCalls = [];
    Index::load('social_posts')->indexItems();
  }

  /**
   * A 429 in the middle of a batch keeps the old vectors of C and D.
   */
  public function testRateLimitMidBatchKeepsOldVectors(): void {
    $this->runWithRateLimitOnC();
    $points = $this->points();

    foreach (['A', 'B'] as $letter) {
      $id = $this->itemIds[$letter];
      $this->assertSame([2], array_values(array_unique($points[$id] ?? [])), "$letter has only generation-2 points.");
    }
    foreach (['C', 'D'] as $letter) {
      $id = $this->itemIds[$letter];
      $this->assertSame($this->baseline[$id], $points[$id] ?? [], "$letter keeps exactly its generation-1 points.");
    }
    $this->assertSame($this->ids('C', 'D'), $this->remaining(), 'C and D stay tracked; A and B do not.');
    $this->assertSame(0, $this->callsFor('D'), 'Nothing was embedded for D after the 429.');
  }

  /**
   * After a 429 a backoff is stored, and a run inside it makes no calls.
   */
  public function testBackoffSkipsEmbeddings(): void {
    $this->runWithRateLimitOnC();
    $until = (int) $this->container->get('state')->get('social_ai_indexing.embedding_backoff_until', 0);
    $this->assertGreaterThan(time(), $until, 'A backoff time in the future is stored.');
    $after_first = $this->points();

    // A second run inside the backoff: zero embeddings calls.
    $this->failWith = NULL;
    $this->embedCalls = [];
    Index::load('social_posts')->indexItems();
    $this->assertSame([], $this->embedCalls, 'Inside the backoff no embeddings call is made.');
    $this->assertSame($this->ids('C', 'D'), $this->remaining(), 'C and D stay tracked.');
    $this->assertSame($after_first, $this->points(), 'The second run changes no points.');

    // The backoff ends: the same items index, as generation 3.
    $this->container->get('state')->set('social_ai_indexing.embedding_backoff_until', time() - 1);
    $this->setGeneration(3);
    Index::load('social_posts')->indexItems();
    $points = $this->points();
    foreach (['C', 'D'] as $letter) {
      $this->assertSame([3], array_values(array_unique($points[$this->itemIds[$letter]] ?? [])), "$letter is re-embedded once the backoff ends.");
    }
    $this->assertSame([], $this->remaining(), 'Everything is indexed.');
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
   * A 429 on a later chunk of a multi-chunk item stores nothing for the item.
   *
   * The echo provider batches an item's chunks, so the batch call fails with
   * a plain error first (upstream falls back to one call per chunk), the
   * first chunk works, and the second chunk is rate limited. ai_search alpha6
   * swallows that 429 and returns the chunks that worked; the rethrow patch
   * lets it through, and B keeps its old, complete set of points.
   */
  public function testMultiChunkItemNotStoredHalfEmbedded(): void {
    $this->setGeneration(2);
    $b_calls = 0;
    $this->failWith = function (string $text) use (&$b_calls): ?\Throwable {
      if (!str_contains($text, self::TOKENS['B'])) {
        return NULL;
      }
      $b_calls++;
      return match (TRUE) {
        // The batch call (all chunks in one request) fails, not rate limited.
        $b_calls === 1 => new \RuntimeException('Fictional: the batch call failed'),
        // The first chunk works. The second is rate limited.
        $b_calls === 3 => new AiRateLimitException('Fictional: too many requests'),
        default => NULL,
      };
    };
    $this->markAllForReindex();
    $this->embedCalls = [];
    Index::load('social_posts')->indexItems();
    $points = $this->points();

    $b = $this->itemIds['B'];
    $this->assertSame($this->baseline[$b], $points[$b] ?? [], 'B keeps exactly its generation-1 points; it is not stored with only some chunks.');
    $this->assertSame([2], array_values(array_unique($points[$this->itemIds['A']] ?? [])), 'A, before B, has its new points.');
    $this->assertSame($this->ids('B', 'C', 'D'), $this->remaining(), 'B, C and D stay tracked.');
    $this->assertSame(0, $this->callsFor('C'), 'Nothing was embedded after the 429.');
  }

  /**
   * A rate-limited batch call does not turn into one call per chunk.
   */
  public function testRateLimitedBatchCallDoesNotFallBackToSingleCalls(): void {
    $this->setGeneration(2);
    $this->failWith = static fn(string $text): ?\Throwable => str_contains($text, self::TOKENS['B']) ? new AiRateLimitException('Fictional: too many requests') : NULL;
    $this->markAllForReindex();
    $this->embedCalls = [];
    Index::load('social_posts')->indexItems();

    $this->assertSame(1, $this->callsFor('B'), 'One call for B, no fallback to a call per chunk.');
    $b = $this->itemIds['B'];
    $this->assertSame($this->baseline[$b], $this->points()[$b] ?? [], 'B keeps its generation-1 points.');
    $this->assertSame($this->ids('B', 'C', 'D'), $this->remaining(), 'B, C and D stay tracked.');
  }

  /**
   * A 503 in the middle of a batch keeps the old vectors of B, C and D.
   */
  public function testUnavailableMidBatchKeepsOldVectors(): void {
    $this->setGeneration(2);
    $this->failWith = static fn(string $text): ?\Throwable => str_contains($text, self::TOKENS['B']) ? new AiRequestErrorException('Fictional: service unavailable', 503) : NULL;
    $this->markAllForReindex();
    $this->embedCalls = [];
    Index::load('social_posts')->indexItems();
    $points = $this->points();

    $this->assertSame([2], array_values(array_unique($points[$this->itemIds['A']] ?? [])), 'A has its new points.');
    foreach (['B', 'C', 'D'] as $letter) {
      $id = $this->itemIds[$letter];
      $this->assertSame($this->baseline[$id], $points[$id] ?? [], "$letter keeps exactly its generation-1 points.");
    }
    $this->assertSame($this->ids('B', 'C', 'D'), $this->remaining(), 'B, C and D stay tracked.');
    $this->assertGreaterThan(time(), (int) $this->container->get('state')->get('social_ai_indexing.embedding_backoff_until', 0), 'A 503 stores a backoff.');
    $lines = $this->loggedLines('index_backoff');
    $this->assertCount(1, $lines, 'One index_backoff line.');
    $this->assertStringContainsString('index=social_posts reason=http_503 retry_after=30 stopped_at=1 of=1', $lines[0]);
  }

  /**
   * A member's save returns at once while Gemini rate-limits.
   *
   * social_posts has index_directly, so a Topic save indexes at the end of the
   * request. The listener throws a 429 for the saved item; the run must not
   * wait, must leave the item's old points alone, and must keep it tracked.
   */
  public function testPostRequestIndexingDoesNotBlock(): void {
    $this->setGeneration(2);
    $a = $this->itemIds['A'];
    $this->failWith = static fn(string $text): ?\Throwable => str_contains($text, self::TOKENS['A']) ? new AiRateLimitException('Fictional: too many requests') : NULL;

    $node = $this->nodes['A'];
    $node->set('body', ['value' => self::TOKENS['A'] . ' is a fictional note, edited by a member.', 'format' => 'plain_text']);
    $started = microtime(TRUE);
    $node->save();
    $this->container->get('search_api.post_request_indexing')->destruct();
    $elapsed = microtime(TRUE) - $started;

    $this->assertLessThan(1.0, $elapsed, 'The save and its post-request indexing return within a second.');
    $this->assertSame('edited by a member', substr(strstr((string) \Drupal::entityTypeManager()->getStorage('node')->loadUnchanged($node->id())->get('body')->value, 'edited'), 0, 18), 'The save itself succeeded.');
    $this->assertSame($this->baseline[$a], $this->points()[$a] ?? [], 'The item keeps its old points.');
    $this->assertContains($a, $this->remaining(), 'The item stays tracked.');
    $this->assertGreaterThan(time(), (int) $this->container->get('state')->get('social_ai_indexing.embedding_backoff_until', 0), 'A backoff is stored.');
  }

  /**
   * One item that always fails for another reason cannot stall indexing.
   */
  public function testPermanentClientErrorDoesNotStall(): void {
    $c = $this->itemIds['C'];
    $state = $this->container->get('state');
    $cases = [
      2 => ['a 400', new AiRequestErrorException('Fictional: bad request', 400)],
      3 => ['a RuntimeException', new \RuntimeException('Fictional: a bad chunk')],
    ];
    foreach ($cases as $generation => [$label, $exception]) {
      $this->setGeneration($generation);
      $this->failWith = static fn(string $text): ?\Throwable => str_contains($text, self::TOKENS['C']) ? $exception : NULL;
      $this->markAllForReindex();
      $this->container->get('social_ai_indexing_test.spy_logger')->reset();
      Index::load('social_posts')->indexItems();
      $points = $this->points();

      foreach (['A', 'B', 'D'] as $letter) {
        $this->assertSame([$generation], array_values(array_unique($points[$this->itemIds[$letter]] ?? [])), "With $label on C: $letter has its new points.");
      }
      $this->assertSame($this->baseline[$c] ?? [], $points[$c] ?? [], "With $label on C: C keeps its generation-1 points.");
      $this->assertSame([$c], $this->remaining(), "With $label on C: only C stays tracked.");
      $this->assertSame(0, (int) $state->get('social_ai_indexing.embedding_backoff_until', 0), "With $label on C: no backoff is stored.");
      $this->assertSame([], $this->loggedLines('index_backoff'), "With $label on C: nothing is logged as a backoff.");
      $lines = $this->loggedLines('index_item_skipped');
      $this->assertCount(1, $lines, "With $label on C: one index_item_skipped line.");
      $this->assertStringContainsString("index=social_posts item=$c reason=", $lines[0]);

      // The next run retries C once, and still gets through the rest.
      $this->embedCalls = [];
      Index::load('social_posts')->indexItems();
      $this->assertSame([$c], $this->remaining(), "With $label on C: the next run leaves only C tracked.");
      $this->assertGreaterThan(0, $this->callsFor('C'), "With $label on C: C is retried.");
      $this->assertSame(0, $this->callsFor('D'), "With $label on C: D is not embedded again.");
    }
  }

  /**
   * An edited item is embedded again, not left with its stale vectors.
   *
   * A member's edit marks the item "changed" through the tracker. With the
   * default tracker the chunk progress ai_search keeps for the item stays at
   * "complete", the next run embeds nothing, and the item is marked indexed
   * with its old vectors (or, in the stock provider, with none). The shipped
   * index uses ai_search_tracker, which resets the progress on an edit.
   */
  public function testEditedItemIsReEmbedded(): void {
    $this->assertSame('ai_search_tracker', Index::load('social_posts')->getTrackerId(), 'The shipped social_posts index uses ai_search_tracker.');
    $this->setGeneration(2);
    $node = $this->nodes['A'];
    $node->set('body', ['value' => self::TOKENS['A'] . ' is a fictional note, edited by a member.', 'format' => 'plain_text']);
    $node->save();
    $this->container->get('search_api.post_request_indexing')->destruct();
    Index::load('social_posts')->indexItems();

    $points = $this->points();
    $a = $this->itemIds['A'];
    $this->assertSame([2], array_values(array_unique($points[$a] ?? [])), 'The edited item has new points.');
    foreach (['B', 'C', 'D'] as $letter) {
      $this->assertSame($this->baseline[$this->itemIds[$letter]], $points[$this->itemIds[$letter]] ?? [], "$letter, not edited, keeps its points.");
    }
    $this->assertSame([], $this->remaining(), 'Everything is indexed.');
  }

  /**
   * Turns the throttle on for a test: budget, context, clean window.
   *
   * The first line is the "not built yet" signal of the red run.
   */
  private function throttleOn(int $budget, bool $cli): void {
    $this->assertTrue($this->container->has(self::THROTTLE_SERVICE), 'The embeddings throttle service exists.');
    $this->config('social_ai_indexing.settings')->set('embedding_requests_per_minute', $budget)->save();
    $state = $this->container->get('state');
    $state->set('social_ai_indexing_test.throttle_cli', $cli);
    $state->set('social_ai_indexing_test.throttle_clock_offset', 0);
    $state->set('social_ai_indexing_test.throttle_waits', []);
    $state->delete(self::WINDOW_KEY);
    $state->delete(self::BACKOFF_KEY);
  }

  /**
   * Five single-chunk items, all indexed (generation 1), window left clean.
   *
   * B is shortened to one chunk and E is new. The first pass runs under the
   * default budget with the throttle in web context, so nothing waits.
   */
  private function prepareFiveSingleChunkItems(): void {
    $b = $this->nodes['B'];
    $b->set('body', ['value' => self::TOKENS['B'] . ' is now a fictional short note.', 'format' => 'plain_text']);
    $b->save();
    $e = $this->createGroupTopic($this->group, 'Fictional E note', 'Epsi5 is a fictional short note for the rate-limit group.', 'public');
    $this->nodes['E'] = $e;
    $this->itemIds['E'] = 'entity:node/' . $e->id() . ':' . $e->language()->getId();
    $this->container->get('search_api.post_request_indexing')->destruct();
    Index::load('social_posts')->indexItems();
    $this->assertSame([], $this->remaining(), 'The five single-chunk items are indexed.');
    foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
      $this->assertCount(1, $this->points()[$this->itemIds[$letter]] ?? [], "Item $letter has one point (one chunk).");
    }
  }

  /**
   * The units in the stored 60-second window.
   */
  private function windowUnits(): int {
    $window = $this->container->get('state')->get(self::WINDOW_KEY, []);
    return (int) array_sum(array_map(static fn(array $entry): int => (int) $entry[1], $window));
  }

  /**
   * The waits the throttle asked for, in seconds.
   *
   * @return list<int>
   *   Seconds.
   */
  private function waits(): array {
    return $this->container->get('state')->get('social_ai_indexing_test.throttle_waits', []);
  }

  /**
   * A web run stops at the budget: no wait, the rest stays tracked.
   *
   * Budget 3, five items of one chunk each. Exactly three calls reach the
   * provider. The other two items keep their old points and stay tracked,
   * nothing waits, and the stored backoff is the provider's usual 60 seconds.
   */
  public function testWebRunStopsAtBudget(): void {
    $this->prepareFiveSingleChunkItems();
    $before = $this->points();
    $this->throttleOn(3, FALSE);
    $this->setGeneration(2);
    $this->markAllForReindex();
    $this->passedCalls = [];

    $started = microtime(TRUE);
    Index::load('social_posts')->indexItems();
    $elapsed = microtime(TRUE) - $started;

    $this->assertCount(3, $this->passedCalls, 'Exactly three embeddings calls got through the budget of 3.');
    $this->assertLessThan(1.0, $elapsed, 'The run takes under a second: nothing waited.');
    $this->assertSame([], $this->waits(), 'A web run never waits.');

    $points = $this->points();
    $new = $old = [];
    foreach ($this->itemIds as $id) {
      if ($points[$id] === [2]) {
        $new[] = $id;
      }
      else {
        $this->assertSame($before[$id], $points[$id], 'An item the throttle stopped keeps exactly its old points.');
        $old[] = $id;
      }
    }
    $this->assertCount(3, $new, 'Three items have new points.');
    $this->assertCount(2, $old, 'Two items keep their old points.');
    sort($old);
    $this->assertSame($old, $this->remaining(), 'The two stopped items, and only they, stay tracked.');

    $until = (int) $this->container->get('state')->get(self::BACKOFF_KEY, 0);
    $this->assertGreaterThan(time(), $until, 'A backoff is stored.');
    $this->assertLessThanOrEqual(time() + 62, $until, 'The backoff is the usual 60 seconds, not longer.');
    $this->assertSame(3, $this->windowUnits(), 'The window holds the three units used.');
  }

  /**
   * A CLI run waits for the window to free, and finishes every item.
   *
   * The same budget as the web test. The recording waiter moves the clock
   * instead of pausing, so every wait ages the window.
   */
  public function testCliRunWaitsAndFinishes(): void {
    $this->prepareFiveSingleChunkItems();
    $this->throttleOn(3, TRUE);
    $this->setGeneration(2);
    $this->markAllForReindex();
    $this->passedCalls = [];

    Index::load('social_posts')->indexItems();

    $this->assertSame([], $this->remaining(), 'All five items are indexed.');
    foreach ($this->points() as $id => $generations) {
      $this->assertSame([2], $generations, "$id has its new point.");
    }
    $this->assertCount(5, $this->passedCalls, 'Five calls reached the provider.');
    $waits = $this->waits();
    $this->assertNotEmpty($waits, 'The throttle waited at least once.');
    foreach ($waits as $seconds) {
      $this->assertGreaterThan(0, $seconds);
      $this->assertLessThanOrEqual(60, $seconds, 'No single wait is longer than 60 seconds.');
    }
    $this->assertSame(0, (int) $this->container->get('state')->get(self::BACKOFF_KEY, 0), 'A run that waits stores no backoff.');
  }

  /**
   * Query embeddings are not throttled; indexing ones are.
   *
   * The control is the indexing-tagged call: it is refused once the budget is
   * used, so the passing query call shows the tag is what decides.
   */
  public function testQueryEmbeddingsNotThrottled(): void {
    $this->throttleOn(1, FALSE);
    $provider = $this->container->get('ai.provider')->createInstance(self::PROVIDER);
    $indexing_tags = ['ai_search', 'indexing'];

    $provider->embeddings(new EmbeddingsInput('Fictional chunk one'), self::MODEL, $indexing_tags);
    try {
      $provider->embeddings(new EmbeddingsInput('Fictional chunk two'), self::MODEL, $indexing_tags);
      $this->fail('A second indexing call inside the budget of 1 should be refused.');
    }
    catch (AiRateLimitException $e) {
      $this->assertSame('Local embeddings budget reached', $e->getMessage());
    }

    // Search time: the backend passes no 'indexing' tag.
    $output = $provider->embeddings(new EmbeddingsInput('Fictional query'), self::MODEL, ['ai_search']);
    $this->assertNotEmpty($output->getNormalized(), 'A query embedding goes through with the budget used up.');
    $output = $provider->embeddings(new EmbeddingsInput('Fictional query, no tags'), self::MODEL);
    $this->assertNotEmpty($output->getNormalized(), 'So does one with no tags at all.');
    $this->assertSame(1, $this->windowUnits(), 'Query calls were not counted.');
  }

  /**
   * A batch call counts by the unit the subscriber uses: its texts.
   *
   * 22.2-11 could not measure the free tier's rule, so the throttle counts
   * texts (orchestrator answer (a)). The test asks the subscriber for the
   * unit instead of hard-coding it, then proves the budget is charged by it.
   */
  public function testBatchCountsByMeasuredUnit(): void {
    $this->throttleOn(3, FALSE);
    $subscriber = $this->container->get(self::THROTTLE_SERVICE);
    $collection = new PreGenerateResponseEvent(
      requestThreadId: 'unit-test',
      providerId: self::PROVIDER,
      operationType: 'embeddings_collection',
      configuration: [],
      input: new EmbeddingsCollectionInput(['Fictional one', 'Fictional two', 'Fictional three']),
      modelId: self::MODEL,
      tags: ['ai_search', 'indexing'],
    );
    $single = new PreGenerateResponseEvent(
      requestThreadId: 'unit-test',
      providerId: self::PROVIDER,
      operationType: 'embeddings',
      configuration: [],
      input: new EmbeddingsInput('Fictional one'),
      modelId: self::MODEL,
      tags: ['ai_search', 'indexing'],
    );
    $this->assertSame(1, $subscriber->unitsFor($single), 'A single embeddings call is one unit.');
    $units = $subscriber->unitsFor($collection);
    $this->assertSame(3, $units, 'A batch of three texts is three units (texts counted, WINDOWS 57).');

    // The budget equals the batch's units: the batch fits, nothing else does.
    $provider = $this->container->get('ai.provider')->createInstance(self::PROVIDER);
    $provider->embeddingsCollection(new EmbeddingsCollectionInput(['Fictional one', 'Fictional two', 'Fictional three']), self::MODEL, ['ai_search', 'indexing']);
    $this->assertSame($units, $this->windowUnits(), 'The batch used exactly its units.');
    $this->expectException(AiRateLimitException::class);
    $provider->embeddings(new EmbeddingsInput('Fictional four'), self::MODEL, ['ai_search', 'indexing']);
  }

  /**
   * The backoff follows the provider's own delay, within limits (D-07).
   *
   * A "retry_after=N" on a rate-limit message sets the backoff to N seconds,
   * capped at 3600 (T-22.2-28: a hostile or absurd delay cannot pause
   * indexing for days). A 429 without it gets 60 seconds, a 503 gets 30.
   * The log line reports the seconds actually chosen.
   */
  public function testBackoffUsesRetryAfter(): void {
    $cases = [
      'retry_after 37' => [new AiRateLimitException('Fictional: too many requests retry_after=37', 429), 37, 'reason=rate_limit retry_after=37'],
      'retry_after 1' => [new AiRateLimitException('Fictional: too many requests retry_after=1', 429), 1, 'reason=rate_limit retry_after=1'],
      'retry_after 999999 is capped' => [new AiRateLimitException('Fictional: too many requests retry_after=999999', 429), 3600, 'reason=rate_limit retry_after=3600'],
      'a 429 with no delay' => [new AiRateLimitException('Fictional: too many requests', 429), 60, 'reason=rate_limit retry_after=60'],
      'a 503' => [new AiRequestErrorException('Fictional: service unavailable', 503), 30, 'reason=http_503 retry_after=30'],
      'a 503 with a delay in the text' => [new AiRequestErrorException('Fictional: unavailable retry_after=45', 503), 45, 'reason=http_503 retry_after=45'],
    ];
    $generation = 1;
    foreach ($cases as $label => [$exception, $seconds, $log]) {
      $this->throttleOn(60, FALSE);
      $this->setGeneration(++$generation);
      $this->failWith = static fn(string $text): ?\Throwable => str_contains($text, self::TOKENS['A']) ? $exception : NULL;
      $this->markAllForReindex();
      $this->container->get('social_ai_indexing_test.spy_logger')->reset();
      $now = time();
      Index::load('social_posts')->indexItems();

      $until = (int) $this->container->get('state')->get(self::BACKOFF_KEY, 0);
      $this->assertGreaterThanOrEqual($now + $seconds - 2, $until, "$label: the backoff is at least $seconds seconds.");
      $this->assertLessThanOrEqual(time() + $seconds + 2, $until, "$label: the backoff is at most $seconds seconds (plus 2).");
      $lines = $this->loggedLines('index_backoff');
      $this->assertCount(1, $lines, "$label: one index_backoff line.");
      $this->assertStringContainsString($log, $lines[0], "$label: the log says the seconds chosen.");
    }
  }

}
