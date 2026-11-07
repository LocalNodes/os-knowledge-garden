<?php

/**
 * @file
 * Seeds the Phase 21 group fund states for the a11y run (plan 21-28).
 *
 * Run with: ddev drush php:script tests/a11y/seed-treasury.php
 * (on the lane site fresh3-p21-a11y, after tests/a11y/seed.php has made the
 * personas a11y-steward and a11y-member).
 *
 * Idempotent: rerunning finds the groups, accounts, approval settings and
 * payments by label and only fills in what is missing. It never deletes an
 * entity. It does reset the stored chain snapshots of "A11y Fund Old" (read
 * 3 hours ago) and "A11y Fund New" (never read), because a run's own page
 * views write new ones.
 *
 * The site is expected to run the test-only safe_smart_accounts_rpc_fixture
 * module (D-14). Its answers don't depend on the address read: every Safe
 * reads as the Base Sepolia fixture Safe (owners 0x3dd5…b337 and
 * 0xc3f4…5890, threshold 2, nonce 3). So the steward's linked wallet is the
 * first fixture owner, and the payments' nonces sit around 3. All addresses
 * here are test values on a local site.
 *
 * Prints one machine-readable line, LN_TREASURY={json}, with:
 * - groups: filled, old, never, setup, failed, retired, sending, empty;
 * - safes: the same keys (except empty) => Safe account id;
 * - payments: approve, ready, waiting, executed;
 * - steward, member: user ids;
 * - personal: the steward's personal shared account id, or null (none is
 *   seeded: the T4 personal create form needs the steward to have none).
 *
 * Helper modes (for treasury.spec.ts, between states):
 * - `-- forget <safe id>`: deletes that Safe's stored snapshot and
 *   invalidates its cache tags, so the next page view reads again;
 * - `-- age <safe id> <seconds>`: moves the stored snapshot's read_at (and
 *   any last error) that far back and invalidates its cache tags.
 */

use Drupal\Core\Cache\Cache;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\safe_smart_accounts\Service\ChainSnapshotStore;
use Drupal\safe_smart_accounts\Value\Address;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

const LN_GROUP_TYPE = 'flexible_group';
const LN_MANAGER_ROLE = 'flexible_group-group_manager';
// The fixture Safe's owners (safe-base-getowners.json), lowercase.
const LN_OWNER_A = '0x3dd578a97f758366e6932c0cd9a3e5478629b337';
const LN_OWNER_B = '0xc3f45c10610aa25534f38040cc2a5db2bfc65890';
// The fixture Safe's nonce (safe-base-nonce.json).
const LN_CHAIN_NONCE = 3;

/**
 * A stable checksummed test address for a key.
 */
function ln_address(string $key): string {
  return Address::checksum('0x' . substr(hash('sha256', 'a11y-21-28-' . $key), 0, 40));
}

/**
 * A stable 32-byte test hash for a key.
 */
function ln_hash(string $key): string {
  return '0x' . hash('sha256', 'a11y-21-28-hash-' . $key);
}

/**
 * Loads a persona made by seed.php, or stops.
 */
function ln_user(string $name): UserInterface {
  $found = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
  $account = $found ? reset($found) : NULL;
  if (!$account instanceof UserInterface) {
    throw new \RuntimeException("Run tests/a11y/seed.php first: $name is missing.");
  }
  return $account;
}

/**
 * Loads or creates a group by label, with the steward as manager and the
 * member as a plain member.
 */
function ln_group(string $label, UserInterface $steward, UserInterface $member): GroupInterface {
  $groups = \Drupal::entityTypeManager()->getStorage('group')
    ->loadByProperties(['type' => LN_GROUP_TYPE, 'label' => $label]);
  ksort($groups);
  $group = $groups ? reset($groups) : NULL;
  if (!$group instanceof GroupInterface) {
    $group = Group::create([
      'type' => LN_GROUP_TYPE,
      'label' => $label,
      'uid' => $steward->id(),
      'field_flexible_group_visibility' => 'community',
      'field_group_allowed_visibility' => ['public', 'community', 'group'],
      'field_group_allowed_join_method' => ['direct'],
    ]);
    $group->save();
  }
  $membership = $group->getMember($steward);
  if (!$membership) {
    $group->addMember($steward, ['group_roles' => [LN_MANAGER_ROLE]]);
  }
  elseif (!array_key_exists(LN_MANAGER_ROLE, $membership->getRoles(FALSE))) {
    $membership->addRole(LN_MANAGER_ROLE);
  }
  if (!$group->getMember($member)) {
    $group->addMember($member);
  }
  return $group;
}

/**
 * Loads or creates the group's fund and its approval settings.
 */
function ln_fund(GroupInterface $group, UserInterface $steward, string $key, string $network, string $status, array $signers, int $threshold): SafeAccountInterface {
  $treasury = \Drupal::service('group_treasury.treasury_service');
  $safe = $treasury->getTreasury($group);
  if (!$safe instanceof SafeAccountInterface) {
    $values = [
      'user_id' => $steward->id(),
      'network' => $network,
      'threshold' => $threshold,
      'status' => $status,
    ];
    // A fund still being set up has no address yet, and a failed set-up
    // with no confirmed receipt has none either (21-13 clears it).
    if ($status === 'active') {
      $values['safe_address'] = ln_address('safe-' . $key);
    }
    if ($status === 'active') {
      $values['deployed_at'] = \Drupal::time()->getRequestTime() - 86400;
    }
    $safe = \Drupal::entityTypeManager()->getStorage('safe_account')->create($values);
    $safe->save();
    $treasury->addTreasury($group, $safe);
  }
  elseif ($status !== 'active' && $safe->getSafeAddress()) {
    // An earlier seed gave it one: "setup didn't finish" needs none.
    $safe->set('safe_address', NULL);
    $safe->save();
  }
  $configurations = \Drupal::entityTypeManager()->getStorage('safe_configuration')
    ->loadByProperties(['safe_account_id' => $safe->id()]);
  if (!$configurations) {
    \Drupal::entityTypeManager()->getStorage('safe_configuration')->create([
      'id' => 'safe_' . $safe->id(),
      'label' => 'Safe ' . $safe->id(),
      'safe_account_id' => (int) $safe->id(),
      'signers' => $signers,
      'threshold' => $threshold,
      'salt_nonce' => 1000 + (int) $safe->id(),
    ])->save();
  }
  else {
    // An earlier seed stored the salt nonce as a string; the entity's
    // getter returns int.
    $configuration = reset($configurations);
    if (!is_int($configuration->get('salt_nonce'))) {
      $configuration->set('salt_nonce', (int) $configuration->get('salt_nonce'))->save();
    }
  }
  return $safe;
}

/**
 * Loads or creates a payment of a fund by its description.
 */
function ln_payment(SafeAccountInterface $safe, UserInterface $steward, string $description, array $values): int {
  $storage = \Drupal::entityTypeManager()->getStorage('safe_transaction');
  $ids = $storage->getQuery()->accessCheck(FALSE)
    ->condition('safe_account', $safe->id())
    ->condition('description', $description)
    ->sort('id')
    ->execute();
  if ($ids) {
    return (int) reset($ids);
  }
  $now = \Drupal::time()->getRequestTime();
  $signatures = [];
  foreach ($values['signed_by'] ?? [] as $i => $signer) {
    $signatures[] = [
      'signer' => strtolower($signer),
      'signature' => '0x' . str_repeat(dechex(1 + $i), 128) . '1b',
      'signed_at' => $now - 3600,
    ];
  }
  $tx = $storage->create([
    'safe_account' => $safe->id(),
    'safe_tx_hash' => ln_hash('safe-tx-' . $description),
    'to_address' => ln_address('recipient'),
    'value' => $values['value'] ?? '50000000000000000',
    'data' => '0x',
    'operation' => 0,
    'nonce' => $values['nonce'],
    'status' => $values['status'] ?? 'pending',
    'description' => $description,
    'created' => $now - 7200,
    'executed_at' => $values['executed_at'] ?? NULL,
    'blockchain_tx_hash' => $values['hash'] ?? NULL,
    'signatures' => json_encode($signatures),
    'created_by' => $steward->id(),
  ]);
  $tx->save();
  return (int) $tx->id();
}

/**
 * The snapshot key of a Safe, with its network, or NULL.
 */
function ln_snapshot_key(int $safe_id): ?array {
  $safe = \Drupal::entityTypeManager()->getStorage('safe_account')->load($safe_id);
  if (!$safe instanceof SafeAccountInterface || !$safe->getSafeAddress()) {
    return NULL;
  }
  $network = \Drupal::service('safe_smart_accounts.network_registry')->forAccount($safe);
  return $network ? [$network, ChainSnapshotStore::key($network, $safe->getSafeAddress())] : NULL;
}

/**
 * Invalidates a Safe's cached card and pages.
 */
function ln_invalidate(int $safe_id): void {
  Cache::invalidateTags(['safe_account:' . $safe_id, 'safe_snapshot:' . $safe_id]);
}

$store = \Drupal::keyValue(ChainSnapshotStore::COLLECTION);

// Helper modes.
$args = $extra ?? [];
if (($args[0] ?? '') === 'forget') {
  $key = ln_snapshot_key((int) ($args[1] ?? 0));
  if ($key) {
    $store->delete($key[1]);
  }
  ln_invalidate((int) ($args[1] ?? 0));
  print 'LN_OK=forget' . PHP_EOL;
  return;
}
if (($args[0] ?? '') === 'age') {
  $key = ln_snapshot_key((int) ($args[1] ?? 0));
  $seconds = (int) ($args[2] ?? 0);
  if ($key && is_array($record = $store->get($key[1]))) {
    foreach (['read_at', 'last_error_at'] as $field) {
      if (isset($record[$field])) {
        $record[$field] -= $seconds;
      }
    }
    $store->set($key[1], $record);
  }
  ln_invalidate((int) ($args[1] ?? 0));
  print 'LN_OK=age' . PHP_EOL;
  return;
}

$steward = ln_user('a11y-steward');
$member = ln_user('a11y-member');

// The steward's linked wallet is a fixture owner, so they are an approver
// of every fixture-read fund. siwe_login stores it lowercase.
if ($steward->hasField('field_ethereum_address') && $steward->get('field_ethereum_address')->value !== LN_OWNER_A) {
  $steward->set('field_ethereum_address', LN_OWNER_A);
  $steward->save();
}

$owners = [Address::checksum(LN_OWNER_A), Address::checksum(LN_OWNER_B)];

$groups = [];
$safes = [];
$plan = [
  'filled' => ['A11y Fund', 'ethereum_sepolia', 'active', $owners, 2],
  'old' => ['A11y Fund Old', 'ethereum_sepolia', 'active', $owners, 2],
  'never' => ['A11y Fund New', 'ethereum_sepolia', 'active', $owners, 2],
  'setup' => ['A11y Fund Setup', 'ethereum_sepolia', 'pending', [$owners[0], $owners[1], ln_address('third-approver')], 2],
  'failed' => ['A11y Fund Failed', 'ethereum_sepolia', 'error', $owners, 2],
  'retired' => ['A11y Fund Retired', 'retired_net', 'active', $owners, 2],
  'sending' => ['A11y Fund Sending', 'base_sepolia', 'active', $owners, 2],
];
foreach ($plan as $key => [$label, $network, $status, $signers, $threshold]) {
  $group = ln_group($label, $steward, $member);
  $groups[$key] = (int) $group->id();
  $safes[$key] = (int) ln_fund($group, $steward, $key, $network, $status, $signers, $threshold)->id();
}
// "A11y Garden" (seed.php) is the group with no fund.
$garden = \Drupal::entityTypeManager()->getStorage('group')
  ->loadByProperties(['type' => LN_GROUP_TYPE, 'label' => 'A11y Garden']);
ksort($garden);
$groups['empty'] = $garden ? (int) reset($garden)->id() : NULL;

$storage = \Drupal::entityTypeManager()->getStorage('safe_account');
$filled = $storage->load($safes['filled']);
$sending = $storage->load($safes['sending']);
$now = \Drupal::time()->getRequestTime();

$payments = [
  // Fully approved and next in line: its nonce is the chain's.
  'ready' => ln_payment($filled, $steward, 'Seeds for the spring planting', [
    'nonce' => LN_CHAIN_NONCE,
    'signed_by' => [LN_OWNER_A, LN_OWNER_B],
  ]),
  // Approved by the other owner only: needs the steward's approval.
  'approve' => ln_payment($filled, $steward, 'Tools for the tool library', [
    'nonce' => LN_CHAIN_NONCE + 1,
    'signed_by' => [LN_OWNER_B],
    'value' => '20000000000000000',
  ]),
  'executed' => ln_payment($filled, $steward, 'Compost bins', [
    'nonce' => LN_CHAIN_NONCE - 1,
    'status' => 'executed',
    'signed_by' => [LN_OWNER_A, LN_OWNER_B],
    'hash' => ln_hash('executed'),
    'executed_at' => $now - 86400,
    'value' => '10000000000000000',
  ]),
  // Sent (a stored send hash) with no receipt yet: the fixture answers
  // eth_getTransactionReceipt with NULL.
  'waiting' => ln_payment($sending, $steward, 'Hall rental for the seed swap', [
    'nonce' => LN_CHAIN_NONCE,
    'signed_by' => [LN_OWNER_A, LN_OWNER_B],
    'hash' => ln_hash('waiting'),
  ]),
];

// "A11y Fund Old": last read 3 hours ago, 0.52 ETH and 120 USDC.
$old = $storage->load($safes['old']);
[$old_network, $old_key] = ln_snapshot_key((int) $old->id());
$token = $old_network->getTokens()[0] ?? NULL;
$balances = [[
  'asset' => 'native',
  'name' => 'Ether',
  'symbol' => 'ETH',
  'raw' => '520000000000000000',
  'decimals' => 18,
  'error' => NULL,
]];
if ($token) {
  $balances[] = [
    'asset' => (string) $token['address'],
    'name' => (string) $token['label'],
    'symbol' => (string) $token['label'],
    'raw' => '120000000',
    'decimals' => 6,
    'error' => NULL,
  ];
}
$store->set($old_key, [
  'owners' => $owners,
  'threshold' => 2,
  'nonce' => (string) LN_CHAIN_NONCE,
  'version' => '1.4.1',
  'balances' => $balances,
  'read_at' => $now - 3 * 3600,
  'last_error' => NULL,
  'last_error_at' => NULL,
]);
ln_invalidate((int) $old->id());

// "A11y Fund New": never read.
$new_key = ln_snapshot_key($safes['never']);
if ($new_key) {
  $store->delete($new_key[1]);
}
ln_invalidate($safes['never']);

// The steward's personal shared account: none (see the file comment).
$personal = NULL;
foreach ($storage->loadByProperties(['user_id' => $steward->id()]) as $account) {
  if (!\Drupal::service('group_treasury.treasury_service')->getGroupsForTreasury($account)) {
    $personal = (int) $account->id();
  }
}

print 'LN_TREASURY=' . json_encode([
  'groups' => $groups,
  'safes' => $safes,
  'payments' => $payments,
  'steward' => (int) $steward->id(),
  'member' => (int) $member->id(),
  'personal' => $personal,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
