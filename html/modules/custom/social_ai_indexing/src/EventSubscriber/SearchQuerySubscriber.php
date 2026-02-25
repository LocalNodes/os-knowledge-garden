<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\social_ai_indexing\Service\PermissionFilterService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber to inject permission filters into Search API queries.
 *
 * This subscriber intercepts Search API queries before execution and applies
 * permission filters to ensure only authorized content is retrieved from
 * AI search indexes (social_posts, social_comments).
 */
class SearchQuerySubscriber implements EventSubscriberInterface {

  /**
   * The permission filter service.
   *
   * @var \Drupal\social_ai_indexing\Service\PermissionFilterService
   */
  protected $permissionFilter;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * AI search index IDs that require permission filtering.
   *
   * @var array
   */
  protected const AI_SEARCH_INDEXES = [
    'social_posts',
    'social_comments',
  ];

  /**
   * Constructs a SearchQuerySubscriber.
   *
   * @param \Drupal\social_ai_indexing\Service\PermissionFilterService $permission_filter
   *   The permission filter service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user account.
   */
  public function __construct(
    PermissionFilterService $permission_filter,
    AccountInterface $current_user
  ) {
    $this->permissionFilter = $permission_filter;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::QUERY_PRE_EXECUTE => ['onQueryPreExecute', 100],
    ];
  }

  /**
   * Reacts to the query pre-execute event.
   *
   * Applies permission filters to AI search queries before they are executed.
   *
   * @param \Drupal\search_api\Event\QueryPreExecuteEvent $event
   *   The query pre-execute event.
   */
  public function onQueryPreExecute(QueryPreExecuteEvent $event): void {
    $query = $event->getQuery();
    $index = $query->getIndex();

    // Only filter AI search indexes (not regular Search API queries).
    if (!$this->isAiSearchIndex($index->id())) {
      return;
    }

    // Get optional scope group ID from query options.
    $scopeGroupId = $query->getOption('ai_search_scope_group_id');

    try {
      $this->permissionFilter->applyPermissionFilters(
        $query,
        $this->currentUser,
        $scopeGroupId !== NULL ? (int) $scopeGroupId : NULL
      );
    }
    catch (\Exception $e) {
      // Log warning but don't break search.
      \Drupal::logger('social_ai_indexing')->warning(
        'Failed to apply permission filters to query on index @index: @message',
        [
          '@index' => $index->id(),
          '@message' => $e->getMessage(),
        ]
      );
    }
  }

  /**
   * Check if the index is an AI search index.
   *
   * @param string $index_id
   *   The index ID to check.
   *
   * @return bool
   *   TRUE if this is an AI search index, FALSE otherwise.
   */
  protected function isAiSearchIndex(string $index_id): bool {
    return in_array($index_id, self::AI_SEARCH_INDEXES, TRUE);
  }

}
