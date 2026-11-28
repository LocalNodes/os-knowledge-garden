<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Account;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;

/**
 * What stays with account 1 when people are managed.
 *
 * Nobody but account 1 changes another person's password or email: members
 * reset their own by the emailed link. Roles are set directly only by
 * account 1 (or a role account 1 gave the right to edit roles). Nobody but
 * account 1 edits or cancels account 1. A role is handed out only by
 * account 1, or when it holds
 * nothing the administrator role could not hold itself; whatever screen,
 * bulk action or form tries, a role that does not qualify is not added.
 */
final class AccountDoors {

  /** Fields of another person's account that only account 1 changes. */
  public const CLOSED_FIELDS = ['mail', 'pass'];

  /** Field access: another person's password and email, and roles. */
  public static function fieldAccess(string $operation, string $field, AccountInterface $actor, ?UserInterface $target): AccessResultInterface {
    if ($operation === 'edit' && $field === 'roles') {
      // Roles are set by those who may edit roles; others hand out roles
      // through role_delegation's own field, which assignableRoles() narrows.
      return AccessResult::forbiddenIf(!self::setsRolesDirectly($actor), 'Only account 1 sets roles directly.')->cachePerUser()->cachePerPermissions();
    }
    if ($operation !== 'edit' || $target === NULL || !in_array($field, self::CLOSED_FIELDS, TRUE)) {
      return AccessResult::neutral();
    }
    $open = $target->isNew() || (int) $target->id() === (int) $actor->id() || (int) $actor->id() === 1;
    return AccessResult::forbiddenIf(!$open, "Only account 1 changes another person's password or email.")
      ->cachePerUser()
      ->addCacheableDependency($target);
  }

  /**
   * Whether the person sets roles directly (the roles field, bulk actions).
   *
   * Account 1, or a role account 1 gave the right to edit roles.
   */
  public static function setsRolesDirectly(AccountInterface $actor): bool {
    return (int) $actor->id() === 1 || $actor->hasPermission('administer permissions');
  }

  /** Entity access: account 1's own account. */
  public static function accountAccess(UserInterface $target, string $operation, AccountInterface $actor): AccessResultInterface {
    $closed = (int) $target->id() === 1 && (int) $actor->id() !== 1 && in_array($operation, ['update', 'delete'], TRUE);
    return AccessResult::forbiddenIf($closed, 'Only account 1 changes account 1.')->cachePerUser();
  }

  /** Narrows the roles a person may hand out (role_delegation). */
  public static function assignableRoles(array &$assignable, array $all_roles, AccountInterface $actor): void {
    foreach (array_keys($assignable) as $rid) {
      if (!isset($all_roles[$rid]) || !AdministratorPermissions::mayHandOut($all_roles[$rid], $actor)) {
        unset($assignable[$rid]);
      }
    }
  }

  /**
   * Takes back a role that was added by someone who may not hand it out.
   *
   * Applies to a signed-in person other than account 1. Code running with no
   * signed-in person (install, drush, cron) is not limited here.
   */
  public static function presave(UserInterface $account, AccountInterface $actor): void {
    if ((int) $actor->id() === 0 || (int) $actor->id() === 1) {
      return;
    }
    $before = isset($account->original) && $account->original instanceof UserInterface ? $account->original->getRoles(TRUE) : [];
    $roles = \Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple(array_diff($account->getRoles(TRUE), $before));
    foreach ($roles as $role) {
      if (!AdministratorPermissions::mayHandOut($role, $actor)) {
        $account->removeRole($role->id());
      }
    }
  }

}
