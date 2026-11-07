<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\SearchApiException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pauses index runs at the AI token ceiling instead of failing them.
 *
 * D-27 (Tom, 2026-11-05; checker W-1). drupal/ai_usage_limits refuses every
 * call to a provider at its ceiling, embeddings included, by throwing
 * AiTokenUsageException from its PreGenerateResponseEvent listener
 * (priority 0). search_api's cron path (Index::indexItems()) and its
 * post-request path (PostRequestIndexing::destruct(), run at kernel
 * terminate after a member's save) catch only SearchApiException, so that
 * exception would escape both.
 *
 * Two checks, both throwing a SearchApiException, which search_api logs
 * while leaving the items tracked, so indexing resumes once
 * ai_usage_limits_cron() resets the window:
 * - At INDEXING_ITEMS, a run on a search_api_ai_search server whose
 *   embeddings provider is at its ceiling stops before the backend runs.
 *   The VDB providers delete an item's old vectors before embedding it, so
 *   a run stopped later would leave edited content out of vector search
 *   until the reset.
 * - During a run, an embeddings call to a provider at its ceiling (a run
 *   that crossed it) is stopped first (priority 50). Calls outside an index run are
 * left to ai_usage_limits; their callers catch its exception (hybrid
 * search's vector leg, related content, the RAG tool, the overview).
 *
 * The ceiling test is ai_usage_limits' own (CheckTokenUsageSubscriber): a
 * counter with usage whose limit is missing or lower.
 */
final class AiCeilingIndexingSubscriber implements EventSubscriberInterface {

  /**
   * The counters ai_usage_limits checks.
   */
  private const COUNTERS = [
    'input_token_usage',
    'output_token_usage',
    'total_token_usage',
    'cached_token_usage',
    'reasoning_token_usage',
  ];

  /**
   * The exception message search_api logs (no content).
   */
  public const MESSAGE = 'AI token ceiling reached; indexing paused until the usage window resets';

  /**
   * Open search_api index runs in this request.
   */
  private int $indexingDepth = 0;

  /**
   * Constructs an AiCeilingIndexingSubscriber.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Before ai_usage_limits' CheckTokenUsageSubscriber (priority 0).
      PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', 50],
      SearchApiEvents::INDEXING_ITEMS => ['onIndexingItems', 0],
      SearchApiEvents::ITEMS_INDEXED => ['onItemsIndexed', 0],
      KernelEvents::TERMINATE => ['onTerminate', 0],
    ];
  }

  /**
   * An index run starts, unless its AI search server is at the ceiling.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function onIndexingItems(IndexingItemsEvent $event): void {
    $server = $event->getIndex()->hasValidServer() ? $event->getIndex()->getServerInstance() : NULL;
    if ($server !== NULL && $server->getBackendId() === 'search_api_ai_search') {
      $engine = (string) ($server->getBackendConfig()['embeddings_engine'] ?? '');
      $provider_id = explode('__', $engine, 2)[0];
      if ($provider_id !== '' && $this->atCeiling($provider_id)) {
        throw new SearchApiException(self::MESSAGE);
      }
    }
    $this->indexingDepth++;
  }

  /**
   * An index run finished.
   */
  public function onItemsIndexed(): void {
    $this->indexingDepth = max(0, $this->indexingDepth - 1);
  }

  /**
   * The request is over.
   */
  public function onTerminate(): void {
    $this->indexingDepth = 0;
  }

  /**
   * Turns the ceiling into a SearchApiException for index-run embeddings.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if ($this->indexingDepth === 0 || $event->getOperationType() !== 'embeddings') {
      return;
    }
    if (!$this->atCeiling($event->getProviderId())) {
      return;
    }
    // The run ends here: ITEMS_INDEXED won't fire for it, so close it now.
    // Otherwise later calls in the same request would count as indexing.
    $this->indexingDepth = 0;
    throw new SearchApiException(self::MESSAGE);
  }

  /**
   * Whether ai_usage_limits would refuse a call to this provider.
   */
  private function atCeiling(string $provider_id): bool {
    $usage = $this->state->get('ai_usage_limits', []);
    $provider_usage = is_array($usage) ? ($usage[$provider_id] ?? []) : [];
    $limits = $this->configFactory->get('ai_usage_limits.settings')->get('providers.' . $provider_id);
    foreach (self::COUNTERS as $counter) {
      if (empty($provider_usage[$counter])) {
        continue;
      }
      // A missing limit counts as below any usage, as in ai_usage_limits.
      $limit = is_array($limits) ? ($limits[$counter] ?? NULL) : NULL;
      if ($limit === NULL || (int) $limit < (int) $provider_usage[$counter]) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
