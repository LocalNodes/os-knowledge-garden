<?php

/**
 * @file
 * Seeds the fictional e2e users on the lane site fresh3-e2e (21-41).
 *
 * Run through tests/e2e/helpers/site.ts siteScript():
 *   ddev drush php:script tests/e2e/.state/scripts/seed-e2e.php -- \
 *     <organizer address> <approver address> <member address>
 *
 * The addresses are anvil's well-known public dev accounts 0, 1 and 2. They hold no
 * value anywhere real, and these users exist only on the lane-owned fresh3-e2e site.
 *
 * Idempotent: a rerun finds the users and fills in only what's missing. It never deletes
 * anything. field_ethereum_address is set only when it's empty, stored lowercase (as
 * EthereumUserManager::normalizeAddress stores it). If a different user already holds an
 * address, the seed stops with an error and doesn't touch that user.
 *
 * Prints one machine-readable line: LN_E2E_USERS={json}, with uid, name and address per
 * role.
 *
 * Per-run groups (21-42): with `--groups=<runId>-<network>` it also load-or-creates three
 * flexible groups, "E2E Fund <runId>-<network>", "E2E Link <runId>-<network>" and
 * "E2E Pay <runId>-<network>". In each, e2e-organizer and e2e-approver are group managers
 * (organizers) and e2e-member is a plain member. It prints LN_E2E_GROUPS={"fund": gid,
 * "link": gid, "pay": gid}. Nothing is deleted.
 *
 * It also reports whether the group fund relation plugin (group_safe_account:safe_account)
 * is installed on flexible_group: LN_E2E_RELATION_PLUGIN=present|missing. Since 21-25 fix 6
 * (group_treasury ed980c3) social_group_treasury installs it, so a fresh install has it.
 * The seed never installs it: `missing` means that fix regressed, and the run records it.
 */

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;

use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

$roles = [
  'organizer' => ['e2e-organizer', 'Wren', 'Organizer'],
  'approver' => ['e2e-approver', 'Linden', 'Approver'],
  'member' => ['e2e-member', 'Sorrel', 'Member'],
];

$args = [];
$groups_suffix = NULL;
foreach (array_values(array_filter($extra ?? [], static fn ($a) => $a !== '--')) as $arg) {
  if (str_starts_with($arg, '--groups=')) {
    $groups_suffix = substr($arg, strlen('--groups='));
    if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $groups_suffix)) {
      throw new \RuntimeException("Not a run suffix: $groups_suffix");
    }
  }
  elseif (str_starts_with($arg, '--')) {
    throw new \RuntimeException("Unknown option: $arg");
  }
  else {
    $args[] = $arg;
  }
}
if (count($args) !== 3) {
  throw new \RuntimeException('seed-e2e.php needs three addresses: organizer, approver, member.');
}

// The Reality Arbitrator stand-in (25-29): where safe_governance is on the site, enable
// safe_governance_arbitrator_fixture so a run that reaches setup "prepare" never downloads the
// real GPL-3.0 init code from GitHub. It changes no chain read (the RPC fixture is a different
// module, and global-setup.ts still refuses that one). Test-only and hidden; the lane site finds
// it because site.sh code writes extension_discovery_scan_tests into settings.local.php. The
// live judge seed refuses while it is enabled.
$handler = \Drupal::moduleHandler();
if ($handler->moduleExists('safe_governance') && !$handler->moduleExists('safe_governance_arbitrator_fixture')) {
  \Drupal::service('module_installer')->install(['safe_governance_arbitrator_fixture']);
}

/**
 * Loads or creates a fixture user and links its address.
 */
function e2e_seed_user(string $name, string $first, string $last, string $address): UserInterface {
  $lower = strtolower(trim($address));
  if (!preg_match('/^0x[0-9a-f]{40}$/', $lower)) {
    throw new \RuntimeException("Not an address: $address");
  }
  $storage = \Drupal::entityTypeManager()->getStorage('user');

  $existing = $storage->loadByProperties(['name' => $name]);
  $account = $existing ? reset($existing) : NULL;

  // Refuse if another user already holds the address; never modify that user.
  $holders = $storage->loadByProperties(['field_ethereum_address' => $lower]);
  foreach ($holders as $holder) {
    if (!$account || (int) $holder->id() !== (int) $account->id()) {
      throw new \RuntimeException(sprintf(
        'Address %s is already linked to user %d (%s), not %s. The seed stops and changes nothing on that user.',
        $lower, $holder->id(), $holder->getAccountName(), $name
      ));
    }
  }

  if (!$account) {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'pass' => \Drupal::service('password_generator')->generate(32),
      'status' => 1,
      'field_ethereum_address' => $lower,
    ]);
  }
  if (!$account->isActive()) {
    $account->activate();
  }
  if (!$account->hasRole('verified')) {
    $account->addRole('verified');
  }
  $current = trim((string) $account->get('field_ethereum_address')->value);
  if ($current === '') {
    $account->set('field_ethereum_address', $lower);
  }
  elseif ($current !== $lower) {
    throw new \RuntimeException(sprintf('%s already has a different address (%s); the seed leaves it.', $name, $current));
  }
  $account->save();

  // Check the address stuck: if it didn't, a sign-in would create a new user instead.
  $storage->resetCache([$account->id()]);
  $reloaded = $storage->load($account->id());
  if (trim((string) $reloaded->get('field_ethereum_address')->value) !== $lower) {
    throw new \RuntimeException("field_ethereum_address didn't persist for $name.");
  }

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

  return $reloaded;
}

$users = [];
$i = 0;
foreach ($roles as $role => [$name, $first, $last]) {
  $address = $args[$i++];
  $account = e2e_seed_user($name, $first, $last, $address);
  $users[$role] = [
    'uid' => (int) $account->id(),
    'name' => $name,
    'address' => $address,
  ];
}

print 'LN_E2E_USERS=' . json_encode($users, JSON_UNESCAPED_SLASHES) . PHP_EOL;

/**
 * Loads or creates one per-run flexible group, with its organizers and member.
 */
function e2e_seed_group(string $label, UserInterface $organizer, UserInterface $approver, UserInterface $member): GroupInterface {
  $storage = \Drupal::entityTypeManager()->getStorage('group');
  $found = $storage->loadByProperties(['type' => 'flexible_group', 'label' => $label]);
  $group = $found ? reset($found) : NULL;
  if (!$group) {
    $group = Group::create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $organizer->id(),
      'field_flexible_group_visibility' => 'public',
      'field_group_allowed_visibility' => ['public', 'community', 'group'],
      'field_group_allowed_join_method' => 'added',
    ]);
    $group->save();
  }
  $manager = 'flexible_group-group_manager';
  foreach ([$organizer, $approver] as $account) {
    $membership = $group->getMember($account);
    if (!$membership) {
      $group->addMember($account, ['group_roles' => [$manager]]);
      continue;
    }
    $relationship = $membership->getGroupRelationship();
    $roles = array_column($relationship->get('group_roles')->getValue(), 'target_id');
    if (!in_array($manager, $roles, TRUE)) {
      $relationship->get('group_roles')->appendItem(['target_id' => $manager]);
      $relationship->save();
    }
  }
  if (!$group->getMember($member)) {
    $group->addMember($member);
  }
  return $group;
}

if ($groups_suffix !== NULL) {
  $etm = \Drupal::entityTypeManager();
  $group_type = $etm->getStorage('group_type')->load('flexible_group');
  if (!$group_type) {
    throw new \RuntimeException('The flexible_group group type does not exist.');
  }
  $plugin_id = 'group_safe_account:safe_account';
  $plugin_state = $group_type->hasPlugin($plugin_id) ? 'present' : 'missing';
  print 'LN_E2E_RELATION_PLUGIN=' . $plugin_state . PHP_EOL;

  $user_storage = $etm->getStorage('user');
  $organizer = $user_storage->load($users['organizer']['uid']);
  $approver = $user_storage->load($users['approver']['uid']);
  $member = $user_storage->load($users['member']['uid']);
  $gids = [];
  foreach (['fund' => 'Fund', 'link' => 'Link', 'pay' => 'Pay'] as $key => $word) {
    $gids[$key] = (int) e2e_seed_group("E2E $word $groups_suffix", $organizer, $approver, $member)->id();
  }
  print 'LN_E2E_GROUPS=' . json_encode($gids, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
