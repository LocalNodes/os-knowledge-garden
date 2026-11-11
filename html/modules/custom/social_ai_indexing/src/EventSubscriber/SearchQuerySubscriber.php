<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\Query\QueryInterface;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
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
   * The AI assistant API runner.
   *
   * @var \Drupal\ai_assistant_api\AiAssistantApiRunner
   */
  protected AiAssistantApiRunner $aiAssistantRunner;

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
   * @param \Drupal\ai_assistant_api\AiAssistantApiRunner $ai_assistant_runner
   *   The AI assistant API runner.
   * @param \Drupal\social_ai_indexing\Citation\SourceRegistry|null $sourceRegistry
   *   The assistant run's registry, told when an index lacks a field.
   */
  public function __construct(
    PermissionFilterService $permission_filter,
    AccountInterface $current_user,
    AiAssistantApiRunner $ai_assistant_runner,
    protected ?SourceRegistry $sourceRegistry = NULL,
  ) {
    $this->permissionFilter = $permission_filter;
    $this->currentUser = $current_user;
    $this->aiAssistantRunner = $ai_assistant_runner;
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
   * Index fields every AI search query filters on.
   *
   * QdrantProvider drops a condition on a field the index lacks, silently
   * (QdrantProvider::convertConditionToQdrant()), so a missing field would
   * turn a permission filter into no filter at all (F-2).
   */
  protected const REQUIRED_FIELDS = [
    'group_id',
    'field_content_visibility',
  ];

  /**
   * Reacts to the query pre-execute event.
   *
   * Applies permission filters to AI search queries before they are
   * executed, and fails closed (D-18): a missing permission field, an
   * unusable scope hint, an unviewable scope group or any error while
   * filtering aborts the query, so it returns nothing.
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

    foreach (self::REQUIRED_FIELDS as $field) {
      if ($index->getField($field) === NULL) {
        // A broken index, the same for every group: the assistant may say
        // the search didn't work. Only here, not in failClosed(), which
        // also refuses scopes; those must read like an empty search.
        $this->sourceRegistry?->markSearchTrouble();
        $this->failClosed($query, 'missing_' . $field);
        return;
      }
    }

    try {
      // The scope group is a hint only: it comes from the client (DeepChat
      // contexts.group_id) or a query option. PermissionFilterService works
      // out membership on the server and aborts on a group the asker cannot
      // view. A hint that is present but not a positive integer aborts
      // here, so a forged value never turns into a site-wide search
      // (D-17, D-18).
      $hint = $this->getScopeHint($query);
      if ($hint !== NULL && !$this->isValidGroupId($hint)) {
        $this->failClosed($query, 'invalid_scope_hint');
        return;
      }

      $this->permissionFilter->applyPermissionFilters(
        $query,
        $this->currentUser,
        $hint === NULL ? NULL : (int) $hint
      );
    }
    catch (\Throwable $e) {
      // Never log the exception message: it can carry content (T-22-24).
      $this->failClosed($query, 'filter_error');
      return;
    }

    if ($query->wasAborted()) {
      // PermissionFilterService aborted: the scope group is missing or the
      // asker cannot view it.
      $this->logAbort($index->id(), 'scope_group_unavailable');
    }
  }

  /**
   * Aborts the query and logs a permission_abort record.
   */
  protected function failClosed(QueryInterface $query, string $reason): void {
    $query->abort('Permission filter failed');
    $this->logAbort($query->getIndex()->id(), $reason);
  }

  /**
   * Logs a permission_abort record: the index id and a reason code only.
   */
  protected function logAbort(string $index_id, string $reason): void {
    \Drupal::logger('social_ai_indexing')->warning('permission_abort index=@index reason=@reason', [
      '@index' => $index_id,
      '@reason' => $reason,
    ]);
  }

  /**
   * The scope hint: the query option first, then the runner context.
   *
   * @return mixed
   *   The raw hint, or NULL when none is present. An empty string counts as
   *   absent, like NULL; any other value is present and must be valid.
   */
  protected function getScopeHint(QueryInterface $query): mixed {
    $hint = $query->getOption('ai_search_scope_group_id');
    if ($hint === NULL || $hint === '') {
      $context = $this->aiAssistantRunner->getContext();
      $hint = is_array($context) ? ($context['group_id'] ?? NULL) : NULL;
    }
    return ($hint === NULL || $hint === '') ? NULL : $hint;
  }

  /**
   * Whether a hint is a positive integer (int or digit string).
   */
  protected function isValidGroupId(mixed $hint): bool {
    if (is_int($hint)) {
      return $hint > 0;
    }
    return is_string($hint) && preg_match('/^[1-9][0-9]{0,17}$/', $hint) === 1;
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
