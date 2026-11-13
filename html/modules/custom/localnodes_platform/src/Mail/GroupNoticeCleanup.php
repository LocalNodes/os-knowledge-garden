<?php

namespace Drupal\localnodes_platform\Mail;

use Drupal\Core\Database\Connection;
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

  public const DROP = 0;
  public const FULL = 1;
  public const GENERIC_REPORT = 2;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyValueStoreInterface $departures,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Drops stored digest rows and records a departure until membership returns.
   */
  public function remove(int $uid, int $gid): void {
    if ($uid <= 0 || $gid <= 0) {
      return;
    }
    // Producers may not have created their activity when membership is removed.
    $this->departures->set($uid . ':' . $gid, $this->time->getCurrentTime());
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
  }

  /**
   * New membership restores normal mail, including notices still queued.
   */
  public function clearDeparture(int $uid, int $gid): void {
    $this->departures->delete($uid . ':' . $gid);
  }

  private function hasDeparture(int $uid, int $gid): bool {
    return $this->departures->has($uid . ':' . $gid);
  }

  /** Whether the recipient may receive the complete notice. */
  public function allows(FieldableEntityInterface $activity, int $uid): bool {
    return $this->delivery($activity, $uid) === self::FULL;
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

  /** Keep every view restriction, including a reply hidden on a readable page. */
  private function viewTargets(?EntityInterface $entity): array {
    $targets = $seen = [];
    while ($entity instanceof EntityInterface && count($seen) < 16) {
      $type = $entity->getEntityTypeId();
      if ($type === 'activity') {
        return [];
      }
      $id = $entity->id();
      $key = $type . ':' . ($id ?? ('object-' . spl_object_id($entity)));
      if (isset($seen[$key])) {
        return [];
      }
      $seen[$key] = TRUE;
      if ($id !== NULL && $id !== '') {
        $storage = $this->entityTypeManager->getStorage($type);
        $storage->resetCache([$id]);
        $entity = $storage->load($id);
        if (!$entity instanceof EntityInterface) {
          return [];
        }
      }
      $targets[] = $entity;
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
