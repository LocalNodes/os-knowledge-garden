<?php

/**
 * @file
 * Creates a fresh, empty group for the recorded import run (plan 22-30).
 *
 * Run with: ddev drush php:script tests/a11y/seed-walkthrough.php
 * (after tests/a11y/seed.php, which creates the a11y-steward persona).
 *
 * Every run creates a new group, "Riverbend Tool Library", with
 * a11y-steward as its group manager (the organizer) and nothing in it, so
 * the walkthrough always starts from an empty Import tab. Older walkthrough
 * groups are left as they are; nothing is deleted. The group and the persona
 * are fictional fixtures on the local DDEV site only.
 *
 * Prints one machine-readable line, "LN_WALK_GROUP=<id>".
 */

use Drupal\group\Entity\Group;

const WALK_GROUP_LABEL = 'Riverbend Tool Library';
const WALK_MANAGER_ROLE = 'flexible_group-group_manager';

$stewards = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'a11y-steward']);
$steward = $stewards ? reset($stewards) : NULL;
if (!$steward) {
  throw new \RuntimeException('Run tests/a11y/seed.php first: the a11y-steward persona is missing.');
}

$group = Group::create([
  'type' => 'flexible_group',
  'label' => WALK_GROUP_LABEL,
  'uid' => $steward->id(),
  'field_flexible_group_visibility' => 'community',
  'field_group_allowed_visibility' => ['public', 'community', 'group'],
  'field_group_allowed_join_method' => ['direct'],
]);
// The group type grants the creator a group_manager membership on save.
$group->save();

$membership = $group->getMember($steward);
if (!$membership) {
  $group->addMember($steward, ['group_roles' => [WALK_MANAGER_ROLE]]);
}
elseif (!array_key_exists(WALK_MANAGER_ROLE, $membership->getRoles(FALSE))) {
  $membership->addRole(WALK_MANAGER_ROLE);
}

print 'LN_WALK_GROUP=' . $group->id() . PHP_EOL;
