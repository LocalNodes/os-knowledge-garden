<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\node\NodeInterface;

/**
 * S5 "N of M searchable" (UI-SPEC Part 8 S5; D-12, D-22).
 *
 * Read from the durable item rows joined to Search API's tracker for the
 * assistant's index, social_posts (RESEARCH Pattern 9, Open Question 3), on
 * item_id = the row's search_item_id ('entity:node/{nid}:{langcode}').
 *
 * - M (total): every imported page in the scope, that is the rows that made
 *   a Topic (imported, imported but not searchable). Duplicate, failed and
 *   refreshed rows name a Topic another row already counts, so they don't
 *   count. A deleted Topic's row becomes "deleted" and leaves M
 *   (localnodes_import_node_delete()).
 * - N (searchable): imported rows whose tracker status is 0 (indexed).
 * - No text: imported-but-not-searchable rows. Search never finds them,
 *   whatever the tracker says.
 * - Waiting: the rest of M, that is imported rows the tracker hasn't
 *   indexed (status 1) or doesn't track at all. A page with no tracker row
 *   isn't in search, so it is never shown as searchable.
 * - Stale: a waiting page has waited more than an hour, counted from its
 *   tracker row's change (a refresh marks it changed again) or, without a
 *   row, from the item's own change.
 *
 * Only Topics that are in the group now, and that the viewer may see, count
 * or are listed (merge review R1 item 1). An item remembers the group it
 * imported into, but its Topic may since have moved to another group (a
 * secret one included) and been renamed there.
 */
class SearchStatus {

  use StringTranslationTrait;

  /**
   * The assistant's index (social_ai_indexing), whose tracker S5 reads.
   */
  public const INDEX = 'social_posts';

  /**
   * Seconds a page may wait before the stale-worker alert shows.
   */
  public const STALE_AFTER = 3600;

  /**
   * Rows per page in the S5 lists (D-22).
   */
  public const PAGE_SIZE = 50;

  /**
   * Pager element of "See what's still waiting".
   */
  public const ELEMENT_WAITING = 1;

  /**
   * Pager element of "See pages that can't be searched".
   */
  public const ELEMENT_NO_TEXT = 2;

  /**
   * Search API's tracker status of an indexed item.
   *
   * @see \Drupal\search_api\Tracker\TrackerPluginBase::STATUS_INDEXED
   */
  protected const TRACKER_INDEXED = 0;

  /**
   * Topics checked for view access per batch, to bound memory.
   */
  protected const ACCESS_BATCH = 100;

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * The numbers for every imported page of a group.
   *
   * @return array{total: int, searchable: int, waiting: int, no_text: int, stale: bool, updated: int}
   *   M, N, waiting, no readable text, whether any page waited an hour, and
   *   the time the numbers were read.
   */
  public function forGroup(int $gid, ?AccountInterface $account = NULL): array {
    return $this->counts('gid', $gid, $account ?? $this->currentUser);
  }

  /**
   * The numbers for the pages of one import run.
   *
   * @return array{total: int, searchable: int, waiting: int, no_text: int, stale: bool, updated: int}
   *   As forGroup(), for the run's items only.
   */
  public function forRun(int $run_id, ?AccountInterface $account = NULL): array {
    return $this->counts('run', $run_id, $account ?? $this->currentUser);
  }

  /**
   * One page of the group's waiting pages, in submitted order.
   *
   * @param int $gid
   *   The group.
   * @param int $element
   *   The pager element, distinct from the page's other pagers.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The viewer; the current user when NULL.
   *
   * @return array<int, array{id: int, title: string, url: string|null, reason: \Drupal\Core\StringTranslation\TranslatableMarkup|null}>
   *   Up to PAGE_SIZE rows: the page title (or the source name when the
   *   Topic is gone), its address, and no reason.
   */
  public function waitingList(int $gid, int $element = self::ELEMENT_WAITING, ?AccountInterface $account = NULL): array {
    $query = $this->base('gid', $gid, $account ?? $this->currentUser);
    $query->condition('i.outcome', ImportItem::OUTCOME_IMPORTED);
    $this->notIndexed($query);
    return $this->page($query, $element, FALSE);
  }

  /**
   * One page of the group's pages with no readable text, in submitted order.
   *
   * @param int $gid
   *   The group.
   * @param int $element
   *   The pager element, distinct from the page's other pagers.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The viewer; the current user when NULL.
   *
   * @return array<int, array{id: int, title: string, url: string|null, reason: \Drupal\Core\StringTranslation\TranslatableMarkup|null}>
   *   Up to PAGE_SIZE rows, each with the copy-table reason.
   */
  public function noTextList(int $gid, int $element = self::ELEMENT_NO_TEXT, ?AccountInterface $account = NULL): array {
    $query = $this->base('gid', $gid, $account ?? $this->currentUser);
    $query->condition('i.outcome', ImportItem::OUTCOME_NOT_SEARCHABLE);
    return $this->page($query, $element, TRUE);
  }

  /**
   * Why a page with no readable text can't be searched (copy table).
   *
   * Shared with the S4 report's "What you can do" cell.
   */
  public function noTextReason(?string $reason): TranslatableMarkup {
    return $reason === 'scanned_pdf'
      ? $this->t("This may be an image-only scan. Members can open it, but we couldn't read enough text for search and the AI assistant. Add a copy whose text you can select and copy.")
      : $this->t("We couldn't read enough text in this file. Members can open it, but search and the AI assistant may not find it. Add a copy whose text you can select and copy.");
  }

  /**
   * Counts the imported pages of a scope.
   *
   * @param string $field
   *   'gid' or 'run'.
   * @param int $value
   *   The group or run id.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The viewer.
   */
  protected function counts(string $field, int $value, AccountInterface $account): array {
    $query = $this->base($field, $value, $account);
    $query->addExpression('COUNT(i.id)', 'total');
    $query->addExpression('SUM(CASE WHEN i.outcome = :ln_ns THEN 1 ELSE 0 END)', 'no_text', [
      ':ln_ns' => ImportItem::OUTCOME_NOT_SEARCHABLE,
    ]);
    $query->addExpression('SUM(CASE WHEN i.outcome = :ln_imp AND s.status = :ln_idx THEN 1 ELSE 0 END)', 'searchable', [
      ':ln_imp' => ImportItem::OUTCOME_IMPORTED,
      ':ln_idx' => self::TRACKER_INDEXED,
    ]);
    // The oldest wait among the waiting pages.
    $query->addExpression('MIN(CASE WHEN i.outcome = :ln_wimp AND (s.status IS NULL OR s.status <> :ln_widx) THEN COALESCE(s.changed, i.changed) ELSE NULL END)', 'oldest', [
      ':ln_wimp' => ImportItem::OUTCOME_IMPORTED,
      ':ln_widx' => self::TRACKER_INDEXED,
    ]);
    $row = $query->execute()->fetchAssoc() ?: [];

    $total = (int) ($row['total'] ?? 0);
    $no_text = (int) ($row['no_text'] ?? 0);
    $searchable = (int) ($row['searchable'] ?? 0);
    $now = (int) $this->time->getRequestTime();
    $oldest = isset($row['oldest']) ? (int) $row['oldest'] : NULL;
    return [
      'total' => $total,
      'searchable' => $searchable,
      'waiting' => max(0, $total - $no_text - $searchable),
      'no_text' => $no_text,
      'stale' => $oldest !== NULL && $now - $oldest > self::STALE_AFTER,
      'updated' => $now,
    ];
  }

  /**
   * The imported rows of a scope, joined to their social_posts tracker row.
   *
   * Only rows whose Topic is in the item's group now and that the viewer may
   * see (visibleNids()).
   */
  protected function base(string $field, int $value, AccountInterface $account): SelectInterface {
    $query = $this->database->select('localnodes_import_item', 'i');
    $query->leftJoin('search_api_item', 's', 's.index_id = :ln_index AND s.item_id = i.search_item_id', [
      ':ln_index' => self::INDEX,
    ]);
    $query->condition('i.' . $field, $value);
    $query->condition('i.outcome', [ImportItem::OUTCOME_IMPORTED, ImportItem::OUTCOME_NOT_SEARCHABLE], 'IN');
    $nids = $this->visibleNids($field, $value, $account);
    if ($nids === []) {
      // Nothing to count or list; the aggregates still return one row.
      $query->where('1 = 0');
    }
    else {
      $query->condition('i.nid', $nids, 'IN');
    }
    return $query;
  }

  /**
   * The scope's imported Topics in their item's group now, viewable.
   *
   * Group membership is a live group_node:topic relationship between the
   * Topic and the item's group (the group that asks, for a group scope; the
   * run's group, for a run). View access is the Topic's own access check for
   * the viewer, the same one its page uses; Group's node access isn't in the
   * node_access query tag, so it can't be done in SQL.
   *
   * @return int[]
   *   Node ids.
   */
  protected function visibleNids(string $field, int $value, AccountInterface $account): array {
    $relationships = $this->entityTypeManager->getDefinition('group_content')->getDataTable();
    $query = $this->database->select('localnodes_import_item', 'i');
    $query->innerJoin($relationships, 'r', "r.entity_id = i.nid AND r.gid = i.gid AND r.plugin_id = :ln_plugin", [
      ':ln_plugin' => 'group_node:topic',
    ]);
    $query->condition('i.' . $field, $value);
    $query->condition('i.outcome', [ImportItem::OUTCOME_IMPORTED, ImportItem::OUTCOME_NOT_SEARCHABLE], 'IN');
    $query->addField('i', 'nid');
    $query->distinct();
    $candidates = array_map('intval', $query->execute()->fetchCol());

    $storage = $this->entityTypeManager->getStorage('node');
    $visible = [];
    foreach (array_chunk($candidates, self::ACCESS_BATCH) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        if ($node->access('view', $account)) {
          $visible[] = (int) $node->id();
        }
      }
      if (count($candidates) > self::ACCESS_BATCH) {
        // A big group: don't keep every Topic in memory.
        $storage->resetCache($chunk);
      }
    }
    return $visible;
  }

  /**
   * Keeps the rows search hasn't indexed (waiting or untracked).
   */
  protected function notIndexed(SelectInterface $query): void {
    $or = $query->orConditionGroup()
      ->isNull('s.status')
      ->condition('s.status', self::TRACKER_INDEXED, '<>');
    $query->condition($or);
  }

  /**
   * One pager page of rows, with titles, links and (optionally) reasons.
   */
  protected function page(SelectInterface $query, int $element, bool $with_reason): array {
    $query->fields('i', ['id', 'nid', 'source_name', 'reason']);
    $query->orderBy('i.run');
    $query->orderBy('i.weight');
    $query->orderBy('i.id');
    /** @var \Drupal\Core\Database\Query\PagerSelectExtender $paged */
    $paged = $query->extend(PagerSelectExtender::class);
    $paged->element($element)->limit(self::PAGE_SIZE);
    $rows = $paged->execute()->fetchAll();

    $nids = array_filter(array_map(static fn($row) => $row->nid === NULL ? NULL : (int) $row->nid, $rows));
    $nodes = $nids ? $this->entityTypeManager->getStorage('node')->loadMultiple($nids) : [];

    $list = [];
    foreach ($rows as $row) {
      $node = $row->nid !== NULL ? ($nodes[(int) $row->nid] ?? NULL) : NULL;
      $list[] = [
        'id' => (int) $row->id,
        'title' => $node instanceof NodeInterface ? (string) $node->label() : (string) $row->source_name,
        'url' => $node instanceof NodeInterface ? $node->toUrl()->toString() : NULL,
        'reason' => $with_reason ? $this->noTextReason($row->reason) : NULL,
      ];
    }
    return $list;
  }

}
