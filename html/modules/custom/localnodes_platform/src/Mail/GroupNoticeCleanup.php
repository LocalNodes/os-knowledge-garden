<?php

namespace Drupal\localnodes_platform\Mail;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\message\MessageInterface;
use Drupal\user\UserInterface;

/**
 * Checks current recipients and removes their ineligible stored notices.
 */
final class GroupNoticeCleanup {

  /** @var array<int, array<int, int>> Current request departure snapshots. */
  private array $departedGroupCache = [];

  public const DROP = 0;
  public const FULL = 1;
  public const GENERIC_REPORT = 2;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyValueStoreInterface $departures,
    private readonly TimeInterface $time,
    private readonly ?CacheTagsInvalidatorInterface $cacheTags = NULL,
  ) {}

  /**
   * Drops stored digest rows and records a departure until membership returns.
   */
  public function remove(int $uid, int $gid): void {
    if ($uid <= 0 || $gid <= 0) {
      return;
    }
    // Producers may not have created their activity when membership is removed.
    if (!$this->hasDeparture($uid, $gid)) {
      $this->departures->set($uid . ':' . $gid, $this->time->getCurrentTime());
      // What they owned in this house as they left. A page handed to them
      // afterwards never joins this list.
      $this->departures->set('authored:' . $uid . ':' . $gid, $this->ownedInHouse($uid, $gid));
    }
    $this->invalidateDeparture($uid, $gid);
    if (!$this->entityTypeManager->hasDefinition('activity')) {
      return;
    }
    $schema = $this->database->schema();
    if ($schema->tableExists('user_activity_digest')) {
      $ids = $this->database->select('user_activity_digest', 'd')->fields('d', ['activity'])->condition('uid', $uid)->execute()->fetchCol();
      $remove = array_filter($ids, fn($id) => $this->belongsToGroup((int) $id, $gid, $uid));
      if ($remove) {
        $this->database->delete('user_activity_digest')->condition('uid', $uid)->condition('activity', $remove, 'IN')->execute();
      }
    }
    if ($schema->tableExists('activity_notification_status')) {
      $ids = $this->database->select('activity_notification_status', 'n')->fields('n', ['aid'])->condition('uid', $uid)->execute()->fetchCol();
      $remove = array_filter($ids, fn($id) => $this->belongsToGroup((int) $id, $gid, $uid));
      if ($remove) {
        $this->database->delete('activity_notification_status')->condition('uid', $uid)->condition('aid', $remove, 'IN')->execute();
      }
    }
  }

  /**
   * New membership restores normal mail, including notices still queued.
   */
  public function clearDeparture(int $uid, int $gid): void {
    $this->departures->delete($uid . ':' . $gid);
    $this->departures->delete('read_closed:' . $uid . ':' . $gid);
    $this->departures->delete('removed:' . $uid . ':' . $gid);
    $this->departures->delete('authored:' . $uid . ':' . $gid);
    $this->invalidateDeparture($uid, $gid);
  }

  /**
   * IDs of the pages ('node') or posts ('post') a person owned in a house when
   * they left it. Empty when nothing was recorded.
   *
   * @return int[]
   */
  public function authoredAtDeparture(int $uid, int $gid, string $type): array {
    $value = $this->departures->get('authored:' . $uid . ':' . $gid, []);
    return is_array($value) && isset($value[$type]) && is_array($value[$type]) ? array_map('intval', $value[$type]) : [];
  }

  /** @return array{node: int[], post: int[]} */
  private function ownedInHouse(int $uid, int $gid): array {
    $owned = ['node' => [], 'post' => []];
    if ($this->entityTypeManager->hasDefinition('group_content') && $this->entityTypeManager->hasDefinition('node')) {
      $schema = $this->database->schema();
      $relationships = $this->entityTypeManager->getDefinition('group_content')->getDataTable();
      $nodes = $this->entityTypeManager->getDefinition('node')->getDataTable();
      if ($relationships && $nodes && $schema->tableExists($relationships) && $schema->tableExists($nodes)) {
        $query = $this->database->select($relationships, 'r');
        $query->innerJoin($nodes, 'n', 'n.nid = r.entity_id');
        $query->addField('r', 'entity_id');
        $query->condition('r.gid', $gid)
          ->condition('r.plugin_id', $this->database->escapeLike('group_node:') . '%', 'LIKE')
          ->condition('n.uid', $uid);
        $owned['node'] = array_values(array_unique(array_map('intval', $query->execute()->fetchCol())));
      }
    }
    if ($this->entityTypeManager->hasDefinition('post')) {
      $schema = $this->database->schema();
      $posts = $this->entityTypeManager->getDefinition('post')->getDataTable();
      if ($posts && $schema->tableExists($posts) && $schema->tableExists('post__field_recipient_group')) {
        $query = $this->database->select('post__field_recipient_group', 'g');
        $query->innerJoin($posts, 'p', 'p.id = g.entity_id');
        $query->addField('g', 'entity_id');
        $query->condition('g.field_recipient_group_target_id', $gid)
          ->condition('g.deleted', 0)
          ->condition('p.user_id', $uid);
        $owned['post'] = array_values(array_unique(array_map('intval', $query->execute()->fetchCol())));
      }
    }
    return $owned;
  }

  /** The current absence's recorded time, never an inferred author date. */
  public function departureTime(int $uid, int $gid): ?int {
    $value = $this->departures->get($uid . ':' . $gid);
    return is_numeric($value) && (int) $value > 0 ? (int) $value : NULL;
  }

  /** @return array<int, int> House IDs and their current departure times. */
  public function departedGroups(int $uid): array {
    if (array_key_exists($uid, $this->departedGroupCache)) return $this->departedGroupCache[$uid];
    $groups = [];
    foreach ($this->departures->getAll() as $key => $value) {
      if (preg_match('/^' . $uid . ':([1-9][0-9]*)$/', (string) $key, $match) && is_numeric($value) && (int) $value > 0) {
        $groups[(int) $match[1]] = (int) $value;
      }
    }
    return $this->departedGroupCache[$uid] = $groups;
  }

  public function retainedReadingClosed(int $uid, int $gid): bool {
    return (bool) $this->departures->get('read_closed:' . $uid . ':' . $gid, FALSE);
  }

  /** A manager's explicit safety choice; ordinary departure never calls this. */
  public function closeRetainedReading(int $uid, int $gid): void {
    if ($uid <= 0 || $gid <= 0) return;
    $this->departures->set('read_closed:' . $uid . ':' . $gid, TRUE);
    $this->invalidateDeparture($uid, $gid);
  }

  public function recordRemoval(int $uid, int $gid, bool $closeReading = FALSE): void {
    if ($uid <= 0 || $gid <= 0) return;
    $this->departures->set('removed:' . $uid . ':' . $gid, TRUE);
    if ($closeReading) $this->closeRetainedReading($uid, $gid);
    $this->invalidateDeparture($uid, $gid);
  }

  public function wasRemoved(int $uid, int $gid): bool {
    return (bool) $this->departures->get('removed:' . $uid . ':' . $gid, FALSE);
  }

  public function groupIdsForEntity(?EntityInterface $entity): array {
    return $this->entityGroups($entity);
  }

  private function invalidateDeparture(int $uid, int $gid): void {
    unset($this->departedGroupCache[$uid]);
    $this->cacheTags?->invalidateTags(['localnodes_departure:' . $uid . ':' . $gid, 'group_content_list']);
  }

  private function hasDeparture(int $uid, int $gid): bool {
    return $this->departures->has($uid . ':' . $gid);
  }

  /** Whether the recipient may receive the complete notice. */
  public function allows(FieldableEntityInterface $activity, int $uid): bool {
    return $this->delivery($activity, $uid) === self::FULL;
  }

  /**
   * Whether a recipient may open a notice's own page under the house rule.
   *
   * As for delivery, an inactive account and a member who has left the house
   * are refused, and so is someone who may not view the item. A notice about
   * no item or about an item that is gone is not refused for that, and an
   * event invitation asks the event, not the enrollment record.
   */
  public function opens(FieldableEntityInterface $activity, int $uid): bool {
    try {
      if ($uid <= 0) {
        return FALSE;
      }
      $users = $this->entityTypeManager->getStorage('user');
      $users->resetCache([$uid]);
      $account = $users->load($uid);
      if (!$account instanceof UserInterface || (int) $account->id() !== $uid
        || !$account->isActive() || !$account->isAuthenticated()) {
        return FALSE;
      }
      $groups = $this->activityGroups($activity);
      if ($groups) {
        $this->entityTypeManager->getStorage('group')->resetCache($groups);
        if ($this->entityTypeManager->hasDefinition('group_content')) {
          $this->entityTypeManager->getStorage('group_content')->resetCache();
        }
        $this->resetAccess('group');
      }
      $targets = $this->viewTargets($activity->getRelatedEntity(), TRUE);
      if ($targets === NULL) {
        return FALSE;
      }
      foreach ($targets as $target) {
        $this->resetAccess($target->getEntityTypeId());
        if (!$target->access('view', $account)) {
          return FALSE;
        }
      }
      return !$this->departedWithoutMembership($uid, $groups);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /** Rechecks the actual content and recipient immediately before delivery. */
  public function delivery(FieldableEntityInterface $activity, int $uid): int {
    try {
      if ($uid <= 0) {
        return self::DROP;
      }
      $users = $this->entityTypeManager->getStorage('user');
      $users->resetCache([$uid]);
      $account = $users->load($uid);
      if (!$account instanceof UserInterface || (int) $account->id() !== $uid
        || !$account->isActive() || !$account->isAuthenticated()) {
        return self::DROP;
      }
      $groups = $this->activityGroups($activity);
      if ($groups) {
        $this->entityTypeManager->getStorage('group')->resetCache($groups);
        // A relationship can retain a group loaded for an earlier queue item.
        if ($this->entityTypeManager->hasDefinition('group_content')) {
          if ($relationships = $this->entityTypeManager->getStorage('group_content')) {
            $relationships->resetCache();
          }
        }
        $this->resetAccess('group');
      }
      $targets = $this->viewTargets($activity->getRelatedEntity());
      $viewable = $targets !== [];
      foreach ($targets as $target) {
        $this->resetAccess($target->getEntityTypeId());
        if (!$target->access('view', $account)) {
          $viewable = FALSE;
          break;
        }
      }
      if ($viewable && !$this->departedWithoutMembership($uid, $groups)) {
        return self::FULL;
      }
      return $this->isEligibleReport($activity, $uid) ? self::GENERIC_REPORT : self::DROP;
    }
    catch (\Throwable) {
      // An unavailable access check is not permission to send content.
      return self::DROP;
    }
  }

  private function resetAccess(string $type): void {
    if ($handler = $this->entityTypeManager->getAccessControlHandler($type)) {
      $handler->resetCache();
    }
  }

  /**
   * Keep every view restriction, including a reply hidden on a readable page.
   *
   * When a notice is opened ($opening): an event invitation asks the event,
   * not the enrollment record, and a notice whose item is gone is not
   * refused for that. NULL means refused.
   */
  private function viewTargets(?EntityInterface $entity, bool $opening = FALSE): ?array {
    $targets = $seen = [];
    while ($entity instanceof EntityInterface && count($seen) < 16) {
      $type = $entity->getEntityTypeId();
      if ($type === 'activity') {
        return $opening ? NULL : [];
      }
      $id = $entity->id();
      $key = $type . ':' . ($id ?? ('object-' . spl_object_id($entity)));
      if (isset($seen[$key])) {
        return $opening ? NULL : [];
      }
      $seen[$key] = TRUE;
      if ($id !== NULL && $id !== '') {
        $storage = $this->entityTypeManager->getStorage($type);
        $storage->resetCache([$id]);
        $entity = $storage->load($id);
        if (!$entity instanceof EntityInterface) {
          return $opening ? $targets : [];
        }
      }
      if (!$opening || $type !== 'event_enrollment') {
        $targets[] = $entity;
      }
      if ($type === 'mentions') {
        $entity = $entity->getMentionedEntity();
      }
      elseif ($type === 'comment') {
        $entity = $entity->getCommentedEntity();
      }
      elseif ($type === 'vote') {
        $entity = $this->entityTypeManager->getStorage($entity->getVotedEntityType())->load($entity->getVotedEntityId());
      }
      elseif ($type === 'flagging') {
        $entity = $entity->getFlaggable();
      }
      elseif ($entity instanceof GroupRelationshipInterface && $entity->getPluginId() !== 'group_membership') {
        $entity = $entity->getEntity();
      }
      elseif ($type === 'event_enrollment' && $entity instanceof FieldableEntityInterface && $entity->hasField('field_event')) {
        $entity = $entity->get('field_event')->entity;
      }
      else {
        return $targets;
      }
    }
    if ($opening) {
      return $entity instanceof EntityInterface ? NULL : $targets;
    }
    return [];
  }

  /** A departure can still suppress notices for an author's retained reading. */
  private function departedWithoutMembership(int $uid, array $groups): bool {
    $departed = FALSE;
    foreach ($groups as $gid) {
      if ($this->isMember($uid, $gid)) {
        return FALSE;
      }
      $departed = $departed || $this->hasDeparture($uid, $gid);
    }
    return $departed;
  }

  /** Remove only this recipient's stored copies, including already queued rows. */
  public function discardDigest(int $uid, int $activityId): void {
    if ($uid > 0 && $activityId > 0 && $this->database->schema()->tableExists('user_activity_digest')) {
      $this->database->delete('user_activity_digest')->condition('uid', $uid)->condition('activity', $activityId)->execute();
    }
  }

  /**
   * Rechecks a digest already handed off by cron, retaining its other groups.
   */
  public function filterDigest(array $payload): ?array {
    if (empty($payload['uid']) || empty($payload['activities'])) {
      return $payload;
    }
    $payload['activities'] = array_values(array_filter($payload['activities'], function ($id) use ($payload): bool {
      $storage = $this->entityTypeManager->getStorage('activity');
      $storage->resetCache([$id]);
      $activity = $storage->load($id);
      if ($activity && $this->delivery($activity, (int) $payload['uid']) !== self::DROP) {
        return TRUE;
      }
      $this->discardDigest((int) $payload['uid'], (int) $id);
      return FALSE;
    }));
    return $payload['activities'] ? $payload : NULL;
  }

  private function isMember(int $uid, int $gid): bool {
    $group = $this->entityTypeManager->getStorage('group')->load($gid);
    if (!$group) {
      return FALSE;
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account && (bool) $group->getMember($account);
  }

  /**
   * Identifies a generic report recipient, never a full-content exemption.
   */
  private function isEligibleReport(FieldableEntityInterface $activity, int $uid): bool {
    if (!$activity->hasField('field_activity_message')) {
      return FALSE;
    }
    $message = $activity->get('field_activity_message')->entity;
    if (!$message instanceof MessageInterface || $message->bundle() !== 'content_reported') {
      return FALSE;
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    return $account && $account->hasPermission('view inappropriate reports');
  }

  /**
   * Retains a cross-posted row only while current delivery remains permitted.
   */
  private function belongsToGroup(int $activity_id, int $gid, int $uid): bool {
    $activity = $this->entityTypeManager->getStorage('activity')->load($activity_id);
    if (!$activity) {
      return FALSE;
    }
    $groups = $this->activityGroups($activity);
    if (!in_array($gid, $groups, TRUE)) {
      return FALSE;
    }
    // This runs before the membership row is deleted. Its departing group
    // must not qualify as a reason to retain mail for that same group.
    if ($this->isEligibleReport($activity, $uid)) {
      // Retain a generic alert; this does not authorize its full content.
      return FALSE;
    }
    foreach ($groups as $other) {
      if ($other !== $gid && $this->isMember($uid, $other)
        && $this->delivery($activity, $uid) !== self::DROP) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function activityGroups(FieldableEntityInterface $activity): array {
    $related = $activity->getRelatedEntity();
    // A relationship's own group is authoritative, even for cross-posted nodes.
    if ($related instanceof GroupRelationshipInterface) {
      return [(int) $related->getGroupId()];
    }
    $groups = [];
    if ($activity->hasField('field_activity_recipient_group')) {
      $groups = array_map('intval', array_column($activity->get('field_activity_recipient_group')->getValue(), 'target_id'));
    }
    return array_values(array_unique(array_merge($groups, $this->entityGroups($related))));
  }

  /**
   * Follows notice wrappers; names and text are never used to infer a group.
   */
  private function entityGroups(?EntityInterface $entity, array $seen = []): array {
    if (!$entity || isset($seen[spl_object_id($entity)])) {
      return [];
    }
    $seen[spl_object_id($entity)] = TRUE;
    $type = $entity->getEntityTypeId();
    if ($type === 'group') {
      return [(int) $entity->id()];
    }
    if ($entity instanceof GroupRelationshipInterface) {
      return [(int) $entity->getGroupId()];
    }
    if ($type === 'mentions') {
      return $this->entityGroups($entity->getMentionedEntity(), $seen);
    }
    if ($type === 'vote') {
      $voted = $this->entityTypeManager->getStorage($entity->getVotedEntityType())->load($entity->getVotedEntityId());
      return $this->entityGroups($voted, $seen);
    }
    if ($type === 'comment') {
      return $this->entityGroups($entity->getCommentedEntity(), $seen);
    }
    if ($type === 'flagging') {
      return $this->entityGroups($entity->getFlaggable(), $seen);
    }
    if ($entity instanceof FieldableEntityInterface) {
      if ($entity->hasField('field_recipient_group')) {
        return array_map('intval', array_column($entity->get('field_recipient_group')->getValue(), 'target_id'));
      }
      if ($type === 'event_enrollment' && $entity->hasField('field_event')) {
        return $this->entityGroups($entity->get('field_event')->entity, $seen);
      }
    }
    if ($type === 'node' && $this->entityTypeManager->hasDefinition('group_content')) {
      $groups = [];
      foreach ($this->entityTypeManager->getStorage('group_content')->loadByEntity($entity) as $relationship) {
        $groups[] = (int) $relationship->getGroupId();
      }
      return array_values(array_unique($groups));
    }
    return [];
  }

}
