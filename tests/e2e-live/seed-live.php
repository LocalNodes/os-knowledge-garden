<?php

/**
 * @file
 * Seeds the nightly live-Sepolia run's organizer and group (21-46). CI only.
 *
 * Run by .github/workflows/nightly-wallet-e2e.yml inside the job's own site container:
 *   drush php:script /opt/e2e-live/seed-live.php -- <wallet address>
 *
 * The address is the throwaway CI wallet's public address (the SYNPRESS_WALLET_ADDRESS
 * repository variable). The site is fresh every night (the job starts it on empty volumes and
 * removes them afterwards), so this script normally creates everything. It never deletes
 * anything.
 *
 * - User ci-organizer: active, verified, field_ethereum_address set to the lowercase address
 *   (the SIWE user lookup is strtolower(address), siwe_login EthereumUserManager), so signing in
 *   with the wallet signs in as this user.
 * - A flexible group "CI Nightly Fund <UTC date>" with ci-organizer as its group manager
 *   (organizer), the only one, so a threshold of 1 is the whole approval.
 *
 * Prints LN_LIVE_GROUP=<gid> on its own line. Refuses a malformed address, an address another
 * user already holds, and a site without the group fund relation plugin.
 */

use Drupal\group\Entity\Group;
use Drupal\user\Entity\User;

$args = array_values(array_filter($extra ?? [], static fn ($a) => $a !== '--'));
if (count($args) !== 1) {
  throw new \RuntimeException('seed-live.php needs one argument: the wallet address.');
}
$address = strtolower(trim((string) $args[0]));
if (!preg_match('/^0x[0-9a-f]{40}$/', $address)) {
  throw new \RuntimeException('seed-live.php: not an Ethereum address (0x and 40 hex digits).');
}

$etm = \Drupal::entityTypeManager();

// The group fund needs the relation plugin on flexible_group (social_group_treasury installs it).
$group_type = $etm->getStorage('group_type')->load('flexible_group');
if (!$group_type) {
  throw new \RuntimeException('The flexible_group group type does not exist.');
}
if (!$group_type->hasPlugin('group_safe_account:safe_account')) {
  throw new \RuntimeException('The group_safe_account:safe_account relation plugin is missing on flexible_group; a group fund cannot be set up. Is WEB3_ENABLED=true?');
}

$storage = $etm->getStorage('user');
$name = 'ci-organizer';
$existing = $storage->loadByProperties(['name' => $name]);
$account = $existing ? reset($existing) : NULL;

foreach ($storage->loadByProperties(['field_ethereum_address' => $address]) as $holder) {
  if (!$account || (int) $holder->id() !== (int) $account->id()) {
    throw new \RuntimeException(sprintf('The address is already linked to user %d (%s), not %s.', $holder->id(), $holder->getAccountName(), $name));
  }
}

if (!$account) {
  $account = User::create([
    'name' => $name,
    'mail' => $name . '@example.com',
    'pass' => \Drupal::service('password_generator')->generate(32),
    'status' => 1,
    'field_ethereum_address' => $address,
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
  $account->set('field_ethereum_address', $address);
}
elseif ($current !== $address) {
  throw new \RuntimeException("$name already has a different address; the seed leaves it.");
}
$account->save();

$storage->resetCache([$account->id()]);
$account = $storage->load($account->id());
if (trim((string) $account->get('field_ethereum_address')->value) !== $address) {
  throw new \RuntimeException("field_ethereum_address didn't persist for $name.");
}

// A readable name on the Open Social profile, when it exists.
$profiles = $etm->getStorage('profile')->loadByProperties(['uid' => $account->id(), 'type' => 'profile']);
$profile = $profiles ? reset($profiles) : NULL;
if ($profile && $profile->hasField('field_profile_first_name')) {
  $profile->set('field_profile_first_name', 'Nightly');
  $profile->set('field_profile_last_name', 'Organizer');
  $profile->save();
}

$label = 'CI Nightly Fund ' . gmdate('Y-m-d');
$found = $etm->getStorage('group')->loadByProperties(['type' => 'flexible_group', 'label' => $label]);
$group = $found ? reset($found) : NULL;
if (!$group) {
  $group = Group::create([
    'type' => 'flexible_group',
    'label' => $label,
    'uid' => $account->id(),
    'field_flexible_group_visibility' => 'public',
    'field_group_allowed_visibility' => ['public', 'community', 'group'],
    'field_group_allowed_join_method' => 'added',
  ]);
  $group->save();
}

$manager = 'flexible_group-group_manager';
$membership = $group->getMember($account);
if (!$membership) {
  $group->addMember($account, ['group_roles' => [$manager]]);
}
else {
  $relationship = $membership->getGroupRelationship();
  $roles = array_column($relationship->get('group_roles')->getValue(), 'target_id');
  if (!in_array($manager, $roles, TRUE)) {
    $relationship->get('group_roles')->appendItem(['target_id' => $manager]);
    $relationship->save();
  }
}

// The nightly signs in with a browser wallet (#siwe-login-button). With WaaP sign-in on
// (localnodes_web3 needs siwe_login_waap), that button is hidden unless this setting allows it,
// and the test would wait for a button that never comes. seed-governance-live.php sets it
// the same way. WaaP sign-in itself is checked by hand, not here.
if (\Drupal::moduleHandler()->moduleExists('siwe_login_waap')) {
  \Drupal::configFactory()->getEditable('siwe_login_waap.settings')
    ->set('show_siwe_wallet_button', TRUE)
    ->save();
}

print 'LN_LIVE_GROUP=' . (int) $group->id() . PHP_EOL;
