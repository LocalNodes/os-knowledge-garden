<?php

/**
 * @file
 * Seeds fictional Groups and personas for the Group template specs.
 *
 * Run with: ddev drush php:script tests/a11y/seed-group-templates.php [-- <mode> <key>]
 *
 * Modes:
 * - `journey <key>` (the default, key "base"): the personas, Group A and
 *   Group B, nothing set up and nobody on a roster. The browser journey
 *   (group-templates.spec.ts) does the rest through the screens.
 * - `states <key>`: the same personas and Groups, plus two more Groups built
 *   through the modules' own services for the accessibility spec: Group C
 *   set up, with its roster and a decision in each state, and a meeting
 *   report; Group D set up with an empty register.
 *
 * Idempotent: the same mode and key find the same accounts and Groups and
 * print the same JSON; a new key gives new Groups, so a spec run can start
 * from a Group that is not set up yet. Nothing is deleted.
 *
 * The Group type and its manager role are read from
 * localnodes_group_setup.settings, and the template from the installed
 * localnodes_group_setup.template.* config, so this file names none of them.
 * All names are fictional; every account gets a random password.
 *
 * Prints one machine-readable line: LN_GROUP_TEMPLATES={json}.
 */

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

$args = $extra ?? [];
$mode = in_array($args[0] ?? '', ['journey', 'states'], TRUE) ? $args[0] : 'journey';
$key = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($args[1] ?? 'base')) ?: 'base';

$types = \Drupal::config('localnodes_group_setup.settings')->get('group_types');
if (!is_array($types) || $types === []) {
  fwrite(STDERR, "localnodes_group_setup.settings lists no group type.\n");
  exit(1);
}
$group_type = (string) array_key_first($types);
$manager_role = (string) reset($types);

/**
 * Loads a fictional account by name, creating it with a random password.
 */
function gt_user(string $name, string $first, string $last, array $roles = []): UserInterface {
  $storage = \Drupal::entityTypeManager()->getStorage('user');
  $found = $storage->loadByProperties(['name' => $name]);
  $account = $found ? reset($found) : User::create([
    'name' => $name,
    'mail' => $name . '@example.com',
    'pass' => \Drupal::service('password_generator')->generate(32),
    'status' => 1,
  ]);
  if (!$account->isActive()) {
    $account->activate();
  }
  foreach (['verified', ...$roles] as $role) {
    if (!$account->hasRole($role)) {
      $account->addRole($role);
    }
  }
  $account->save();
  $profiles = \Drupal::entityTypeManager()->getStorage('profile')->loadByProperties(['uid' => $account->id(), 'type' => 'profile']);
  $profile = $profiles ? reset($profiles) : NULL;
  if ($profile && $profile->hasField('field_profile_first_name')
    && ($profile->get('field_profile_first_name')->value !== $first || $profile->get('field_profile_last_name')->value !== $last)) {
    $profile->set('field_profile_first_name', $first);
    $profile->set('field_profile_last_name', $last);
    $profile->save();
  }
  return User::load($account->id());
}

/**
 * Loads a Group by label (oldest first), creating it if needed.
 */
function gt_group(string $type, string $label, UserInterface $owner, string $own, array $allowed): GroupInterface {
  $found = \Drupal::entityTypeManager()->getStorage('group')->loadByProperties(['type' => $type, 'label' => $label]);
  ksort($found);
  $group = $found ? reset($found) : NULL;
  if (!$group) {
    $group = Group::create([
      'type' => $type,
      'label' => $label,
      'uid' => $owner->id(),
      'field_flexible_group_visibility' => $own,
      'field_group_allowed_visibility' => array_map(fn (string $value) => ['value' => $value], $allowed),
      'field_group_allowed_join_method' => 'added',
    ]);
    $group->save();
  }
  return $group;
}

/**
 * Makes an account a member of a Group, with the given Group roles.
 */
function gt_member(GroupInterface $group, UserInterface $account, array $roles = []): void {
  $membership = $group->getMember($account);
  if (!$membership) {
    $group->addMember($account, $roles ? ['group_roles' => $roles] : []);
    return;
  }
  $have = array_keys($membership->getRoles(FALSE));
  foreach ($roles as $role) {
    if (!in_array($role, $have, TRUE)) {
      $membership->addRole($role);
    }
  }
}

// The personas. Display names are what the screens show.
$people = [
  'organizer' => ['gt-organizer', 'Marisol', 'Ferro'],
  'roster01' => ['gt-roster-01', 'Anika', 'Brandt'],
  'roster02' => ['gt-roster-02', 'Basil', 'Corwin'],
  'roster03' => ['gt-roster-03', 'Celia', 'Dunmore'],
  'roster04' => ['gt-roster-04', 'Dorian', 'Ellery'],
  'roster05' => ['gt-roster-05', 'Esme', 'Fairweather'],
  'roster06' => ['gt-roster-06', 'Felix', 'Garnet'],
  'roster07' => ['gt-roster-07', 'Greta', 'Holloway'],
  'roster08' => ['gt-roster-08', 'Hugo', 'Ingram'],
  'roster09' => ['gt-roster-09', 'Iris', 'Jessup'],
  'roster10' => ['gt-roster-10', 'Jonah', 'Kestrel'],
  'roster11' => ['gt-roster-11', 'Kira', 'Lindqvist'],
  'reader' => ['gt-reader', 'Tobin', 'Ashe'],
  'comanager' => ['gt-comanager', 'Wren', 'Okafor'],
  'bmember' => ['gt-b-member', 'Dara', 'Quill'],
  'outsider' => ['gt-outsider', 'Ellis', 'Moor'],
  'sitemanager' => ['gt-sitemanager', 'Pell', 'Navarro'],
];
$accounts = [];
$users = [];
foreach ($people as $persona => [$name, $first, $last]) {
  $accounts[$persona] = gt_user($name, $first, $last, $persona === 'sitemanager' ? ['sitemanager'] : []);
  $users[$persona] = [
    'uid' => (int) $accounts[$persona]->id(),
    'name' => $name,
    'display' => (string) $accounts[$persona]->getDisplayName(),
  ];
}
$roster_keys = ['organizer', ...array_map(fn (int $n) => sprintf('roster%02d', $n), range(1, 11))];

/**
 * Group A's shape: the organizer manages it, twelve roster-to-be members, an
 * off-roster member and an off-roster manager. It allows all three readers.
 */
$group_a = static function (string $label) use ($group_type, $manager_role, $accounts, $roster_keys): GroupInterface {
  $group = gt_group($group_type, $label, $accounts['organizer'], 'public', ['public', 'community', 'group']);
  gt_member($group, $accounts['organizer'], [$manager_role]);
  foreach ($roster_keys as $persona) {
    gt_member($group, $accounts[$persona]);
  }
  gt_member($group, $accounts['reader']);
  gt_member($group, $accounts['comanager'], [$manager_role]);
  return $group;
};

$suffix = $key === 'base' ? '' : " $key";
$a = $group_a("Willow Court$suffix");
$b = gt_group($group_type, "Juniper Row$suffix", $accounts['bmember'], 'members', ['group']);
gt_member($b, $accounts['bmember']);

$out = [
  'mode' => $mode,
  'key' => $key,
  'groups' => ['A' => (int) $a->id(), 'B' => (int) $b->id()],
  'group_labels' => ['A' => (string) $a->label(), 'B' => (string) $b->label()],
  'users' => $users,
  'roster' => $roster_keys,
];

if ($mode === 'states') {
  $state_key = "localnodes_gt_seed.states.$key";
  $saved = \Drupal::state()->get($state_key);
  if (is_array($saved) && \Drupal::entityTypeManager()->getStorage('group')->load($saved['groups']['C'] ?? 0)) {
    $out = $saved;
  }
  else {
    $templates = \Drupal::service('config.storage')->listAll('localnodes_group_setup.template.');
    $template = substr((string) reset($templates), strlen('localnodes_group_setup.template.'));
    $applications = \Drupal::service('localnodes_group_setup.application_manager');
    $roster = \Drupal::service('localnodes_consensus.roster');
    $lifecycle = \Drupal::service('localnodes_consensus.round_lifecycle');
    $meetings = \Drupal::service('localnodes_consensus.meeting_records');
    $organizer = $accounts['organizer'];

    $c = $group_a("Hazel Yard$suffix");
    $d = $group_a("Sorrel Gate$suffix");
    foreach ([$c, $d] as $group) {
      if ($applications->forGroup($group) === NULL) {
        $applications->confirm($group, $template, [], FALSE, $organizer);
      }
    }
    foreach ($roster_keys as $persona) {
      $roster->approve($c, $accounts[$persona], $organizer, 'Lives here');
    }
    $c = Group::load($c->id());
    $first = $accounts['roster01'];
    $rounds = [];
    $rounds['open_money'] = $lifecycle->open($c, $first, 'money', "Replace the porch light\nA brighter, motion-sensing light by the front steps.", [
      'visibility' => 'group',
      'amount_up_to_cents' => 18000,
    ]);
    $rounds['blocked'] = $lifecycle->open($c, $first, 'everyday', "Move the bike rack\nTo the side yard, out of the walkway.", ['visibility' => 'group']);
    $lifecycle->respond($rounds['blocked'], $accounts['roster02'], 'block', 'The side yard floods every spring.');
    $rounds['agreed'] = $lifecycle->open($c, $first, 'everyday', "Plant the herb bed\nBasil, thyme and mint along the south fence.", ['visibility' => 'group']);
    foreach ($roster_keys as $persona) {
      $lifecycle->respond(\Drupal::entityTypeManager()->getStorage('localnodes_consensus_round')->loadUnchanged($rounds['agreed']->id()), $accounts[$persona], 'agree', NULL);
    }
    // Every roster member but the last has agreed: the last one sees the
    // close-on-agree line.
    $rounds['last_one'] = $lifecycle->open($c, $first, 'everyday', "Share the snow shovels\nKeep both by the back door all winter.", ['visibility' => 'group']);
    foreach (array_slice($roster_keys, 0, -1) as $persona) {
      $lifecycle->respond(\Drupal::entityTypeManager()->getStorage('localnodes_consensus_round')->loadUnchanged($rounds['last_one']->id()), $accounts[$persona], 'agree', NULL);
    }
    $rounds['replaced'] = $lifecycle->open($c, $first, 'money', "Buy a shared ladder\nAn eight-foot step ladder for the shed.", [
      'visibility' => 'group',
      'amount_up_to_cents' => 12000,
    ]);
    $rounds['successor'] = $lifecycle->replace($rounds['replaced'], "Buy a shared ladder\nA six-foot step ladder fits the shed better.", $first, 'The shed door is too low.', ['amount_up_to_cents' => 12000]);
    $rounds['rule_change'] = $lifecycle->open($c, $first, 'rule_change', "Give everyday decisions ten days\nMore time for people who travel.", [
      'visibility' => 'group',
      'proposed_rules' => [
        'path' => 'advanced',
        'everyday' => ['method' => 'consensus', 'quorum_numerator' => 2, 'quorum_denominator' => 3, 'threshold_numerator' => NULL, 'threshold_denominator' => NULL, 'response_days' => 10],
        'money' => ['method' => 'consensus', 'quorum_numerator' => 2, 'quorum_denominator' => 3, 'threshold_numerator' => NULL, 'threshold_denominator' => NULL, 'response_days' => 7],
        'rule_change' => ['method' => 'consensus', 'quorum_numerator' => 2, 'quorum_denominator' => 3, 'threshold_numerator' => NULL, 'threshold_denominator' => NULL, 'response_days' => 14],
      ],
    ]);
    // Ended and waiting for its result: the deadline is moved back, as the
    // Functional suite does, and the sweeper has not run yet. A spec that
    // runs cron must not rely on this state afterwards.
    $rounds['ended'] = $lifecycle->open($c, $first, 'everyday', "Paint the mailbox\nDark green, to match the door.", ['visibility' => 'group']);
    \Drupal::database()->update('localnodes_consensus_round')
      ->fields(['deadline' => \Drupal::time()->getRequestTime() - 60])
      ->condition('id', $rounds['ended']->id())
      ->execute();
    $storage = \Drupal::entityTypeManager()->getStorage('localnodes_consensus_round');
    $storage->resetCache();
    \Drupal::service('cache_tags.invalidator')->invalidateTags($rounds['ended']->getCacheTags());
    $report = $meetings->record($c, $organizer, [
      'title' => 'Hold a spring work day',
      'decision_text' => 'Everyone who can comes on the second Saturday in April.',
      'meeting_date' => '2026-11-01',
      'minutes' => 'Notes from the November meeting, kept in the library.',
      'deciding_body' => 'The monthly meeting',
      'method' => 'Consensus at the meeting',
      'confirmer' => (int) $accounts['roster03']->id(),
      'visibility' => 'group',
    ]);
    $out['groups']['C'] = (int) $c->id();
    $out['groups']['D'] = (int) $d->id();
    $out['group_labels']['C'] = (string) $c->label();
    $out['group_labels']['D'] = (string) $d->label();
    $out['rounds'] = array_map(fn ($round) => (int) $round->id(), $rounds);
    $out['report'] = (int) $report->id();
    \Drupal::state()->set($state_key, $out);
  }
}

print 'LN_GROUP_TEMPLATES=' . json_encode($out) . "\n";
