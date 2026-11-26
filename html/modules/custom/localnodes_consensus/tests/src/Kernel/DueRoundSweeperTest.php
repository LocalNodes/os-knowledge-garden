<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\localnodes_consensus\Kernel\Support\FailingRoundLifecycle;
use Drupal\Tests\localnodes_consensus\Kernel\Support\FixedTime;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Due rounds close by server time through cron, exactly once.
 *
 * The cron sweep finalizes open rounds whose frozen deadline has passed,
 * oldest deadline first, through RoundLifecycle only. The three modules go
 * in through the module installer, as on a fresh install. The server clock
 * is fixed.
 *
 * @group localnodes_consensus
 */
final class DueRoundSweeperTest extends GroupAccessKernelTestBase {

  /**
   * The feed event names (ProposalFeedEvents).
   */
  protected const OPENED = 'localnodes_proposal_feed.opened';
  protected const CHANGED = 'localnodes_proposal_feed.changed';
  protected const CLOSED = 'localnodes_proposal_feed.closed';

  /**
   * The round entity type id.
   */
  protected const ROUND = 'localnodes_consensus_round';

  /**
   * One day in seconds.
   */
  protected const DAY = 86400;

  /**
   * The listed Group type and its manager role, from the set-up settings.
   */
  protected string $groupType;
  protected string $managerRole;

  /**
   * Feed events seen, in order: [short name, entity type id, entity id].
   *
   * @var array<int, array{string, string, int}>
   */
  protected array $feed = [];

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
    if ($container->hasDefinition('datetime.time')) {
      $container->getDefinition('datetime.time')->setClass(FixedTime::class);
    }
    if ($container->hasDefinition('localnodes_consensus.round_lifecycle')) {
      $container->getDefinition('localnodes_consensus.round_lifecycle')->setClass(FailingRoundLifecycle::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    FixedTime::$now = 1800000000;
    FailingRoundLifecycle::$failures = 0;
    parent::setUp();
    $this->container->get('module_installer')->install(['localnodes_group_setup', 'localnodes_proposal_feed', 'localnodes_consensus'], FALSE);
    $this->installConfig(['entity_access_by_field']);
    $this->installShippedConfig('social_group_flexible_group', [
      'field.storage.group.field_group_allowed_visibility',
      'field.field.group.flexible_group.field_group_allowed_visibility',
    ]);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $types = $this->config('localnodes_group_setup.settings')->get('group_types');
    $this->groupType = (string) array_key_first($types);
    $this->managerRole = (string) reset($types);
    $this->listen();
  }

  /**
   * Records every feed event on the current container's dispatcher.
   */
  protected function listen(): void {
    $this->feed = [];
    foreach (['opened' => static::OPENED, 'changed' => static::CHANGED, 'closed' => static::CLOSED] as $short => $name) {
      $this->container->get('event_dispatcher')->addListener($name, function ($event) use ($short): void {
        $source = $event->getSource();
        $this->feed[] = [$short, $source->getEntityTypeId(), (int) $source->id()];
      });
    }
  }

  /**
   * Feed events seen for one round, as short names.
   *
   * @return string[]
   *   Names in order.
   */
  protected function feedFor(object $round): array {
    $names = [];
    foreach ($this->feed as [$short, $type, $id]) {
      if ($type === static::ROUND && $id === (int) $round->id()) {
        $names[] = $short;
      }
    }
    return $names;
  }

  /**
   * The lifecycle service.
   */
  protected function lifecycle(): object {
    return $this->container->get('localnodes_consensus.round_lifecycle');
  }

  /**
   * The roster service.
   */
  protected function roster(): object {
    return $this->container->get('localnodes_consensus.roster');
  }

  /**
   * The cron sweep: returns how many rounds it brought to a terminal state.
   */
  protected function sweep(): int {
    return $this->container->get('localnodes_consensus.due_round_sweeper')->sweep();
  }

  /**
   * Creates an active account in the Group, as manager or ordinary member.
   */
  protected function member(GroupInterface $group, string $name, bool $manager = FALSE): UserInterface {
    $user = User::load($this->createPersona($name)->id());
    $group->addMember($user, $manager ? ['group_roles' => [$this->managerRole]] : []);
    return $user;
  }

  /**
   * The first shipped Group template id.
   */
  protected function templateId(): string {
    $names = $this->container->get('config.storage')->listAll('localnodes_group_setup.template.');
    sort($names);
    $this->assertNotEmpty($names);
    return (string) $this->config(reset($names))->get('id');
  }

  /**
   * A Group whose manager confirmed the template, with approved members.
   *
   * @return array{0: \Drupal\group\Entity\GroupInterface, 1: \Drupal\user\UserInterface, 2: \Drupal\user\UserInterface[]}
   *   The Group, its manager and the approved members by name.
   */
  protected function setUpGroup(string $label, array $names): array {
    $group = Group::create([
      'type' => $this->groupType,
      'label' => $label,
      'uid' => $this->siteOwner->id(),
      'field_flexible_group_visibility' => 'public',
      'field_group_allowed_visibility' => [['value' => 'public'], ['value' => 'community'], ['value' => 'group']],
    ]);
    $group->save();
    $manager = $this->member($group, $label . '_organizer', TRUE);
    $this->container->get('localnodes_group_setup.application_manager')->confirm($group, $this->templateId(), [], FALSE, $manager);
    $members = [];
    foreach ($names as $name) {
      $members[$name] = $this->member($group, $name);
      $this->roster()->approve($group, $members[$name], $manager, 'Lives here');
    }
    return [$group, $manager, $members];
  }

  /**
   * A fresh copy of a stored entity.
   */
  protected function reload(object $entity): object {
    return $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
  }

  /**
   * Asserts a call is refused with the reason code.
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
   * Responds for several members.
   *
   * @param array<int, array{0: \Drupal\user\UserInterface, 1: string}> $answers
   *   Member and choice pairs.
   */
  protected function answer(object $round, array $answers): void {
    foreach ($answers as [$member, $choice]) {
      $this->lifecycle()->respond($round, $member, $choice, $choice === 'block' ? 'It would hurt someone' : NULL);
    }
  }

  /**
   * The stored values of a round, for before-and-after comparison.
   */
  protected function snapshot(object $round): array {
    $round = $this->reload($round);
    $values = [];
    foreach (array_keys($round->getFields()) as $name) {
      $values[$name] = $round->get($name)->getValue();
    }
    return $values;
  }

  /**
   * The ending of a round: state, reason, note and closure key.
   *
   * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
   *   The four values.
   */
  protected function ending(object $round): array {
    $round = $this->reload($round);
    return [$round->getClosedState(), $round->getClosedReason(), $round->getClosedNote(), $round->getClosureKey()];
  }

  /**
   * Asserts a round reads ended, pending: neither open nor a result.
   */
  protected function assertEndedPending(object $round, string $label = ''): void {
    $round = $this->reload($round);
    $this->assertSame('ended_pending', $round->getStatus(), $label);
    $this->assertSame('open', $round->getStoredStatus(), $label);
    $this->assertFalse($round->isTerminal(), $label);
    $this->assertNull($round->getClosedState(), $label);
    $this->assertNull($round->getClosedReason(), $label);
    $this->assertNull($round->getClosedAt(), $label);
    $this->assertNull($round->getClosureKey(), $label);
  }

  /**
   * Boundary times: server time decides, and ended, pending stays distinct.
   *
   * One second before the deadline a response is accepted. At the deadline
   * and one second after it a response is refused, even the agree that
   * would otherwise close the round early, and the round reads ended,
   * pending until the sweep finalizes it at or after its deadline.
   */
  public function testBoundaryTimesAndEndedPending(): void {
    [$group, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $x = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $this->assertSame(2, $x->getQuorum()['required']);
    FixedTime::$now++;
    $y = $this->lifecycle()->open($group, $a, 'everyday', 'Compost bins');
    $this->assertSame($x->getDeadline() + 1, $y->getDeadline());
    $this->answer($x, [[$a, 'agree']]);
    $this->answer($y, [[$a, 'agree']]);

    // One second before x's deadline: accepted, open, the sweep leaves it.
    FixedTime::$now = $x->getDeadline() - 1;
    $this->lifecycle()->respond($x, $b, 'stand_aside', NULL);
    $this->assertSame('open', $this->reload($x)->getStatus());
    $this->feed = [];
    $this->assertSame(0, $this->sweep());
    $this->assertSame([], $this->feed);

    // At x's deadline: b's agree would close x early; it is refused.
    FixedTime::$now = $x->getDeadline();
    $before = $this->snapshot($x);
    $this->assertRefused('deadline_passed', fn () => $this->lifecycle()->respond($x, $b, 'agree', NULL), 'the last agree at the deadline');
    $this->assertSame($before, $this->snapshot($x), 'A refused response writes nothing');
    $this->assertEndedPending($x, 'x at its deadline');
    // y is one second before its own deadline: still open, still answerable.
    $this->lifecycle()->respond($y, $b, 'stand_aside', NULL);
    $this->assertSame('open', $this->reload($y)->getStatus());

    // The sweep at x's deadline finalizes x only, from frozen inputs.
    $this->feed = [];
    $this->assertSame(1, $this->sweep());
    $this->assertSame(['agreed', 'deadline', NULL, 'agreed:deadline'], $this->ending($x));
    $this->assertSame(FixedTime::$now, $this->reload($x)->getClosedAt());
    $this->assertSame(['closed'], $this->feedFor($x));
    $this->assertSame('open', $this->reload($y)->getStatus());
    $this->assertSame([], $this->feedFor($y));

    // One second after x's deadline, at y's: the closing agree is refused.
    FixedTime::$now = $y->getDeadline();
    $this->assertRefused('deadline_passed', fn () => $this->lifecycle()->respond($y, $b, 'agree', NULL), 'the last agree one second late');
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->respond($x, $b, 'agree', NULL), 'x is closed');
    $this->assertEndedPending($y, 'y at its deadline');
    $this->assertSame(1, $this->sweep());
    $this->assertSame(['agreed', 'deadline', NULL, 'agreed:deadline'], $this->ending($y));
    $this->assertSame(['closed'], $this->feedFor($y));

    // A repeated sweep finds nothing and changes nothing.
    $before = [$this->snapshot($x), $this->snapshot($y)];
    $this->assertSame(0, $this->sweep());
    $this->assertSame($before, [$this->snapshot($x), $this->snapshot($y)]);
    $this->assertSame(['closed'], $this->feedFor($x));
    $this->assertSame(['closed'], $this->feedFor($y));
  }

  /**
   * Rounds that closed early, were withdrawn or were replaced are skipped.
   */
  public function testTerminalRoundsAreSkipped(): void {
    [$group, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $agreed = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $this->answer($agreed, [[$a, 'agree'], [$b, 'agree']]);
    $this->assertSame(['agreed', 'all_agreed'], [$this->reload($agreed)->getStatus(), $this->reload($agreed)->getClosedReason()]);
    $withdrawn = $this->lifecycle()->open($group, $a, 'everyday', 'Compost bins');
    $this->answer($withdrawn, [[$b, 'block']]);
    $this->lifecycle()->withdraw($withdrawn, $a, 'Not now');
    $replaced = $this->lifecycle()->open($group, $a, 'everyday', 'Tool shelf');
    $this->answer($replaced, [[$b, 'stand_aside']]);
    $successor = $this->lifecycle()->replace($replaced, 'Tool shelf, bigger', $a, 'Bigger');
    $this->assertSame('replaced', $this->reload($replaced)->getStatus());
    $terminal = ['agreed' => $agreed, 'withdrawn' => $withdrawn, 'replaced' => $replaced];
    $before = array_map(fn ($round) => $this->snapshot($round), $terminal);

    FixedTime::$now = $successor->getDeadline() + 3 * 3600;
    $this->feed = [];
    $this->assertSame(1, $this->sweep(), 'Only the open successor is due');
    foreach ($terminal as $label => $round) {
      $this->assertSame($before[$label], $this->snapshot($round), "$label: unchanged");
      $this->assertSame([], $this->feedFor($round), "$label: no event");
    }
    $this->assertSame(['closed_participation', 'deadline'], [$this->reload($successor)->getStatus(), $this->reload($successor)->getClosedReason()]);
    $this->assertSame(['closed'], $this->feedFor($successor));
  }

  /**
   * hook_cron sweeps every Group's due rounds, oldest deadline first.
   *
   * Each run takes at most DueRoundSweeper::BATCH rounds; the next run takes
   * the rest. Rounds in different Groups are found independently. The sweep
   * reads no proposal text and checks no access.
   */
  public function testCronSweepsEveryGroupInBatches(): void {
    [$willow, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    [$aspen, , ['cy' => $c, 'dee' => $d]] = $this->setUpGroup('Aspen', ['cy', 'dee']);
    $batch = \Drupal\localnodes_consensus\Service\DueRoundSweeper::BATCH;
    $this->assertSame(50, $batch);
    $rounds = [];
    for ($i = 0; $i < $batch + 2; $i++) {
      // Alternate Groups; each round opens one second after the last.
      $rounds[] = $i % 2 === 0
        ? $this->lifecycle()->open($willow, $a, 'everyday', "Willow question $i")
        : $this->lifecycle()->open($aspen, $c, 'everyday', "Aspen question $i");
      FixedTime::$now++;
    }
    $this->answer($rounds[0], [[$a, 'agree'], [$b, 'agree']]);
    $this->answer($rounds[1], [[$c, 'agree'], [$d, 'stand_aside']]);

    FixedTime::$now = end($rounds)->getDeadline() + 60;
    $this->feed = [];
    localnodes_consensus_cron();
    $closed = array_values(array_filter($rounds, fn ($round) => $this->reload($round)->isTerminal()));
    // Round 0 closed early (all agreed), so the first run takes rounds 1..50.
    $this->assertCount($batch + 1, $closed);
    $this->assertSame('open', $this->reload(end($rounds))->getStoredStatus(), 'The newest deadline waits for the next run');
    $this->assertSame(['agreed', 'deadline'], [$this->reload($rounds[1])->getStatus(), $this->reload($rounds[1])->getClosedReason()]);
    localnodes_consensus_cron();
    foreach ($rounds as $i => $round) {
      $this->assertTrue($this->reload($round)->isTerminal(), "Round $i");
    }
    $this->assertSame([], $this->feedFor($rounds[0]), 'The early close was not touched');
    foreach (array_slice($rounds, 1) as $i => $round) {
      $this->assertSame(['closed'], $this->feedFor($round), 'Round ' . ($i + 1) . ': CLOSED once');
    }
    localnodes_consensus_cron();
    $this->assertCount($batch + 1, array_filter($this->feed, fn ($event) => $event[0] === 'closed'));

    // The sweep names no proposal text and no access check.
    $source = (string) file_get_contents(DRUPAL_ROOT . '/modules/custom/localnodes_consensus/src/Service/DueRoundSweeper.php');
    foreach (['getText(', "'text'", '->access(', 'accessCheck(TRUE)', 'ProposalFeedEvents'] as $needle) {
      $this->assertStringNotContainsString($needle, $source);
    }
  }

  /**
   * The four deadline outcomes, on time and hours late, are the same.
   *
   * A repeated finalize() (a duplicate) or a repeated sweep changes nothing
   * and sends no second CLOSED.
   */
  public function testDelayedRunMatchesOnTime(): void {
    $names = ['ada', 'bo', 'cy', 'dee', 'eli'];
    [$willow, , $w] = $this->setUpGroup('Willow', $names);
    [$aspen, , $s] = $this->setUpGroup('Aspen', array_map(fn ($name) => "$name-2", $names));
    $cases = [
      'closed_blocked' => fn ($m, $x) => [[$m['ada' . $x], 'agree'], [$m['bo' . $x], 'agree'], [$m['cy' . $x], 'agree'], [$m['dee' . $x], 'agree'], [$m['eli' . $x], 'block']],
      'closed_participation' => fn ($m, $x) => [[$m['ada' . $x], 'agree'], [$m['bo' . $x], 'agree'], [$m['cy' . $x], 'agree']],
      'closed_no_agree' => fn ($m, $x) => [[$m['ada' . $x], 'stand_aside'], [$m['bo' . $x], 'stand_aside'], [$m['cy' . $x], 'stand_aside'], [$m['dee' . $x], 'stand_aside']],
      'agreed' => fn ($m, $x) => [[$m['ada' . $x], 'agree'], [$m['bo' . $x], 'stand_aside'], [$m['cy' . $x], 'stand_aside'], [$m['dee' . $x], 'stand_aside']],
    ];
    $on_time = [];
    foreach ($cases as $state => $answers) {
      $on_time[$state] = $this->lifecycle()->open($willow, $w['ada'], 'everyday', "Case: $state");
      $this->answer($on_time[$state], $answers($w, ''));
    }
    FixedTime::$now += 3600;
    $late = [];
    foreach ($cases as $state => $answers) {
      $late[$state] = $this->lifecycle()->open($aspen, $s['ada-2'], 'everyday', "Case: $state");
      $this->answer($late[$state], $answers($s, '-2'));
    }

    // On time: the sweep at the first deadline takes Willow's rounds only.
    FixedTime::$now = reset($on_time)->getDeadline();
    $this->feed = [];
    $this->assertSame(4, $this->sweep());
    foreach ($on_time as $state => $round) {
      $this->assertSame([$state, 'deadline', NULL, "$state:deadline"], $this->ending($round), "$state: on time");
      $this->assertSame(FixedTime::$now, $this->reload($round)->getClosedAt());
    }
    foreach ($late as $state => $round) {
      $this->assertSame('open', $this->reload($round)->getStatus(), "$state: not yet due");
    }

    // Late: hours after Aspen's deadline, the result is the same.
    FixedTime::$now = reset($late)->getDeadline() + 5 * 3600;
    foreach ($late as $state => $round) {
      $this->assertEndedPending($round, "$state: waiting for a late cron");
    }
    $this->assertSame(4, $this->sweep());
    foreach ($late as $state => $round) {
      $this->assertSame($this->ending($on_time[$state]), $this->ending($round), "$state: late equals on time");
      $this->assertSame(['closed'], $this->feedFor($round), "$state: CLOSED once");
      $this->assertSame(['closed'], $this->feedFor($on_time[$state]), "$state: CLOSED once on time");
    }

    // Duplicates: a direct finalize() and another sweep change nothing.
    $before = array_map(fn ($round) => $this->snapshot($round), $on_time + array_combine(array_map(fn ($k) => "late $k", array_keys($late)), $late));
    foreach (array_merge(array_values($on_time), array_values($late)) as $round) {
      $this->lifecycle()->finalize($round);
    }
    $this->assertSame(0, $this->sweep());
    $this->assertSame($before, array_map(fn ($round) => $this->snapshot($round), $on_time + array_combine(array_map(fn ($k) => "late $k", array_keys($late)), $late)));
    $this->assertCount(8, array_filter($this->feed, fn ($event) => $event[0] === 'closed'));
  }

  /**
   * A failure before commit leaves the round pending; the retry closes it once.
   */
  public function testRetryAfterFailureBeforeCommit(): void {
    [$group, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $first = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    FixedTime::$now++;
    $second = $this->lifecycle()->open($group, $a, 'everyday', 'Compost bins');
    $this->answer($first, [[$a, 'agree'], [$b, 'stand_aside']]);
    $this->answer($second, [[$a, 'agree'], [$b, 'stand_aside']]);

    FixedTime::$now = $second->getDeadline() + 3600;
    FailingRoundLifecycle::$failures = 1;
    $this->feed = [];
    // The oldest round fails before commit; the run goes on to the next.
    $this->assertSame(1, $this->sweep());
    $this->assertSame(0, FailingRoundLifecycle::$failures, 'The failure was hit');
    $this->assertEndedPending($first, 'rolled back');
    $this->assertSame([], $this->feedFor($first), 'No CLOSED for a rolled-back close');
    $this->assertSame(['agreed', 'deadline', NULL, 'agreed:deadline'], $this->ending($second));
    $this->assertSame(['closed'], $this->feedFor($second));

    // The next run retries it: one result, one closure key, one CLOSED.
    $this->assertSame(1, $this->sweep());
    $this->assertSame($this->ending($second), $this->ending($first));
    $this->assertSame(['closed'], $this->feedFor($first));
    $before = [$this->snapshot($first), $this->snapshot($second)];
    $this->assertSame(0, $this->sweep());
    $this->assertSame($before, [$this->snapshot($first), $this->snapshot($second)]);
    $this->assertSame(['closed'], $this->feedFor($first));
    $this->assertSame(['closed'], $this->feedFor($second));
  }

  /**
   * Roster changes after the deadline leave a late sweep's result alone.
   */
  public function testLateRosterChangeKeepsTheOutcome(): void {
    $names = ['ada', 'bo', 'cy', 'gil', 'hal', 'ivy'];
    [$control, , $n] = $this->setUpGroup('Aspen', array_map(fn ($name) => "$name-2", $names));
    [$group, $manager, $m] = $this->setUpGroup('Willow', $names);
    $plain = $this->lifecycle()->open($control, $n['ada-2'], 'everyday', 'Same question');
    FixedTime::$now += 3600;
    $changed = $this->lifecycle()->open($group, $m['ada'], 'everyday', 'Same question');
    $this->answer($plain, [[$n['ada-2'], 'agree'], [$n['bo-2'], 'agree'], [$n['cy-2'], 'agree'], [$n['gil-2'], 'stand_aside']]);
    $this->answer($changed, [[$m['ada'], 'agree'], [$m['bo'], 'agree'], [$m['cy'], 'agree'], [$m['gil'], 'stand_aside']]);

    // The control round closes on time.
    FixedTime::$now = $plain->getDeadline();
    $this->assertSame(1, $this->sweep());

    // After the changed round's deadline, before a late cron: three changes.
    FixedTime::$now = $changed->getDeadline() + 60;
    $this->feed = [];
    $before = $this->snapshot($changed);
    $this->roster()->remove($group, $m['gil'], $manager, 'Moved away');
    $group->removeMember($m['hal']);
    $m['ivy']->block();
    $m['ivy']->save();
    $this->assertSame($before, $this->snapshot($changed), 'Each change leaves the ended round as it is');
    $this->assertEndedPending($changed);
    $this->assertSame([], $this->feedFor($changed));

    FixedTime::$now += 6 * 3600;
    $this->assertSame(1, $this->sweep());
    $this->assertSame(['agreed', 'deadline', NULL, 'agreed:deadline'], $this->ending($changed));
    $this->assertSame($this->ending($plain), $this->ending($changed));
    $this->assertSame(['closed'], $this->feedFor($changed));
  }

  /**
   * A clock moved back changes nothing; forward again, the round closes once.
   */
  public function testClockMovedBackChangesNothing(): void {
    [$group, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $this->answer($round, [[$a, 'agree'], [$b, 'stand_aside']]);
    FixedTime::$now = $round->getDeadline();
    $this->assertEndedPending($round);
    $before = $this->snapshot($round);

    FixedTime::$now = $round->getDeadline() - 3600;
    $this->feed = [];
    $this->assertSame(0, $this->sweep());
    $this->assertSame($before, $this->snapshot($round));
    $this->assertSame('open', $this->reload($round)->getStatus());
    $this->assertSame([], $this->feed);

    FixedTime::$now = $round->getDeadline() + 10;
    $this->assertSame(1, $this->sweep());
    $this->assertSame(['agreed', 'deadline', NULL, 'agreed:deadline'], $this->ending($round));
    $this->assertSame(0, $this->sweep());
    $this->assertSame(['closed'], $this->feedFor($round));
  }

  /**
   * Leaving the Group: listeners that save work, and the sweep withdraws once.
   *
   * The membership delete writes the dated roster row inside Group's
   * transaction and announces nothing there, so no event goes out from
   * core's post-commit callbacks. The round stays open until the sweep (or
   * a response, or finalize()) reads the stored row, sends its roster event
   * once and withdraws the round with reason roster_changed, keeping every
   * response. The departure keeps the time of the leave.
   */
  public function testLeavingTheGroupIsSweptOnceAndListenersMaySave(): void {
    [$group, , ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $this->answer($round, [[$b, 'block'], [$c, 'agree']]);
    $saved = [];
    foreach (['closed' => static::CLOSED, 'roster' => 'localnodes_consensus.roster_ended'] as $short => $name) {
      $this->container->get('event_dispatcher')->addListener($name, function () use ($short, &$saved): void {
        $account = User::create(['name' => 'listener-' . $short . '-' . count($saved), 'status' => 1]);
        $account->save();
        $saved[] = [$short, (int) $account->id()];
      });
    }
    $this->feed = [];

    FixedTime::$now += 3600;
    $left_at = FixedTime::$now;
    $group->removeMember($b);
    $this->assertFalse($group->getMember($b));
    // The ending row exists right after the removal, dated at the leave.
    $rows = $this->endings($group, $b);
    $this->assertCount(1, $rows, 'One ending row, written by the removal');
    $this->assertSame(['approved', 'left', 'membership_ended', $left_at], [$rows[0]->getBefore(), $rows[0]->getAfter(), $rows[0]->getReason(), $rows[0]->getCreatedTime()]);
    $this->assertSame('left', $this->roster()->standing($group, (int) $b->id()));
    // No event yet, and the round is still open.
    $this->assertSame('open', $this->reload($round)->getStatus(), 'Still open right after the removal');
    $this->assertSame([], $this->feedFor($round));
    $this->assertSame([], $saved);

    FixedTime::$now += 60;
    $this->assertSame(1, $this->sweep());
    $this->assertSame(['withdrawn', 'roster_changed', NULL, 'withdrawn:roster_changed'], $this->ending($round));
    $this->assertSame([['block', (int) $b->id()], ['agree', (int) $c->id()]], array_map(fn ($r) => [$r['choice'], $r['member']], $this->reload($round)->getResponses()));
    $this->assertSame(['closed'], $this->feedFor($round));
    $shorts = array_column($saved, 0);
    sort($shorts);
    $this->assertSame(['closed', 'roster'], $shorts, 'Both listeners saved, once each');
    foreach ($saved as [, $id]) {
      $this->assertInstanceOf(UserInterface::class, User::load($id));
    }
    // Still the one row the removal wrote, dated at the leave, not the sweep.
    $rows = $this->endings($group, $b);
    $this->assertCount(1, $rows);
    $this->assertSame($left_at, $rows[0]->getCreatedTime());

    // Again: nothing more.
    $this->assertSame(0, $this->sweep());
    $this->assertSame(['closed'], $this->feedFor($round));
    $this->assertCount(2, $saved);
  }

  /**
   * Leaving and rejoining before anything looks does not restore standing.
   *
   * The row written at removal stays: standing reads left after the rejoin,
   * the sweep withdraws the open round once with one CLOSED and one roster
   * event, and the rejoined member cannot respond. A response from the
   * rejoined member before any sweep is refused the same way and sends the
   * event itself; the later sweep sends nothing more. A manager's
   * re-approval after the rejoin does not bring back the old round either,
   * and the old ending never withdraws a round opened after it.
   */
  public function testLeaveAndRejoinBeforeTheSweep(): void {
    [$group, , ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $this->answer($round, [[$c, 'agree']]);
    $roster_events = [];
    $this->container->get('event_dispatcher')->addListener('localnodes_consensus.roster_ended', function ($event) use (&$roster_events): void {
      $roster_events[] = [(int) $event->getArgument('group_id'), (int) $event->getArgument('account_id'), (int) $event->getArgument('audit_id')];
    });
    $this->feed = [];

    // Bo leaves and rejoins within one cron interval.
    FixedTime::$now += 3600;
    $left_at = FixedTime::$now;
    $group->removeMember($b);
    FixedTime::$now += 10;
    $group->addMember($b);
    $this->assertNotFalse($group->getMember($b));
    $this->assertSame('left', $this->roster()->standing($group, (int) $b->id()), 'Rejoining does not restore standing');
    $this->assertNotContains((int) $b->id(), $this->roster()->currentRoster($group));
    $this->assertSame('open', $this->reload($round)->getStatus());
    $this->assertSame([], $roster_events);

    FixedTime::$now += 50;
    $this->assertSame(1, $this->sweep());
    $this->assertSame(['withdrawn', 'roster_changed', NULL, 'withdrawn:roster_changed'], $this->ending($round));
    $this->assertSame(['closed'], $this->feedFor($round));
    $rows = $this->endings($group, $b);
    $this->assertCount(1, $rows);
    $this->assertSame($left_at, $rows[0]->getCreatedTime());
    $this->assertSame([[(int) $group->id(), (int) $b->id(), (int) $rows[0]->id()]], $roster_events, 'One roster event for the row');
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->respond($round, $b, 'agree', NULL), 'The rejoined member responds');
    $this->assertSame(0, $this->sweep());
    $this->assertSame(['closed'], $this->feedFor($round));
    $this->assertCount(1, $roster_events);

    // The rejoined member responds before any sweep: refused, withdrawn once.
    [$aspen, $organizer, ['fay' => $f, 'gus' => $g, 'hal' => $h]] = $this->setUpGroup('Aspen', ['fay', 'gus', 'hal']);
    $lazy = $this->lifecycle()->open($aspen, $f, 'everyday', 'Compost bins');
    $roster_events = [];
    $this->feed = [];
    FixedTime::$now += 3600;
    $aspen->removeMember($g);
    $aspen->addMember($g);
    FixedTime::$now += 10;
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->respond($lazy, $g, 'agree', NULL), 'Rejoined, before any sweep');
    $this->assertSame(['withdrawn', 'roster_changed', NULL, 'withdrawn:roster_changed'], $this->ending($lazy));
    $this->assertSame(['closed'], $this->feedFor($lazy));
    $this->assertCount(1, $roster_events, 'The response sent the roster event');
    $this->sweep();
    $this->assertSame(['closed'], $this->feedFor($lazy));
    $this->assertCount(1, $roster_events, 'The sweep sends it no second time');

    // Leave, rejoin and re-approval before the sweep: the old round is still
    // withdrawn, and a round opened after the leave is left alone.
    $old = $this->lifecycle()->open($aspen, $f, 'everyday', 'Tool shelf');
    $roster_events = [];
    FixedTime::$now += 3600;
    $aspen->removeMember($h);
    $aspen->addMember($h);
    $this->roster()->approve($aspen, $h, $organizer, 'Back again');
    $this->assertSame('approved', $this->roster()->standing($aspen, (int) $h->id()));
    FixedTime::$now += 10;
    $new = $this->lifecycle()->open($aspen, $f, 'everyday', 'Rain barrels');
    $this->assertContains((int) $h->id(), $new->getOpeningRoster());
    FixedTime::$now += 50;
    $this->sweep();
    $this->assertSame(['withdrawn', 'roster_changed'], array_slice($this->ending($old), 0, 2), 'Left after the old round opened');
    $this->assertSame('open', $this->reload($new)->getStatus(), 'Opened after the leave');
    $this->assertSame(['opened'], $this->feedFor($new));
    $this->assertCount(1, $roster_events);
    $this->lifecycle()->respond($new, $h, 'agree', NULL);
    $this->assertSame('open', $this->reload($new)->getStatus());

    // Leave, rejoin and re-approval, then a response before any sweep: the
    // lazy check reads the stored ending, not the standing, and refuses.
    [$birch, $birch_organizer, ['ivy' => $i, 'jo' => $j]] = $this->setUpGroup('Birch', ['ivy', 'jo']);
    $kept = $this->lifecycle()->open($birch, $i, 'everyday', 'Seed library');
    $roster_events = [];
    $this->feed = [];
    FixedTime::$now += 3600;
    $birch->removeMember($j);
    $birch->addMember($j);
    $this->roster()->approve($birch, $j, $birch_organizer, 'Back again');
    $this->assertSame('approved', $this->roster()->standing($birch, (int) $j->id()));
    FixedTime::$now += 10;
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->respond($kept, $j, 'agree', NULL), 'Re-approved, before any sweep');
    $this->assertSame(['withdrawn', 'roster_changed', NULL, 'withdrawn:roster_changed'], $this->ending($kept));
    $this->assertSame(['closed'], $this->feedFor($kept));
    $this->assertCount(1, $roster_events, 'The response sent the roster event');
    $this->sweep();
    $this->assertSame(['closed'], $this->feedFor($kept));
    $this->assertCount(1, $roster_events);
  }

  /**
   * Before the deadline the sweep withdraws a round a departed member was on.
   *
   * A blocked account and a membership that ended are both found. A round
   * of another Group, and a round its departed member was not on, stay
   * open. A departure is dated at the leave, so a member who left before
   * the deadline withdraws the round even when the sweep finds it after the
   * deadline.
   */
  public function testDepartedMembersAreSweptBeforeTheDeadline(): void {
    [$willow, , ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    [$aspen, , ['fay' => $f, 'gus' => $g]] = $this->setUpGroup('Aspen', ['fay', 'gus']);
    $blocked = $this->lifecycle()->open($willow, $a, 'everyday', 'Blocked account');
    $other = $this->lifecycle()->open($aspen, $f, 'everyday', 'Another Group');
    $c->block();
    $c->save();
    $after = $this->lifecycle()->open($willow, $a, 'everyday', 'Opened after the block');
    $this->assertSame([(int) $a->id(), (int) $b->id()], $after->getOpeningRoster());
    $this->feed = [];

    FixedTime::$now += 3600;
    $this->assertSame(1, $this->sweep());
    $this->assertSame(['withdrawn', 'roster_changed'], array_slice($this->ending($blocked), 0, 2));
    $this->assertSame(['closed'], $this->feedFor($blocked));
    foreach (['another Group' => $other, 'not on its roster' => $after] as $label => $round) {
      $this->assertSame('open', $this->reload($round)->getStatus(), $label);
      $this->assertSame([], $this->feedFor($round), $label);
    }

    // A membership that ended is found the same way.
    $left = $this->lifecycle()->open($willow, $a, 'everyday', 'Bo leaves');
    $willow->removeMember($b);
    $this->assertSame('open', $this->reload($left)->getStatus());
    FixedTime::$now += 60;
    $this->assertSame(2, $this->sweep(), 'Bo was on both open Willow rounds');
    $this->assertSame(['withdrawn', 'roster_changed'], array_slice($this->ending($left), 0, 2));
    $this->assertSame(['withdrawn', 'roster_changed'], array_slice($this->ending($after), 0, 2));
    $this->assertSame('open', $this->reload($other)->getStatus());

    // Leaves one minute before the deadline; the sweep runs after it. The
    // departure is dated at the leave, before the deadline, so the round is
    // withdrawn, not decided.
    $late = $this->lifecycle()->open($aspen, $f, 'everyday', 'Last minute');
    $this->answer($late, [[$f, 'agree'], [$g, 'stand_aside']]);
    $this->feed = [];
    FixedTime::$now = $late->getDeadline() - 60;
    $aspen->removeMember($g);
    FixedTime::$now = $late->getDeadline() + 60;
    $this->sweep();
    $this->assertSame(['withdrawn', 'roster_changed', NULL, 'withdrawn:roster_changed'], $this->ending($late), 'Left before the deadline: withdrawn');
    $this->assertSame(['closed'], $this->feedFor($late));
    $rows = $this->endings($aspen, $g);
    $this->assertSame($late->getDeadline() - 60, end($rows)->getCreatedTime());
  }

  /**
   * The account's ending roster rows in the Group, oldest first.
   *
   * @return \Drupal\localnodes_consensus\Entity\RosterAudit[]
   *   Rows.
   */
  protected function endings(GroupInterface $group, UserInterface $account): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_roster_audit');
    $storage->resetCache();
    $rows = $storage->loadByProperties(['gid' => $group->id(), 'account' => $account->id()]);
    ksort($rows);
    return array_values(array_filter($rows, fn ($row) => $row->getAfter() !== 'approved'));
  }

}
