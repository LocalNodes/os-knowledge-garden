<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Access;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\user\EntityOwnerInterface;
use Drupal\node\NodeInterface;
use Drupal\social_post\Entity\PostInterface;
use Drupal\user\UserInterface;

/** Separates retained reading of authored house pages from participation. */
class DepartedAuthorAccess {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly GroupNoticeCleanup $departures,
  ) {}

  public function root(EntityInterface $entity): EntityInterface {
    $seen = [];
    while (!isset($seen[spl_object_id($entity)])) {
      $seen[spl_object_id($entity)] = TRUE;
      if ($entity->getEntityTypeId() === 'comment' && $parent = $entity->getCommentedEntity()) {
        $entity = $parent;
        continue;
      }
      if ($entity->getEntityTypeId() === 'event_enrollment'
        && $entity instanceof FieldableEntityInterface
        && $entity->hasField('field_event')
        && $event = $entity->get('field_event')->entity) {
        $entity = $event;
        continue;
      }
      break;
    }
    return $entity;
  }

  /** @return \Drupal\group\Entity\GroupInterface[] */
  public function groups(EntityInterface $entity): array {
    if (!$this->entities->hasDefinition('group')) return [];
    $ids = $this->departures->groupIdsForEntity($this->root($entity));
    return $ids ? $this->entities->getStorage('group')->loadMultiple($ids) : [];
  }

  public function isPrivate(EntityInterface $entity): bool {
    $root = $this->root($entity);
    if ($root instanceof FieldableEntityInterface) {
      if ($root->hasField('field_content_visibility') && $root->get('field_content_visibility')->value === 'group') return TRUE;
      if ($root->hasField('field_visibility') && (string) $root->get('field_visibility')->value === '3') return TRUE;
    }
    foreach ($this->groups($root) as $group) {
      if (in_array($group->bundle(), ['closed_group', 'secret_group'], TRUE)
        || ($group->hasField('field_flexible_group_visibility') && $group->get('field_flexible_group_visibility')->value === 'members')) return TRUE;
    }
    return FALSE;
  }

  public function isCurrentMember(EntityInterface $entity, AccountInterface $actor): bool {
    foreach ($this->groups($entity) as $group) {
      if ($group->getMember($actor)) return TRUE;
    }
    return FALSE;
  }

  public function canRetainView(EntityInterface $entity, AccountInterface $actor): bool {
    $root = $this->root($entity);
    $type = $root->getEntityTypeId();
    if (!in_array($type, ['node', 'post'], TRUE)
      || !$root instanceof EntityOwnerInterface
      || (!$root instanceof NodeInterface && !$root instanceof PostInterface)
      || $root->isNew() || !$actor->isAuthenticated()
      || (int) $root->getOwnerId() !== (int) $actor->id()
      || !$this->isPrivate($root) || $this->isCurrentMember($root, $actor)) return FALSE;
    if (!$this->isActive($actor)) return FALSE;
    if ($type === 'node' && !$actor->hasPermission('access content')) return FALSE;
    if (($type === 'post' || $root instanceof EntityPublishedInterface) && !$root->isPublished()) {
      $permission = $type === 'node' ? 'view own unpublished content' : 'view own unpublished post entities';
      if (!$actor->hasPermission($permission)) return FALSE;
    }
    elseif ($type === 'post') {
      if (!$actor->hasPermission('view published post entities')) return FALSE;
      $public = $root instanceof FieldableEntityInterface && $root->hasField('field_visibility') && (string) $root->get('field_visibility')->value === '1';
      if (!$actor->hasPermission($public ? 'view public posts' : 'view community posts')) return FALSE;
    }
    foreach ($this->groups($root) as $group) {
      $gid = (int) $group->id();
      $departed = $this->departureTime((int) $actor->id(), $gid);
      if ($departed !== NULL && (int) $root->getCreatedTime() <= $departed
        && in_array((int) $root->id(), $this->departures->authoredAtDeparture((int) $actor->id(), $gid, $type), TRUE)
        && !$this->departures->retainedReadingClosed((int) $actor->id(), $gid)) return TRUE;
    }
    return FALSE;
  }

  public function deniesParticipation(EntityInterface $entity, AccountInterface $actor): bool {
    return !$this->isPrivileged($actor) && $this->isPrivate($entity) && !$this->isCurrentMember($entity, $actor);
  }

  public function canMention(EntityInterface $entity, AccountInterface $target): bool {
    return !$this->isPrivate($entity) || $this->isCurrentMember($entity, $target);
  }

  public function departedGroupIds(AccountInterface $actor): array {
    if (!$actor->isAuthenticated() || !$this->isActive($actor)) return [];
    $ids = [];
    foreach ($this->departures->departedGroups((int) $actor->id()) as $gid => $timestamp) {
      if (!$this->departures->retainedReadingClosed((int) $actor->id(), (int) $gid)) $ids[] = (int) $gid;
    }
    return $ids;
  }

  public function departureTime(int $uid, int $gid): ?int {
    return $this->departures->departureTime($uid, $gid);
  }

  /** @return int[] Pages ('node') or posts ('post') owned in the house at departure. */
  public function authoredAtDeparture(int $uid, int $gid, string $type): array {
    return $this->departures->authoredAtDeparture($uid, $gid, $type);
  }

  public function accessMetadata(EntityInterface $entity, AccountInterface $actor): CacheableMetadata {
    $metadata = (new CacheableMetadata())
      ->setCacheContexts(['user', 'user.roles', 'user.permissions'])
      ->setCacheTags(['group_content_list', 'group_list'])
      ->addCacheableDependency($entity)
      ->addCacheableDependency($this->root($entity));
    foreach ($this->groups($entity) as $group) {
      $metadata->addCacheableDependency($group);
      $metadata->addCacheTags(['localnodes_departure:' . $actor->id() . ':' . $group->id()]);
    }
    foreach ($this->entities->getStorage('user_role')->loadMultiple($actor->getRoles()) as $role) $metadata->addCacheableDependency($role);
    return $metadata;
  }

  private function isActive(AccountInterface $actor): bool {
    $account = $actor instanceof UserInterface ? $actor : $this->entities->getStorage('user')->load($actor->id());
    return $account instanceof UserInterface && $account->isActive();
  }

  private function isPrivileged(AccountInterface $actor): bool {
    if ((int) $actor->id() === 1) return TRUE;
    foreach ($this->entities->getStorage('user_role')->loadMultiple($actor->getRoles()) as $role) {
      if ($role->isAdmin()) return TRUE;
    }
    return FALSE;
  }

}
