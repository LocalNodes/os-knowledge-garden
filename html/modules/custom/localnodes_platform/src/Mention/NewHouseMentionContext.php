<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Mention;

use Drupal\Core\Entity\EntityInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Validates selected groups before a new node's relationships exist.
 */
final class NewHouseMentionContext {

  /**
   * Loads actual selected groups and checks the actor's right to create there.
   */
  public static function groups(EntityInterface $root): array {
    if (!$root instanceof NodeInterface || !$root->isNew() || !$root->hasField('groups')) {
      return [];
    }
    $groups = [];
    // Read a copy. Reading the computed groups field marks it computed even
    // when it is empty, and SocialGroupReferenceItemList::postSave() then
    // deletes every house relationship that list lacks the next time this same
    // object is saved. A node made in code and attached to a house afterwards
    // would lose its relationships on its second save.
    foreach ((clone $root)->get('groups')->getValue() as $reference) {
      $gid = (int) ($reference['target_id'] ?? 0);
      $group = $gid > 0 ? \Drupal::entityTypeManager()->getStorage('group')->load($gid) : NULL;
      if ($group instanceof GroupInterface && self::mayCreateIn($root, $group)) {
        $groups[$gid] = $group;
      }
    }
    return $groups;
  }

  /**
   * An actual server form-state group can scope a picker without changing data.
   */
  public static function mayCreateIn(EntityInterface $root, GroupInterface $group): bool {
    if (!$root instanceof NodeInterface || !$root->isNew()) {
      return FALSE;
    }
    // Reload even server-supplied objects: real membership and the real group
    // plugin control authority, not query arguments or a fabricated object.
    $actual = \Drupal::entityTypeManager()->getStorage('group')->load($group->id());
    $actor = \Drupal::currentUser();
    $plugin = 'group_node:' . $root->bundle();
    return $actual instanceof GroupInterface && (bool) $actual->getMember($actor)
      && $actual->getGroupType()->hasPlugin($plugin)
      && (bool) \Drupal::service('group_relation_type.manager')->getAccessControlHandler($plugin)->entityCreateAccess($actual, $actor);
  }

  public static function isPrivate(EntityInterface $root): bool {
    if (\Drupal::service('localnodes_platform.departed_author_access')->isPrivate($root)) {
      return TRUE;
    }
    foreach (self::groups($root) as $group) {
      if (self::groupIsPrivate($group)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public static function groupIsPrivate(GroupInterface $group): bool {
    return in_array($group->bundle(), ['closed_group', 'secret_group'], TRUE)
      || ($group->hasField('field_flexible_group_visibility') && $group->get('field_flexible_group_visibility')->value === 'members');
  }

  public static function canMention(EntityInterface $root, UserInterface $target): bool {
    if ($root instanceof NodeInterface && $root->isNew() && self::isPrivate($root)) {
      foreach (self::groups($root) as $group) {
        if ($group->getMember($target)) {
          return TRUE;
        }
      }
      return FALSE;
    }
    return \Drupal::service('localnodes_platform.departed_author_access')->canMention($root, $target);
  }

}
