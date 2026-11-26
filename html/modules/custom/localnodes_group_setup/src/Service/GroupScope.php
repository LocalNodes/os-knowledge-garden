<?php

declare(strict_types=1);

namespace Drupal\localnodes_group_setup\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembership;
use Drupal\user\UserInterface;

/**
 * The one Group eligibility check for set-up and for consensus.
 *
 * - A Group is allowed only when its type is listed in
 *   localnodes_group_setup.settings:group_types; an empty or missing list
 *   refuses every Group.
 * - A current member is an active (not blocked), signed-in account with a
 *   membership in the Group.
 * - A current manager is a current member who holds, individually, the role
 *   the settings name for the Group's type. Roles synchronized from site
 *   roles (outsider and insider roles) are excluded, and no permission is
 *   consulted, so site administrators, uid 1 and admin-flagged group roles
 *   gain nothing here.
 *
 * Nothing here names a Group type, role, kind or Group.
 */
class GroupScope {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The manager role id the settings name for the Group's type, if listed.
   */
  public function managerRole(GroupInterface $group): ?string {
    $types = $this->configFactory->get('localnodes_group_setup.settings')->get('group_types');
    $role = is_array($types) ? ($types[$group->bundle()] ?? NULL) : NULL;
    return is_string($role) && $role !== '' ? $role : NULL;
  }

  /**
   * Whether the Group's type is listed in the settings.
   */
  public function isAllowedGroup(GroupInterface $group): bool {
    return !$group->isNew() && $this->managerRole($group) !== NULL;
  }

  /**
   * Whether the account is an active member of an allowed Group now.
   */
  public function isCurrentMember(GroupInterface $group, AccountInterface $account): bool {
    return $this->membership($group, $account) !== NULL;
  }

  /**
   * Whether the account individually holds the Group's manager role now.
   */
  public function isCurrentManager(GroupInterface $group, AccountInterface $account): bool {
    $membership = $this->membership($group, $account);
    if ($membership === NULL) {
      return FALSE;
    }
    $role = $this->managerRole($group);
    return isset($membership->getRoles(FALSE)[$role]);
  }

  /**
   * The account's membership of an allowed Group, if the account is active.
   */
  protected function membership(GroupInterface $group, AccountInterface $account): ?GroupMembership {
    if (!$this->isAllowedGroup($group) || $account->isAnonymous()) {
      return NULL;
    }
    // Load the stored account: a session object can outlive a block.
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if (!$user instanceof UserInterface || !$user->isActive()) {
      return NULL;
    }
    $membership = $group->getMember($user);
    return $membership ?: NULL;
  }

}
