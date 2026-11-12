<?php

/**
 * @file
 * Seeds the live governance judge's people and group (25-27). CI only.
 *
 * Run by .github/workflows/governance-judge.yml inside the job's own site container:
 *   drush php:script /opt/e2e-live/seed-governance-live.php -- <account 1> <account 3>
 *
 * The arguments are public addresses of the CI test-operator wallet (D-02), never a personal wallet:
 * account 1 (the organizer) and account 3 (the second approver). Account 2, the voting member,
 * has no linked address here on purpose: the judge connects it after voting is ready, through
 * siwe_login's "Connect your wallet", so the member's "Let them vote" row is queued by the
 * module's own wallet hook (MembershipQueue::onWallet) with the D-33 setting, exactly as a new
 * member's would be. A member whose wallet was linked before voting was ready gets no row.
 *
 * It REFUSES (exit 1, message on stderr) when the test-only module safe_governance_arbitrator_fixture
 * is enabled: that module stands in for the real Arbitrator code, and a live run must use the real code.
 *
 * The site is fresh every run (the job starts it on empty volumes and removes them afterwards).
 * It never deletes anything.
 *
 * - Module safe_governance_group (the Votes tab and its routes) is enabled when it isn't:
 *   localnodes_web3 doesn't depend on it.
 * - When siwe_login_waap is on, its show_siwe_wallet_button setting is turned on, so the
 *   browser-wallet sign-in button the judge clicks is on the sign-in page.
 * - Users, active and verified:
 *   - ci-organizer ("Nightly Organizer"), linked to account 1, with a role holding
 *     'administer safe governance' (the admin-only test-group overrides, D-16, D-21, D-32);
 *   - ci-approver ("Nightly Approver"), linked to account 3;
 *   - ci-member ("Nightly Member"), no linked address.
 * - A flexible group "CI Governance Judge <UTC date and time>" people join only when added
 *   (join method 'added', so "Add new members to the approval list automatically" starts
 *   checked, D-33), with ci-organizer and ci-approver as group managers and ci-member as a plain
 *   member.
 *
 * Prints, each on its own line:
 *   LN_LIVE_GROUP=<gid>
 *   LN_LIVE_MEMBER_UID=<uid>
 *   LN_LIVE_MEMBER_LOGIN=<one-time login link for ci-member>
 * The login link is a credential for this throwaway site: the workflow masks it and never prints
 * it. Refuses malformed or equal addresses, an address another user holds, and a site without
 * the group fund relation plugin.
 */

use Drupal\group\Entity\Group;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

// A live judge run must use the real Reality Arbitrator code (25-29).
// safe_governance_arbitrator_fixture is the test-only stand-in the a11y and e2e seeds enable; if it
// is on, setup "prepare" would use fake code and the judge would prove nothing. Refuse, before
// anything is read or changed. Nothing here ever enables it.
if (\Drupal::moduleHandler()->moduleExists('safe_governance_arbitrator_fixture')) {
  fwrite(STDERR, "seed-governance-live.php refused: safe_governance_arbitrator_fixture is enabled on this site. "
    . "A live judge run must use the real Arbitrator code. Disable it (drush pm:uninstall safe_governance_arbitrator_fixture) "
    . "or use a fresh site.\n");
  exit(1);
}

$args = array_values(array_filter($extra ?? [], static fn ($a) => $a !== '--'));
if (count($args) !== 2) {
  throw new \RuntimeException('seed-governance-live.php needs two arguments: the addresses of accounts 1 and 3.');
}
[$organizer_address, $approver_address] = array_map(static fn ($a) => strtolower(trim((string) $a)), $args);
foreach ([$organizer_address, $approver_address] as $address) {
  if (!preg_match('/^0x[0-9a-f]{40}$/', $address)) {
    throw new \RuntimeException('seed-governance-live.php: not an Ethereum address (0x and 40 hex digits).');
  }
}
if ($organizer_address === $approver_address) {
  throw new \RuntimeException('seed-governance-live.php: accounts 1 and 3 must differ.');
}

$etm = \Drupal::entityTypeManager();

$group_type = $etm->getStorage('group_type')->load('flexible_group');
if (!$group_type) {
  throw new \RuntimeException('The flexible_group group type does not exist.');
}
if (!$group_type->hasPlugin('group_safe_account:safe_account')) {
  throw new \RuntimeException('The group_safe_account:safe_account relation plugin is missing on flexible_group. Is WEB3_ENABLED=true?');
}
$handler = \Drupal::moduleHandler();
if (!$handler->moduleExists('safe_governance')) {
  throw new \RuntimeException('safe_governance is not enabled. Is WEB3_ENABLED=true, and is the module in the image?');
}
if (!$handler->moduleExists('safe_governance_group')) {
  \Drupal::service('module_installer')->install(['safe_governance_group']);
}

// The judge signs in with a browser wallet (the "Sign in with Ethereum" button). When WaaP
// sign-in is on, siwe_login_waap hides that button unless this setting allows it, so the judge
// would wait for a button that never comes. WaaP sign-in itself is checked by hand, not here.
if ($handler->moduleExists('siwe_login_waap')) {
  \Drupal::configFactory()->getEditable('siwe_login_waap.settings')
    ->set('show_siwe_wallet_button', TRUE)
    ->save();
}

// The role that shows the admin-only test-group overrides on "Set up group voting".
$role_id = 'ci_governance_admin';
$role = Role::load($role_id) ?? Role::create(['id' => $role_id, 'label' => 'CI governance admin']);
$role->grantPermission('administer safe governance');
$role->save();

/**
 * Loads or creates a verified user, with a profile name and an optional linked address.
 */
$person = static function (string $name, string $first, string $last, ?string $address, array $roles = []) use ($etm): UserInterface {
  $storage = $etm->getStorage('user');
  $found = $storage->loadByProperties(['name' => $name]);
  $account = $found ? reset($found) : NULL;
  if ($address !== NULL) {
    foreach ($storage->loadByProperties(['field_ethereum_address' => $address]) as $holder) {
      if (!$account || (int) $holder->id() !== (int) $account->id()) {
        throw new \RuntimeException(sprintf('The address for %s is already linked to user %d.', $name, $holder->id()));
      }
    }
  }
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
  foreach (array_merge(['verified'], $roles) as $role) {
    if (!$account->hasRole($role)) {
      $account->addRole($role);
    }
  }
  $current = trim((string) $account->get('field_ethereum_address')->value);
  if ($address !== NULL && $current === '') {
    // siwe_login looks the address up in lowercase (EthereumUserManager).
    $account->set('field_ethereum_address', $address);
  }
  elseif ($address !== NULL && $current !== $address) {
    throw new \RuntimeException("$name already has a different address; the seed leaves it.");
  }
  elseif ($address === NULL && $current !== '') {
    throw new \RuntimeException("$name already has a linked address; the judge links it itself.");
  }
  $account->save();

  $profiles = $etm->getStorage('profile')->loadByProperties(['uid' => $account->id(), 'type' => 'profile']);
  $profile = $profiles ? reset($profiles) : NULL;
  if ($profile && $profile->hasField('field_profile_first_name')) {
    $profile->set('field_profile_first_name', $first);
    $profile->set('field_profile_last_name', $last);
    $profile->save();
  }
  return $account;
};

$organizer = $person('ci-organizer', 'Nightly', 'Organizer', $organizer_address, [$role_id]);
$approver = $person('ci-approver', 'Nightly', 'Approver', $approver_address);
$member = $person('ci-member', 'Nightly', 'Member', NULL);

$label = 'CI Governance Judge ' . gmdate('Y-m-d H:i');
$found = $etm->getStorage('group')->loadByProperties(['type' => 'flexible_group', 'label' => $label]);
$group = $found ? reset($found) : NULL;
if (!$group) {
  $group = Group::create([
    'type' => 'flexible_group',
    'label' => $label,
    'uid' => $organizer->id(),
    'field_flexible_group_visibility' => 'public',
    'field_group_allowed_visibility' => ['public', 'community', 'group'],
    // Added by organizers only: D-33's checkbox starts checked for this group.
    'field_group_allowed_join_method' => 'added',
  ]);
  $group->save();
}

$manager = 'flexible_group-group_manager';
foreach ([[$organizer, [$manager]], [$approver, [$manager]], [$member, []]] as [$account, $roles]) {
  $membership = $group->getMember($account);
  if (!$membership) {
    $group->addMember($account, ['group_roles' => $roles]);
    continue;
  }
  $relationship = $membership->getGroupRelationship();
  $have = array_column($relationship->get('group_roles')->getValue(), 'target_id');
  foreach (array_diff($roles, $have) as $missing) {
    $relationship->get('group_roles')->appendItem(['target_id' => $missing]);
  }
  $relationship->save();
}

print 'LN_LIVE_GROUP=' . (int) $group->id() . PHP_EOL;
print 'LN_LIVE_MEMBER_UID=' . (int) $member->id() . PHP_EOL;
// The same link `drush uli` makes: one use, for ci-member only.
print 'LN_LIVE_MEMBER_LOGIN=' . user_pass_reset_url($member) . '/login' . PHP_EOL;
