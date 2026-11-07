<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_import\Entity\ImportItem;

/**
 * Finds a Topic already in a group from the same bytes or address (D-15).
 *
 * RESEARCH Pattern 8, file half: a byte-identical file (same sha256) that
 * this group already imported is not imported again. The same file in
 * another group is not a duplicate. Callers hold the group-and-hash lock
 * around the lookup and the Topic creation (TopicFactory).
 *
 * Every candidate must be in the group now (merge review R1 item 4). An
 * import item remembers the group it imported into, but its Topic may since
 * have moved to another group; then it is no longer "already here", and the
 * group gets a new Topic instead of a report that points elsewhere.
 *
 * Every candidate must also be a Topic the importer may view (merge review
 * R2 item 2). "Already here" about a Topic they can't see (another
 * organizer's unpublished page, say) would tell them it exists; instead the
 * importer gets a Topic of their own and keeps their upload.
 */
class DuplicateFinder {

  /**
   * Item outcomes that mean "this group has a Topic from these bytes".
   */
  public const MATCHING_OUTCOMES = [
    ImportItem::OUTCOME_IMPORTED,
    ImportItem::OUTCOME_NOT_SEARCHABLE,
    ImportItem::OUTCOME_REFRESHED,
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The nid of a live Topic imported into the group from this sha256.
   *
   * @param int $gid
   *   The group id.
   * @param string $sha256
   *   The file's sha256, lower-case hex.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The importer, who must be able to view the Topic.
   *
   * @return int|null
   *   The earliest such Topic that is still in the group and the importer
   *   may view, or NULL.
   */
  public function findFileDuplicate(int $gid, string $sha256, AccountInterface $account): ?int {
    if ($gid <= 0 || $sha256 === '') {
      return NULL;
    }
    $item_storage = $this->entityTypeManager->getStorage('localnodes_import_item');
    $ids = $item_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $gid)
      ->condition('content_hash', $sha256)
      ->condition('outcome', self::MATCHING_OUTCOMES, 'IN')
      ->exists('nid')
      ->sort('id')
      ->execute();
    if (!$ids) {
      return NULL;
    }

    $nids = [];
    foreach ($item_storage->loadMultiple($ids) as $item) {
      if ($nid = $item->get('nid')->target_id) {
        $nids[] = (int) $nid;
      }
    }
    return $this->firstInGroup($gid, $nids, $account);
  }

  /**
   * The nid of a live Topic in the group from this canonical address.
   *
   * RESEARCH Pattern 8, web half: Topics in group G whose field_source_url
   * is the address (through G's group_node:topic relationships), plus Topics
   * this group's import items recorded under that canonical_url. The same
   * page in another group is not a duplicate. Callers hold the
   * group-and-address lock around the lookup and the Topic creation.
   *
   * @param int $gid
   *   The group id.
   * @param string $url
   *   The canonical address (MediaWikiClient::canonicalUrl() or
   *   WebPageExtractor::canonicalUrl()).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The importer, who must be able to view the Topic.
   *
   * @return int|null
   *   The earliest such Topic that is still in the group and the importer
   *   may view, or NULL.
   */
  public function findByCanonicalUrl(int $gid, string $url, AccountInterface $account): ?int {
    if ($gid <= 0 || $url === '') {
      return NULL;
    }
    $nids = [];
    $topic_ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'topic')
      ->condition('field_source_url.uri', $url)
      ->execute();
    if ($topic_ids) {
      $relationship_storage = $this->entityTypeManager->getStorage('group_content');
      $relationship_ids = $relationship_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('gid', $gid)
        ->condition('plugin_id', 'group_node:topic')
        ->condition('entity_id', array_values($topic_ids), 'IN')
        ->execute();
      foreach ($relationship_storage->loadMultiple($relationship_ids) as $relationship) {
        $nids[] = (int) $relationship->get('entity_id')->target_id;
      }
    }

    $item_storage = $this->entityTypeManager->getStorage('localnodes_import_item');
    $item_ids = $item_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $gid)
      ->condition('canonical_url', $url)
      ->condition('outcome', self::MATCHING_OUTCOMES, 'IN')
      ->exists('nid')
      ->execute();
    foreach ($item_storage->loadMultiple($item_ids) as $item) {
      if ($nid = $item->get('nid')->target_id) {
        $nids[] = (int) $nid;
      }
    }
    $nids = array_values(array_unique($nids));
    sort($nids);
    return $this->firstInGroup($gid, $nids, $account);
  }

  /**
   * The first nid in the list whose Topic is in the group and viewable.
   *
   * A Topic that moved to another group is no longer "already here"; nor is
   * a deleted one, nor one the importer can't view.
   *
   * @param int $gid
   *   The group id.
   * @param int[] $nids
   *   Candidate node ids, in preference order.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The importer.
   */
  protected function firstInGroup(int $gid, array $nids, AccountInterface $account): ?int {
    if ($nids === []) {
      return NULL;
    }
    $relationship_storage = $this->entityTypeManager->getStorage('group_content');
    $relationship_ids = $relationship_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $gid)
      ->condition('plugin_id', 'group_node:topic')
      ->condition('entity_id', array_values(array_unique($nids)), 'IN')
      ->execute();
    $in_group = [];
    foreach ($relationship_storage->loadMultiple($relationship_ids) as $relationship) {
      $in_group[(int) $relationship->get('entity_id')->target_id] = TRUE;
    }
    $candidates = array_values(array_filter($nids, static fn (int $nid): bool => isset($in_group[$nid])));
    if ($candidates === []) {
      return NULL;
    }
    $live = $this->entityTypeManager->getStorage('node')->loadMultiple(array_unique($candidates));
    foreach ($candidates as $nid) {
      if (isset($live[$nid]) && $live[$nid]->access('view', $account)) {
        return $nid;
      }
    }
    return NULL;
  }

}
