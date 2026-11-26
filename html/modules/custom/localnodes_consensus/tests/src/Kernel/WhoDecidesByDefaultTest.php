<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Entity\RosterEntry;
use Drupal\localnodes_consensus\Service\RosterManager;
use Drupal\Tests\localnodes_consensus\Kernel\Support\RecordingDispatcher;
use Drupal\user\UserInterface;

/**
 * Who decides by default.
 *
 * Where every way into a group needs approval or an invite, its members
 * decide automatically: at once when they join, and by cron for members
 * who joined before. Where any way in is free, the organizer makes the
 * first list once, with no reason asked.
 *
 * @group localnodes_consensus
 */
class WhoDecidesByDefaultTest extends ConsensusRecordTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installShippedConfig('social_group_flexible_group', [
      'field.storage.group.field_group_allowed_join_method',
      'field.field.group.flexible_group.field_group_allowed_join_method',
    ]);
  }

  /**
   * A group of the listed type with the given ways in.
   *
   * @param string[]|null $methods
   *   Join method ids; NULL leaves the field empty.
   */
  private function joinGroup(string $label, ?array $methods): GroupInterface {
    $values = [
      'type' => $this->groupType,
      'label' => $label,
      'uid' => $this->siteOwner->id(),
      'field_flexible_group_visibility' => 'public',
      'field_group_allowed_visibility' => [['value' => 'public'], ['value' => 'community'], ['value' => 'group']],
    ];
    if ($methods !== NULL) {
      $values['field_group_allowed_join_method'] = array_map(fn (string $m) => ['value' => $m], $methods);
    }
    $group = Group::create($values);
    $group->save();
    return $group;
  }

  private function joinRule(): object {
    return $this->container->get('localnodes_consensus.join_rule');
  }

  private function auditCount(GroupInterface $group, ?int $uid = NULL): int {
    $query = $this->container->get('entity_type.manager')->getStorage('localnodes_roster_audit')->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $group->id());
    if ($uid !== NULL) {
      $query->condition('account', $uid);
    }
    return (int) $query->count()->execute();
  }

  private function lastReason(GroupInterface $group, int $uid): string {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_roster_audit');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('gid', $group->id())
      ->condition('account', $uid)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    return $ids ? (string) $storage->load(reset($ids))->get('reason')->value : '';
  }

  /**
   * Deletes every list row of the group, as on a site whose members joined
   * before this code ran.
   */
  private function forgetList(GroupInterface $group): void {
    // Storage refuses to delete roster history, so the rows go by SQL: a
    // redeployed site's earlier members simply never had any.
    $etm = $this->container->get('entity_type.manager');
    foreach (['localnodes_roster_entry', 'localnodes_roster_audit'] as $type) {
      $this->container->get('database')->delete($type)->condition('gid', $group->id())->execute();
      $etm->getStorage($type)->resetCache();
    }
  }

  private function standing(GroupInterface $group, UserInterface $account): string {
    return $this->roster()->standing($group, (int) $account->id());
  }

  public function testJoinRuleReadsEveryWayIn(): void {
    $cases = [
      'direct' => [['direct'], FALSE],
      'request' => [['request'], TRUE],
      'added' => [['added'], TRUE],
      'request and added' => [['request', 'added'], TRUE],
      'direct and request' => [['direct', 'request'], FALSE],
      'field present, no value' => [[], FALSE],
      'field never set' => [NULL, FALSE],
    ];
    foreach ($cases as $label => [$methods, $expected]) {
      $this->assertSame($expected, $this->joinRule()->isAutomatic($this->joinGroup($label, $methods)), $label);
    }
  }

  public function testNewMemberOfAnInviteOnlyGroupDecidesAtOnce(): void {
    $group = $this->joinGroup('Invite only', ['added']);
    $member = $this->member($group, 'newcomer');
    $this->assertSame(RosterEntry::APPROVED, $this->standing($group, $member));
    $this->assertSame(1, $this->auditCount($group, (int) $member->id()));
    $this->assertSame('automatic', $this->lastReason($group, (int) $member->id()));
  }

  public function testNewMemberOfAnOpenGroupDoesNot(): void {
    $group = $this->joinGroup('Open', ['direct']);
    $member = $this->member($group, 'walker');
    $this->assertSame(RosterEntry::NONE, $this->standing($group, $member));
    $this->assertSame(0, $this->auditCount($group));
  }

  public function testRemovedMemberWhoRejoinsIsNotOverwritten(): void {
    $group = $this->joinGroup('Invite only', ['added']);
    $organizer = $this->member($group, 'organizer', TRUE);
    $member = $this->member($group, 'mover');
    $this->roster()->remove($group, $member, $organizer, 'Moved out');
    $group->removeMember($member);
    $this->member($group, 'mover_again');
    $group->addMember($member);
    localnodes_consensus_cron();
    $this->assertNotSame(RosterEntry::APPROVED, $this->standing($group, $member));
  }

  public function testDisputedEntryIsKept(): void {
    $group = $this->joinGroup('Invite only', ['added']);
    $organizer = $this->member($group, 'organizer', TRUE);
    $member = $this->member($group, 'questioned');
    $this->roster()->dispute($group, $member, $organizer, 'Not sure they live here');
    $member->save();
    localnodes_consensus_cron();
    $this->assertSame(RosterEntry::DISPUTED, $this->standing($group, $member));
  }

  public function testCronCatchesUpMembersWhoJoinedBefore(): void {
    $group = $this->joinGroup('Invite only', ['added']);
    $people = [$this->member($group, 'early_a'), $this->member($group, 'early_b', TRUE), $this->member($group, 'early_c')];
    $this->forgetList($group);
    localnodes_consensus_cron();
    foreach ($people as $person) {
      $this->assertSame(RosterEntry::APPROVED, $this->standing($group, $person), $person->getAccountName());
    }
    $count = $this->auditCount($group);
    localnodes_consensus_cron();
    $this->assertSame($count, $this->auditCount($group));
  }

  public function testNewcomerBeforeTheFirstPassDoesNotBlockCatchUp(): void {
    $group = $this->joinGroup('Invite only', ['added']);
    $early = [$this->member($group, 'early_a'), $this->member($group, 'early_b')];
    $this->forgetList($group);
    $newcomer = $this->member($group, 'newcomer');
    $this->assertSame(RosterEntry::APPROVED, $this->standing($group, $newcomer));
    localnodes_consensus_cron();
    foreach ($early as $person) {
      $this->assertSame(RosterEntry::APPROVED, $this->standing($group, $person), $person->getAccountName());
    }
  }

  public function testCronNeverTouchesAnExistingStanding(): void {
    $group = $this->joinGroup('Invite only', ['added']);
    $organizer = $this->member($group, 'organizer', TRUE);
    $removed = $this->member($group, 'removed');
    $disputed = $this->member($group, 'disputed');
    $left = $this->member($group, 'left');
    $this->roster()->remove($group, $removed, $organizer, 'Asked to stop');
    $this->roster()->dispute($group, $disputed, $organizer, 'Unclear');
    $group->removeMember($left);
    $before = $this->auditCount($group);
    localnodes_consensus_cron();
    localnodes_consensus_cron();
    $this->assertSame(RosterEntry::REMOVED, $this->standing($group, $removed));
    $this->assertSame(RosterEntry::DISPUTED, $this->standing($group, $disputed));
    $this->assertSame(RosterEntry::LEFT, $this->standing($group, $left));
    $this->assertSame($before, $this->auditCount($group));
  }

  public function testCatchUpFinishesOverSeveralPasses(): void {
    $group = $this->joinGroup('Invite only', ['added']);
    $people = [];
    foreach (['one', 'two', 'three', 'four', 'five'] as $name) {
      $people[] = $this->member($group, $name);
    }
    $this->forgetList($group);
    // The group's creator may be a member too, so count the real members.
    $remaining = count($this->roster()->currentMembers($group));
    $this->assertGreaterThanOrEqual(5, $remaining);
    while ($remaining > 0) {
      $this->assertSame(min(2, $remaining), $this->roster()->catchUpAutomatic(2));
      $remaining -= min(2, $remaining);
    }
    $this->assertSame(0, $this->roster()->catchUpAutomatic(2));
    foreach ($people as $person) {
      $this->assertSame(RosterEntry::APPROVED, $this->standing($group, $person), $person->getAccountName());
    }
    $this->assertSame(count($this->roster()->currentMembers($group)), $this->auditCount($group));
  }

  public function testGroupThatBecomesInviteOnlyIsCaughtUp(): void {
    $group = $this->joinGroup('Open, then invite only', ['direct']);
    $member = $this->member($group, 'stayer');
    $this->assertSame(RosterEntry::NONE, $this->standing($group, $member));
    $group->set('field_group_allowed_join_method', [['value' => 'added']])->save();
    localnodes_consensus_cron();
    $this->assertSame(RosterEntry::APPROVED, $this->standing($group, $member));
  }

  public function testFirstListNeedsNoReasonAndOnlyOnce(): void {
    $group = $this->joinGroup('Open', ['direct']);
    $organizer = $this->member($group, 'organizer', TRUE);
    $a = $this->member($group, 'a');
    $b = $this->member($group, 'b');
    $audits = $this->roster()->approveFirstList($group, [(int) $organizer->id(), (int) $a->id(), (int) $b->id()], $organizer);
    $this->assertCount(3, $audits);
    foreach ([$organizer, $a, $b] as $person) {
      $this->assertSame(RosterEntry::APPROVED, $this->standing($group, $person));
      $this->assertSame('first_list', $this->lastReason($group, (int) $person->id()));
    }
    $c = $this->member($group, 'c');
    $this->assertRefused('first_list_done', fn () => $this->roster()->approveFirstList($group, [(int) $c->id()], $organizer));
    $this->assertSame(RosterEntry::NONE, $this->standing($group, $c));
  }

  public function testFirstListKeepsTheOrganizerWhenEmpty(): void {
    $group = $this->joinGroup('Open', ['direct']);
    $organizer = $this->member($group, 'organizer', TRUE);
    $this->member($group, 'a');
    $this->roster()->approveFirstList($group, [], $organizer);
    $this->assertSame([(int) $organizer->id()], $this->roster()->currentRoster($group));
  }

  public function testFirstListRefusesANonManagerAndANonMember(): void {
    $group = $this->joinGroup('Open', ['direct']);
    $organizer = $this->member($group, 'organizer', TRUE);
    $member = $this->member($group, 'member');
    $outsider = $this->account('outsider');
    $this->assertRefused('not_manager', fn () => $this->roster()->approveFirstList($group, [(int) $member->id()], $member));
    $this->assertRefused('not_member', fn () => $this->roster()->approveFirstList($group, [(int) $organizer->id(), (int) $outsider->id()], $organizer));
    $this->assertSame(0, $this->auditCount($group));
  }

  public function testApprovalAnnouncesNothing(): void {
    $group = $this->joinGroup('Invite only', ['added']);
    RecordingDispatcher::$names = [];
    $this->member($group, 'quiet');
    $open = $this->joinGroup('Open', ['direct']);
    $organizer = $this->member($open, 'organizer', TRUE);
    $this->roster()->approveFirstList($open, [], $organizer);
    $this->assertNotContains(RosterManager::ROSTER_ENDED_EVENT, RecordingDispatcher::$names);
  }

}
