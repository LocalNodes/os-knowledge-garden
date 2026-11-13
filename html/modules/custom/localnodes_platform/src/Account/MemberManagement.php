<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Account;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Limits site membership management to account status, without user.update. */
final class MemberManagement {

  /** Checks both bulk-action access modes against the same target policy. */
  public static function access(UserInterface $target, AccountInterface $actor): AccessResultInterface {
    $allowed = !$target->isNew()
      && (int) $target->id() > 0
      && (int) $target->id() !== (int) $actor->id();
    $host = (int) $actor->id() === 1;
    $allowed = $allowed && ($host || $actor->hasPermission('block users'));
    $result = AccessResult::allowedIf($allowed)->cachePerUser()->cachePerPermissions()->addCacheableDependency($target);
    if (!$allowed || $host) {
      return $result;
    }
    if ((int) $target->id() === 1) {
      return AccessResult::forbidden()->inheritCacheability($result);
    }
    $roles = \Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple($target->getRoles());
    foreach ($roles as $role) {
      $result->addCacheableDependency($role);
      if ($role->isAdmin()) {
        return AccessResult::forbidden()->inheritCacheability($result);
      }
    }
    $state = \Drupal::state();
    $state->resetCache();
    $owner = (int) $state->get('localnodes.organizer_uid', 0);
    // State has no invalidation tag. Do not retain an old owner decision.
    $result->setCacheMaxAge(0);
    if (in_array('sitemanager', $target->getRoles(), TRUE) || (int) $target->id() === $owner) {
      $administrator = FALSE;
      foreach (\Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple($actor->getRoles()) as $role) {
        $result->addCacheableDependency($role);
        if ($role->isAdmin()) $administrator = TRUE;
      }
      if (!$administrator) return AccessResult::forbidden()->inheritCacheability($result);
    }
    return $result;
  }

  /** Rechecks the current actor before changing only the account status. */
  public static function setActive(UserInterface $target, AccountInterface $actor, bool $active): void {
    if (!self::access($target, $actor)->isAllowed()) {
      throw new AccessDeniedHttpException('You cannot manage this account.');
    }
    if ($target->isActive() === $active) {
      return;
    }
    $target->original = clone $target;
    if ($active) {
      $target->activate();
    }
    else {
      $target->block();
    }
    $target->save();
  }

}
