<?php

/**
 * @file
 * Place-based check of the four Open Social 13.1 group features (22.2 SC2).
 *
 * Phase 22.2 criterion 2 wants four group features on for every community.
 * GroupFeatureDefaultsTest proves them for an online-only community built in
 * the test (D-06). This script checks the same four on a place-based demo
 * community after a fresh install with demo content (localnodes_demo here,
 * boulder_demo in plan 22.2-05):
 * - group affiliation is on for flexible_group, and a member of the demo
 *   group gets it as an option on their profile form;
 * - group managers can customise the "Request to join" message;
 * - custom-link online events are available (meeting_api_manual and the
 *   custom_link meeting type);
 * - the flexible_group manager role has the four 13.1 moderation permissions,
 *   and in the demo group a manager can update and delete a member's topic
 *   while a plain member can't.
 *
 * "Place-based" group: the demo groups carry no address or location of their
 * own, so the first flexible group (lowest id) with a group address or
 * location is used, else the first one holding an event with an address.
 *
 * Accounts for the access check must hold no site-wide role beyond
 * verified, so the result comes from the group permissions alone. When the
 * demo has no such manager or plain member in the group, the script creates
 * temporary accounts with those memberships and deletes them before it ends.
 *
 * It prints one "PASS <check>" or "FAIL <check>: <why>" line per check, and
 * counts and ids only, never content text.
 *
 * Usage:
 *   ddev drush php:script html/modules/custom/localnodes_platform/scripts/check_group_features.php
 *
 * Exit 0: every line passed. Exit 1: at least one FAIL line.
 */

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Runs the checks and prints the lines.
 */
final class CheckGroupFeatures {

  /**
   * The group type every community uses.
   */
  private const GROUP_TYPE = 'flexible_group';

  /**
   * The flexible_group manager role.
   */
  private const MANAGER_ROLE = 'flexible_group-group_manager';

  /**
   * The 13.1 moderation permissions on the manager role (RESEARCH §6).
   */
  private const MANAGER_PERMISSIONS = [
    'edit any post entities in group',
    'delete any post entities in group',
    'update any group_node:topic entity',
    'update any group_node:event entity',
  ];

  /**
   * Site roles an access-check account may hold.
   */
  private const PLAIN_ROLES = ['authenticated', 'verified'];

  /**
   * Number of FAIL lines printed.
   */
  private int $failures = 0;

  /**
   * Temporary accounts to delete at the end, each with its group.
   *
   * @var array<int, array{0: \Drupal\user\UserInterface, 1: \Drupal\group\Entity\GroupInterface}>
   */
  private array $temporary = [];

  /**
   * Runs every check; returns the exit code.
   */
  public function run(): int {
    $users_before = $this->countUsers();
    try {
      $this->checkSettings();
      $this->checkOnlineEvents();
      $this->checkManagerRole();
      $this->checkPlaceBasedGroup();
    }
    catch (\Throwable $e) {
      $this->fail('script', 'stopped with ' . get_class($e) . ' at line ' . $e->getLine());
    }
    finally {
      $this->deleteTemporaryAccounts();
    }
    $users_after = $this->countUsers();
    $this->line($users_before === $users_after, 'no temporary account left', "users before $users_before, after $users_after");
    print "users before=$users_before after=$users_after\n";

    return $this->failures === 0 ? 0 : 1;
  }

  /**
   * Affiliation and request-to-join settings.
   */
  private function checkSettings(): void {
    $type = \Drupal::entityTypeManager()->getStorage('group_type')->load(self::GROUP_TYPE);
    $affiliation = $type?->getThirdPartySetting('social_profile', 'affiliation_enabled');
    $this->line($affiliation === TRUE, 'flexible_group affiliation_enabled', 'value is ' . var_export($affiliation, TRUE));

    $allow = \Drupal::config('social_group_request.settings')->get('allow_customize.' . self::GROUP_TYPE);
    $this->line($allow === TRUE, 'social_group_request allow_customize.flexible_group', 'value is ' . var_export($allow, TRUE));
  }

  /**
   * Custom-link online events.
   */
  private function checkOnlineEvents(): void {
    $this->line(\Drupal::moduleHandler()->moduleExists('meeting_api_manual'), 'meeting_api_manual enabled', 'module is off');

    $type = \Drupal::entityTypeManager()->getStorage('meeting_api_meeting_type')->load('custom_link');
    $server = $type?->get('server_id');
    $this->line($server === 'manual_url', 'custom_link meeting type exists', $type ? "server is $server" : 'type missing');
  }

  /**
   * The four moderation permissions on the manager role.
   */
  private function checkManagerRole(): void {
    $role = \Drupal::entityTypeManager()->getStorage('group_role')->load(self::MANAGER_ROLE);
    $missing = $role ? array_values(array_diff(self::MANAGER_PERMISSIONS, $role->getPermissions())) : self::MANAGER_PERMISSIONS;
    $this->line($missing === [], 'flexible_group manager role has the 4 moderation permissions', $role ? 'missing: ' . implode(', ', $missing) : 'role missing');
  }

  /**
   * Moderation access and the affiliation option in a place-based group.
   */
  private function checkPlaceBasedGroup(): void {
    [$group, $how] = $this->findPlaceBasedGroup();
    if (!$group) {
      $this->fail('place-based demo group found', 'no flexible group with an address, a location or an event with an address');
      return;
    }
    $this->pass("place-based demo group found (group {$group->id()}, $how)");

    $members = [];
    foreach ($group->getMembers() as $membership) {
      $members[(int) $membership->getUser()->id()] = $membership;
    }

    // A member's topic in the group.
    $topic = NULL;
    foreach ($group->getRelationships('group_node:topic') as $relationship) {
      assert($relationship instanceof GroupRelationshipInterface);
      $node = $relationship->getEntity();
      if ($node instanceof NodeInterface && isset($members[(int) $node->getOwnerId()])) {
        $topic = $node;
        break;
      }
    }
    if (!$topic) {
      $this->fail('member topic in the group', "group {$group->id()} has no topic by one of its members");
      return;
    }
    $author = (int) $topic->getOwnerId();
    $this->pass("member topic in the group (topic {$topic->id()}, author {$author})");

    // A manager and a plain member with no site-wide role beyond verified.
    $manager = NULL;
    $plain = NULL;
    foreach ($members as $uid => $membership) {
      $user = $membership->getUser();
      if (!$this->hasPlainRoles($user)) {
        continue;
      }
      $group_roles = array_keys($membership->getRoles(FALSE));
      if (!$manager && in_array(self::MANAGER_ROLE, $group_roles, TRUE)) {
        $manager = $user;
      }
      elseif (!$plain && $group_roles === [] && $uid !== $author) {
        $plain = $user;
      }
    }
    $made = [];
    if (!$manager) {
      $manager = $this->temporaryAccount($group, [self::MANAGER_ROLE]);
      $made[] = 'manager';
    }
    if (!$plain) {
      $plain = $this->temporaryAccount($group, []);
      $made[] = 'member';
    }
    print 'INFO accounts: manager ' . $manager->id() . ', plain member ' . $plain->id()
      . ($made ? ' (temporary: ' . implode(', ', $made) . ')' : ' (from the demo)') . "\n";

    $this->checkAccess($topic, $manager, TRUE, 'group manager');
    $this->checkAccess($topic, $plain, FALSE, 'plain member');

    // The affiliation option on the topic author's profile form.
    $owner = User::load($author);
    $options = $owner ? $this->affiliationOptions($owner) : NULL;
    if ($options === NULL) {
      $this->fail('profile form offers the group as an affiliation', "no profile form affiliation field for user $author");
      return;
    }
    $this->line(isset($options[(int) $group->id()]), "profile form offers the group as an affiliation (user $author)", count($options) . " group options, group {$group->id()} not among them");
  }

  /**
   * Update and delete access on the topic for one account.
   */
  private function checkAccess(NodeInterface $topic, AccountInterface $account, bool $expected, string $who): void {
    $handler = \Drupal::entityTypeManager()->getAccessControlHandler('node');
    $handler->resetCache();
    foreach (['update', 'delete'] as $operation) {
      $result = $topic->access($operation, $account);
      $word = $expected ? 'TRUE' : 'FALSE';
      $this->line($result === $expected, "$who access('$operation') on a member's topic is $word (user {$account->id()})", 'got ' . var_export($result, TRUE));
    }
  }

  /**
   * The first flexible group with a place, and how it was found.
   *
   * @return array{0: ?\Drupal\group\Entity\GroupInterface, 1: string}
   *   The group (or NULL) and a short reason.
   */
  private function findPlaceBasedGroup(): array {
    $storage = \Drupal::entityTypeManager()->getStorage('group');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::GROUP_TYPE)
      ->sort('id')
      ->execute();
    $groups = $storage->loadMultiple($ids);

    foreach ($groups as $group) {
      assert($group instanceof GroupInterface);
      foreach (['field_group_address' => 'group address', 'field_group_location' => 'group location'] as $field => $how) {
        if ($group->hasField($field) && !$group->get($field)->isEmpty()) {
          return [$group, $how];
        }
      }
    }
    foreach ($groups as $group) {
      foreach ($group->getRelationships('group_node:event') as $relationship) {
        $event = $relationship->getEntity();
        if ($event instanceof NodeInterface && $event->hasField('field_event_address') && !$event->get('field_event_address')->isEmpty()) {
          return [$group, "event {$event->id()} has an address"];
        }
      }
    }
    return [NULL, ''];
  }

  /**
   * Whether an account holds no site role beyond verified.
   */
  private function hasPlainRoles(AccountInterface $account): bool {
    return array_diff($account->getRoles(), self::PLAIN_ROLES) === [];
  }

  /**
   * Creates a temporary verified account with a membership in the group.
   */
  private function temporaryAccount(GroupInterface $group, array $group_roles): UserInterface {
    $name = 'p222_check_' . bin2hex(random_bytes(4));
    $user = User::create([
      'name' => $name,
      'mail' => $name . '@example.invalid',
      'status' => 1,
      'roles' => ['verified'],
    ]);
    $user->save();
    $this->temporary[] = [$user, $group];
    $group->addMember($user, ['group_roles' => $group_roles]);
    return $user;
  }

  /**
   * Deletes the temporary accounts, memberships first.
   *
   * The memberships go before the account: deleting a user that still has
   * one re-saves the deleted user from Group's relationship postDelete(), and
   * Open Social's user_presave hook (SocialGroupEntityAlter) then asserts on
   * a missing $user->original.
   */
  private function deleteTemporaryAccounts(): void {
    foreach ($this->temporary as [$user, $group]) {
      $id = $user->id();
      try {
        $group->removeMember($user);
        $user->delete();
        print "INFO deleted temporary account $id\n";
      }
      catch (\Throwable $e) {
        $this->fail("delete temporary account $id", get_class($e) . ' at line ' . $e->getLine());
      }
    }
    $this->temporary = [];
  }

  /**
   * Group options the profile form's affiliation field offers a user.
   *
   * Builds the user's profile form, the same widget
   * (GroupAffiliationWidget) and option lookup a member sees on
   * /user/{uid}/profile.
   *
   * @return array<int, true>|null
   *   Offered group ids, or NULL when the field or the profile is missing.
   */
  private function affiliationOptions(UserInterface $user): ?array {
    $profile = \Drupal::entityTypeManager()->getStorage('profile')->loadByUser($user, 'profile');
    if (!$profile) {
      return NULL;
    }
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($user);
    try {
      $form = \Drupal::service('entity.form_builder')->getForm($profile, 'default');
    }
    finally {
      $switcher->switchBack();
    }
    $select = $form['field_group_affiliation']['widget'][0]['container']['group'] ?? NULL;
    if (!is_array($select) || !isset($select['#options'])) {
      return NULL;
    }
    $ids = [];
    array_walk_recursive($select['#options'], static function ($label, $key) use (&$ids): void {
      if (is_int($key) || ctype_digit((string) $key)) {
        $ids[(int) $key] = TRUE;
      }
    });
    return $ids;
  }

  /**
   * Number of user accounts, the anonymous row included.
   */
  private function countUsers(): int {
    return (int) \Drupal::entityTypeManager()->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Prints PASS or FAIL for a condition.
   */
  private function line(bool $ok, string $check, string $why): void {
    $ok ? $this->pass($check) : $this->fail($check, $why);
  }

  /**
   * Prints a PASS line.
   */
  private function pass(string $check): void {
    print "PASS $check\n";
  }

  /**
   * Prints a FAIL line.
   */
  private function fail(string $check, string $why): void {
    $this->failures++;
    print "FAIL $check: $why\n";
  }

}

$code = (new CheckGroupFeatures())->run();
if ($code !== 0) {
  exit($code);
}
