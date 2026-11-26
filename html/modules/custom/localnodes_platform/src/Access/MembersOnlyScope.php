<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Access;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * The one rule for "inside a members-only group".
 *
 * An item is members-only when any of its groups is a closed or secret group,
 * or a flexible group whose visibility is "members"; or when the item itself
 * is for group members only (a node with visibility "group", a post with
 * visibility 3). A reply follows its parent.
 *
 * This is the same test _localnodes_platform_private_house_access() makes
 * (a parity test keeps the two equal until that function calls
 * this class). It only answers the question: it grants and refuses nothing.
 * A plain class built at call time, not a service, so a module that calls it
 * adds no container wiring.
 */
final class MembersOnlyScope {

  /** Whether an item lies inside a members-only scope. */
  public static function isMembersOnly(EntityInterface $entity): bool {
    if ($entity instanceof CommentInterface) {
      $parent = $entity->getCommentedEntity();
      return $parent !== NULL && self::isMembersOnly($parent);
    }
    if (!$entity instanceof FieldableEntityInterface) {
      return FALSE;
    }
    if ($entity->getEntityTypeId() === 'post') {
      if ($entity->hasField('field_visibility') && (string) $entity->get('field_visibility')->value === '3') {
        return TRUE;
      }
    }
    elseif ($entity->getEntityTypeId() === 'node') {
      if ($entity->hasField('field_content_visibility') && $entity->get('field_content_visibility')->value === 'group') {
        return TRUE;
      }
    }
    else {
      return FALSE;
    }
    foreach (self::groupsOf($entity) as $group) {
      if (self::groupIsMembersOnly($group)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /** Whether a group is members-only. */
  public static function groupIsMembersOnly(GroupInterface $group): bool {
    return in_array($group->bundle(), ['closed_group', 'secret_group'], TRUE)
      || ($group->hasField('field_flexible_group_visibility') && $group->get('field_flexible_group_visibility')->value === 'members');
  }

  /**
   * The groups an item belongs to (a post: its recipient group).
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   */
  public static function groupsOf(EntityInterface $entity): array {
    if ($entity->isNew()) {
      return [];
    }
    $entities = \Drupal::entityTypeManager();
    $groups = [];
    if ($entity->getEntityTypeId() === 'post' && $entity instanceof FieldableEntityInterface) {
      $gid = $entity->hasField('field_recipient_group') ? $entity->get('field_recipient_group')->target_id : NULL;
      if ($gid && $entities->hasDefinition('group')) {
        $group = $entities->getStorage('group')->load($gid);
        if ($group instanceof GroupInterface) {
          $groups[] = $group;
        }
      }
    }
    elseif ($entity->getEntityTypeId() === 'node' && $entities->hasDefinition('group_content')) {
      foreach ($entities->getStorage('group_content')->loadByEntity($entity) as $relationship) {
        if ($group = $relationship->getGroup()) {
          $groups[] = $group;
        }
      }
    }
    return $groups;
  }

}
