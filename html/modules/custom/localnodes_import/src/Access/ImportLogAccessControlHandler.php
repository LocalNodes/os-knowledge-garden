<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Access\GroupAccessResult;
use Drupal\group\Entity\Group;

/**
 * Access to import runs and items (T-22-17).
 *
 * A row can be viewed by people who hold "import content into group" in the
 * row's group, and by site administrators of groups. Everything else is
 * denied: the log names files and pages of members-only groups.
 */
class ImportLogAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    $admin = AccessResult::allowedIfHasPermission($account, 'administer group');
    if ($admin->isAllowed()) {
      return $admin;
    }
    if ($operation !== 'view' || !method_exists($entity, 'getGroupId')) {
      return AccessResult::neutral()->addCacheableDependency($entity)->cachePerPermissions();
    }

    $gid = $entity->getGroupId();
    $group = $gid === NULL ? NULL : Group::load($gid);
    if ($group === NULL) {
      return AccessResult::neutral()->addCacheableDependency($entity);
    }
    return GroupAccessResult::allowedIfHasGroupPermission($group, $account, 'import content into group')
      ->addCacheableDependency($entity)
      ->addCacheableDependency($group);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Rows are written by the importer itself, never through an entity form.
    return AccessResult::allowedIfHasPermission($account, 'administer group');
  }

}
