<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\localnodes_consensus\Access\ConsensusRecordAccessControlHandler;
use Drupal\Tests\localnodes_consensus\Kernel\Support\RecordingReader;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * The audited Group roster and the one reader decision.
 *
 * Both modules go in through the module installer, as on a fresh install.
 * The Group type and its manager role are read from localnodes_group_setup's
 * settings, never written here.
 *
 * @group localnodes_consensus
 */
final class RosterAccessTest extends GroupAccessKernelTestBase {

  /**
   * The roster event name (RosterManager::ROSTER_ENDED_EVENT).
   */
  protected const ENDED = 'localnodes_consensus.roster_ended';

  /**
   * The listed Group type (first entry of the set-up settings).
   */
  protected string $groupType;

  /**
   * The listed Group type's manager role id, from the settings.
   */
  protected string $managerRole;

  /**
   * The record context passed alongside each reader check.
   */
  protected ?EntityInterface $context = NULL;

  /**
   * Roster events seen, in order: [group_id, account_id, audit_id].
   *
   * @var array<int, array{int, int, int}>
   */
  protected array $events = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    if ($container->hasDefinition('extension.list.module')) {
      $container->getDefinition('extension.list.module')->setClass(AllProfilesModuleExtensionList::class);
    }
    if ($container->hasDefinition('config.storage.schema')) {
      $container->getDefinition('config.storage.schema')->setClass(AllProfilesSchemaStorage::class);
    }
    if ($container->hasDefinition('localnodes_consensus.current_eligible_reader')) {
      $container->getDefinition('localnodes_consensus.current_eligible_reader')->setClass(RecordingReader::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['localnodes_group_setup', 'localnodes_proposal_feed', 'localnodes_consensus'], FALSE);
    // Open Social's allowed-content field on the Group type, as shipped.
    // Open Social's site visibility settings, as a fresh install has them.
    $this->installConfig(['entity_access_by_field']);
    $this->installShippedConfig('social_group_flexible_group', [
      'field.storage.group.field_group_allowed_visibility',
      'field.field.group.flexible_group.field_group_allowed_visibility',
    ]);
    $types = $this->config('localnodes_group_setup.settings')->get('group_types');
    $this->groupType = (string) array_key_first($types);
    $this->managerRole = (string) reset($types);
    $this->listen();
  }

  /**
   * Records every roster event on the current container's dispatcher.
   */
  protected function listen(): void {
    $this->events = [];
    $this->container->get('event_dispatcher')->addListener(static::ENDED, function (GenericEvent $event): void {
      $this->events[] = [
        (int) $event->getArgument('group_id'),
        (int) $event->getArgument('account_id'),
        (int) $event->getArgument('audit_id'),
      ];
    });
  }

  /**
   * The roster service.
   */
  protected function roster(): object {
    return $this->container->get('localnodes_consensus.roster');
  }

  /**
   * Creates an active account in the Group, as manager or ordinary member.
   */
  protected function member(GroupInterface $group, string $name, bool $manager = FALSE): UserInterface {
    $user = $this->account($name);
    $group->addMember($user, $manager ? ['group_roles' => [$this->managerRole]] : []);
    return $user;
  }

  /**
   * Creates an active community account.
   */
  protected function account(string $name): UserInterface {
    return User::load($this->createPersona($name)->id());
  }

  /**
   * Every audit row for a Group, oldest first, as plain arrays.
   *
   * @return array<int, array<string, mixed>>
   *   Rows.
   */
  protected function audit(GroupInterface $group): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_roster_audit');
    $storage->resetCache();
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('gid', $group->id())->sort('id')->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $row) {
      $rows[] = [
        'id' => (int) $row->id(),
        'account' => $row->getAccountId(),
        'actor' => $row->getActorId(),
        'time' => $row->getCreatedTime(),
        'reason' => $row->getReason(),
        'before' => $row->getBefore(),
        'after' => $row->getAfter(),
      ];
    }
    return $rows;
  }

  /**
   * Runs the cron sweep (DueRoundSweeper).
   */
  protected function sweep(): int {
    return $this->container->get('localnodes_consensus.due_round_sweeper')->sweep();
  }

  /**
   * Number of roster entries for every Group.
   */
  protected function entryCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('localnodes_roster_entry')
      ->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Asserts a call is refused with the reason code, and returns nothing.
   */
  protected function assertRefused(string $reason, callable $call, string $label = ''): void {
    try {
      $call();
      $this->fail("Not refused: $label");
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringStartsWith($reason . ':', $e->getMessage(), $label);
    }
  }

  /**
   * A Group of a type the settings do not list, with its own lead.
   *
   * @return array{0: \Drupal\group\Entity\GroupInterface, 1: \Drupal\user\UserInterface, 2: \Drupal\user\UserInterface}
   *   The Group, its lead and an ordinary member.
   */
  protected function unlistedGroup(): array {
    GroupType::create(['id' => 'neutral', 'label' => 'Neutral', 'creator_membership' => FALSE])->save();
    GroupRole::create([
      'id' => 'neutral-lead',
      'label' => 'Lead',
      'group_type' => 'neutral',
      'scope' => 'individual',
      'admin' => FALSE,
    ])->save();
    $group = Group::create(['type' => 'neutral', 'label' => 'Unlisted', 'uid' => $this->siteOwner->id()]);
    $group->save();
    $lead = $this->account('lead');
    $group->addMember($lead, ['group_roles' => ['neutral-lead']]);
    $other = $this->account('neutral_member');
    $group->addMember($other);
    return [$group, $lead, $other];
  }

  /**
   * A manager approves, disputes and removes; each change is audited once.
   */
  public function testManagerChangesAreAuditedAndAnnouncedOnce(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $maya = $this->member($group, 'maya');
    $noor = $this->member($group, 'noor');

    $this->assertSame('none', $this->roster()->standing($group, (int) $maya->id()));
    $this->assertSame([], $this->roster()->currentRoster($group));

    $start = time();
    $approved = $this->roster()->approve($group, $noor, $manager, 'Lives here');
    $end = time();
    $this->roster()->approve($group, $maya, $manager, 'Lives here too');
    $rows = $this->audit($group);
    $this->assertCount(2, $rows);
    $this->assertSame((int) $approved->id(), $rows[0]['id']);
    $this->assertSame((int) $noor->id(), $rows[0]['account']);
    $this->assertSame((int) $manager->id(), $rows[0]['actor']);
    $this->assertGreaterThanOrEqual($start, $rows[0]['time']);
    $this->assertLessThanOrEqual($end, $rows[0]['time']);
    $this->assertSame('Lives here', $rows[0]['reason']);
    $this->assertSame('none', $rows[0]['before']);
    $this->assertSame('approved', $rows[0]['after']);
    $this->assertSame([], $this->events, 'An approval announces nothing');

    // Ascending account ids, whatever the approval order.
    $ids = [(int) $maya->id(), (int) $noor->id()];
    sort($ids);
    $this->assertSame($ids, $this->roster()->currentRoster($group));
    $this->assertSame('approved', $this->roster()->standing($group, (int) $maya->id()));

    // A second approval changes nothing.
    $again = $this->roster()->approve($group, $noor, $manager, 'Again');
    $this->assertSame((int) $approved->id(), (int) $again->id());
    $this->assertCount(2, $this->audit($group));
    $this->assertSame(2, $this->entryCount(), 'One entry per account and Group');

    // A dispute: one row, one event carrying its id; a retry adds neither.
    $disputed = $this->roster()->dispute($group, $maya, $manager, 'Two accounts, one person?');
    $rows = $this->audit($group);
    $this->assertCount(3, $rows);
    $this->assertSame(['approved', 'disputed'], [$rows[2]['before'], $rows[2]['after']]);
    $this->assertSame([[(int) $group->id(), (int) $maya->id(), (int) $disputed->id()]], $this->events);
    $this->roster()->dispute($group, $maya, $manager, 'Retry');
    $this->assertCount(3, $this->audit($group));
    $this->assertCount(1, $this->events, 'A retried dispute announces nothing new');
    $this->assertSame('disputed', $this->roster()->standing($group, (int) $maya->id()));
    $this->assertSame([(int) $noor->id()], $this->roster()->currentRoster($group));

    // A removal: one row, one event; a retry adds neither.
    $removed = $this->roster()->remove($group, $noor, $manager, 'Moved away');
    $this->assertSame(['approved', 'removed'], [$this->audit($group)[3]['before'], $this->audit($group)[3]['after']]);
    $this->assertSame([(int) $group->id(), (int) $noor->id(), (int) $removed->id()], $this->events[1]);
    $this->roster()->remove($group, $noor, $manager, 'Retry');
    $this->assertCount(4, $this->audit($group));
    $this->assertCount(2, $this->events);
    $this->assertSame('removed', $this->roster()->standing($group, (int) $noor->id()));
    $this->assertSame([], $this->roster()->currentRoster($group));

    // Removed members are still Group members; only a manager restores them.
    $this->roster()->approve($group, $noor, $manager, 'Back from abroad');
    $this->assertSame('approved', $this->roster()->standing($group, (int) $noor->id()));
    $this->assertCount(2, $this->events);

    // A reason is required, for every change.
    $count = count($this->audit($group));
    foreach (['approve' => $maya, 'remove' => $noor, 'dispute' => $noor] as $method => $account) {
      $this->assertRefused('reason_required', fn () => $this->roster()->$method($group, $account, $manager, '  '), $method);
    }
    $this->assertCount($count, $this->audit($group));
    $this->assertCount(2, $this->events);
  }

  /**
   * Only the Group's own current manager changes the roster.
   */
  public function testEveryOtherActorAndUnlistedTypeIsRefused(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $other = $this->createFlexibleGroup('Juniper', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $other_manager = $this->member($other, 'quinn', TRUE);
    $ordinary = $this->member($group, 'maya');
    $target = $this->member($group, 'noor');
    $admin = $this->account('admin');
    $admin->addRole('administrator');
    $admin->save();
    // A member whose site role gives the Group's admin-flagged insider role.
    $insider = $this->account('sitemanager');
    $insider->addRole('sitemanager');
    $insider->save();
    $group->addMember($insider);
    $outsider = $this->account('outsider');
    $blocked = $this->member($group, 'blocked', TRUE);
    $blocked->block();
    $blocked->save();

    $actors = [
      'other manager' => $other_manager,
      'site admin, not a member' => $admin,
      'ordinary member' => $ordinary,
      'synchronized insider' => $insider,
      'outsider' => $outsider,
      'blocked manager' => $blocked,
      'anonymous' => $this->anonymous(),
    ];
    foreach ($actors as $label => $actor) {
      $this->assertRefused('not_manager', fn () => $this->roster()->approve($group, $target, $actor, 'Because'), $label);
    }
    $this->assertSame(0, $this->entryCount());
    $this->assertSame([], $this->audit($group));

    // Remove and dispute are refused to the same people.
    $this->roster()->approve($group, $target, $manager, 'Lives here');
    foreach ($actors as $label => $actor) {
      $this->assertRefused('not_manager', fn () => $this->roster()->remove($group, $target, $actor, 'Because'), $label);
      $this->assertRefused('not_manager', fn () => $this->roster()->dispute($group, $target, $actor, 'Because'), $label);
    }
    $this->assertCount(1, $this->audit($group));
    $this->assertSame([], $this->events);

    // Approving someone who is not a current member is refused.
    $this->assertRefused('not_member', fn () => $this->roster()->approve($group, $outsider, $manager, 'Not here'));
    $this->assertRefused('not_member', fn () => $this->roster()->approve($group, $blocked, $manager, 'Blocked'));
    $this->assertRefused('no_entry', fn () => $this->roster()->remove($group, $ordinary, $manager, 'Never on it'));
    $this->assertSame(1, $this->entryCount());

    // No role grants roster standing silently.
    foreach (['manager' => $manager, 'insider' => $insider, 'ordinary' => $ordinary] as $label => $account) {
      $this->assertSame('none', $this->roster()->standing($group, (int) $account->id()), $label);
    }
    $this->assertSame([(int) $target->id()], $this->roster()->currentRoster($group));

    // A site administrator joins and is approved through the same path.
    $group->addMember($admin);
    $this->roster()->approve($group, $admin, $manager, 'Joined to help');
    $this->assertSame('approved', $this->roster()->standing($group, (int) $admin->id()));
    $audit = $this->audit($group);
    $this->assertSame([(int) $admin->id(), (int) $manager->id()], [end($audit)['account'], end($audit)['actor']]);

    // A Group whose type is not listed, even for its own lead.
    [$unlisted, $lead, $neutral_member] = $this->unlistedGroup();
    $this->assertRefused('group_type_not_listed', fn () => $this->roster()->approve($unlisted, $neutral_member, $lead, 'Because'));
    $this->assertSame('none', $this->roster()->standing($unlisted, (int) $neutral_member->id()));
    $this->assertSame([], $this->audit($unlisted));
  }

  /**
   * Leaving the Group writes a dated row at once; its event goes out once.
   *
   * The membership delete writes the row inside Group's transaction and
   * announces nothing there. The roster event for the row goes out later,
   * once, from outside any transaction (the cron sweep, or a response or
   * finalize() that finds it).
   */
  public function testLeavingTheGroupEndsStandingOnce(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $maya = $this->member($group, 'maya');
    $noor = $this->member($group, 'noor');
    $kai = $this->member($group, 'kai');
    $lee = $this->member($group, 'lee');
    $sam = $this->member($group, 'sam');
    $this->roster()->approve($group, $maya, $manager, 'Lives here');
    $this->roster()->approve($group, $noor, $manager, 'Lives here');
    $this->roster()->dispute($group, $noor, $manager, 'Unclear');
    $this->roster()->approve($group, $lee, $manager, 'Lives here');
    $this->roster()->remove($group, $lee, $manager, 'Moved');
    $this->roster()->approve($group, $sam, $manager, 'Lives here');
    $this->events = [];
    $rows = count($this->audit($group));

    // An approved member's membership removed without RosterManager: the
    // removal writes one dated row and announces nothing yet.
    $start = time();
    $group->removeMember($maya);
    $end = time();
    $this->assertSame('left', $this->roster()->standing($group, (int) $maya->id()));
    $audit = $this->audit($group);
    $this->assertCount($rows + 1, $audit);
    $last = end($audit);
    $this->assertSame((int) $maya->id(), $last['account']);
    $this->assertSame(['approved', 'left', 'membership_ended'], [$last['before'], $last['after'], $last['reason']]);
    $this->assertGreaterThanOrEqual($start, $last['time']);
    $this->assertLessThanOrEqual($end, $last['time']);
    $this->assertSame([], $this->events, 'No event inside the delete');
    $this->assertNull($this->roster()->recordMembershipEnded($group, (int) $maya->id()), 'Recorded once');
    $this->assertCount($rows + 1, $this->audit($group));
    // The sweep sends the row's event, once.
    $this->sweep();
    $this->assertSame([[(int) $group->id(), (int) $maya->id(), $last['id']]], $this->events);
    $this->sweep();
    $this->assertCount(1, $this->events);

    // The same for a disputed member.
    $group->removeMember($noor);
    $audit = $this->audit($group);
    $last = end($audit);
    $this->assertSame(['disputed', 'left', 'membership_ended'], [$last['before'], $last['after'], $last['reason']]);
    $this->assertSame('left', $this->roster()->standing($group, (int) $noor->id()));
    $this->assertCount(1, $this->events);
    $this->sweep();
    $this->assertCount(2, $this->events);
    $this->assertSame($last['id'], $this->events[1][2]);

    // No entry, or a removed entry: nothing written, nothing announced.
    $group->removeMember($kai);
    $group->removeMember($lee);
    $this->assertNull($this->roster()->recordMembershipEnded($group, (int) $kai->id()));
    $this->assertNull($this->roster()->recordMembershipEnded($group, (int) $lee->id()));
    $this->sweep();
    $this->assertCount($rows + 2, $this->audit($group));
    $this->assertCount(2, $this->events);
    $this->assertSame('none', $this->roster()->standing($group, (int) $kai->id()));
    $this->assertSame('removed', $this->roster()->standing($group, (int) $lee->id()));

    // Called directly for a still-current, active member: nothing happens.
    $this->assertNull($this->roster()->recordMembershipEnded($group, (int) $sam->id(), $manager));
    $this->assertCount($rows + 2, $this->audit($group));
    $this->assertCount(2, $this->events);
    $this->assertSame('approved', $this->roster()->standing($group, (int) $sam->id()));

    // A blocked account reads left, with no row yet (the lifecycle records it).
    $sam->block();
    $sam->save();
    $this->assertSame('left', $this->roster()->standing($group, (int) $sam->id()));
    $this->assertSame([], $this->roster()->currentRoster($group));
    $this->assertCount($rows + 2, $this->audit($group));
    $this->assertCount(2, $this->events);

    // Rejoining does not restore standing; a manager approves again.
    $group->addMember($maya);
    $this->assertSame('left', $this->roster()->standing($group, (int) $maya->id()));
    $this->assertSame([], $this->roster()->currentRoster($group));
    $this->roster()->approve($group, $maya, $manager, 'Back again');
    $this->assertSame('approved', $this->roster()->standing($group, (int) $maya->id()));
    $this->assertSame([(int) $maya->id()], $this->roster()->currentRoster($group));
  }

  /**
   * The event waits for the outermost transaction and skips a rollback.
   */
  public function testEventWaitsForTheOutermostCommit(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $maya = $this->member($group, 'maya');
    $noor = $this->member($group, 'noor');
    $this->roster()->approve($group, $maya, $manager, 'Lives here');
    $this->roster()->approve($group, $noor, $manager, 'Lives here');
    $database = $this->container->get('database');

    $transaction = $database->startTransaction();
    $this->roster()->remove($group, $maya, $manager, 'Moved');
    $this->assertSame([], $this->events, 'Nothing announced inside the transaction');
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame([], $this->events, 'Nothing announced after a rollback');
    $this->assertSame('approved', $this->roster()->standing($group, (int) $maya->id()));

    $transaction = $database->startTransaction();
    $audit = $this->roster()->remove($group, $noor, $manager, 'Moved');
    $this->assertSame([], $this->events);
    unset($transaction);
    $this->assertSame([[(int) $group->id(), (int) $noor->id(), (int) $audit->id()]], $this->events);
  }

  /**
   * The reader service.
   */
  protected function reader(): object {
    return $this->container->get('localnodes_consensus.current_eligible_reader');
  }

  /**
   * A Group of the listed type with its own and its content visibilities.
   *
   * @param string $label
   *   Label.
   * @param string $own
   *   The Group's own visibility: public, community or members.
   * @param string[] $allowed
   *   The content visibilities the Group allows.
   */
  protected function visibilityGroup(string $label, string $own, array $allowed): GroupInterface {
    $group = Group::create([
      'type' => $this->groupType,
      'label' => $label,
      'uid' => $this->siteOwner->id(),
      'field_flexible_group_visibility' => $own,
      'field_group_allowed_visibility' => array_map(fn (string $value) => ['value' => $value], $allowed),
    ]);
    $group->save();
    return $group;
  }

  /**
   * Whether the reader allows, checked with and without a record context.
   */
  protected function reads(GroupInterface $group, AccountInterface $account, ?string $visibility, string $label): bool {
    $plain = $visibility === NULL
      ? $this->reader()->access($group, $account)
      : $this->reader()->access($group, $account, $visibility);
    $with = $this->reader()->access($group, $account, $visibility ?? 'group', $this->context);
    $this->assertSame($plain->isAllowed(), $with->isAllowed(), "The record context changes nothing: $label");
    return $plain->isAllowed();
  }

  /**
   * The persona matrix for public, community and group in each Group.
   */
  public function testVisibilityPersonaMatrix(): void {
    $a = $this->visibilityGroup('All three', 'public', ['public', 'community', 'group']);
    $b = $this->visibilityGroup('No public', 'public', ['community', 'group']);
    $c = $this->visibilityGroup('Group only', 'community', ['group']);
    $d = $this->visibilityGroup('Nothing chosen', 'public', []);
    $private = $this->visibilityGroup('Members only', 'members', ['public', 'community', 'group']);
    $community = $this->visibilityGroup('Community Group', 'community', ['public', 'community', 'group']);
    $manager = $this->member($a, 'avery', TRUE);
    $this->context = $this->roster()->approve($a, $manager, $manager, 'Organizer');

    // Allowed and default visibilities, in the order public, community, group.
    $expected = [
      'All three' => [$a, ['public', 'community', 'group'], 'community'],
      'No public' => [$b, ['community', 'group'], 'community'],
      'Group only' => [$c, ['group'], 'group'],
      'Nothing chosen' => [$d, ['group'], 'group'],
      'Members only' => [$private, ['group'], 'group'],
      'Community Group' => [$community, ['community', 'group'], 'community'],
    ];
    foreach ($expected as $label => [$group, $allowed, $default]) {
      $this->assertSame($allowed, $this->reader()->allowedVisibilities($group), $label);
      $this->assertSame($default, $this->reader()->defaultVisibility($group), $label);
      foreach (['public', 'community', 'group'] as $value) {
        $this->assertSame(in_array($value, $allowed, TRUE) ? $value : 'group', $this->reader()->effectiveVisibility($group, $value), "$label: $value");
      }
    }

    // Personas, members of every Group above where named.
    $groups = [$a, $b, $c, $d, $private, $community];
    $member = $this->account('maya');
    $blocked = $this->account('blocked');
    $removed = $this->account('removed');
    foreach ($groups as $group) {
      $group->addMember($member);
      $group->addMember($blocked);
      $group->addMember($removed);
      $group->removeMember($removed);
    }
    $blocked->block();
    $blocked->save();
    $outsider = $this->account('outsider');
    $admin = $this->account('admin');
    $admin->addRole('administrator');
    $admin->save();
    $site_role = $this->account('sitemanager');
    $site_role->addRole('sitemanager');
    $site_role->save();
    $anonymous = $this->anonymous();

    // Who reads each value where the Group allows it (Group A).
    $matrix = [
      'member' => [$member, TRUE, TRUE, TRUE],
      'outsider' => [$outsider, TRUE, TRUE, FALSE],
      'anonymous' => [$anonymous, TRUE, FALSE, FALSE],
      'blocked member' => [$blocked, TRUE, FALSE, FALSE],
      'removed member' => [$removed, TRUE, TRUE, FALSE],
      'site admin, not a member' => [$admin, TRUE, TRUE, FALSE],
      'site role only' => [$site_role, TRUE, TRUE, FALSE],
    ];
    foreach ($matrix as $label => [$account, $public, $community_read, $group_read]) {
      $this->assertSame($public, $this->reads($a, $account, 'public', $label), "A public: $label");
      $this->assertSame($community_read, $this->reads($a, $account, 'community', $label), "A community: $label");
      $this->assertSame($group_read, $this->reads($a, $account, 'group', $label), "A group: $label");
      $this->assertSame($group_read, $this->reads($a, $account, NULL, $label), "A default group: $label");
    }

    // A value the Group does not allow reads as group: only members read it.
    $narrowed = [
      'No public' => [$b, ['public']],
      'Group only' => [$c, ['public', 'community']],
      'Nothing chosen' => [$d, ['public', 'community']],
      'Members only' => [$private, ['public', 'community']],
      'Community Group' => [$community, ['public']],
    ];
    foreach ($narrowed as $label => [$group, $values]) {
      foreach ($values as $value) {
        $this->assertTrue($this->reads($group, $member, $value, $label), "$label $value: member");
        foreach (['outsider' => $outsider, 'anonymous' => $anonymous, 'admin' => $admin, 'site role' => $site_role, 'removed' => $removed] as $persona => $account) {
          $this->assertFalse($this->reads($group, $account, $value, "$label $persona"), "$label $value: $persona");
        }
      }
    }
    // Where a Group allows a value, it is not narrowed.
    $this->assertTrue($this->reads($b, $outsider, 'community', 'B community'));
    $this->assertTrue($this->reads($community, $outsider, 'community', 'Community Group community'));

    // An unknown value is refused, even to a member.
    foreach (['everyone', '', 'members', 'Public'] as $value) {
      $this->assertFalse($this->reads($a, $member, $value, "unknown '$value'"), "Unknown value '$value'");
    }

    // A Group of a type the settings do not list is refused for every value.
    [$unlisted, $lead, $neutral_member] = $this->unlistedGroup();
    foreach (['public', 'community', 'group'] as $value) {
      $this->assertFalse($this->reads($unlisted, $neutral_member, $value, 'unlisted'), "Unlisted: $value");
      $this->assertFalse($this->reads($unlisted, $lead, $value, 'unlisted lead'), "Unlisted lead: $value");
    }

    // The roster never decides reading.
    $this->assertSame('none', $this->roster()->standing($a, (int) $member->id()));
    $this->assertTrue($this->reads($a, $member, 'group', 'off the roster'));
    $this->roster()->approve($a, $member, $manager, 'Lives here');
    $this->roster()->remove($a, $member, $manager, 'Not responding');
    $this->assertTrue($this->reads($a, $member, 'group', 'removed from the roster only'));

    // Wording members see.
    $labels = array_map('strval', $this->reader()->visibilityLabels());
    $this->assertSame(['public' => 'Public', 'community' => 'Whole community', 'group' => 'This group only'], $labels);
  }

  /**
   * The site-wide public switch-off is enforced by the reader itself.
   */
  public function testSiteWidePublicSwitchOff(): void {
    $a = $this->visibilityGroup('All three', 'public', ['public', 'community', 'group']);
    $member = $this->member($a, 'maya');
    $outsider = $this->account('outsider');
    $override = $this->account('override');
    $role = Role::create(['id' => 'public_override', 'label' => 'Public override']);
    $role->grantPermission('override disabled public visibility');
    $role->save();
    $override->addRole('public_override');
    $override->save();
    $this->context = NULL;

    $this->config('entity_access_by_field.settings')->set('disable_public_visibility', 1)->save();
    $this->assertSame(['community', 'group'], $this->reader()->allowedVisibilities($a));
    $this->assertSame('group', $this->reader()->effectiveVisibility($a, 'public'));
    $this->assertFalse($this->reads($a, $this->anonymous(), 'public', 'anonymous'));
    $this->assertFalse($this->reads($a, $outsider, 'public', 'outsider'));
    $this->assertFalse($this->reads($a, $override, 'public', 'override permission'));
    $this->assertTrue($this->reads($a, $member, 'public', 'member'));
    $result = $this->reader()->access($a, $this->anonymous(), 'public');
    $this->assertContains('config:entity_access_by_field.settings', $result->getCacheTags());

    $this->config('entity_access_by_field.settings')->set('disable_public_visibility', 0)->save();
    $this->assertSame(['public', 'community', 'group'], $this->reader()->allowedVisibilities($a));
    $this->assertTrue($this->reads($a, $this->anonymous(), 'public', 'anonymous restored'));
  }

  /**
   * Results carry cache metadata, so a removal or block applies at once.
   */
  public function testResultsCarryCacheMetadata(): void {
    $a = $this->visibilityGroup('All three', 'public', ['public', 'community', 'group']);
    $member = $this->member($a, 'maya');
    $gid = $a->id();
    foreach (['public', 'community', 'group'] as $value) {
      $result = $this->reader()->access($a, $member, $value);
      $tags = $result->getCacheTags();
      foreach (['config:entity_access_by_field.settings', "group:$gid", 'config:localnodes_group_setup.settings'] as $tag) {
        $this->assertContains($tag, $tags, "$value: $tag");
      }
    }
    $result = $this->reader()->access($a, $member, 'group');
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains("group_content_list:plugin:group_membership:group:$gid", $result->getCacheTags());
    $this->assertContains('user:' . $member->id(), $result->getCacheTags());

    // A removal and a block apply on the next check.
    $this->assertTrue($this->reader()->access($a, $member, 'group')->isAllowed());
    $a->removeMember($member);
    $this->assertFalse($this->reader()->access($a, $member, 'group')->isAllowed());
    $noor = $this->member($a, 'noor');
    $this->assertTrue($this->reader()->access($a, $noor, 'community')->isAllowed());
    $noor->block();
    $noor->save();
    $this->assertFalse($this->reader()->access($a, $noor, 'group')->isAllowed());
    $this->assertFalse($this->reader()->access($a, $noor, 'community')->isAllowed());
  }

  /**
   * The three record types are read through the reader and never written.
   */
  public function testRecordsAreReadThroughTheReaderAndNeverWritten(): void {
    $a = $this->visibilityGroup('Public Group', 'public', ['public', 'community', 'group']);
    $manager = $this->member($a, 'avery', TRUE);
    $member = $this->member($a, 'maya');
    $outsider = $this->account('outsider');
    // User 1 created the Group, so it is a member; writes are still refused.
    $owner = User::load($this->siteOwner->id());
    $this->assertSame(1, (int) $owner->id());
    $admin = $this->account('admin');
    $admin->addRole('administrator');
    $admin->save();

    $audit = $this->roster()->approve($a, $member, $manager, 'Lives here');
    $entry_ids = $this->container->get('entity_type.manager')->getStorage('localnodes_roster_entry')
      ->getQuery()->accessCheck(FALSE)->execute();
    $entry = $this->container->get('entity_type.manager')->getStorage('localnodes_roster_entry')->load(reset($entry_ids));
    $rules_id = $this->container->get('localnodes_consensus.decision_rules')->seedFromSetup($a, [
      'path' => 'simple',
      'everyday' => ['method' => 'consensus', 'quorum_numerator' => 2, 'quorum_denominator' => 3, 'threshold_numerator' => NULL, 'threshold_denominator' => NULL, 'response_days' => 7],
      'money' => ['method' => 'consensus', 'quorum_numerator' => 2, 'quorum_denominator' => 3, 'threshold_numerator' => NULL, 'threshold_denominator' => NULL, 'response_days' => 7],
      'rule_change' => ['method' => 'consensus', 'quorum_numerator' => 2, 'quorum_denominator' => 3, 'threshold_numerator' => NULL, 'threshold_denominator' => NULL, 'response_days' => 14],
    ], $manager, 1);
    $rules = $this->container->get('entity_type.manager')->getStorage('localnodes_decision_rules')->load($rules_id);

    foreach (['localnodes_decision_rules' => $rules, 'localnodes_roster_entry' => $entry, 'localnodes_roster_audit' => $audit] as $type => $record) {
      $handler = $this->container->get('entity_type.manager')->getAccessControlHandler($type);
      $this->assertSame(ConsensusRecordAccessControlHandler::class, get_class($handler), $type);

      RecordingReader::$records = [];
      $this->assertTrue($record->access('view', $member), "$type: member reads");
      $this->assertSame([$record], RecordingReader::$records, "$type: the handler passes the record as context");
      $this->assertFalse($record->access('view', $outsider), "$type: outsider, group visibility");
      $this->assertFalse($record->access('view', $this->anonymous()), "$type: anonymous");
      $this->assertFalse($record->access('view', $admin), "$type: a site administrator who is not a member");
      foreach (['update', 'delete'] as $operation) {
        foreach (['member' => $member, 'manager' => $manager, 'user 1' => $owner] as $label => $account) {
          $this->assertFalse($record->access($operation, $account), "$type: $operation refused to $label");
        }
      }
      foreach (['member' => $member, 'manager' => $manager, 'user 1' => $owner] as $label => $account) {
        $this->assertFalse($handler->createAccess(NULL, $account), "$type: create refused to $label");
      }

      // A member who leaves the Group stops reading at once.
      $a->removeMember($member);
      $handler->resetCache();
      $this->assertFalse($record->access('view', $member), "$type: a removed member no longer reads");
      $a->addMember($member);
      $handler->resetCache();
    }
  }

  /**
   * Only CurrentEligibleReader checks membership for reading.
   */
  public function testReadersGoThroughOneService(): void {
    $root = DRUPAL_ROOT . '/modules/custom/localnodes_consensus';
    $files = [];
    foreach (['src/Access', 'src/Controller', 'src/Form', 'src/Plugin'] as $dir) {
      if (!is_dir("$root/$dir")) {
        continue;
      }
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
          $files[] = $file->getPathname();
        }
      }
    }
    $files[] = "$root/localnodes_consensus.module";
    $this->assertContains("$root/src/Access/CurrentEligibleReader.php", $files);
    $callers = [];
    foreach ($files as $file) {
      $source = (string) file_get_contents($file);
      if (str_contains($source, 'isCurrentMember(') || str_contains($source, 'getMember(')) {
        $callers[] = substr($file, strlen($root) + 1);
      }
    }
    $this->assertSame(['src/Access/CurrentEligibleReader.php'], $callers);
  }

  /**
   * User 1 without a membership neither reads a group round nor responds.
   *
   * The site's first account gets no way around the one reader: once its
   * membership of the Group ends, a `group` round and its responses are
   * closed to it, and it cannot respond, not being on the opening roster.
   */
  public function testUserOneWithoutMembershipNeitherReadsNorResponds(): void {
    $a = $this->visibilityGroup('Public Group', 'public', ['public', 'community', 'group']);
    $owner = User::load($this->siteOwner->id());
    $this->assertSame(1, (int) $owner->id());
    $manager = $this->member($a, 'avery', TRUE);
    $member = $this->member($a, 'maya');
    $this->roster()->approve($a, $member, $manager, 'Lives here');
    // User 1 created the Group, so it was a member; end that membership.
    $this->assertNotFalse($a->getMember($owner));
    $a->removeMember($owner);
    $this->assertFalse($a->getMember($owner));

    $lifecycle = $this->container->get('localnodes_consensus.round_lifecycle');
    $round = $lifecycle->open($a, $member, 'everyday', 'Members only', ['visibility' => 'group']);
    $response = $lifecycle->respond($round, $member, 'stand_aside', NULL);
    foreach (['localnodes_consensus_round', 'localnodes_consensus_response'] as $type) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    }
    $this->assertTrue($round->access('view', $member), 'The member reads');
    $this->assertFalse($round->access('view', $owner), 'User 1, not a member, does not read the round');
    $this->assertFalse($response->access('view', $owner), 'User 1, not a member, does not read a response');
    $this->assertRefused('not_on_opening_roster', fn () => $lifecycle->respond($round, $owner, 'agree', NULL), 'user 1 responds');
    $this->assertCount(1, $round->getResponseRevisions(), 'Nothing written for user 1');
  }

}
