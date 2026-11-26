<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\localnodes_consensus\Kernel\Support\FixedTime;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * The frozen decision record and its lifecycle.
 *
 * The three modules go in through the module installer, as on a fresh
 * install. The Group type, its manager role and the template id are read
 * from shipped config, never written here. The server clock is fixed.
 *
 * @group localnodes_consensus
 */
final class RoundLifecycleTest extends GroupAccessKernelTestBase {

  /**
   * The feed event names (ProposalFeedEvents).
   */
  protected const OPENED = 'localnodes_proposal_feed.opened';
  protected const CHANGED = 'localnodes_proposal_feed.changed';
  protected const CLOSED = 'localnodes_proposal_feed.closed';

  /**
   * Round and response entity type ids.
   */
  protected const ROUND = 'localnodes_consensus_round';
  protected const RESPONSE = 'localnodes_consensus_response';

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
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    FixedTime::$now = 1800000000;
    FixedTime::$next = [];
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
   * Runs the cron sweep (DueRoundSweeper).
   */
  protected function sweep(): int {
    return $this->container->get('localnodes_consensus.due_round_sweeper')->sweep();
  }

  /**
   * The roster service.
   */
  protected function roster(): object {
    return $this->container->get('localnodes_consensus.roster');
  }

  /**
   * The reader service.
   */
  protected function reader(): object {
    return $this->container->get('localnodes_consensus.current_eligible_reader');
  }

  /**
   * The rules service.
   */
  protected function rules(): object {
    return $this->container->get('localnodes_consensus.decision_rules');
  }

  /**
   * Creates an active community account.
   */
  protected function account(string $name): UserInterface {
    return User::load($this->createPersona($name)->id());
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
   * A Group of the listed type with its own and its content visibilities.
   */
  protected function visibilityGroup(string $label, string $own = 'public', array $allowed = ['public', 'community', 'group']): GroupInterface {
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
   *   The Group, its manager and the approved members in name order.
   */
  protected function setUpGroup(string $label, array $names, bool $confirm = TRUE, string $own = 'public', array $allowed = ['public', 'community', 'group']): array {
    $group = $this->visibilityGroup($label, $own, $allowed);
    $manager = $this->member($group, $label . '_organizer', TRUE);
    if ($confirm) {
      $this->container->get('localnodes_group_setup.application_manager')->confirm($group, $this->templateId(), [], FALSE, $manager);
    }
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
   * Number of stored entities of a type.
   */
  protected function stored(string $type): int {
    return (int) $this->container->get('entity_type.manager')->getStorage($type)->getQuery()->accessCheck(FALSE)->count()->execute();
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
   * Sorted account ids.
   *
   * @return int[]
   *   Ids.
   */
  protected function ids(UserInterface ...$accounts): array {
    $ids = array_map(fn (UserInterface $account) => (int) $account->id(), $accounts);
    sort($ids);
    return $ids;
  }

  /**
   * The tracer: a set-up Group opens a round on its live rules and closes early.
   */
  public function testSetUpGroupOpensOnLiveRulesAndClosesEarly(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $live = $this->rules()->currentRules($group);
    $this->assertNotNull($live['version_id'], 'Set-up seeded a live rules version');
    $this->feed = [];

    // OPENED waits for the creating transaction.
    $transaction = $this->container->get('database')->startTransaction();
    $round = $this->lifecycle()->open($group, $a, 'everyday', "Paint the porch blue.\nWe vote on colour next week.");
    $this->assertSame([], $this->feed, 'Nothing dispatched inside the transaction');
    unset($transaction);
    $this->assertSame(['opened'], $this->feedFor($round));
    $round = $this->reload($round);

    $now = FixedTime::$now;
    $this->assertSame((int) $round->id(), $round->getRecordId());
    $this->assertSame((int) $group->id(), $round->getGroupId());
    $this->assertSame('consensus', $round->getMethod());
    $this->assertSame(1, $round->getSchemaVersion());
    $this->assertSame($now, $round->getOpenedAt());
    $this->assertSame("Paint the porch blue.\nWe vote on colour next week.", $round->getText());
    $this->assertSame(1, $round->getTextVersion());
    $this->assertSame(hash('sha256', $round->getText()), $round->getTextHash());
    $this->assertNull($round->getPredecessorRecordId());
    $this->assertSame('everyday', $round->getDecisionType());
    $allowed = $this->reader()->allowedVisibilities($group);
    $this->assertSame(end($allowed), $round->getVisibility(), 'No visibility given: the most restrictive the Group allows');
    $this->assertSame($this->ids($a, $b, $c), $round->getOpeningRoster());
    $this->assertSame($live['version_id'], $round->getRulesVersionId());
    $this->assertSame(['numerator' => 2, 'denominator' => 3, 'required' => 2], $round->getQuorum());
    $this->assertNull($round->getThreshold());
    $this->assertSame($now + 7 * static::DAY, $round->getDeadline());
    $this->assertNull($round->getAmountUpToCents());
    $this->assertNull($round->getProposedRules());
    $this->assertSame([], $round->getResponses());
    $this->assertSame([], $round->getResponseRevisions());
    $this->assertSame('open', $round->getStatus());
    $this->assertNull($round->getClosedState());
    $this->assertNull($round->getClosedReason());
    $this->assertNull($round->getClosedNote());
    $this->assertNull($round->getClosedAt());
    $this->assertSame((int) $a->id(), $round->getProposerId());
    $this->assertSame((int) $a->id(), $round->getFacilitatorId(), 'The proposer facilitates by default');

    // The sha256 test vector.
    $abc = $this->lifecycle()->open($group, $b, 'everyday', 'abc');
    $this->assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $abc->getTextHash());

    // CHANGED once per new responder; none for a changed answer.
    $this->feed = [];
    FixedTime::$now += 60;
    $this->lifecycle()->respond($round, $a, 'agree', NULL);
    $this->assertSame(['changed'], $this->feedFor($round));
    $this->lifecycle()->respond($round, $b, 'stand_aside', NULL);
    $this->lifecycle()->respond($round, $b, 'agree', 'Fine by me');
    $this->assertSame(['changed', 'changed'], $this->feedFor($round), 'A changed answer is not a new responder');
    $round = $this->reload($round);
    $this->assertSame('open', $round->getStatus(), 'Two agrees leave the round open');

    // The third agree closes it at once: CLOSED, and no CHANGED for it.
    FixedTime::$now += 60;
    $this->lifecycle()->respond($round, $c, 'agree', NULL);
    $round = $this->reload($round);
    $this->assertSame('agreed', $round->getStatus());
    $this->assertSame('agreed', $round->getClosedState());
    $this->assertSame('all_agreed', $round->getClosedReason());
    $this->assertSame(FixedTime::$now, $round->getClosedAt());
    $this->assertSame(['changed', 'changed', 'closed'], $this->feedFor($round));

    // Current revisions, one per member, with stable ids; unsigned.
    $responses = $round->getResponses();
    $this->assertSame($this->ids($a, $b, $c), array_column($responses, 'member'));
    foreach ($responses as $response) {
      $this->assertSame(['revision_id', 'member', 'choice', 'reason', 'changed_at', 'signing_state'], array_keys($response));
      $this->assertIsInt($response['revision_id']);
      $this->assertSame('agree', $response['choice']);
      $this->assertSame('unsigned', $response['signing_state']);
    }
    $this->assertCount(4, $round->getResponseRevisions());
    $this->assertSame($round->getResponses(), $this->reload($round)->getResponses(), 'Revision ids are stable');

    // A later response is refused and dispatches nothing.
    $this->feed = [];
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->respond($round, $a, 'block', 'Too late'));
    $this->assertSame([], $this->feed);
  }

  /**
   * The opener picks only the type; facilitator, visibility and defaults.
   */
  public function testOpeningChecksFacilitatorOptionsAndVisibility(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $outsider = $this->account('outsider');
    $admin = $this->account('admin');
    $admin->addRole('administrator');
    $admin->save();
    $removed = $this->member($group, 'removed');
    $group->removeMember($removed);
    $blocked = $this->member($group, 'blocked');
    $blocked->block();
    $blocked->save();
    $this->feed = [];

    // Facilitator: another current member is accepted; others are refused.
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Shared tools shelf', ['facilitator' => $manager]);
    $this->assertSame((int) $manager->id(), $round->getFacilitatorId());
    $before = $this->stored(static::ROUND);
    $this->feed = [];
    foreach (['outsider' => $outsider, 'site admin, not a member' => $admin, 'removed member' => $removed, 'blocked member' => $blocked] as $label => $facilitator) {
      $this->assertRefused('facilitator_not_member', fn () => $this->lifecycle()->open($group, $a, 'everyday', 'Shared tools shelf', ['facilitator' => $facilitator]), $label);
    }
    $this->assertSame($before, $this->stored(static::ROUND), 'No round saved');
    $this->assertSame([], $this->feed, 'No event dispatched');

    // The opener chooses no rule value.
    foreach (['quorum', 'threshold', 'deadline', 'method', 'quorum_numerator', 'response_days'] as $key) {
      $this->assertRefused('option_not_allowed', fn () => $this->lifecycle()->open($group, $a, 'everyday', 'Text', [$key => 1]), $key);
    }
    $this->assertRefused('type_unknown', fn () => $this->lifecycle()->open($group, $a, 'party', 'Text'));
    $this->assertRefused('text_required', fn () => $this->lifecycle()->open($group, $a, 'everyday', "  \n "));
    // Only a current roster member proposes.
    $this->assertRefused('not_on_roster', fn () => $this->lifecycle()->open($group, $manager, 'everyday', 'Text'));
    $this->assertRefused('not_on_roster', fn () => $this->lifecycle()->open($group, $outsider, 'everyday', 'Text'));
    $this->assertSame($before, $this->stored(static::ROUND));

    // Visibility: none given (the most restrictive allowed, not the Group's
    // usual default), an allowed choice, a disallowed and an unknown value.
    $this->assertSame('group', $round->getVisibility());
    $public = $this->lifecycle()->open($group, $a, 'everyday', 'Open day', ['visibility' => 'public']);
    $this->assertSame('public', $public->getVisibility());
    [$narrow, , ['dee' => $d]] = $this->setUpGroup('Juniper', ['dee'], TRUE, 'public', ['community', 'group']);
    $this->assertRefused('visibility_not_allowed', fn () => $this->lifecycle()->open($narrow, $d, 'everyday', 'Text', ['visibility' => 'public']));
    $this->assertRefused('visibility_unknown', fn () => $this->lifecycle()->open($narrow, $d, 'everyday', 'Text', ['visibility' => 'everyone']));
    $this->assertSame('community', $this->reader()->defaultVisibility($narrow), 'The usual default here is community');
    $this->assertSame('group', $this->lifecycle()->open($narrow, $d, 'everyday', 'Text')->getVisibility(), 'None given: the most restrictive allowed');
    [$wide_only, , ['fay' => $f]] = $this->setUpGroup('Hazel', ['fay'], TRUE, 'public', ['public', 'community']);
    $this->assertSame('community', $this->lifecycle()->open($wide_only, $f, 'everyday', 'Text')->getVisibility(), 'None given, no group value allowed: the narrowest allowed');

    // A Group with no rules version opens on the config defaults.
    [$plain, , ['eli' => $e]] = $this->setUpGroup('Aspen', ['eli'], FALSE);
    $this->assertNull($this->rules()->currentRules($plain)['version_id']);
    $default = $this->lifecycle()->open($plain, $e, 'everyday', 'Text');
    $this->assertNull($default->getRulesVersionId());
    $this->assertSame(['numerator' => 2, 'denominator' => 3, 'required' => 1], $default->getQuorum(), 'Two-thirds of one, rounded up');
    $this->assertSame(FixedTime::$now + 7 * static::DAY, $default->getDeadline());
  }

  /**
   * Rounds and responses are read by their visibility, through the reader.
   */
  public function testReadingFollowsTheRoundVisibility(): void {
    [$group, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $reader_member = $this->member($group, 'reader');
    $outsider = $this->account('outsider');
    $anonymous = $this->anonymous();

    $rounds = [];
    foreach (['public', 'community', 'group'] as $visibility) {
      $rounds[$visibility] = $this->lifecycle()->open($group, $a, 'everyday', "A $visibility item", ['visibility' => $visibility]);
      $this->lifecycle()->respond($rounds[$visibility], $b, 'stand_aside', NULL);
    }
    $expected = [
      'public' => ['anonymous' => TRUE, 'outsider' => TRUE, 'member' => TRUE],
      'community' => ['anonymous' => FALSE, 'outsider' => TRUE, 'member' => TRUE],
      'group' => ['anonymous' => FALSE, 'outsider' => FALSE, 'member' => TRUE],
    ];
    $storage = $this->container->get('entity_type.manager')->getStorage(static::RESPONSE);
    foreach ($expected as $visibility => $cases) {
      $round = $this->reload($rounds[$visibility]);
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('round', $round->id())->execute();
      $response = $storage->load(reset($ids));
      foreach (['anonymous' => $anonymous, 'outsider' => $outsider, 'member' => $reader_member] as $label => $account) {
        $this->assertSame($cases[$label], $round->access('view', $account), "$visibility round: $label");
        $this->assertSame($cases[$label], $response->access('view', $account), "$visibility response: $label");
      }
      $this->assertFalse($round->access('update', $a), "$visibility: no update through the entity API");
    }

    // A removed member who wrote nothing loses reading of a group round.
    $group->removeMember($reader_member);
    $this->container->get('entity_type.manager')->getAccessControlHandler(static::ROUND)->resetCache();
    $this->assertFalse($this->reload($rounds['group'])->access('view', $reader_member));
    $this->assertTrue($this->reload($rounds['community'])->access('view', $reader_member));
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
   * Asserts a direct entity save or delete is refused.
   */
  protected function assertStorageRefused(callable $call, string $label): void {
    try {
      $call();
      $this->fail("Not refused: $label");
    }
    catch (\Drupal\Core\Entity\EntityStorageException | \LogicException $e) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * Rules with one rule for every type and the given response days.
   */
  protected function ruleSet(int $numerator, int $denominator, int $everyday_days = 7): array {
    $rule = fn (int $days) => ['method' => 'consensus', 'quorum_numerator' => $numerator, 'quorum_denominator' => $denominator, 'threshold_numerator' => NULL, 'threshold_denominator' => NULL, 'response_days' => $days];
    return ['path' => 'simple', 'everyday' => $rule($everyday_days), 'money' => $rule(7), 'rule_change' => $rule(14)];
  }

  /**
   * The deadline state table, from frozen inputs and the server clock.
   */
  public function testDeadlineStateTable(): void {
    [$group, , $m] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy', 'dee', 'eli']);
    ['ada' => $a, 'bo' => $b, 'cy' => $c, 'dee' => $d, 'eli' => $e] = $m;
    $cases = [
      'blocked' => [[[$a, 'agree'], [$b, 'agree'], [$c, 'agree'], [$d, 'agree'], [$e, 'block']], 'closed_blocked'],
      'participation' => [[[$a, 'agree'], [$b, 'agree'], [$c, 'agree']], 'closed_participation'],
      'stand-asides only' => [[[$a, 'stand_aside'], [$b, 'stand_aside'], [$c, 'stand_aside'], [$d, 'stand_aside']], 'closed_no_agree'],
      'agreed' => [[[$a, 'agree'], [$b, 'stand_aside'], [$c, 'stand_aside'], [$d, 'stand_aside']], 'agreed'],
    ];
    $rounds = [];
    foreach ($cases as $label => [$answers]) {
      $rounds[$label] = $this->lifecycle()->open($group, $a, 'everyday', "Case: $label");
      $this->assertSame(['numerator' => 2, 'denominator' => 3, 'required' => 4], $rounds[$label]->getQuorum(), 'Two-thirds of five, rounded up');
      $this->answer($rounds[$label], $answers);
    }
    // Quorum 1 of 1: every opening-roster member must respond.
    $this->config('localnodes_consensus.settings')->set('decision_rules', $this->ruleSet(1, 1))->save();
    [$all, , ['fay' => $f, 'gus' => $g]] = $this->setUpGroup('Aspen', ['fay', 'gus'], FALSE);
    $silent = $this->lifecycle()->open($all, $f, 'everyday', 'Everyone must answer');
    $this->assertSame(2, $silent->getQuorum()['required']);
    $this->answer($silent, [[$f, 'agree']]);
    $everyone = $this->lifecycle()->open($all, $f, 'everyday', 'Everyone answers');
    $this->answer($everyone, [[$f, 'agree'], [$g, 'stand_aside']]);
    $rounds['1 of 1, one silent'] = $silent;
    $rounds['1 of 1, all answered'] = $everyone;
    $expected = array_map(fn ($case) => $case[1], $cases) + ['1 of 1, one silent' => 'closed_participation', '1 of 1, all answered' => 'agreed'];

    // Before the deadline finalize() changes nothing.
    $this->feed = [];
    foreach ($rounds as $label => $round) {
      $this->assertSame('open', $this->lifecycle()->finalize($round)->getStatus(), "$label: open before the deadline");
    }
    $this->assertSame([], $this->feed);

    // At the deadline: no response, ended_pending until finalize().
    FixedTime::$now = $rounds['blocked']->getDeadline();
    $this->assertRefused('deadline_passed', fn () => $this->lifecycle()->respond($rounds['participation'], $d, 'agree', NULL));
    foreach ($rounds as $label => $round) {
      $this->assertSame('ended_pending', $this->reload($round)->getStatus(), $label);
    }
    foreach ($rounds as $label => $round) {
      $closed = $this->lifecycle()->finalize($round);
      $this->assertSame($expected[$label], $closed->getStatus(), $label);
      $this->assertSame('deadline', $closed->getClosedReason(), $label);
      $this->assertSame(FixedTime::$now, $closed->getClosedAt(), $label);
      $this->assertSame(['closed'], $this->feedFor($round), "$label: CLOSED once");
    }
    $before = array_map(fn ($round) => $this->snapshot($round), $rounds);
    foreach ($rounds as $label => $round) {
      $this->lifecycle()->finalize($round);
      $this->assertSame(['closed'], $this->feedFor($round), "$label: a repeated finalize dispatches nothing");
      $this->assertSame($before[$label], $this->snapshot($round), "$label: a repeated finalize changes nothing");
    }
  }

  /**
   * Block reasons, revision history, roster-only responding, signing_state.
   */
  public function testResponsesKeepRevisionsAndSigningStateNeverDecides(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Quiet hours after ten', ['visibility' => 'group']);

    foreach (['', '   ', NULL] as $reason) {
      $this->assertRefused('reason_required', fn () => $this->lifecycle()->respond($round, $b, 'block', $reason));
    }
    $this->assertRefused('choice_unknown', fn () => $this->lifecycle()->respond($round, $b, 'yes', NULL));
    $first = $this->lifecycle()->respond($round, $b, 'block', 'Night shifts sleep in the day');
    $this->assertSame('Night shifts sleep in the day', $first->getReason());
    $second = $this->lifecycle()->respond($round, $b, 'stand_aside', NULL);
    $third = $this->lifecycle()->respond($round, $b, 'agree', NULL);
    $this->assertGreaterThan((int) $first->id(), (int) $second->id());
    $revisions = $this->reload($round)->getResponseRevisions();
    $this->assertSame([(int) $first->id(), (int) $second->id(), (int) $third->id()], array_column($revisions, 'revision_id'));
    $this->assertSame(['block', 'stand_aside', 'agree'], array_column($revisions, 'choice'));
    $this->assertSame([(int) $third->id()], array_column($this->reload($round)->getResponses(), 'revision_id'));

    // Added after opening: reads, cannot respond. The API has no actor.
    $late = $this->member($group, 'late');
    $this->roster()->approve($group, $late, $manager, 'Moved in');
    $this->assertRefused('not_on_opening_roster', fn () => $this->lifecycle()->respond($round, $late, 'agree', NULL));
    $this->assertTrue($this->reload($round)->access('view', $late));
    $params = array_map(fn ($p) => $p->getName(), (new \ReflectionMethod($this->lifecycle(), 'respond'))->getParameters());
    $this->assertSame(['round', 'member', 'choice', 'reason'], $params, 'A member responds only as themselves');

    // signing_state is settable; nothing else on a saved revision is.
    $round_before = $this->snapshot($round);
    $third->set('signing_state', 'scheme-a');
    $third->save();
    $this->assertSame('scheme-a', $this->reload($third)->getSigningState());
    $this->assertSame($round_before, $this->snapshot($round), 'Setting signing_state leaves the round unchanged');
    foreach (['choice' => 'block', 'reason' => 'Changed', 'member' => $a->id(), 'changed_at' => 5, 'round' => 999] as $field => $value) {
      $copy = $this->reload($third);
      $copy->set($field, $value);
      $this->assertStorageRefused(fn () => $copy->save(), "change $field");
    }
    foreach (['', str_repeat('x', 65), "two\nlines", '<b>x</b>'] as $state) {
      $copy = $this->reload($third);
      $copy->set('signing_state', $state);
      $this->assertStorageRefused(fn () => $copy->save(), "signing_state '$state'");
    }
    $this->assertSame('agree', $this->reload($third)->getChoice());

    // Outcome invariance: the same answers, every revision signed. The
    // roster is now four (late joined), so quorum needs three responses.
    $plain = $this->lifecycle()->open($group, $a, 'everyday', 'Same answers');
    $signed = $this->lifecycle()->open($group, $a, 'everyday', 'Same answers');
    $this->assertSame(3, $plain->getQuorum()['required']);
    foreach ([$plain, $signed] as $copy) {
      $this->answer($copy, [[$a, 'agree'], [$b, 'stand_aside'], [$c, 'stand_aside']]);
    }
    $storage = $this->container->get('entity_type.manager')->getStorage(static::RESPONSE);
    foreach ($storage->loadByProperties(['round' => $signed->id()]) as $revision) {
      $revision->set('signing_state', 'scheme-b')->save();
    }
    FixedTime::$now = $plain->getDeadline();
    $one = $this->lifecycle()->finalize($plain);
    $two = $this->lifecycle()->finalize($signed);
    $this->assertSame([$one->getStatus(), $one->getClosedReason(), $one->getClosureKey()], [$two->getStatus(), $two->getClosedReason(), $two->getClosureKey()]);
    $this->assertSame('agreed', $one->getStatus());

    // Still settable after close.
    $after = $this->reload($third);
    $after->set('signing_state', 'scheme-c');
    $after->save();
    $this->assertSame('scheme-c', $this->reload($third)->getSigningState());
  }

  /**
   * A reason is stored as written, with only its line ends made LF.
   *
   * A signing module may sign the stored reason, so it must be exactly what the member
   * wrote: CRLF and a lone CR become LF, and nothing else changes (no trim,
   * no collapsing). Emptiness is still judged on the trimmed text, and the
   * no-new-revision check compares the stored form.
   */
  public function testReasonIsStoredAsWrittenWithLfLineEnds(): void {
    [$group, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Quiet hours after ten', ['visibility' => 'group']);

    $this->assertRefused('reason_required', fn () => $this->lifecycle()->respond($round, $b, 'block', "  \r\n "));
    $this->assertNull($this->lifecycle()->respond($round, $b, 'stand_aside', "  \r\n ")->getReason());
    $this->assertNull($this->reload($round)->getResponses()[0]['reason']);

    $written = "  line one\r\nline two\rline three  ";
    $stored = "  line one\nline two\nline three  ";
    $first = $this->lifecycle()->respond($round, $b, 'block', $written);
    $this->assertSame($stored, $first->getReason());
    $this->assertSame($stored, $this->reload($first)->getReason());
    $revisions = $this->reload($round)->getResponseRevisions();
    $this->assertSame($stored, end($revisions)['reason']);
    $this->assertSame($stored, $this->reload($round)->getResponses()[0]['reason']);

    // The same choice and the same text, in either line-end form, saves
    // nothing new.
    foreach ([$written, $stored] as $again) {
      $this->assertSame((int) $first->id(), (int) $this->lifecycle()->respond($round, $b, 'block', $again)->id());
    }
    $this->assertCount(count($revisions), $this->reload($round)->getResponseRevisions());

    // Inner spacing and tabs are kept too.
    $spaced = $this->lifecycle()->respond($round, $b, 'block', "Two  spaces,\ta tab\n\n and a gap");
    $this->assertGreaterThan((int) $first->id(), (int) $spaced->id());
    $this->assertSame("Two  spaces,\ta tab\n\n and a gap", $this->reload($spaced)->getReason());
  }

  /**
   * A closed round and every response are locked at the entity layer.
   */
  public function testClosedRecordIsLocked(): void {
    [$group, , $m] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    ['ada' => $a, 'bo' => $b, 'cy' => $c] = $m;
    $open = $this->lifecycle()->open($group, $a, 'everyday', 'Still open');
    $this->answer($open, [[$b, 'agree']]);
    $agreed = $this->lifecycle()->open($group, $a, 'everyday', 'All agree');
    $this->answer($agreed, [[$a, 'agree'], [$b, 'agree'], [$c, 'agree']]);
    $blocked = $this->lifecycle()->open($group, $a, 'everyday', 'Blocked');
    $this->answer($blocked, [[$a, 'agree'], [$b, 'block']]);
    $thin = $this->lifecycle()->open($group, $a, 'everyday', 'Too few');
    $this->answer($thin, [[$a, 'agree']]);
    $aside = $this->lifecycle()->open($group, $a, 'everyday', 'Nobody agrees');
    $this->answer($aside, [[$a, 'stand_aside'], [$b, 'stand_aside']]);

    // Deleting a response is refused while the round is open.
    $storage = $this->container->get('entity_type.manager')->getStorage(static::RESPONSE);
    $open_response = current($storage->loadByProperties(['round' => $open->id()]));
    $this->assertStorageRefused(fn () => $open_response->delete(), 'delete an open round\'s response');

    FixedTime::$now = $blocked->getDeadline();
    $closed = ['agreed' => $agreed];
    foreach (['closed_blocked' => $blocked, 'closed_participation' => $thin, 'closed_no_agree' => $aside] as $state => $round) {
      $closed[$state] = $this->lifecycle()->finalize($round);
      $this->assertSame($state, $closed[$state]->getStatus());
    }
    $changes = [
      'status' => 'open',
      'closed_reason' => 'deadline2',
      'closed_note' => 'Edited',
      'closed_at' => 12345,
      'closure_key' => 'other',
      'text' => 'Rewritten',
    ];
    foreach ($closed as $state => $round) {
      $before = $this->snapshot($round);
      foreach ($changes as $field => $value) {
        $copy = $this->reload($round);
        $copy->set($field, $value);
        $this->assertStorageRefused(fn () => $copy->save(), "$state: change $field");
      }
      $this->assertStorageRefused(fn () => $this->reload($round)->save(), "$state: save with no change");
      $this->assertStorageRefused(fn () => $this->reload($round)->delete(), "$state: delete");
      foreach ($storage->loadByProperties(['round' => $round->id()]) as $response) {
        $this->assertStorageRefused(fn () => $response->delete(), "$state: delete a response");
      }
      $new = $storage->create(['round' => $round->id(), 'member' => $c->id(), 'choice' => 'agree', 'changed_at' => FixedTime::$now]);
      $this->assertStorageRefused(fn () => $new->save(), "$state: a new response row");
      $this->assertSame($before, $this->snapshot($round), "$state: stored values unchanged");
    }
  }

  /**
   * An answer is refused when the deadline passes while it waits its turn.
   *
   * The first deadline check reads the clock before the round's lock; the
   * clock then passes the deadline before the answer is saved (a request
   * that waited on the lock). The late answer is not stored and does not
   * count toward the outcome.
   */
  public function testAnswerAfterTheDeadlineInsideTheLockIsRefused(): void {
    [$group, , $m] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    ['ada' => $a, 'bo' => $b] = $m;
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Late answers');
    $this->answer($round, [[$a, 'agree']]);
    $deadline = $round->getDeadline();
    FixedTime::$now = $deadline + 1;
    FixedTime::$next = [$deadline - 1];
    try {
      $this->lifecycle()->respond($round, $b, 'agree', NULL);
      $this->fail('An answer saved after the deadline was accepted');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringStartsWith('deadline_passed', $e->getMessage());
    }
    FixedTime::$next = [];
    $this->assertSame([(int) $a->id()], array_column($this->reload($round)->getResponses(), 'member'), 'Only the timely answer is stored');
    $closed = $this->lifecycle()->finalize($round);
    $this->assertSame('closed_participation', $closed->getStatus(), 'The late answer does not count toward participation');
  }

  /**
   * An answer is stored with the time that decided it was in time.
   *
   * Both deadline checks read the clock before the deadline and the clock
   * then passes it. The answer was in time when its turn came, so it is
   * stored, timed before the deadline; no stored answer is ever timed at or
   * after the deadline (it would be a late answer that still counts).
   */
  public function testAnswerIsTimedByItsDeadlineCheck(): void {
    [$group, , $m] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    ['ada' => $a, 'bo' => $b] = $m;
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Answers timed late');
    $this->answer($round, [[$a, 'agree']]);
    $deadline = $round->getDeadline();
    FixedTime::$now = $deadline + 1;
    FixedTime::$next = [$deadline - 1, $deadline - 1];
    $this->lifecycle()->respond($round, $b, 'agree', NULL);
    FixedTime::$next = [];
    $times = array_column($this->reload($round)->getResponses(), 'changed_at', 'member');
    $this->assertSame($deadline - 1, $times[(int) $b->id()] ?? NULL, 'The answer is timed by the check that let it in');
    foreach ($times as $time) {
      $this->assertLessThan($deadline, $time, 'No stored answer is timed at or after the deadline');
    }
  }

  /**
   * A response timed at or after the deadline never counts.
   *
   * Storage refuses one saved directly, skipping respond(); and a late row
   * already stored (written around storage) is left out of the outcome.
   */
  public function testLateResponseRowsNeverCount(): void {
    [$group, , $m] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    ['ada' => $a, 'bo' => $b] = $m;
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Late rows');
    $this->answer($round, [[$a, 'agree']]);
    $deadline = $round->getDeadline();
    $storage = $this->container->get('entity_type.manager')->getStorage(static::RESPONSE);
    FixedTime::$now = $deadline + 1;
    foreach ([$deadline, $deadline + 1] as $late) {
      $row = $storage->create([
        'round' => $round->id(),
        'member' => $b->id(),
        'choice' => 'agree',
        'changed_at' => $late,
      ]);
      $this->assertStorageRefused(fn () => $row->save(), "a response timed $late, deadline $deadline");
    }
    $this->assertCount(1, $this->reload($round)->getResponses(), 'Nothing late was stored');

    // A late row written around storage: copied from the timely one.
    $db = $this->container->get('database');
    $table = 'localnodes_consensus_response';
    $copy = $db->select($table, 'r')->fields('r')->condition('round', $round->id())->execute()->fetchAssoc();
    unset($copy['id']);
    $copy['uuid'] = $this->container->get('uuid')->generate();
    $copy['member'] = $b->id();
    $copy['changed_at'] = $deadline + 1;
    $db->insert($table)->fields($copy)->execute();
    $storage->resetCache();
    $this->assertCount(2, $this->reload($round)->getResponseRevisions(), 'The late row is there');
    $this->assertSame('closed_participation', $this->lifecycle()->finalize($round)->getStatus(), 'The late agree does not count');
  }

  /**
   * A copy left from a refused save never writes over a closed round.
   */
  public function testStaleCopyNeverReopensClosedRound(): void {
    [$group, , $m] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    ['ada' => $a, 'bo' => $b, 'cy' => $c] = $m;
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Stale copy');
    $copy = $this->reload($round);
    $text = $copy->get('text')->value;
    $copy->set('text', 'Rewritten');
    $this->assertStorageRefused(fn () => $copy->save(), 'change frozen text while open');
    $copy->set('text', $text);
    $this->answer($round, [[$a, 'agree'], [$b, 'agree'], [$c, 'agree']]);
    $before = $this->snapshot($round);
    $this->assertSame('agreed', $this->reload($round)->getStatus());
    $this->assertStorageRefused(fn () => $copy->save(), 'save the stale open copy over the closed round');
    $this->assertSame($before, $this->snapshot($round), 'The closed round is unchanged');
    $this->assertSame('agreed', $this->reload($round)->getStatus());
  }

  /**
   * The feed adapter maps every kind, state and visibility case.
   */
  public function testFeedAdapterMapsEveryCase(): void {
    [$group, , ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $collector = $this->container->get('localnodes_proposal_feed.sources');
    $rules = $this->rules()->currentRules($group);
    unset($rules['version_id']);

    $everyday = $this->lifecycle()->open($group, $a, 'everyday', "\n\n  Paint   the porch  \nDetails follow.", ['visibility' => 'public']);
    $money = $this->lifecycle()->open($group, $a, 'money', 'Buy a ladder', ['amount_up_to_cents' => 4000]);
    $change = $this->lifecycle()->open($group, $a, 'rule_change', 'Longer to answer', ['proposed_rules' => $rules]);
    $long = $this->lifecycle()->open($group, $a, 'everyday', str_repeat('garden ', 40));
    $source = $collector->getSourceFor($everyday);
    $this->assertInstanceOf('Drupal\localnodes_consensus\Feed\RoundProposalSource', $source);

    // Applies to rounds only.
    $response = $this->lifecycle()->respond($everyday, $b, 'agree', NULL);
    $rules_row = $this->container->get('entity_type.manager')->getStorage('localnodes_decision_rules')->load($this->rules()->currentRules($group)['version_id']);
    $node = $this->createGroupTopic($group, 'A topic', 'Body', 'group');
    foreach (['response' => $response, 'rules row' => $rules_row, 'node' => $node] as $label => $entity) {
      $this->assertFalse($source->applies($entity), $label);
      $this->assertNull($collector->getSourceFor($entity), $label);
    }

    $labels = ['Everyday decision', 'Money decision', 'Rule change'];
    foreach ([$everyday, $money, $change] as $i => $round) {
      $round = $this->reload($round);
      $this->assertSame('decision', $source->getKind($round));
      $this->assertSame('open', $source->getState($round));
      $this->assertSame((int) $group->id(), (int) $source->getGroup($round)->id());
      $this->assertSame((int) $a->id(), (int) $source->getProposer($round)->id());
      $this->assertSame($round->getOpenedAt(), $source->getOpenedTime($round));
      $this->assertSame($round->getText(), $source->getText($round));
      $this->assertSame($round->toUrl()->toString(), $source->getSourceUrl($round)->toString(), 'The round\'s own page');
      $facts = $source->getFacts($round);
      $this->assertArrayNotHasKey('amount', $facts);
      $this->assertSame($labels[$i], $facts['kind_label']);
      $this->assertSame($round->getDeadline(), $facts['closes']);
      $this->assertNull($facts['result']);
    }
    $this->assertSame('1 of 3 have responded', $source->getFacts($this->reload($everyday))['status']);
    $this->assertSame('Paint the porch', $source->getTitle($this->reload($everyday)));
    $title = $source->getTitle($this->reload($long));
    $this->assertLessThanOrEqual(120, mb_strlen($title));
    $this->assertStringStartsWith('garden garden', $title);
    $this->assertStringEndsWith('garden', $title, 'Cut at a word boundary');

    // Visibility: the stored value, or group once the Group no longer allows it.
    $this->assertSame('public', $source->getVisibility($this->reload($everyday)));
    $this->assertSame('group', $source->getVisibility($this->reload($money)));
    $group->set('field_group_allowed_visibility', [['value' => 'community'], ['value' => 'group']])->save();
    $this->assertSame('group', $source->getVisibility($this->reload($everyday)));

    // Ended, then each closed state.
    FixedTime::$now = $everyday->getDeadline();
    $this->assertSame('ended', $source->getState($this->reload($everyday)));
    $this->assertNull($source->getFacts($this->reload($everyday))['result']);
    $closed = $this->lifecycle()->finalize($everyday);
    $this->assertSame('closed_participation', $closed->getStatus());
    $this->assertSame('closed', $source->getState($closed));
    $facts = $source->getFacts($closed);
    $this->assertNull($facts['status']);
    $this->assertSame('Not agreed, too few responded: 1 agree, 0 stand aside, 0 block', $facts['result']);
    $this->assertSame('closed', $source->getState($this->lifecycle()->finalize($money)));
  }

  /**
   * An agreed rule change writes one rules version; nothing else moves rules.
   */
  public function testAgreedRuleChangeAppliesOnce(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $application = $this->container->get('localnodes_group_setup.application_manager')->forGroup($group);
    $snapshot = $application->get('applied_snapshot')->value;
    $live = $this->rules()->currentRules($group);
    $old_version = $live['version_id'];
    $proposed = $this->ruleSet(2, 3, 5);
    $count = fn () => $this->stored('localnodes_decision_rules');

    $this->assertRefused('proposed_rules_not_allowed', fn () => $this->lifecycle()->open($group, $a, 'everyday', 'Text', ['proposed_rules' => $proposed]));
    $this->assertRefused('proposed_rules_required', fn () => $this->lifecycle()->open($group, $a, 'rule_change', 'Text'));
    $this->assertRefused('quorum_invalid', fn () => $this->lifecycle()->open($group, $a, 'rule_change', 'Text', ['proposed_rules' => $this->ruleSet(4, 3)]));

    $before = $this->lifecycle()->open($group, $a, 'everyday', 'Opened before the change');
    $change = $this->lifecycle()->open($group, $b, 'rule_change', 'Five days for everyday decisions', ['proposed_rules' => $proposed]);
    $this->assertSame(FixedTime::$now + 14 * static::DAY, $change->getDeadline(), 'Rule changes run 14 days');
    $this->assertSame($proposed, $change->getProposedRules());
    $failing = $this->lifecycle()->open($group, $b, 'rule_change', 'Never agreed', ['proposed_rules' => $this->ruleSet(1, 1)]);
    $at_deadline = $this->lifecycle()->open($group, $c, 'rule_change', 'Agreed at the deadline', ['proposed_rules' => $this->ruleSet(2, 3, 6)]);
    $versions = $count();

    // Early agreement writes one version, citing the round and its proposer.
    $this->answer($change, [[$a, 'agree'], [$b, 'agree'], [$c, 'agree']]);
    $this->assertSame($versions + 1, $count());
    $new = $this->rules()->currentRules($group);
    $this->assertNotSame($old_version, $new['version_id']);
    $row = $this->container->get('entity_type.manager')->getStorage('localnodes_decision_rules')->load($new['version_id']);
    $this->assertSame((int) $change->id(), $row->getDecisionRecordId());
    $this->assertSame((int) $b->id(), (int) $row->getOwnerId());
    $this->assertSame(5, $new['everyday']['response_days']);
    $this->lifecycle()->finalize($change);
    $this->assertSame($versions + 1, $count(), 'A repeated finalize writes nothing');

    // Open rounds keep their rules; new rounds freeze the new version.
    $this->assertSame($old_version, $this->reload($before)->getRulesVersionId());
    $this->assertSame($before->getOpenedAt() + 7 * static::DAY, $this->reload($before)->getDeadline());
    $after = $this->lifecycle()->open($group, $a, 'everyday', 'Opened after the change');
    $this->assertSame($new['version_id'], $after->getRulesVersionId());
    $this->assertSame(FixedTime::$now + 5 * static::DAY, $after->getDeadline());

    // At the deadline: agreed writes one more version; any other end writes none.
    $this->answer($failing, [[$a, 'agree']]);
    $this->answer($at_deadline, [[$a, 'agree'], [$b, 'agree']]);
    FixedTime::$now = $failing->getDeadline();
    $this->assertSame('closed_participation', $this->lifecycle()->finalize($failing)->getStatus());
    $this->assertSame($versions + 1, $count(), 'A rule change that is not agreed writes nothing');
    $this->assertSame('agreed', $this->lifecycle()->finalize($at_deadline)->getStatus());
    $this->assertSame($versions + 2, $count());
    $this->assertSame((int) $at_deadline->id(), $this->rules()->currentRules($group)['version_id'] ? $this->container->get('entity_type.manager')->getStorage('localnodes_decision_rules')->load($this->rules()->currentRules($group)['version_id'])->getDecisionRecordId() : 0);
    $this->lifecycle()->finalize($at_deadline);
    $this->assertSame($versions + 2, $count());

    // The set-up snapshot never moves.
    $this->assertSame($snapshot, $this->container->get('entity_type.manager')->getStorage($application->getEntityTypeId())->loadUnchanged($application->id())->get('applied_snapshot')->value);
  }

  /**
   * Source scans: one caller of addDecidedVersion.
   */
  public function testSourceScans(): void {
    $callers = [];
    foreach ($this->sourceFiles() as $relative => $source) {
      if (str_contains($source, '->addDecidedVersion(')) {
        $callers[] = $relative;
      }
    }
    $this->assertSame(['src/Service/RoundLifecycle.php'], $callers);
  }

  /**
   * Every PHP file under the module's src, keyed by relative path.
   *
   * @return array<string, string>
   *   Sources.
   */
  protected function sourceFiles(): array {
    $root = DRUPAL_ROOT . '/modules/custom/localnodes_consensus';
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/src", \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->getExtension() === 'php') {
        $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
      }
    }
    ksort($files);
    $this->assertArrayHasKey('src/Service/RoundLifecycle.php', $files);
    return $files;
  }

  /**
   * Feed events in order for several rounds: "short:id".
   *
   * @return string[]
   *   Events.
   */
  protected function feedOrder(): array {
    return array_map(fn ($event) => $event[0] . ':' . $event[2], array_values(array_filter($this->feed, fn ($event) => $event[1] === static::ROUND)));
  }

  /**
   * Replace and withdraw keep evidence, with narrow authority.
   */
  public function testReplaceAndWithdrawKeepEvidence(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $outsider = $this->account('outsider');
    $first = $this->lifecycle()->open($group, $a, 'everyday', 'Paint the porch blue', ['facilitator' => $manager]);
    $this->lifecycle()->respond($first, $b, 'block', 'Blue fades fast');
    $this->lifecycle()->respond($first, $c, 'agree', NULL);
    $evidence = $this->reload($first)->getResponseRevisions();

    foreach (['member' => $b, 'outsider' => $outsider] as $label => $actor) {
      $this->assertRefused('not_authorized', fn () => $this->lifecycle()->replace($first, 'Paint it green', $actor, 'Better'), "replace: $label");
      $this->assertRefused('not_authorized', fn () => $this->lifecycle()->withdraw($first, $actor, 'Stop'), "withdraw: $label");
    }
    $this->assertRefused('reason_required', fn () => $this->lifecycle()->replace($first, 'Paint it green', $a, ' '));
    $this->assertRefused('reason_required', fn () => $this->lifecycle()->withdraw($first, $a, ''));
    $this->assertRefused('option_not_allowed', fn () => $this->lifecycle()->replace($first, 'Paint it green', $a, 'Better', ['visibility' => 'public']));

    // Replace: the old round ends replaced with its evidence; one linked new round.
    $this->feed = [];
    $second = $this->lifecycle()->replace($first, 'Paint the porch green', $a, 'Green lasts longer');
    $old = $this->reload($first);
    $this->assertSame('replaced', $old->getStatus());
    $this->assertSame('replaced', $old->getClosedReason());
    $this->assertSame('Green lasts longer', $old->getClosedNote());
    $this->assertSame('Paint the porch blue', $old->getText());
    $this->assertSame($evidence, $old->getResponseRevisions());
    $this->assertSame((int) $first->id(), $second->getPredecessorRecordId());
    $this->assertSame(2, $second->getTextVersion());
    $this->assertSame('Paint the porch green', $second->getText());
    $this->assertSame([], $second->getResponses());
    $this->assertSame($this->roster()->currentRoster($group), $second->getOpeningRoster());
    $this->assertSame($this->rules()->currentRules($group)['version_id'], $second->getRulesVersionId());
    $this->assertSame((int) $manager->id(), $second->getFacilitatorId(), 'The facilitator carries over');
    $this->assertSame(['closed:' . $first->id(), 'opened:' . $second->id()], $this->feedOrder());
    $this->assertRefused('already_replaced', fn () => $this->lifecycle()->replace($first, 'Again', $a, 'Again'));

    // Withdraw by the facilitator.
    $this->feed = [];
    $withdrawn = $this->lifecycle()->withdraw($second, $manager, 'We will talk at the meeting');
    $this->assertSame('withdrawn', $withdrawn->getStatus());
    $this->assertSame('withdrawn', $withdrawn->getClosedReason());
    $this->assertSame(['closed'], $this->feedFor($second));
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->withdraw($second, $manager, 'Again'));
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->replace($second, 'Again', $a, 'A plain withdrawal is not replaced'));

    // A round withdrawn for roster_changed: replaced by a current roster member only.
    $third = $this->lifecycle()->open($group, $c, 'everyday', 'Shared tools shelf', ['facilitator' => $manager]);
    $group->removeMember($c);
    // Leaving writes nothing at delete time; the cron sweep withdraws.
    $this->sweep();
    $third = $this->reload($third);
    $this->assertSame('withdrawn', $third->getStatus());
    $this->assertSame('roster_changed', $third->getClosedReason());
    $this->assertRefused('not_member', fn () => $this->lifecycle()->replace($third, 'Tools shelf, again', $c, 'Proposer left'));
    $this->assertRefused('not_on_roster', fn () => $this->lifecycle()->replace($third, 'Tools shelf, again', $manager, 'Facilitator off the roster'));
    $this->roster()->approve($group, $manager, $manager, 'Organizer responds too');
    $before = $this->snapshot($third);
    $this->feed = [];
    $fourth = $this->lifecycle()->replace($third, 'Tools shelf, again', $manager, 'Fresh roster');
    $this->assertSame($before, $this->snapshot($third), 'A withdrawn predecessor is not saved again');
    $this->assertSame((int) $third->id(), $fourth->getPredecessorRecordId());
    $this->assertSame(['opened:' . $fourth->id()], $this->feedOrder());
    $this->assertSame((int) $manager->id(), $fourth->getProposerId());
    $this->assertRefused('already_replaced', fn () => $this->lifecycle()->replace($third, 'Third time', $manager, 'Again'));

    // A proposer who left the Group can no longer withdraw.
    $fifth = $this->lifecycle()->open($group, $b, 'everyday', 'Bike rack');
    $this->roster()->remove($group, $b, $manager, 'Moved away');
    $this->assertSame('withdrawn', $this->reload($fifth)->getStatus());
    $sixth = $this->lifecycle()->open($group, $a, 'everyday', 'Compost bins', ['facilitator' => $manager]);
    $group->removeMember($manager);
    $this->assertRefused('not_member', fn () => $this->lifecycle()->withdraw($sixth, $manager, 'Left anyway'));
  }

  /**
   * replace() carries and revalidates the type's payload.
   */
  public function testReplacePayloadIsCarriedAndRevalidated(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $rules = $this->rules()->currentRules($group);
    unset($rules['version_id']);
    $count = fn () => $this->stored(static::ROUND);

    $money = $this->lifecycle()->open($group, $a, 'money', 'Buy a ladder', ['amount_up_to_cents' => 5000, 'facilitator' => $b]);
    $carried = $this->lifecycle()->replace($money, 'Buy a taller ladder', $a, 'Taller');
    $this->assertSame('money', $carried->getDecisionType());
    $this->assertSame(5000, $carried->getAmountUpToCents());
    $this->assertSame((int) $b->id(), $carried->getFacilitatorId());
    $rounds = $count();
    foreach ([0, -1, '7000', 70.5] as $bad) {
      $this->assertRefused('amount_invalid', fn () => $this->lifecycle()->replace($carried, 'Buy two ladders', $a, 'Two', ['amount_up_to_cents' => $bad]));
    }
    $this->assertRefused('proposed_rules_not_allowed', fn () => $this->lifecycle()->replace($carried, 'Buy two ladders', $a, 'Two', ['proposed_rules' => $rules]));
    $this->assertSame($rounds, $count(), 'Nothing saved');
    $this->assertSame('open', $this->reload($carried)->getStatus());
    // A facilitator on the opening roster who leaves withdraws the round.
    $group->removeMember($b);
    $this->sweep();
    $this->assertSame('withdrawn', $this->reload($carried)->getStatus(), 'b was on the opening roster');
    // A facilitator off the roster who leaves: the actor facilitates the new round.
    $money2 = $this->lifecycle()->open($group, $a, 'money', 'Buy paint', ['amount_up_to_cents' => 2500, 'facilitator' => $manager]);
    $group->removeMember($manager);
    $this->assertSame('open', $this->reload($money2)->getStatus(), 'The manager was not on the roster');
    $changed = $this->lifecycle()->replace($money2, 'Buy more paint', $a, 'More', ['amount_up_to_cents' => 7000]);
    $this->assertSame(7000, $changed->getAmountUpToCents());
    $this->assertSame((int) $a->id(), $changed->getFacilitatorId());
    $this->assertSame('money', $changed->getDecisionType());

    $everyday = $this->lifecycle()->open($group, $a, 'everyday', 'Quiet hours');
    $this->assertRefused('amount_not_allowed', fn () => $this->lifecycle()->replace($everyday, 'Quieter hours', $a, 'Quieter', ['amount_up_to_cents' => 100]));

    $change = $this->lifecycle()->open($group, $a, 'rule_change', 'Longer answers', ['proposed_rules' => $rules]);
    $kept = $this->lifecycle()->replace($change, 'Longer answers, reworded', $a, 'Wording');
    $this->assertSame($rules, $kept->getProposedRules());
    $bad = $rules;
    $bad['everyday']['quorum_numerator'] = 9;
    $rounds = $count();
    $this->assertRefused('quorum_invalid', fn () => $this->lifecycle()->replace($kept, 'Again', $a, 'Again', ['proposed_rules' => $bad]));
    $this->assertSame($rounds, $count());
    $this->assertSame('open', $this->reload($kept)->getStatus());
    $new_rules = $rules;
    $new_rules['rule_change']['response_days'] = 21;
    $this->assertSame($new_rules, $this->lifecycle()->replace($kept, 'Again', $a, 'Again', ['proposed_rules' => $new_rules])->getProposedRules());

    // Visibility: kept while allowed, else the Group's default.
    $public = $this->lifecycle()->open($group, $a, 'everyday', 'Open day', ['visibility' => 'public']);
    $this->assertSame('public', $this->lifecycle()->replace($public, 'Open day, Sunday', $a, 'Day')->getVisibility());
    $public2 = $this->lifecycle()->open($group, $a, 'everyday', 'Open evening', ['visibility' => 'public']);
    $group->set('field_group_allowed_visibility', [['value' => 'community'], ['value' => 'group']])->save();
    $this->assertSame($this->reader()->defaultVisibility($group), $this->lifecycle()->replace($public2, 'Open evening, Friday', $a, 'Day')->getVisibility());
  }

  /**
   * Roster endings withdraw open rounds once and keep every response.
   */
  public function testRosterEndingsWithdrawOnce(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c, 'dee' => $d]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy', 'dee']);
    $agreed = $this->lifecycle()->open($group, $a, 'everyday', 'Already agreed');
    $this->answer($agreed, [[$a, 'agree'], [$b, 'agree'], [$c, 'agree'], [$d, 'agree']]);
    $agreed_before = $this->snapshot($agreed);
    $removal = $this->lifecycle()->open($group, $a, 'everyday', 'Removed from the roster');
    $this->lifecycle()->respond($removal, $b, 'block', 'Not without a vote');
    $leaving = $this->lifecycle()->open($group, $a, 'everyday', 'Leaving the Group');
    $this->lifecycle()->respond($leaving, $c, 'block', 'I object');

    // A roster removal: one withdrawal, evidence kept, CLOSED once.
    $this->feed = [];
    $audit = $this->roster()->remove($group, $b, $manager, 'Moved away');
    $round = $this->reload($removal);
    $this->assertSame(['withdrawn', 'roster_changed'], [$round->getStatus(), $round->getClosedReason()]);
    $this->assertSame('block', $round->getResponses()[0]['choice']);
    $this->assertSame(['closed'], $this->feedFor($removal));
    $this->assertSame(['closed'], $this->feedFor($leaving), 'b was on that opening roster too');
    // A repeated event changes nothing.
    $before = $this->snapshot($removal);
    $this->container->get('event_dispatcher')->dispatch(new \Symfony\Component\EventDispatcher\GenericEvent(NULL, ['group_id' => (int) $group->id(), 'account_id' => (int) $b->id(), 'audit_id' => (int) $audit->id()]), 'localnodes_consensus.roster_ended');
    $this->assertSame($before, $this->snapshot($removal));
    $this->assertSame(['closed'], $this->feedFor($removal));
    $this->assertSame($agreed_before, $this->snapshot($agreed), 'A terminal round never changes');

    // A Group membership removed without RosterManager.
    $open = $this->lifecycle()->open($group, $a, 'everyday', 'After the removal');
    $this->assertSame($this->ids($a, $c, $d), $open->getOpeningRoster());
    $this->lifecycle()->respond($open, $c, 'block', 'Still object');
    $this->lifecycle()->respond($open, $d, 'agree', NULL);
    // A roster addition changes nothing.
    $eve = $this->member($group, 'eve');
    $this->roster()->approve($group, $eve, $manager, 'Moved in');
    $this->assertSame('open', $this->reload($open)->getStatus());
    $this->feed = [];
    $group->removeMember($c);
    // The delete writes nothing of ours; the cron sweep finds the ending.
    $this->assertSame('open', $this->reload($open)->getStatus());
    $this->assertSame([], $this->feedFor($open));
    $this->sweep();
    $round = $this->reload($open);
    $this->assertSame(['withdrawn', 'roster_changed'], [$round->getStatus(), $round->getClosedReason()]);
    $this->assertSame([['block', (int) $c->id()], ['agree', (int) $d->id()]], array_map(fn ($r) => [$r['choice'], $r['member']], $round->getResponses()));
    $this->assertCount(2, $round->getResponseRevisions());
    $this->assertSame(['closed'], $this->feedFor($open));

    // Lazy: a blocked account is found by the next respond().
    $lazy = $this->lifecycle()->open($group, $a, 'everyday', 'Blocked account');
    $d->block();
    $d->save();
    $this->feed = [];
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->respond($lazy, $a, 'agree', NULL));
    $round = $this->reload($lazy);
    $this->assertSame(['withdrawn', 'roster_changed'], [$round->getStatus(), $round->getClosedReason()]);
    $this->assertSame(['closed'], $this->feedFor($lazy));
    $rows = $this->container->get('entity_type.manager')->getStorage('localnodes_roster_audit')->loadByProperties(['gid' => $group->id(), 'account' => $d->id()]);
    $last = end($rows);
    $this->assertSame(['left', 'membership_ended', FixedTime::$now], [$last->getAfter(), $last->getReason(), $last->getCreatedTime()]);

    // finalize() makes the same check.
    [$other, , ['fay' => $f, 'gus' => $g]] = $this->setUpGroup('Aspen', ['fay', 'gus']);
    $checked = $this->lifecycle()->open($other, $f, 'everyday', 'Checked at finalize');
    $g->block();
    $g->save();
    $this->assertSame('withdrawn', $this->lifecycle()->finalize($checked)->getStatus());
  }

  /**
   * After the deadline, no roster change alters the deadline outcome.
   */
  public function testChangesAfterTheDeadlineKeepTheOutcome(): void {
    $names = ['ada', 'bo', 'cy', 'gil', 'hal', 'ivy'];
    [$group, $manager, $m] = $this->setUpGroup('Willow', $names);
    [$control, , $n] = $this->setUpGroup('Aspen', array_map(fn ($name) => "$name-2", $names));
    $changed = $this->lifecycle()->open($group, $m['ada'], 'everyday', 'Same question');
    $plain = $this->lifecycle()->open($control, $n['ada-2'], 'everyday', 'Same question');
    $this->answer($changed, [[$m['ada'], 'agree'], [$m['bo'], 'agree'], [$m['cy'], 'agree'], [$m['gil'], 'stand_aside']]);
    $this->answer($plain, [[$n['ada-2'], 'agree'], [$n['bo-2'], 'agree'], [$n['cy-2'], 'agree'], [$n['gil-2'], 'stand_aside']]);

    FixedTime::$now = $changed->getDeadline() + 60;
    $this->feed = [];
    $before = $this->snapshot($changed);
    $this->roster()->remove($group, $m['gil'], $manager, 'Moved away');
    $group->removeMember($m['hal']);
    $m['ivy']->block();
    $m['ivy']->save();
    $this->assertSame($before, $this->snapshot($changed), 'Each change leaves the ended round as it is');
    $this->assertSame('ended_pending', $this->reload($changed)->getStatus());
    $this->assertSame([], $this->feedFor($changed));

    $one = $this->lifecycle()->finalize($changed);
    $two = $this->lifecycle()->finalize($plain);
    $this->assertSame(['agreed', 'deadline'], [$one->getStatus(), $one->getClosedReason()]);
    $this->assertSame([$two->getStatus(), $two->getClosedReason(), $two->getClosureKey()], [$one->getStatus(), $one->getClosedReason(), $one->getClosureKey()]);
    $this->assertSame(['closed'], $this->feedFor($changed));
    // The blocked account's ending was recorded at finalize, after the deadline.
    $rows = $this->container->get('entity_type.manager')->getStorage('localnodes_roster_audit')->loadByProperties(['gid' => $group->id(), 'account' => $m['ivy']->id()]);
    $this->assertGreaterThanOrEqual($changed->getDeadline(), end($rows)->getCreatedTime());
  }

  /**
   * A money round states an "up to" amount in whole cents.
   */
  public function testMoneyRoundsStateAnAmount(): void {
    [$group, , ['ada' => $a]] = $this->setUpGroup('Willow', ['ada']);
    $this->assertRefused('amount_required', fn () => $this->lifecycle()->open($group, $a, 'money', 'Buy a ladder'));
    foreach ([0, -5, '500', 5.5, NULL] as $bad) {
      $this->assertRefused('amount_invalid', fn () => $this->lifecycle()->open($group, $a, 'money', 'Buy a ladder', ['amount_up_to_cents' => $bad]), var_export($bad, TRUE));
    }
    $this->assertRefused('amount_not_allowed', fn () => $this->lifecycle()->open($group, $a, 'everyday', 'Text', ['amount_up_to_cents' => 100]));
    $this->assertSame(4000, $this->lifecycle()->open($group, $a, 'money', 'Buy a ladder', ['amount_up_to_cents' => 4000])->getAmountUpToCents());
  }

  /**
   * The boundary check: the stub recorded no call during finalization.
   */
  protected function assertNoPaymentCall(object $stub, object $round): void {
    $this->assertSame([], $stub->calls, 'Payment stub called during finalization of record ' . implode(', ', $stub->calls) . ' (checked for record ' . $round->id() . ')');
  }

  /**
   * A recording payment-enqueue stub.
   */
  protected function paymentStub(): object {
    return new class() {

      /**
       * Record ids passed to each call.
       *
       * @var int[]
       */
      public array $calls = [];

      /**
       * Records one call.
       */
      public function enqueue(int $record_id): void {
        $this->calls[] = $record_id;
      }

    };
  }

  /**
   * A stub planted on the close event is noticed at finalize().
   */
  public function testPlantedPaymentBridgeIsDetected(): void {
    [$group, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Agreed at the deadline');
    $this->answer($round, [[$a, 'agree'], [$b, 'stand_aside']]);
    $stub = $this->paymentStub();
    $this->container->get('event_dispatcher')->addListener(static::CLOSED, fn ($event) => $stub->enqueue((int) $event->getSource()->id()));
    FixedTime::$now = $round->getDeadline();
    $this->assertSame('agreed', $this->lifecycle()->finalize($round)->getStatus());
    try {
      $this->assertNoPaymentCall($stub, $round);
      $caught = NULL;
    }
    catch (\PHPUnit\Framework\ExpectationFailedException $e) {
      $caught = $e->getMessage();
    }
    $this->assertNotNull($caught, 'The boundary check failed on the planted stub');
    $this->assertStringContainsString('record ' . $round->id(), $caught);
    $this->assertSame([(int) $round->id()], $stub->calls);
  }

  /**
   * Production finalization never calls the stub, on every terminal path.
   */
  public function testFinalizationNeverCallsPaymentStub(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $stub = $this->paymentStub();
    $early = $this->lifecycle()->open($group, $a, 'money', 'Buy a ladder', ['amount_up_to_cents' => 4000]);
    $this->answer($early, [[$a, 'agree'], [$b, 'agree'], [$c, 'agree']]);
    $this->assertSame('agreed', $this->reload($early)->getStatus());
    $this->assertNoPaymentCall($stub, $early);
    $deadline = [
      'agreed' => [[$a, 'agree'], [$b, 'agree']],
      'closed_blocked' => [[$a, 'agree'], [$b, 'block']],
      'closed_participation' => [[$a, 'agree']],
      'closed_no_agree' => [[$a, 'stand_aside'], [$b, 'stand_aside']],
    ];
    $rounds = [];
    foreach ($deadline as $state => $answers) {
      $rounds[$state] = $this->lifecycle()->open($group, $a, 'money', "Spend: $state", ['amount_up_to_cents' => 1000]);
      $this->answer($rounds[$state], $answers);
    }
    $withdrawn = $this->lifecycle()->withdraw($this->lifecycle()->open($group, $a, 'money', 'Withdrawn', ['amount_up_to_cents' => 100]), $a, 'Not now');
    $this->assertNoPaymentCall($stub, $withdrawn);
    $replaced = $this->lifecycle()->open($group, $a, 'money', 'Replaced', ['amount_up_to_cents' => 100]);
    $this->lifecycle()->replace($replaced, 'Replaced, again', $a, 'Wording');
    $this->assertNoPaymentCall($stub, $replaced);
    FixedTime::$now = $rounds['agreed']->getDeadline();
    foreach ($rounds as $state => $round) {
      $this->assertSame($state, $this->lifecycle()->finalize($round)->getStatus(), $state);
      $this->assertNoPaymentCall($stub, $round);
    }
    $roster = $this->lifecycle()->open($group, $a, 'money', 'Roster changes', ['amount_up_to_cents' => 100]);
    $this->roster()->remove($group, $c, $manager, 'Moved away');
    $this->assertSame('withdrawn', $this->reload($roster)->getStatus());
    $this->assertNoPaymentCall($stub, $roster);
    $this->assertSame([], $stub->calls);
  }

  /**
   * The feed adapter reports withdrawn and replaced rounds as withdrawn.
   */
  public function testFeedAdapterMapsWithdrawnAndReplaced(): void {
    [$group, , ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $source = $this->container->get('localnodes_proposal_feed.sources');
    $withdrawn = $this->lifecycle()->withdraw($this->lifecycle()->open($group, $a, 'everyday', 'Withdrawn'), $a, 'Not now');
    $replaced = $this->lifecycle()->open($group, $a, 'rule_change', 'Replaced', ['proposed_rules' => array_diff_key($this->rules()->currentRules($group), ['version_id' => 0])]);
    $next = $this->lifecycle()->replace($replaced, 'Replaced, reworded', $a, 'Wording');
    $agreed = $this->lifecycle()->open($group, $a, 'money', 'Agreed', ['amount_up_to_cents' => 900]);
    $this->answer($agreed, [[$a, 'agree'], [$b, 'agree']]);
    $cases = [
      'withdrawn' => [$withdrawn, 'withdrawn', 'Withdrawn: 0 agree, 0 stand aside, 0 block'],
      'replaced' => [$replaced, 'withdrawn', 'Replaced by a new version: 0 agree, 0 stand aside, 0 block'],
      'agreed' => [$agreed, 'closed', 'Agreed: 2 agree, 0 stand aside, 0 block'],
      'successor' => [$next, 'open', NULL],
    ];
    foreach ($cases as $label => [$round, $state, $result]) {
      $round = $this->reload($round);
      $adapter = $source->getSourceFor($round);
      $this->assertSame($state, $adapter->getState($round), $label);
      $this->assertSame('decision', $adapter->getKind($round), $label);
      $this->assertSame($result, $adapter->getFacts($round)['result'], $label);
      $this->assertArrayNotHasKey('amount', $adapter->getFacts($round), $label);
    }
  }

  /**
   * One creation path, one dispatching file, no stream entries.
   */
  public function testCreationAndFeedSourceScans(): void {
    $files = $this->sourceFiles();
    $this->assertSame([], $this->scanViolations($files));
    // The scan sees the real creation form in the one allowed file.
    $this->assertTrue($this->createsRound($this->code($files['src/Service/RoundLifecycle.php'])));
    // Each rule fires on a planted file.
    $planted = [
      "\$storage->getStorage(DecisionRecordInterface::ENTITY_TYPE_ID)->create([]);" => 'creates a round',
      "ConsensusRound::create(['gid' => 1]);" => 'creates a round',
      "\$this->roundStorage()->create(['gid' => 1]);" => 'creates a round',
      "\$d->dispatch(new ProposalFeedEvent(\$r), ProposalFeedEvents::CLOSED);" => 'names ProposalFeedEvents',
      "\$d->dispatch(new ProposalFeedEvent(\$r), ProposalFeedEvents::CHANGED);" => 'names ProposalFeedEvents',
      "\$this->entityTypeManager->getStorage('activity')->create([]);" => 'writes a stream entry',
      "\$this->entityTypeManager->getStorage('message')->create([]);" => 'writes a stream entry',
      "\\Drupal::service('activity_creator.activity_factory');" => 'writes a stream entry',
    ];
    foreach ($planted as $line => $rule) {
      $this->assertSame(["src/Planted.php: $rule"], $this->scanViolations(['src/Planted.php' => "<?php\n$line\n"]), $line);
    }
    // Documentation alone is not a violation.
    $this->assertSame([], $this->scanViolations(['src/Documented.php' => "<?php\n/** The proposal feed hears ProposalFeedEvents::CLOSED. */\n// getStorage('activity') is never called.\n"]));
  }

  /**
   * What breaks the creation, feed-dispatch and no-stream rules.
   *
   * Comments are removed first: documentation may name the events.
   *
   * @param array<string, string> $sources
   *   Sources keyed by path relative to the module.
   *
   * @return string[]
   *   One line per finding.
   */
  protected function scanViolations(array $sources): array {
    $found = [];
    foreach ($sources as $path => $source) {
      $source = $this->code($source);
      $own = $path === 'src/Service/RoundLifecycle.php';
      if (!$own && $this->createsRound($source)) {
        $found[] = "$path: creates a round";
      }
      if (!$own && str_contains($source, 'ProposalFeedEvents')) {
        $found[] = "$path: names ProposalFeedEvents";
      }
      if (preg_match("/getStorage\\('(activity|message)'\\)|activity_creator/", $source)) {
        $found[] = "$path: writes a stream entry";
      }
    }
    return $found;
  }

  /**
   * Whether code creates a round entity.
   */
  protected function createsRound(string $code): bool {
    return str_contains($code, 'ConsensusRound::create(')
      || (bool) preg_match("/(getStorage\\((DecisionRecordInterface::ENTITY_TYPE_ID|ConsensusRound::ENTITY_TYPE_ID|'localnodes_consensus_round')\\)|roundStorage\\(\\))\\s*->\\s*create\\(/", $code);
  }

  /**
   * PHP source without its comments.
   */
  protected function code(string $source): string {
    $code = '';
    foreach (token_get_all($source) as $token) {
      if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], TRUE)) {
        continue;
      }
      $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
  }

  /**
   * One response_saved event per saved response, after the release.
   *
   * Another module may listen to this event. It carries the round id,
   * the response revision id and the member id. A changed answer is a new
   * saved row and gets its own event; a repeat that saves nothing, and a
   * refused response, get none. A listener that saves succeeds.
   */
  public function testResponseSavedFollowsEachSavedResponse(): void {
    [$group, , ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $saved = [];
    $writes = 0;
    $write = TRUE;
    $this->container->get('event_dispatcher')->addListener('localnodes_consensus.response_saved', function ($event) use (&$saved, &$writes, &$write): void {
      $saved[] = [$event->getRoundId(), $event->getResponseRevisionId(), $event->getMemberId()];
      if (!$write) {
        return;
      }
      // A listener that saves: any entity save starts a transaction.
      $transaction = $this->container->get('database')->startTransaction();
      $writes++;
      unset($transaction);
    });
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $this->assertSame([], $saved, 'Opening saves no response');
    $rid = (int) $round->id();

    // Inside an outer transaction the event waits for its commit. It then
    // arrives from core's post-commit callback, where no save can start
    // (the feed events' known limit), so this listener only records.
    $write = FALSE;
    $transaction = $this->container->get('database')->startTransaction();
    $first = $this->lifecycle()->respond($round, $a, 'agree', NULL);
    $this->assertSame([], $saved, 'Nothing dispatched inside the transaction');
    unset($transaction);
    $this->assertSame([[$rid, (int) $first->id(), (int) $a->id()]], $saved);
    $write = TRUE;

    // A changed answer is a new saved row; an identical repeat saves nothing.
    $second = $this->lifecycle()->respond($round, $b, 'stand_aside', NULL);
    $third = $this->lifecycle()->respond($round, $b, 'block', 'Not this spot');
    $this->lifecycle()->respond($round, $b, 'block', 'Not this spot');
    $this->assertSame([
      [$rid, (int) $first->id(), (int) $a->id()],
      [$rid, (int) $second->id(), (int) $b->id()],
      [$rid, (int) $third->id(), (int) $b->id()],
    ], $saved);

    // Refused responses send nothing.
    $before = $saved;
    $outsider = $this->account('zed');
    $this->assertRefused('not_on_opening_roster', fn () => $this->lifecycle()->respond($round, $outsider, 'agree', NULL));
    $this->assertRefused('reason_required', fn () => $this->lifecycle()->respond($round, $c, 'block', NULL));
    $this->assertRefused('choice_unknown', fn () => $this->lifecycle()->respond($round, $c, 'maybe', NULL));
    $this->assertSame($before, $saved, 'No event for a refused response');

    // The response that closes the round early is saved, so it is announced.
    $this->lifecycle()->respond($round, $b, 'agree', NULL);
    $last = $this->lifecycle()->respond($round, $c, 'agree', NULL);
    $this->assertSame('agreed', $this->reload($round)->getStatus());
    $this->assertSame([$rid, (int) $last->id(), (int) $c->id()], end($saved));
    $this->assertCount(5, $saved);
    $this->assertSame(4, $writes, 'Every saving listener call after a release the call owned succeeded');
    $this->assertRefused('round_closed', fn () => $this->lifecycle()->respond($round, $a, 'block', 'Late'));
    $this->assertCount(5, $saved, 'No event after close');
  }

  /**
   * Feed and roster listeners may write: events go out after the release.
   *
   * Each listener starts a database transaction, as any entity save does.
   * Inside core's post-commit callbacks that throws, so this fails if an
   * event is dispatched from one when the call owned the root transaction.
   */
  public function testListenersMayWrite(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $writes = [];
    $write = function (string $name) use (&$writes): void {
      $transaction = $this->container->get('database')->startTransaction();
      $writes[] = $name;
      unset($transaction);
    };
    foreach (['opened' => static::OPENED, 'changed' => static::CHANGED, 'closed' => static::CLOSED, 'roster' => 'localnodes_consensus.roster_ended'] as $short => $name) {
      $this->container->get('event_dispatcher')->addListener($name, fn () => $write($short));
    }
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $this->lifecycle()->respond($round, $b, 'stand_aside', NULL);
    $this->lifecycle()->withdraw($round, $a, 'Not now');
    $other = $this->lifecycle()->open($group, $a, 'everyday', 'Compost bins');
    $this->roster()->remove($group, $b, $manager, 'Moved away');
    $this->assertSame(['opened', 'changed', 'closed', 'opened'], array_slice($writes, 0, 4));
    // The invalidator and this listener hear the roster event in either order.
    $rest = array_slice($writes, 4);
    sort($rest);
    $this->assertSame(['closed', 'roster'], $rest);
    $this->assertSame(['withdrawn', 'roster_changed'], [$this->reload($other)->getStatus(), $this->reload($other)->getClosedReason()]);
  }

}
