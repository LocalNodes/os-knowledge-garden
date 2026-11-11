<?php

/**
 * @file
 * Seeds the Phase 25 group governance states for the a11y run (plan 25-26).
 *
 * Run with: ddev drush php:script tests/a11y/seed-governance.php
 * (on the lane site, after tests/a11y/seed.php has made the personas
 * a11y-steward and a11y-member; global-setup.ts runs it first).
 *
 * Idempotent: rerunning finds the people, groups, funds, voting records and
 * proposal mirrors by name and only fills in what is missing, then writes
 * the synthetic chain facts again.
 *
 * The site runs the test-only fixture modules: safe_smart_accounts_rpc_fixture
 * (every Safe reads as the fixture Safe, owners LN_GOV_OWNER_A and _B,
 * threshold 2), safe_governance_chain_fixture (governance reads from the
 * SYNTHETIC facts written here), safe_governance_hub_fixture and
 * gas_sponsor_fixture. Page views read the proposal mirror only; nothing
 * here asks the voting service. All addresses are test values.
 *
 * Prints one machine-readable line, LN_GOVERNANCE={json}, with groups,
 * proposals and users (ids) and the fund address.
 */

use Drupal\Core\Cache\Cache;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\safe_governance\Codec\HatId;
use Drupal\safe_governance\Entity\GovernanceProposalInterface;
use Drupal\safe_governance\Entity\SafeGovernanceInterface;
use Drupal\safe_governance\Service\ProposalSync;
use Drupal\safe_governance\Snapshot\VotingStrategy;
use Drupal\safe_governance_chain_fixture\FixtureChainClient;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\safe_smart_accounts\Value\Address;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

const LN_GOV_GROUP_TYPE = 'flexible_group';
const LN_GOV_MANAGER_ROLE = 'flexible_group-group_manager';
// The RPC fixture Safe's owners (safe-base-getowners.json), lowercase.
const LN_GOV_OWNER_A = '0x3dd578a97f758366e6932c0cd9a3e5478629b337';
const LN_GOV_OWNER_B = '0xc3f45c10610aa25534f38040cc2a5db2bfc65890';
const LN_GOV_NETWORK = 'ethereum_sepolia';
const LN_GOV_TOP_HAT = '4242';
const LN_GOV_TIMEZONE = 'America/Denver';

/**
 * A stable checksummed test address for a key.
 */
function ln_gov_address(string $key): string {
  return Address::checksum('0x' . substr(hash('sha256', 'a11y-25-26-' . $key), 0, 40));
}

/**
 * A stable 32-byte test id for a key.
 */
function ln_gov_hash(string $key): string {
  return '0x' . hash('sha256', 'a11y-25-26-hash-' . $key);
}

/**
 * Loads or creates a persona, with a display name, timezone and wallet.
 */
function ln_gov_user(string $name, string $first, string $last, ?string $wallet, array $roles = []): UserInterface {
  $found = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
  $account = $found ? reset($found) : NULL;
  if (!$account instanceof UserInterface) {
    $account = User::create([
      'name' => $name,
      'mail' => $name . '@example.com',
      'pass' => \Drupal::service('password_generator')->generate(32),
      'status' => 1,
    ]);
  }
  foreach (array_merge(['verified'], $roles) as $role) {
    if (!$account->hasRole($role)) {
      $account->addRole($role);
    }
  }
  $account->set('timezone', LN_GOV_TIMEZONE);
  if ($wallet !== NULL && $account->hasField('field_ethereum_address')) {
    $account->set('field_ethereum_address', strtolower($wallet));
  }
  $account->activate();
  $account->save();

  $profiles = \Drupal::entityTypeManager()->getStorage('profile')
    ->loadByProperties(['uid' => $account->id(), 'type' => 'profile']);
  $profile = $profiles ? reset($profiles) : NULL;
  if ($profile && $profile->hasField('field_profile_first_name')
    && ($profile->get('field_profile_first_name')->value !== $first || $profile->get('field_profile_last_name')->value !== $last)) {
    $profile->set('field_profile_first_name', $first);
    $profile->set('field_profile_last_name', $last);
    $profile->save();
  }
  return $account;
}

/**
 * Loads a persona made by seed.php, or stops.
 */
function ln_gov_existing(string $name): UserInterface {
  $found = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]);
  $account = $found ? reset($found) : NULL;
  if (!$account instanceof UserInterface) {
    throw new \RuntimeException("Run tests/a11y/seed.php first: $name is missing.");
  }
  return $account;
}

/**
 * Loads or creates a group with its organizers and members.
 *
 * @param \Drupal\user\UserInterface[] $managers
 *   Group managers.
 * @param \Drupal\user\UserInterface[] $members
 *   Plain members.
 */
function ln_gov_group(string $label, array $managers, array $members, array $join = ['direct']): GroupInterface {
  $groups = \Drupal::entityTypeManager()->getStorage('group')
    ->loadByProperties(['type' => LN_GOV_GROUP_TYPE, 'label' => $label]);
  ksort($groups);
  $group = $groups ? reset($groups) : NULL;
  if (!$group instanceof GroupInterface) {
    $group = Group::create([
      'type' => LN_GOV_GROUP_TYPE,
      'label' => $label,
      'uid' => $managers[0]->id(),
      'field_flexible_group_visibility' => 'community',
      'field_group_allowed_visibility' => ['public', 'community', 'group'],
      'field_group_allowed_join_method' => $join,
    ]);
    $group->save();
  }
  foreach ($managers as $manager) {
    $membership = $group->getMember($manager);
    if (!$membership) {
      $group->addMember($manager, ['group_roles' => [LN_GOV_MANAGER_ROLE]]);
    }
    elseif (!array_key_exists(LN_GOV_MANAGER_ROLE, $membership->getRoles(FALSE))) {
      $membership->addRole(LN_GOV_MANAGER_ROLE);
    }
  }
  foreach ($members as $member) {
    if (!$group->getMember($member)) {
      $group->addMember($member);
    }
  }
  return $group;
}

/**
 * Loads or creates the group's fund (active, the fixture Safe's owners).
 */
function ln_gov_fund(GroupInterface $group, UserInterface $owner, string $key): SafeAccountInterface {
  $treasury = \Drupal::service('group_treasury.treasury_service');
  $safe = $treasury->getTreasury($group);
  if (!$safe instanceof SafeAccountInterface) {
    $safe = \Drupal::entityTypeManager()->getStorage('safe_account')->create([
      'user_id' => $owner->id(),
      'network' => LN_GOV_NETWORK,
      'threshold' => 2,
      'status' => 'active',
      'safe_address' => ln_gov_address('fund-' . $key),
      'deployed_at' => \Drupal::time()->getRequestTime() - 86400,
    ]);
    $safe->save();
    $treasury->addTreasury($group, $safe);
  }
  $configurations = \Drupal::entityTypeManager()->getStorage('safe_configuration')
    ->loadByProperties(['safe_account_id' => $safe->id()]);
  if (!$configurations) {
    \Drupal::entityTypeManager()->getStorage('safe_configuration')->create([
      'id' => 'safe_' . $safe->id(),
      'label' => 'Safe ' . $safe->id(),
      'safe_account_id' => (int) $safe->id(),
      'signers' => [Address::checksum(LN_GOV_OWNER_A), Address::checksum(LN_GOV_OWNER_B)],
      'threshold' => 2,
      'salt_nonce' => 2500 + (int) $safe->id(),
    ])->save();
  }
  return $safe;
}

/**
 * Loads or creates a fund's voting record with the given values.
 *
 * @param array<string, mixed> $values
 *   Field values over a ready record under the proposer-role rule.
 */
function ln_gov_record(SafeAccountInterface $safe, string $key, array $values = []): SafeGovernanceInterface {
  $storage = \Drupal::entityTypeManager()->getStorage('safe_governance');
  $found = $storage->loadByProperties(['safe_account' => $safe->id()]);
  $record = $found ? reset($found) : NULL;
  if ($record instanceof SafeGovernanceInterface) {
    return $record;
  }
  $member_hat = HatId::member(LN_GOV_TOP_HAT);
  $record = $storage->create($values + [
    'safe_account' => $safe->id(),
    'network' => LN_GOV_NETWORK,
    'space' => $key . '.eth',
    'quorum_votes' => 5,
    'voting_period' => 259200,
    'proposer_rule' => SafeGovernanceInterface::PROPOSER_HAT,
    'reality_timeout' => 3600,
    'new_member_votes' => TRUE,
    'status' => SafeGovernanceInterface::STATUS_READY,
    'module' => ln_gov_address('module-' . $key),
    'arbitrator' => ln_gov_address('arbitrator-' . $key),
    'top_hat' => HatId::toUint(LN_GOV_TOP_HAT),
    'member_hat' => HatId::toUint($member_hat),
    'proposer_hat' => HatId::toUint(HatId::proposer(LN_GOV_TOP_HAT)),
    'voting_strategy' => VotingStrategy::memberHat(\Drupal::service('safe_governance.contracts')->get(LN_GOV_NETWORK, 'hats'), $member_hat),
  ]);
  $record->save();
  return $record;
}

/**
 * Loads or creates a proposal mirror by title, with times from now.
 *
 * @param array<string, mixed> $values
 *   Field values over an open decision with no votes.
 */
function ln_gov_proposal(SafeGovernanceInterface $record, string $title, array $values = []): GovernanceProposalInterface {
  $storage = \Drupal::entityTypeManager()->getStorage('governance_proposal');
  $found = $storage->loadByProperties(['governance' => $record->id(), 'title' => $title]);
  $proposal = $found ? reset($found) : NULL;
  $now = \Drupal::time()->getRequestTime();
  $values += [
    'kind' => GovernanceProposalInterface::KIND_DECISION,
    'body' => 'What happens if it passes: ' . $title . '.',
    'author' => LN_GOV_OWNER_A,
    'voting_start' => $now - 3600,
    'voting_end' => $now + 3 * 86400,
    'snapshot_block' => '100',
    'choices' => ['For', 'Against', 'Abstain'],
    'hub_state' => 'active',
    'scores_state' => 'pending',
    'scores' => ['3', '1', '1'],
    'scores_total' => '5',
    'quorum' => '5',
    'read_at' => $now,
  ];
  if (!$proposal instanceof GovernanceProposalInterface) {
    $proposal = $storage->create($values + [
      'governance' => $record->id(),
      'hub_id' => ln_gov_hash('proposal-' . $record->id() . '-' . $title),
      'title' => $title,
    ]);
  }
  else {
    // Times move with the clock, so an open proposal stays open.
    foreach ($values as $field => $value) {
      $proposal->set($field, $value);
    }
  }
  $proposal->save();
  return $proposal;
}

/**
 * Records a list read of the group's proposals just now.
 */
function ln_gov_listed(SafeGovernanceInterface $record, ?int $at = NULL, bool $failed = FALSE): void {
  \Drupal::keyValue(ProposalSync::COLLECTION)->set((string) $record->id(), [
    'listed_at' => $at ?? \Drupal::time()->getRequestTime(),
    'failed' => $failed,
  ]);
  Cache::invalidateTags([ProposalSync::CACHE_TAG_PREFIX . $record->id()]);
}

$steward = ln_gov_existing('a11y-steward');
$member = ln_gov_existing('a11y-member');
// The steward's linked wallet is the fixture Safe's first owner, so an
// approver (as seed-treasury.php sets it). siwe_login stores it lowercase.
if ($steward->get('field_ethereum_address')->value !== LN_GOV_OWNER_A || $steward->get('timezone')->value !== LN_GOV_TIMEZONE) {
  $steward->set('field_ethereum_address', LN_GOV_OWNER_A);
  $steward->set('timezone', LN_GOV_TIMEZONE);
  $steward->save();
}
// The voting record's fund reference is checked against the saving user's
// access (25-17 item 5): the seed saves as the fund's organizer.
\Drupal::service('account_switcher')->switchTo($steward);

$voter = ln_gov_user('a11y-voter', 'Ana', 'Ruiz', ln_gov_address('voter'));
$recipient = ln_gov_user('a11y-recipient', 'Sam', 'Lee', ln_gov_address('recipient'));

$newcomer = ln_gov_user('a11y-newcomer', 'Kai', 'Moreno', ln_gov_address('newcomer'));
$leaver = ln_gov_user('a11y-leaver', 'Lou', 'Park', ln_gov_address('leaver'));
$sitemanager = ln_gov_user('a11y-sitemanager', 'Robin', 'Hale', NULL, ['sitemanager']);
$now = \Drupal::time()->getRequestTime();

$groups = [];
$proposals = [];

// "A11y Votes": voting is set up, with proposals in every state.
$ready_group = ln_gov_group('A11y Votes', [$steward], [$member, $voter, $recipient, $newcomer]);
$groups['ready'] = (int) $ready_group->id();
$ready_fund = ln_gov_fund($ready_group, $steward, 'ready');
$ready = ln_gov_record($ready_fund, 'a11y-votes');
$recipient_address = Address::checksum($recipient->get('field_ethereum_address')->value);
$payment = static fn(string $to, string $value = '20000000000000000'): array => [
  'kind' => GovernanceProposalInterface::KIND_PAYMENT,
  'module_tx' => ['to' => $to, 'value' => $value, 'data' => '0x', 'operation' => 0],
];
$final = static fn(array $scores, int $ended_ago = 7200): array => [
  'voting_start' => $now - 3 * 86400 - $ended_ago,
  'voting_end' => $now - $ended_ago,
  'hub_state' => 'closed',
  'scores_state' => 'final',
  'scores' => $scores,
  'scores_total' => (string) array_sum(array_map('intval', $scores)),
];
// A payment that passed and is being carried out here (a stored tx hash).
$carry = static fn(string $title, string $state, array $execution = []) => ln_gov_proposal($ready, $title, $payment($recipient_address) + $final(['6', '1', '1']) + [
  'body' => 'A payment that passed: ' . $title . '.',
  'tx_hash' => ln_gov_hash('tx-' . $title),
  'execution_state' => $state,
  'execution' => $execution,
]);

$open_payment = ln_gov_proposal($ready, 'Tools for the shared garden', $payment($recipient_address) + [
  'body' => 'Two spades, a wheelbarrow and gloves for the Saturday work days.',
]);
// Nobody has voted on it yet (the keyboard run casts a vote here).
$open_payment->setVotes([])->save();
$proposals['open_payment'] = (int) $open_payment->id();
// The voter already voted For on the open decision (V3 "You voted For").
$voted = ln_gov_proposal($ready, 'Move the monthly meeting to Thursdays', [
  'body' => 'Thursday evenings suit more of us than Mondays.',
  'voting_end' => $now + 2 * 3600,
]);
$voted->setVotes([strtolower(ln_gov_address('voter')) => ['choice' => 1, 'created' => $now - 1800, 'receipt' => '', 'confirmed' => TRUE]])->save();
$proposals['open_decision'] = (int) $voted->id();
$proposals['counting'] = (int) ln_gov_proposal($ready, 'Plant a hedge along the fence', [
  'voting_start' => $now - 3 * 86400 - 30,
  'voting_end' => $now - 30,
  'hub_state' => 'closed',
  'scores_state' => 'pending',
])->id();
$proposals['passed_decision'] = (int) ln_gov_proposal($ready, 'Start a seed library', $final(['5', '1', '0']))->id();
$proposals['not_passed'] = (int) ln_gov_proposal($ready, 'Paint the shed orange', $final(['1', '1', '1']))->id();
$proposals['outside'] = (int) ln_gov_proposal($ready, 'Pay the soil test lab', $payment(ln_gov_address('outside-lab')) + [
  'body' => 'The lab tests our beds for lead before the spring planting.',
])->id();
$proposals['reporting'] = (int) $carry('Rent the hall for the seed swap', 'reporting')->id();
$proposals['safety_check'] = (int) $carry('Buy compost for the beds', 'safety_check', ['finalize_ts' => $now + 1800])->id();
$proposals['sending'] = (int) $carry('Pay for the rain barrels', 'sending', ['execute_hash' => ln_gov_hash('execute-sending')])->id();
$proposals['carried_out'] = (int) $carry('Fix the tool shed door', 'carried_out', [
  'execute_hash' => ln_gov_hash('execute-done'),
  'carried_out_at' => $now - 3600,
])->id();
$proposals['failed'] = (int) $carry('Order the fruit trees', 'failed', ['execute_hash' => ln_gov_hash('execute-failed'), 'reverted' => TRUE])->id();
$proposals['on_hold'] = (int) $carry('Buy a shared tiller', 'on_hold')->id();
$proposals['low_balance'] = (int) $carry('Hire the hedge trimmer', 'sending', ['detail' => 'low_balance', 'balance' => '10000000000000000', 'finalize_ts' => $now - 600])->id();
ln_gov_listed($ready);

// The queue on Approve new members: a newcomer selected by the
// new-members setting (D-33), a leaver with the proposer role in one row
// (D-28), and a proposer change selected by a group manager change (D-34).
$changes = \Drupal::entityTypeManager()->getStorage('governance_member_change');
$queue = [
  [ln_gov_address('newcomer'), 'add_member', 'new_members_setting', TRUE],
  [ln_gov_address('leaver'), 'remove_member_and_proposer', 'left', TRUE],
  [ln_gov_address('voter'), 'add_proposer', 'became_manager', TRUE],
  [ln_gov_address('stopped-signer'), 'remove_proposer', 'stopped_signer', TRUE],
];
foreach ($queue as [$address, $change, $reason, $selected]) {
  $existing = $changes->loadByProperties(['governance' => $ready->id(), 'address' => strtolower($address), 'change' => $change]);
  if (!$existing) {
    $changes->create([
      'governance' => $ready->id(),
      'address' => strtolower($address),
      'change' => $change,
      'reason' => $reason,
      'selected' => $selected,
      'status' => 'waiting',
      'created' => $now - 86400,
    ])->save();
  }
}

// "A11y Votes Quiet": set up, no proposals yet.
$quiet_group = ln_gov_group('A11y Votes Quiet', [$steward], [$member, $voter]);
$groups['quiet'] = (int) $quiet_group->id();
$quiet = ln_gov_record(ln_gov_fund($quiet_group, $steward, 'quiet'), 'a11y-quiet');

// "A11y Votes Setup": a request-to-join group with a fund, voting not set
// up (V6; the new-members box starts checked, D-33). "A11y Votes Open": an
// open group (the box starts unchecked).
$setup_group = ln_gov_group('A11y Votes Setup', [$steward], [$member, $voter], ['request']);
$groups['setup'] = (int) $setup_group->id();
ln_gov_fund($setup_group, $steward, 'setup');
$open_group = ln_gov_group('A11y Votes Open', [$steward], [$member], ['direct']);
$groups['open'] = (int) $open_group->id();
ln_gov_fund($open_group, $steward, 'open');

// "A11y Votes Progress": the organizers are approving the setup (V7).
$progress_group = ln_gov_group('A11y Votes Progress', [$steward], [$member]);
$groups['progress'] = (int) $progress_group->id();
$progress = ln_gov_record(ln_gov_fund($progress_group, $steward, 'progress'), 'a11y-progress', [
  'status' => SafeGovernanceInterface::STATUS_APPROVING,
]);

// "A11y Garden" (seed.php): no group fund.
$garden = \Drupal::entityTypeManager()->getStorage('group')
  ->loadByProperties(['type' => LN_GOV_GROUP_TYPE, 'label' => 'A11y Garden']);
ksort($garden);
$groups['no_fund'] = $garden ? (int) reset($garden)->id() : 0;

// The synthetic chain facts: the ready group's fund owns its voting setup;
// the voter, the steward and the fund's other owner wear the member role,
// the two owners the proposer role. The newcomer and the leaver don't.
$member_hat = (string) $ready->get('member_hat')->value;
$proposer_hat = (string) $ready->get('proposer_hat')->value;
$top_hat = (string) $ready->get('top_hat')->value;
\Drupal::state()->set(FixtureChainClient::FACTS_KEY, [
  'fund' => strtolower($ready_fund->getSafeAddress()),
  // Voting setup's approval messages read the next top hat id (V7).
  'last_top_hat' => 4241,
  'wearers' => [
    $top_hat => [strtolower($ready_fund->getSafeAddress())],
    $member_hat => [strtolower(ln_gov_address('voter')), LN_GOV_OWNER_A, LN_GOV_OWNER_B],
    $proposer_hat => [LN_GOV_OWNER_A, LN_GOV_OWNER_B],
  ],
]);

// The voting service answers the seeded groups' voting pages (no admins)
// for the organizers' host-power checks; page views read nothing else from
// it. The call log is emptied so it doesn't grow across runs.
\Drupal::state()->set('safe_governance_hub_fixture.scenario', 'a11y_ready');
\Drupal::state()->delete('safe_governance_hub_fixture.calls');

// Named states (`-- state <name>`), switched between scans. Each resets
// what the others change first.
$args = $extra ?? [];
$state = ($args[0] ?? '') === 'state' ? (string) ($args[1] ?? '') : 'base';
ln_gov_listed($ready);
ln_gov_listed($quiet);
if ($ready->getAlerts() !== []) {
  $ready->setAlerts([]);
  \Drupal::service('safe_governance.record_writer')->saveInBackground($ready, 'a11y seed: alerts cleared');
}
\Drupal::state()->set('gas_sponsor_fixture.scenario', 'configured');
$runner = \Drupal::service('safe_governance.membership_runner');
if ($runner->inProgress($ready) !== NULL || $runner->batchedRows($ready) !== []) {
  $runner->withdraw($ready, (int) $steward->id());
}
$progress->set('step_detail', NULL);
$progress->set('status', SafeGovernanceInterface::STATUS_APPROVING);
switch ($state) {
  case 'base':
    break;

  case 'hub_last_known':
    ln_gov_listed($ready, $now - 600, TRUE);
    break;

  case 'hub_never_read':
    \Drupal::keyValue(ProposalSync::COLLECTION)->set((string) $quiet->id(), ['listed_at' => NULL, 'failed' => TRUE]);
    break;

  case 'progress_name_taken':
  case 'progress_setup_failed':
  case 'progress_rules_refused':
  case 'progress_cap':
    $progress->set('step_detail', substr($state, strlen('progress_')));
    break;

  case 'batch':
    // V8 "Approvals in progress": the signer's proposer removal, batched by
    // the steward and not yet approved (withdrawn again by the next run).
    $rows = $changes->loadByProperties(['governance' => $ready->id(), 'change' => 'remove_proposer', 'status' => 'waiting']);
    $runner->propose($ready, array_keys($rows), (int) $steward->id());
    break;

  case 'alert_wrong_result':
  case 'alert_escalation':
  case 'alert_resolved':
    // V9 through the product's own path: the relayer's event feed (the
    // sponsor fixture's synthetic events, about this group's fund, module
    // and the on-hold payment) read by the poller, which stores the alert.
    $scenarios = [
      'alert_wrong_result' => 'events_challenged',
      'alert_escalation' => 'events_escalation',
      'alert_resolved' => 'events_resolved',
    ];
    $on_hold = \Drupal::entityTypeManager()->getStorage('governance_proposal')->load($proposals['on_hold']);
    \Drupal::state()->set('gas_sponsor_fixture.scenario', $scenarios[$state]);
    \Drupal::state()->set('gas_sponsor_fixture.event', [
      'safe' => $ready_fund->getSafeAddress(),
      'module' => (string) $ready->get('module')->value,
      'proposalId' => $on_hold->getHubId(),
      'chainId' => 11155111,
    ]);
    \Drupal::state()->delete('safe_governance.relayer_event_cursor');
    \Drupal::service('safe_governance.relayer_event_poller')->poll();
    \Drupal::state()->set('gas_sponsor_fixture.scenario', 'configured');
    break;

  default:
    throw new \InvalidArgumentException("Unknown state $state.");
}
$progress->save();
Cache::invalidateTags([
  'safe_governance:' . $ready->id(),
  'safe_governance:' . $quiet->id(),
  'safe_governance:' . $progress->id(),
  ProposalSync::CACHE_TAG_PREFIX . $ready->id(),
  ProposalSync::CACHE_TAG_PREFIX . $quiet->id(),
]);
foreach ($groups as $id) {
  Cache::invalidateTags(['group:' . $id]);
}

print 'LN_GOVERNANCE=' . json_encode([
  'groups' => $groups,
  'proposals' => $proposals,
  'users' => [
    'steward' => (int) $steward->id(),
    'member' => (int) $member->id(),
    'voter' => (int) $voter->id(),
    'recipient' => (int) $recipient->id(),
    'newcomer' => (int) $newcomer->id(),
    'leaver' => (int) $leaver->id(),
    'sitemanager' => (int) $sitemanager->id(),
  ],
  'wallets' => [
    'voter' => strtolower(ln_gov_address('voter')),
    'newcomer' => strtolower(ln_gov_address('newcomer')),
    'steward' => LN_GOV_OWNER_A,
  ],
  'fund' => $ready_fund->getSafeAddress(),
]) . PHP_EOL;
