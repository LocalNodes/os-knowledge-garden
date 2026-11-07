<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\Core\Cache\Cache;
use Drupal\node\Entity\Node;
use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\Event\ProcessingResultsEvent;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\search_api\ServerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Query embeddings are cached; index-time embeddings never are (D-27b, c).
 *
 * Embeds through the real provider proxy with the echo provider (ai_test),
 * so the drupal/ai pre and post events fire exactly as they do for Gemini.
 * Real provider calls are counted with a PostGenerateResponseEvent
 * listener: the post event fires only after a real call, never for a
 * forced (cached) output.
 *
 * The query context is opened and closed with search_api's own events on a
 * mocked query, index and server. The mock index id is not a shipped AI
 * index, so SearchQuerySubscriber leaves it alone.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class EmbeddingCacheTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai_test',
  ];

  /**
   * The echo provider and one of its models.
   */
  private const PROVIDER = 'echoai';
  private const MODEL = 'gpt-test';

  /**
   * Cache lifetime (contract with the subscriber): seven days.
   */
  private const TTL = 604800;

  /**
   * Real (non-forced) embeddings calls: the input text of each.
   *
   * @var list<string>
   */
  private array $realCalls = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The echo provider looks up mocked chat results in this entity.
    $this->installEntitySchema('ai_mock_provider_result');
    $this->container->get('event_dispatcher')->addListener(
      PostGenerateResponseEvent::EVENT_NAME,
      function (PostGenerateResponseEvent $event): void {
        if ($event->getOperationType() === 'embeddings') {
          $input = $event->getInput();
          $this->realCalls[] = $input instanceof EmbeddingsInput ? $input->getPrompt() : (string) $input;
        }
      },
      -1000,
    );
  }

  /**
   * The cache id the subscriber uses for a text (contract).
   */
  private static function cid(string $normalised, string $provider = self::PROVIDER, string $model = self::MODEL): string {
    return 'emb:' . $provider . ':' . $model . ':' . hash('sha256', $normalised);
  }

  /**
   * The ai_embeddings bin (the factory makes any bin name, so RED can read it).
   */
  private function bin(): \Drupal\Core\Cache\CacheBackendInterface {
    // MemoryBackendFactory keeps one backend per bin, the one the service gets.
    return $this->container->get('cache_factory')->get('ai_embeddings');
  }

  /**
   * Embeds a text through the provider proxy and returns the normalized output.
   */
  private function embed(string|EmbeddingsInput $input, string $model = self::MODEL): array {
    return $this->container->get('ai.provider')
      ->createInstance(self::PROVIDER)
      ->embeddings($input, $model, ['ai_search'])
      ->getNormalized();
  }

  /**
   * A mocked query on an index whose server runs the given backend.
   */
  private function query(string $backend = 'search_api_ai_search'): QueryInterface {
    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackendId')->willReturn($backend);
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('phase22_mock_index');
    $index->method('hasValidServer')->willReturn(TRUE);
    $index->method('getServerInstance')->willReturn($server);
    $query = $this->createMock(QueryInterface::class);
    $query->method('getIndex')->willReturn($index);
    return $query;
  }

  /**
   * Dispatches search_api's QUERY_PRE_EXECUTE for a query.
   */
  private function openQuery(QueryInterface $query): void {
    $this->container->get('event_dispatcher')->dispatch(new QueryPreExecuteEvent($query), SearchApiEvents::QUERY_PRE_EXECUTE);
  }

  /**
   * Dispatches search_api's PROCESSING_RESULTS for a query.
   */
  private function closeQuery(QueryInterface $query): void {
    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getQuery')->willReturn($query);
    $this->container->get('event_dispatcher')->dispatch(new ProcessingResultsEvent($results), SearchApiEvents::PROCESSING_RESULTS);
  }

  /**
   * Runs a callable inside one AI-search query context.
   */
  private function inQuery(callable $callable): mixed {
    $query = $this->query();
    $this->openQuery($query);
    try {
      return $callable();
    }
    finally {
      $this->closeQuery($query);
    }
  }

  /**
   * Dispatches search_api's INDEXING_ITEMS and ITEMS_INDEXED.
   */
  private function indexing(bool $start): void {
    $index = $this->query()->getIndex();
    $dispatcher = $this->container->get('event_dispatcher');
    if ($start) {
      $dispatcher->dispatch(new IndexingItemsEvent($index, []), SearchApiEvents::INDEXING_ITEMS);
    }
    else {
      $dispatcher->dispatch(new ItemsIndexedEvent($index, ['entity:node/1:en']), SearchApiEvents::ITEMS_INDEXED);
    }
  }

  /**
   * Dispatches the kernel terminate event.
   */
  private function terminate(): void {
    $this->container->get('event_dispatcher')->dispatch(
      new TerminateEvent($this->container->get('http_kernel'), Request::create('/'), new Response()),
      KernelEvents::TERMINATE,
    );
  }

  /**
   * The subscriber and its bin are registered.
   */
  public function testServicesRegistered(): void {
    $this->assertTrue($this->container->has('cache.ai_embeddings'), 'The cache.ai_embeddings bin service exists.');
    $this->assertTrue(class_exists('Drupal\social_ai_indexing\EventSubscriber\EmbeddingCacheSubscriber'), 'EmbeddingCacheSubscriber exists.');
  }

  /**
   * A repeated query is one real call; whitespace is normalised, case kept.
   */
  public function testRepeatedQueryIsOneCall(): void {
    $first = $this->inQuery(fn() => $this->embed('bioregional food'));
    $second = $this->inQuery(fn() => $this->embed('bioregional food'));
    $this->assertCount(1, $this->realCalls, 'The same text twice: one real call.');
    $this->assertSame($first, $second, 'The cached vector equals the real one.');

    $third = $this->inQuery(fn() => $this->embed(new EmbeddingsInput("  bioregional \n  food ")));
    $this->assertCount(1, $this->realCalls, 'Extra whitespace reuses the entry.');
    $this->assertSame($first, $third);

    $this->inQuery(fn() => $this->embed('Bioregional food'));
    $this->assertCount(2, $this->realCalls, 'A different case is a separate entry.');
    $this->assertNotFalse($this->bin()->get(self::cid('Bioregional food')));
    $this->assertNotFalse($this->bin()->get(self::cid('bioregional food')));
  }

  /**
   * Outside any query context nothing is read or written.
   */
  public function testOutsideQueryContextIsNotCached(): void {
    $this->embed('bioregional food');
    $this->embed('bioregional food');
    $this->assertCount(2, $this->realCalls, 'Outside a query: two real calls.');
    $this->assertFalse($this->bin()->get(self::cid('bioregional food')), 'Outside a query: nothing stored.');

    // Open and close a context: the next call is not cached either.
    $query = $this->query();
    $this->openQuery($query);
    $this->closeQuery($query);
    $this->embed('bioregional food');
    $this->assertCount(3, $this->realCalls, 'After PROCESSING_RESULTS the context is closed.');
    $this->assertFalse($this->bin()->get(self::cid('bioregional food')));

    // Closing more often than opening never goes below zero.
    $this->closeQuery($query);
    $this->closeQuery($query);
    $this->inQuery(fn() => $this->embed('bioregional food'));
    $this->inQuery(fn() => $this->embed('bioregional food'));
    $this->assertCount(4, $this->realCalls, 'A fresh context after extra closes caches normally.');
  }

  /**
   * A query on an index that isn't on the AI search backend opens nothing.
   */
  public function testOtherBackendDoesNotOpenContext(): void {
    $query = $this->query('search_api_db');
    $this->openQuery($query);
    $this->embed('bioregional food');
    $this->embed('bioregional food');
    $this->closeQuery($query);
    $this->assertCount(2, $this->realCalls, 'A search_api_db query: two real calls.');
    $this->assertFalse($this->bin()->get(self::cid('bioregional food')));
  }

  /**
   * Indexing writes no entry, even inside a query context (W-2).
   */
  public function testIndexingIsNeverCached(): void {
    $chunk = 'Fictional members-only chunk: the Willow Creek seed library meets on Thursdays.';
    $query = $this->query();
    $this->openQuery($query);
    $this->indexing(TRUE);
    $this->embed($chunk);
    $this->embed($chunk);
    $this->assertCount(2, $this->realCalls, 'During indexing: two real calls.');
    $this->assertFalse($this->bin()->get(self::cid($chunk)), 'During indexing: no entry for the chunk.');

    $this->indexing(FALSE);
    $this->embed('bioregional food');
    $this->embed('bioregional food');
    $this->closeQuery($query);
    $this->assertCount(3, $this->realCalls, 'After ITEMS_INDEXED, inside a query context, caching resumes.');
    $this->assertNotFalse($this->bin()->get(self::cid('bioregional food')));
  }

  /**
   * Terminate resets both depths.
   */
  public function testTerminateResetsContext(): void {
    // An aborted query never reaches PROCESSING_RESULTS.
    $this->openQuery($this->query());
    $this->terminate();
    $this->embed('bioregional food');
    $this->embed('bioregional food');
    $this->assertCount(2, $this->realCalls, 'After terminate the open context is gone.');
    $this->assertFalse($this->bin()->get(self::cid('bioregional food')));

    // An indexing run that threw never reaches ITEMS_INDEXED.
    $this->indexing(TRUE);
    $this->terminate();
    $this->inQuery(fn() => $this->embed('bioregional food'));
    $this->inQuery(fn() => $this->embed('bioregional food'));
    $this->assertCount(3, $this->realCalls, 'After terminate the indexing gate is reset.');
  }

  /**
   * Model and provider are part of the key.
   */
  public function testModelAndProviderAreSeparateEntries(): void {
    $this->inQuery(fn() => $this->embed('bioregional food', 'gpt-test'));
    $this->inQuery(fn() => $this->embed('bioregional food', 'gpt-awesome'));
    $this->assertCount(2, $this->realCalls, 'A different model is a separate entry.');
    $this->assertNotFalse($this->bin()->get(self::cid('bioregional food', self::PROVIDER, 'gpt-awesome')));

    // Another provider id, same model and text: a miss, then its own entry.
    $query = $this->query();
    $this->openQuery($query);
    $pre = new PreGenerateResponseEvent(
      requestThreadId: 'other-thread',
      providerId: 'otherai',
      operationType: 'embeddings',
      configuration: [],
      input: new EmbeddingsInput('bioregional food'),
      modelId: self::MODEL,
      tags: ['embeddings', 'ai_search'],
    );
    $this->container->get('event_dispatcher')->dispatch($pre, PreGenerateResponseEvent::EVENT_NAME);
    $this->assertNull($pre->getForcedOutputObject(), 'A different provider is a miss.');
    $post = new PostGenerateResponseEvent(
      requestThreadId: 'other-thread',
      providerId: 'otherai',
      operationType: 'embeddings',
      configuration: [],
      input: new EmbeddingsInput('bioregional food'),
      modelId: self::MODEL,
      output: new EmbeddingsOutput([0.25, 0.5], [], []),
      tags: ['embeddings', 'ai_search'],
    );
    $this->container->get('event_dispatcher')->dispatch($post, PostGenerateResponseEvent::EVENT_NAME);
    $this->closeQuery($query);
    $item = $this->bin()->get(self::cid('bioregional food', 'otherai'));
    $this->assertNotFalse($item, 'The other provider gets its own entry.');
    $this->assertSame(['normalized' => [0.25, 0.5]], $item->data);
  }

  /**
   * Long inputs and chat calls are never cached.
   */
  public function testLongInputAndChatAreNotCached(): void {
    $long = str_repeat('a', 1001);
    $this->inQuery(fn() => $this->embed($long));
    $this->inQuery(fn() => $this->embed($long));
    $this->assertCount(2, $this->realCalls, 'A 1,001-character input: two real calls.');
    $this->assertFalse($this->bin()->get(self::cid($long)));

    $exact = str_repeat('a', 1000);
    $this->inQuery(fn() => $this->embed($exact));
    $this->inQuery(fn() => $this->embed($exact));
    $this->assertCount(3, $this->realCalls, 'A 1,000-character input is cached.');

    $chats = 0;
    $this->container->get('event_dispatcher')->addListener(
      PostGenerateResponseEvent::EVENT_NAME,
      function (PostGenerateResponseEvent $event) use (&$chats): void {
        if ($event->getOperationType() === 'chat') {
          $chats++;
        }
      },
    );
    $provider = $this->container->get('ai.provider')->createInstance(self::PROVIDER);
    $this->inQuery(fn() => $provider->chat(new ChatInput([new ChatMessage('user', 'bioregional food')]), self::MODEL, ['ai_search']));
    $this->inQuery(fn() => $provider->chat(new ChatInput([new ChatMessage('user', 'bioregional food')]), self::MODEL, ['ai_search']));
    $this->assertSame(2, $chats, 'Chat calls are never cached.');
  }

  /**
   * RelatedContentService's query text hits the cache across node saves (D-27c).
   */
  public function testRelatedContentTextHitsCache(): void {
    $node = Node::create([
      'type' => 'topic',
      'title' => 'Fictional seed swap',
      'body' => ['value' => '<p>Fictional neighbours trade heirloom bean seeds every spring.</p>', 'format' => 'plain_text'],
    ]);
    $text = $this->relatedText($node);

    $this->inQuery(fn() => $this->embed($text));
    // Any node save clears the related-content render cache through node_list.
    Cache::invalidateTags(['node_list']);
    $this->inQuery(fn() => $this->embed($this->relatedText($node)));
    $this->assertCount(1, $this->realCalls, 'An unchanged node: one real call across a node_list invalidation.');

    $node->set('body', ['value' => '<p>Fictional neighbours trade squash seeds in autumn.</p>', 'format' => 'plain_text']);
    $this->inQuery(fn() => $this->embed($this->relatedText($node)));
    $this->assertCount(2, $this->realCalls, 'A changed body is a second call.');
  }

  /**
   * RelatedContentService::extractQueryText(): label plus 500 body bytes.
   */
  private function relatedText(Node $node): string {
    $method = new \ReflectionMethod($this->container->get('social_ai_indexing.related_content'), 'extractQueryText');
    return $method->invoke($this->container->get('social_ai_indexing.related_content'), $node);
  }

  /**
   * An output forced by a higher-priority listener is neither read nor written.
   */
  public function testForcedOutputIsLeftAlone(): void {
    // Seed the cache with a real call first.
    $this->inQuery(fn() => $this->embed('bioregional food'));
    $this->assertCount(1, $this->realCalls);

    $forced = new EmbeddingsOutput(['forced' => TRUE], [], []);
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      function (PreGenerateResponseEvent $event) use ($forced): void {
        if ($event->getOperationType() === 'embeddings') {
          $event->setForcedOutputObject($forced);
        }
      },
      200,
    );
    $output = $this->inQuery(fn() => $this->embed('bioregional food'));
    $this->assertSame(['forced' => TRUE], $output, 'The earlier forced output wins over the cache.');
    $this->inQuery(fn() => $this->embed('fictional orchard'));
    $this->assertFalse($this->bin()->get(self::cid('fictional orchard')), 'Nothing is written for a forced output.');
    $this->assertCount(1, $this->realCalls);
  }

  /**
   * The stored item: own bin, seven-day expiry, the vector only.
   */
  public function testStoredItem(): void {
    $normalized = $this->inQuery(fn() => $this->embed('bioregional food'));
    $item = $this->bin()->get(self::cid('bioregional food'));
    $this->assertNotFalse($item, 'Stored in the ai_embeddings bin.');
    $this->assertSame($this->container->get('datetime.time')->getRequestTime() + self::TTL, (int) $item->expire, 'Expires in seven days.');
    $this->assertSame(['normalized' => $normalized], $item->data, 'The data is the normalized array only.');
    $this->assertSame([], $item->tags, 'No cache tags: the entry is user-independent.');

    // A hit is marked in the forced output's metadata.
    $output = $this->inQuery(fn() => $this->container->get('ai.provider')
      ->createInstance(self::PROVIDER)
      ->embeddings('bioregional food', self::MODEL, ['ai_search']));
    $this->assertSame(['social_ai_indexing_cache' => 'hit'], $output->getMetadata());
  }

}
