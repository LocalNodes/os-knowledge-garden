<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\Event\ProcessingResultsEvent;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Caches query-side embeddings, never index-time ones (D-27b, D-27c).
 *
 * Every search, every assistant search-tool call, the overview's retrieval
 * and RelatedContentBlock embed their query text through
 * SearchApiAiSearchBackend::search(). drupal/ai's ProviderProxy dispatches
 * PreGenerateResponseEvent before each provider call and returns a forced
 * output when one is set, and PostGenerateResponseEvent only after a real
 * call. So a hit is served on the pre event and an entry is stored on the
 * post event, from real calls only.
 *
 * Query and chunk embeddings both carry the tag 'ai_search', so tags can't
 * tell them apart. The cache acts only inside a search query context instead:
 * - search_api QUERY_PRE_EXECUTE on an index whose server runs the AI search
 *   backend opens it (depth +1), PROCESSING_RESULTS closes it (depth -1,
 *   never below 0);
 * - between INDEXING_ITEMS and ITEMS_INDEXED the cache is off, even inside a
 *   query context, so a members-only chunk's vector is never stored (no
 *   timing oracle on members-only text, W-2);
 * - kernel terminate resets both. An aborted query that never reaches
 *   PROCESSING_RESULTS keeps the context open only until then; an indexing
 *   run that threw keeps the cache off until then. Both fail safe enough:
 *   the indexing gate still holds, and only embeddings are ever cached.
 *
 * The key is provider + model + sha256 of the normalised text, and the value
 * is the vector only, so an entry carries nothing about who asked. Every
 * caller still applies the asker's permission filters to the results.
 */
final class EmbeddingCacheSubscriber implements EventSubscriberInterface {

  /**
   * Longest input, in characters after normalisation, that is cached.
   */
  public const MAX_CHARS = 1000;

  /**
   * Entry lifetime, in seconds: seven days.
   */
  public const TTL = 604800;

  /**
   * The search_api backend that embeds query text.
   */
  public const AI_SEARCH_BACKEND = 'search_api_ai_search';

  /**
   * Metadata on a forced output served from the cache.
   */
  public const HIT_METADATA = ['social_ai_indexing_cache' => 'hit'];

  /**
   * Most cache ids held between a pre event and its post event.
   */
  private const MAX_PENDING = 64;

  /**
   * Open AI-search query contexts in this request.
   */
  private int $queryDepth = 0;

  /**
   * Open indexing runs in this request.
   */
  private int $indexingDepth = 0;

  /**
   * Cache ids awaiting a real call's output, keyed by request thread id.
   *
   * @var array<string, string>
   */
  private array $pending = [];

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Early, so a hit skips other pre-call work; a listener above 100 that
      // already forced an output wins and the cache stays out of it.
      PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', 100],
      PostGenerateResponseEvent::EVENT_NAME => ['onPostGenerate', 0],
      // Last, so a query SearchQuerySubscriber (priority 100) aborted
      // opens no context: an aborted query never reaches PROCESSING_RESULTS.
      SearchApiEvents::QUERY_PRE_EXECUTE => ['onQueryPreExecute', -1000],
      SearchApiEvents::PROCESSING_RESULTS => ['onProcessingResults', 1000],
      // The indexing gate closes first and opens last.
      SearchApiEvents::INDEXING_ITEMS => ['onIndexingItems', 1000],
      SearchApiEvents::ITEMS_INDEXED => ['onItemsIndexed', -1000],
      KernelEvents::TERMINATE => ['onTerminate', 0],
    ];
  }

  /**
   * Opens a query context for a query on the AI search backend.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    if (!$query->wasAborted() && $this->isAiSearchQuery($query)) {
      $this->queryDepth++;
    }
  }

  /**
   * Closes the query context a query on the AI search backend opened.
   */
  public function onProcessingResults(ProcessingResultsEvent $event): void {
    if ($this->queryDepth > 0 && $this->isAiSearchQuery($event->getResults()->getQuery())) {
      $this->queryDepth--;
    }
  }

  /**
   * Turns the cache off while items are handed to a backend.
   */
  public function onIndexingItems(IndexingItemsEvent $event): void {
    $this->indexingDepth++;
  }

  /**
   * Turns the cache back on after an indexing run.
   */
  public function onItemsIndexed(ItemsIndexedEvent $event): void {
    if ($this->indexingDepth > 0) {
      $this->indexingDepth--;
    }
  }

  /**
   * Resets both depths at the end of the request.
   */
  public function onTerminate(): void {
    $this->queryDepth = 0;
    $this->indexingDepth = 0;
    $this->pending = [];
  }

  /**
   * Serves a cached vector, or remembers the key for the real call.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if ($event->getForcedOutputObject() !== NULL) {
      return;
    }
    $cid = $this->cacheId($event->getOperationType(), $event->getProviderId(), $event->getModelId(), $event->getInput());
    if ($cid === NULL) {
      return;
    }
    $item = $this->cache->get($cid);
    if ($item !== FALSE && is_array($item->data['normalized'] ?? NULL)) {
      $event->setForcedOutputObject(new EmbeddingsOutput($item->data['normalized'], [], self::HIT_METADATA));
      return;
    }
    // A later listener that forces an output leaves its entry behind (no
    // post event). Bounded, for long-lived processes with no terminate.
    if (count($this->pending) >= self::MAX_PENDING) {
      $this->pending = [];
    }
    $this->pending[$event->getRequestThreadId()] = $cid;
  }

  /**
   * Stores the vector of a real call the pre event found eligible.
   */
  public function onPostGenerate(PostGenerateResponseEvent $event): void {
    $thread = $event->getRequestThreadId();
    if (!isset($this->pending[$thread])) {
      return;
    }
    $cid = $this->pending[$thread];
    unset($this->pending[$thread]);
    if (!$this->isActive()) {
      return;
    }
    $output = $event->getOutput();
    if (!$output instanceof EmbeddingsOutput) {
      return;
    }
    // Stored as the provider returned it: no packing or casting.
    $normalized = $output->getNormalized();
    if ($normalized === []) {
      return;
    }
    $this->cache->set($cid, ['normalized' => $normalized], $this->time->getRequestTime() + self::TTL);
  }

  /**
   * Whether the cache may read or write now.
   */
  private function isActive(): bool {
    return $this->queryDepth > 0 && $this->indexingDepth === 0;
  }

  /**
   * The cache id for an eligible embeddings call, or NULL.
   */
  private function cacheId(string $operation, string $provider, string $model, mixed $input): ?string {
    if ($operation !== 'embeddings' || !$this->isActive()) {
      return NULL;
    }
    if ($input instanceof EmbeddingsInput) {
      // An image embedding has no text key.
      if ($input->getImage() !== NULL) {
        return NULL;
      }
      $text = $input->getPrompt();
    }
    elseif (is_string($input)) {
      $text = $input;
    }
    else {
      return NULL;
    }
    $text = self::normalise($text);
    if ($text === NULL || $text === '' || mb_strlen($text) > self::MAX_CHARS) {
      return NULL;
    }
    return 'emb:' . $provider . ':' . $model . ':' . hash('sha256', $text);
  }

  /**
   * Unicode NFC, trimmed, inner whitespace collapsed; case kept.
   *
   * Embedding models are case-sensitive, so lowercasing would change the
   * vector. NULL for text that isn't valid UTF-8.
   */
  public static function normalise(string $text): ?string {
    if (class_exists(\Normalizer::class)) {
      $nfc = \Normalizer::normalize($text, \Normalizer::FORM_C);
      if ($nfc === FALSE) {
        return NULL;
      }
      $text = $nfc;
    }
    $text = preg_replace('/\s+/u', ' ', $text);
    return $text === NULL ? NULL : trim($text, ' ');
  }

  /**
   * Whether a query runs on an index served by the AI search backend.
   */
  private function isAiSearchQuery(QueryInterface $query): bool {
    try {
      $index = $query->getIndex();
      return $index->hasValidServer()
        && $index->getServerInstance()->getBackendId() === self::AI_SEARCH_BACKEND;
    }
    catch (\Throwable) {
      // No server, or a broken one: not an AI query; the cache stays off.
      return FALSE;
    }
  }

}
