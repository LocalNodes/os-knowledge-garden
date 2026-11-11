<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Embeddings\EmbeddingsCollectionInput;
use Drupal\ai_usage_limits\Exception\AiTokenUsageException;
use Drupal\Component\Serialization\Json;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\social_ai_indexing\Rag\PermissionAwareRagTool;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\OverviewTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * The site-wide Gemini token ceiling and how each AI path degrades at it.
 *
 * D-27 (Tom, 2026-11-05): drupal/ai_usage_limits is the last-resort spend
 * stop. localnodes_platform_install() sets the ceiling (30,000,000 tokens
 * per 30 days, all five counters equal), and AiCeilingIndexingSubscriber
 * turns the ceiling into a SearchApiException during index runs, so
 * search_api's own catches log it and the items stay tracked (checker W-1).
 *
 * The ceiling paths run on a real search_api_ai_search server on ai_test's
 * echo VDB provider with echoai embeddings, so the embeddings call that
 * ai_usage_limits refuses is the one the backend really makes. The keyword
 * leg is Open Social's social_content index on search_api_db. Only the
 * echo providers stand in for Gemini and Qdrant; no network call is made.
 * Every fixture is fictional (LeakFixtureTrait).
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class AiUsageCeilingTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use OverviewTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'ai_usage_limits',
    'gemini_provider',
    'localnodes_platform',
  ];

  /**
   * The echo provider and one of its models (chat and embeddings).
   */
  private const PROVIDER = 'echoai';
  private const MODEL = 'gpt-test';

  /**
   * The default ceiling (contract with the install helper).
   */
  private const CEILING = 30000000;

  /**
   * The five ai_usage_limits counters.
   */
  private const COUNTERS = [
    'input_token_usage',
    'output_token_usage',
    'total_token_usage',
    'cached_token_usage',
    'reasoning_token_usage',
  ];

  /**
   * The index-run subscriber's service id (contract).
   */
  private const SUBSCRIBER = 'localnodes_platform.ai_ceiling_indexing_subscriber';

  /**
   * The vector index on the echo AI search server.
   */
  private IndexInterface $posts;

  /**
   * Embeddings calls attempted (pre events), refused or not.
   */
  private int $embedAttempts = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The echo provider looks up mocked chat results in this entity.
    $this->installEntitySchema('ai_mock_provider_result');
    $this->installConfig(['ai_usage_limits']);

    // The shipped per-field indexing options ai_search's embedding strategy
    // reads for the social_posts index.
    $this->installShippedConfig('localnodes_platform', ['ai_search.index.social_posts']);
    $this->posts = $this->createShippedIndex('social_posts', $this->createEchoAiServer());
    $content = $this->createKeywordIndex($this->createTestSearchServer());
    $this->setUpLeakFixtures();
    // Indexed before the ceiling is set (W-1): every Topic fixture.
    $this->assertSame(6, $this->posts->indexItems(), 'Every Topic fixture is in the vector index.');
    $this->assertSame(6, $content->indexItems(), 'Every Topic fixture is in the keyword index.');
    // Flush the post-request operations the fixture saves registered
    // (social_posts has index_directly), while still under the ceiling.
    $this->container->get('search_api.post_request_indexing')->destruct();

    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => self::PROVIDER, 'model_id' => self::MODEL])
      ->set('default_providers.embeddings', ['provider_id' => self::PROVIDER, 'model_id' => self::MODEL])
      ->save();
    $this->spy()->reset();

    // Counts every embeddings attempt before any listener can refuse it.
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      function (PreGenerateResponseEvent $event): void {
        if ($event->getOperationType() === 'embeddings') {
          $this->embedAttempts++;
        }
      },
      10000,
    );
  }

  /**
   * A search_api_ai_search server on the echo VDB with echoai embeddings.
   *
   * The shipped ai_knowledge_garden server's settings, with Qdrant and
   * Gemini swapped for ai_test's echo providers.
   */
  private function createEchoAiServer(): string {
    Server::create([
      'id' => 'phase22_echo_ai',
      'name' => 'Phase 22 echo AI search server',
      'status' => TRUE,
      'backend' => 'search_api_ai_search',
      'backend_config' => [
        'chat_model' => self::PROVIDER . '__' . self::MODEL,
        'database' => 'echo_db',
        'database_settings' => [
          'database_name' => 'default',
          'collection' => 'phase22_echo',
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
    return 'phase22_echo_ai';
  }

  /**
   * The spy logger on every channel.
   */
  private function spy(): SpyLogger {
    return $this->container->get('social_ai_indexing_test.spy_logger');
  }

  /**
   * Loads localnodes_platform.install and asserts the helper exists.
   */
  private function assertHelperExists(): void {
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    $this->assertTrue(function_exists('_localnodes_platform_configure_ai_usage_limits'), 'localnodes_platform.install defines _localnodes_platform_configure_ai_usage_limits().');
  }

  /**
   * Asserts the index-run subscriber is registered.
   */
  private function assertSubscriberRegistered(): void {
    $this->assertTrue($this->container->has(self::SUBSCRIBER), 'localnodes_platform registers the AI ceiling indexing subscriber.');
  }

  /**
   * Sets echoai's limits (all five counters) and its stored usage.
   *
   * @param int $limit
   *   The limit for every counter.
   * @param int|null $total
   *   The stored total usage; NULL clears the usage (the window reset).
   */
  private function setEchoUsage(int $limit, ?int $total): void {
    $this->config('ai_usage_limits.settings')
      ->set('providers.' . self::PROVIDER, ['enable_limits' => TRUE] + array_fill_keys(self::COUNTERS, $limit))
      ->save();
    $usage = $this->container->get('state')->get('ai_usage_limits', []) ?: [];
    if ($total === NULL) {
      // What ai_usage_limits_cron() does when the window rolls over.
      $usage[self::PROVIDER] = [];
    }
    else {
      $usage[self::PROVIDER] = array_fill_keys(self::COUNTERS, 0);
      $usage[self::PROVIDER]['total_token_usage'] = $total;
      $usage[self::PROVIDER]['retention_start_date'] = $this->container->get('datetime.time')->getRequestTime();
    }
    $this->container->get('state')->set('ai_usage_limits', $usage);
  }

  /**
   * Puts echoai at its ceiling: limits 10, total usage 11.
   */
  private function reachCeiling(): void {
    $this->setEchoUsage(10, 11);
  }

  /**
   * One echoai chat call through the provider proxy.
   */
  private function chat(): void {
    $this->container->get('ai.provider')
      ->createInstance(self::PROVIDER)
      ->chat(new ChatInput([new ChatMessage('user', 'A fictional question.')]), self::MODEL, ['phase22']);
  }

  /**
   * One echoai embeddings call through the provider proxy.
   */
  private function embed(): void {
    $this->container->get('ai.provider')
      ->createInstance(self::PROVIDER)
      ->embeddings('A fictional query', self::MODEL, ['ai_search']);
  }

  /**
   * One echoai batch embeddings call (several texts, one request).
   *
   * What ai_search sends for an item with several chunks when the provider
   * implements EmbeddingsCollectionInterface (echoai does, and since 22.2-11
   * so does gemini_provider).
   */
  private function embedCollection(): void {
    $this->container->get('ai.provider')
      ->createInstance(self::PROVIDER)
      ->embeddingsCollection(new EmbeddingsCollectionInput(['A fictional query', 'Another fictional query']), self::MODEL, ['ai_search']);
  }

  /**
   * Runs a callable and returns the exception it throws, or NULL.
   */
  private static function thrown(callable $callable): ?\Throwable {
    try {
      $callable();
    }
    catch (\Throwable $e) {
      return $e;
    }
    return NULL;
  }

  /**
   * Spy records on a channel whose filled-in text contains a needle.
   *
   * @return list<array>
   *   The matching records.
   */
  private function logged(string $channel, string $needle): array {
    return array_values(array_filter(
      $this->spy()->records(),
      static fn(array $record): bool => $record['channel'] === $channel && str_contains(SpyLogger::flatten($record), $needle),
    ));
  }

  /**
   * Item ids of an index still waiting to be indexed.
   *
   * @return list<string>
   *   Remaining item ids, sorted.
   */
  private function remaining(IndexInterface $index): array {
    $ids = $index->getTrackerInstance()->getRemainingItems();
    sort($ids);
    return $ids;
  }

  /**
   * The helper sets the ceiling on all five counters, and it validates.
   */
  public function testInstallHelperSetsCeiling(): void {
    $this->assertHelperExists();
    _localnodes_platform_configure_ai_usage_limits();

    $config = $this->config('ai_usage_limits.settings');
    $this->assertSame(['enable_limits' => TRUE] + array_fill_keys(self::COUNTERS, self::CEILING), $config->get('providers.gemini'), 'Gemini: limits on, all five counters at 30,000,000.');
    $this->assertSame(30, $config->get('retention_days'), 'A 30-day window.');

    $violations = $this->container->get('config.typed')
      ->createFromNameAndData('ai_usage_limits.settings', $config->getRawData())
      ->validate();
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    $this->assertSame([], $messages, 'The settings pass ai_usage_limits schema validation.');

    // ai_usage_limits_cron() runs foreach over this State value, so it is
    // an array from install on (a NULL warns on every cron run).
    $this->assertIsArray($this->container->get('state')->get('ai_usage_limits'), 'The usage State is an array after install.');
  }

  /**
   * Running the helper again keeps an admin's change.
   */
  public function testInstallHelperKeepsAdminChanges(): void {
    $this->assertHelperExists();
    _localnodes_platform_configure_ai_usage_limits();
    $this->config('ai_usage_limits.settings')
      ->set('providers.gemini.total_token_usage', 5000)
      ->set('retention_days', 7)
      ->save();
    $before = $this->config('ai_usage_limits.settings')->getRawData();

    _localnodes_platform_configure_ai_usage_limits();
    _localnodes_platform_configure_ai_usage_limits();
    $this->assertSame($before, $this->config('ai_usage_limits.settings')->getRawData(), 'The helper changes nothing once providers.gemini exists.');
    $this->assertSame(5000, $this->config('ai_usage_limits.settings')->get('providers.gemini.total_token_usage'));
  }

  /**
   * Over the ceiling a chat call is refused before the provider runs.
   */
  public function testChatRefusedAtCeiling(): void {
    $this->setEchoUsage(10, 5);
    $this->assertNull(self::thrown(fn() => $this->chat()), 'Under the ceiling (5 of 10) the chat call runs.');

    $this->reachCeiling();
    $this->assertInstanceOf(AiTokenUsageException::class, self::thrown(fn() => $this->chat()), 'Over the ceiling (11 of 10) the chat call is refused.');
  }

  /**
   * At the ceiling the overview controller answers 200 with no overview.
   */
  public function testOverviewEmptyAtCeiling(): void {
    $this->reachCeiling();
    $this->setCurrentUser($this->personas['P-MEM']);
    $this->resetAccessCaches();
    $response = $this->overviewController()->overview(Request::create('/api/ai/overview', 'GET', ['q' => 'fictional notice']));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['summary_html' => NULL], Json::decode((string) $response->getContent()), 'No overview at the ceiling.');
    $this->assertNotSame([], $this->logged('social_ai_indexing', AiTokenUsageException::class), 'The overview met ai_usage_limits\' refusal and handled it.');
  }

  /**
   * At the ceiling the search_api query paths return normally (W-1 a).
   */
  public function testQueryPathsDegradeAtCeiling(): void {
    $this->reachCeiling();
    $non = $this->personas['P-NON'];
    $this->setCurrentUser($non);
    $this->resetAccessCaches();

    $results = NULL;
    $this->assertNull(self::thrown(function () use ($non, &$results) {
      $results = $this->container->get('social_ai_indexing.hybrid_search')->search('fictional notice', $non, 10);
    }), 'HybridSearchService::search() does not throw at the ceiling.');
    $this->assertNotSame([], $results, 'The keyword leg still answers.');
    $ids = array_map(static fn(array $result): string => $result['entity']->getEntityTypeId() . ':' . $result['entity']->id(), $results);
    $this->assertNotContains('node:' . $this->fixtures['G-MO']->id(), $ids, 'P-NON never gets G-MO.');
    foreach ($results as $result) {
      $this->assertTrue($result['entity']->access('view', $non), 'Every result is one P-NON may view.');
    }
    $this->assertNotSame([], $this->logged('social_ai_indexing', 'Vector search failed: Token limit reached'), 'The vector leg met ai_usage_limits\' own refusal.');

    $related = NULL;
    $this->assertNull(self::thrown(function () use ($non, &$related) {
      $related = $this->container->get('social_ai_indexing.related_content')->findRelated($this->fixtures['G-PUB'], $non);
    }), 'RelatedContentService::findRelated() does not throw at the ceiling.');
    $this->assertSame([], $related, 'No related content at the ceiling.');
    $this->assertNotSame([], $this->logged('social_ai_indexing', 'Related content search failed: Token limit reached'));

    $retrieved = NULL;
    $this->assertNull(self::thrown(function () use ($non, &$retrieved) {
      $retrieved = $this->container->get('social_ai_indexing.ai_overview')->retrieve('fictional notice', $non);
    }), 'AiOverviewService::retrieve() does not throw at the ceiling.');
    $this->assertIsArray($retrieved);

    // Outside an index run, nothing is converted: the caller sees
    // ai_usage_limits' own exception.
    $this->assertInstanceOf(AiTokenUsageException::class, self::thrown(fn() => $this->embed()), 'A direct embeddings call outside an index run gets AiTokenUsageException.');
  }

  /**
   * At the ceiling the assistant's search tool fails closed (W-1 b).
   */
  public function testRagToolFailsClosedAtCeiling(): void {
    $this->reachCeiling();
    $tool = $this->runRagTool('social_posts', $this->personas['P-MEM'], (int) $this->groups['G']->id());
    $output = $tool->getReadableOutput();
    $this->assertSame(PermissionAwareRagTool::NO_RESULTS, $output, 'Zero blocks at the ceiling.');
    foreach ($this->fixtureCanaryTexts() as $canary) {
      $this->assertStringNotContainsString($canary, $output);
    }
    $this->assertNotSame([], $this->logged('social_ai_indexing', 'reason=search_failed:' . AiTokenUsageException::class), 'The tool finished with reason search_failed.');
  }

  /**
   * Index runs at the ceiling log, keep their items, and resume (W-1 c).
   */
  public function testIndexRunsPauseAtCeilingAndResume(): void {
    $this->reachCeiling();

    // A new Topic saved at the ceiling: tracked, not yet indexed.
    $topic = $this->createGroupTopic($this->groups['H'], 'Fictional H late notice', 'A fictional late notice for group H.', 'public');
    $item_id = 'entity:node/' . $topic->id() . ':' . $topic->language()->getId();
    $tracked = $this->remaining($this->posts);
    $this->assertContains($item_id, $tracked, 'The new Topic is waiting to be indexed.');

    // Cron path: Index::indexItems().
    $this->spy()->reset();
    $this->embedAttempts = 0;
    $this->assertNull(self::thrown(fn() => $this->posts->indexItems()), 'The cron index run lets no exception escape.');
    $this->assertSame($tracked, $this->remaining(Index::load('social_posts')), 'Cron path: nothing is marked indexed.');
    // Stopped at INDEXING_ITEMS, before the backend runs: the VDB provider
    // deletes an item's old vectors before embedding it, so a run stopped
    // later would leave edited content out of vector search until the reset.
    $this->assertSame(0, $this->embedAttempts, 'Cron path: the run stops before the backend deletes or embeds anything.');
    $this->assertNotSame([], $this->logged('search_api', SearchApiException::class), 'Cron path: search_api logged a SearchApiException.');
    $this->assertNotSame([], $this->logged('search_api', 'AI token ceiling reached'), 'Cron path: the log names the ceiling.');

    // Post-request path: PostRequestIndexing::destruct().
    $this->spy()->reset();
    $post_request = $this->container->get('search_api.post_request_indexing');
    $post_request->registerIndexingOperation('social_posts', [$item_id]);
    $this->embedAttempts = 0;
    $this->assertNull(self::thrown(fn() => $post_request->destruct()), 'The post-request index run lets no exception escape.');
    $this->assertSame(0, $this->embedAttempts, 'Post-request path: no embeddings call is attempted.');
    $this->assertSame($tracked, $this->remaining(Index::load('social_posts')), 'Post-request path: nothing is marked indexed.');
    $this->assertNotSame([], $this->logged('search_api', SearchApiException::class), 'Post-request path: search_api logged a SearchApiException.');

    // The keyword index makes no AI call, so it keeps indexing.
    $this->assertSame(1, Index::load('social_content')->indexItems(), 'The keyword index indexes the new Topic at the ceiling.');

    // After a failed run, a call outside indexing is not converted.
    $this->assertInstanceOf(AiTokenUsageException::class, self::thrown(fn() => $this->embed()), 'After the failed runs, a direct embeddings call gets AiTokenUsageException.');

    // The window rolls over: the same items index and are marked indexed.
    $this->setEchoUsage(10, NULL);
    $this->assertSame(count($tracked), Index::load('social_posts')->indexItems(), 'After the reset, the paused items index.');
    $this->assertSame([], $this->remaining(Index::load('social_posts')), 'Every item is marked indexed.');
  }

  /**
   * Only embeddings calls inside an index run are converted.
   *
   * A run that starts under the ceiling and crosses it (another request's
   * chat output lands meanwhile) is stopped at its next embeddings call.
   */
  public function testOnlyIndexRunEmbeddingsConverted(): void {
    $this->assertSubscriberRegistered();
    $dispatcher = $this->container->get('event_dispatcher');

    $dispatcher->dispatch(new IndexingItemsEvent($this->posts, []), SearchApiEvents::INDEXING_ITEMS);
    $this->reachCeiling();
    $this->assertInstanceOf(SearchApiException::class, self::thrown(fn() => $this->embed()), 'Inside an index run, the ceiling becomes a SearchApiException.');
    // The run is over once it threw: the next call is outside it.
    $this->assertInstanceOf(AiTokenUsageException::class, self::thrown(fn() => $this->embed()), 'After the converted call, the next call is not converted.');

    $this->setEchoUsage(10, 5);
    $dispatcher->dispatch(new IndexingItemsEvent($this->posts, []), SearchApiEvents::INDEXING_ITEMS);
    // Under the ceiling, nothing is refused inside an index run.
    $this->assertNull(self::thrown(fn() => $this->embed()), 'Under the ceiling, embeddings inside an index run run.');
    $this->reachCeiling();
    $this->assertInstanceOf(AiTokenUsageException::class, self::thrown(fn() => $this->chat()), 'A chat call inside an index run is not converted.');

    // An index run that starts at the ceiling on an AI search server stops
    // before the backend; one on another server (the keyword index) runs.
    $this->assertInstanceOf(SearchApiException::class, self::thrown(fn() => $dispatcher->dispatch(new IndexingItemsEvent($this->posts, []), SearchApiEvents::INDEXING_ITEMS)), 'INDEXING_ITEMS at the ceiling on the AI search server is refused.');
    $this->assertNull(self::thrown(fn() => $dispatcher->dispatch(new IndexingItemsEvent(Index::load('social_content'), []), SearchApiEvents::INDEXING_ITEMS)), 'INDEXING_ITEMS on the keyword index is not refused.');
  }

  /**
   * A batch embeddings call is converted inside an index run, like a single one.
   *
   * 22.2-11 gave Gemini batch embeddings, so a multi-chunk item now goes out
   * as one 'embeddings_collection' request. ai_usage_limits refuses it at the
   * ceiling like any other call; the subscriber has to turn that refusal into
   * the same SearchApiException a single embeddings call gets.
   */
  public function testBatchEmbeddingsInsideIndexRunConverted(): void {
    $this->assertSubscriberRegistered();
    $dispatcher = $this->container->get('event_dispatcher');

    // Outside an index run a batch call is not converted.
    $this->reachCeiling();
    $this->assertInstanceOf(AiTokenUsageException::class, self::thrown(fn() => $this->embedCollection()), 'Outside an index run, a batch call at the ceiling gets AiTokenUsageException.');

    // Inside one, it is.
    $this->setEchoUsage(10, 5);
    $dispatcher->dispatch(new IndexingItemsEvent($this->posts, []), SearchApiEvents::INDEXING_ITEMS);
    $this->assertNull(self::thrown(fn() => $this->embedCollection()), 'Under the ceiling, a batch call inside an index run runs.');
    $this->reachCeiling();
    $this->assertInstanceOf(SearchApiException::class, self::thrown(fn() => $this->embedCollection()), 'Inside an index run, a batch call at the ceiling becomes a SearchApiException.');
    // The run is over once it threw: the next call is outside it.
    $this->assertInstanceOf(AiTokenUsageException::class, self::thrown(fn() => $this->embedCollection()), 'After the converted call, the next batch call is not converted.');
  }

  /**
   * Text of the members-only fixtures that must never reach an output.
   *
   * @return list<string>
   *   Canary strings.
   */
  private function fixtureCanaryTexts(): array {
    return [self::ZQ7_TITLE, self::ZQ7_BODY, self::ZQ7_FACT, self::ZQ7_COMMENT, self::HK3_BODY, self::SX9_BODY];
  }

}
