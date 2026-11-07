<?php

/**
 * @file
 * Seeds the fictional a11y personas and their group (Phase 22, plan 22-11).
 *
 * Run with: ddev drush php:script tests/a11y/seed.php
 *
 * Idempotent: rerunning finds the existing users, group and memberships and
 * only fills in anything missing. It never deletes or renames anything. The
 * users and the group are fictional fixtures that exist only on the local
 * DDEV site.
 *
 * Prints one machine-readable line, "LN_GROUP=<id>", which
 * tests/a11y/global-setup.ts reads.
 */

use Drupal\group\Entity\Group;
use Drupal\user\Entity\User;

const A11Y_GROUP_LABEL = 'A11y Garden';
const A11Y_GROUP_TYPE = 'flexible_group';
const A11Y_MANAGER_ROLE = 'flexible_group-group_manager';

/**
 * Loads a fixture user by name, creating it if needed.
 */
function a11y_seed_user(string $name, string $first, string $last): User {
  $existing = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $name]);
  $account = $existing ? reset($existing) : NULL;

  if (!$account) {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'pass' => \Drupal::service('password_generator')->generate(32),
      'status' => 1,
    ]);
  }
  if (!$account->isActive()) {
    $account->activate();
  }
  // Open Social gives confirmed members the "verified" role; personas should
  // see the site the way a real member does.
  if (!$account->hasRole('verified')) {
    $account->addRole('verified');
  }
  $account->save();

  // Fictional display names on the Open Social profile, when it exists.
  $profiles = \Drupal::entityTypeManager()
    ->getStorage('profile')
    ->loadByProperties(['uid' => $account->id(), 'type' => 'profile']);
  $profile = $profiles ? reset($profiles) : NULL;
  if ($profile && $profile->hasField('field_profile_first_name')) {
    if ($profile->get('field_profile_first_name')->value !== $first
      || $profile->get('field_profile_last_name')->value !== $last) {
      $profile->set('field_profile_first_name', $first);
      $profile->set('field_profile_last_name', $last);
      $profile->save();
    }
  }

  return $account;
}

$steward = a11y_seed_user('a11y-steward', 'Juniper', 'Steward');
$member = a11y_seed_user('a11y-member', 'Rowan', 'Member');

$groups = \Drupal::entityTypeManager()
  ->getStorage('group')
  ->loadByProperties(['type' => A11Y_GROUP_TYPE, 'label' => A11Y_GROUP_LABEL]);
// Oldest first, so a rerun always settles on the same group.
ksort($groups);
$group = $groups ? reset($groups) : NULL;

if (!$group) {
  $group = Group::create([
    'type' => A11Y_GROUP_TYPE,
    'label' => A11Y_GROUP_LABEL,
    'uid' => $steward->id(),
    'field_flexible_group_visibility' => 'community',
    'field_group_allowed_visibility' => ['public', 'community', 'group'],
    'field_group_allowed_join_method' => ['direct'],
  ]);
  // The group type grants the creator a group_manager membership on save.
  $group->save();
}

// Steward: group manager. Ensure even if the creator membership was skipped.
$steward_membership = $group->getMember($steward);
if (!$steward_membership) {
  $group->addMember($steward, ['group_roles' => [A11Y_MANAGER_ROLE]]);
}
elseif (!array_key_exists(A11Y_MANAGER_ROLE, $steward_membership->getRoles(FALSE))) {
  $steward_membership->addRole(A11Y_MANAGER_ROLE);
}

// Member: a plain member, no extra group roles.
if (!$group->getMember($member)) {
  $group->addMember($member);
}

print 'LN_GROUP=' . $group->id() . PHP_EOL;
