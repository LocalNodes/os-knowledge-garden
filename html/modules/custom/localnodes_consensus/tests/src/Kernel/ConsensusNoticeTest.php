<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\localnodes_consensus\Kernel\Support\FixedTime;
use Drupal\Tests\localnodes_consensus\Kernel\Support\TestNotifier;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Short, link-only decision notices, decided at delivery.
 *
 * The three modules go in through the module installer, as on a fresh
 * install. The Group type and its manager role come from shipped config.
 * The notifier is the production class unless a test sets one of
 * TestNotifier's switches; with none set, email stays off, as the
 * preference preflight decided.
 *
 * @group localnodes_consensus
 */
final class ConsensusNoticeTest extends GroupAccessKernelTestBase {

  use AssertMailTrait;

  /**
   * Entity type ids and the pending collection.
   */
  protected const NOTICE = 'localnodes_consensus_notice';
  protected const ROUND = 'localnodes_consensus_round';
  protected const PENDING = 'localnodes_consensus.notice_pending';

  /**
   * One day in seconds.
   */
  protected const DAY = 86400;

  /**
   * A word planted in decision text; it must reach no notice or email.
   */
  protected const PLANTED = 'Quillfeather';

  /**
   * The listed Group type and its manager role.
   */
  protected string $groupType;
  protected string $managerRole;

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
    if ($container->hasDefinition('localnodes_consensus.notifier')) {
      $container->getDefinition('localnodes_consensus.notifier')->setClass(TestNotifier::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    FixedTime::$now = 1800000000;
    TestNotifier::reset();
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
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    TestNotifier::reset();
    parent::tearDown();
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
   * Runs this module's cron (the sweep, then notice delivery).
   */
  protected function cron(): void {
    localnodes_consensus_cron();
  }

  /**
   * Pending occurrence keys.
   *
   * @return string[]
   *   Keys, sorted.
   */
  protected function pendingKeys(): array {
    $keys = array_keys($this->container->get('keyvalue.database')->get(static::PENDING)->getAll());
    sort($keys);
    return $keys;
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
   * A Group of the listed type whose manager confirmed the template.
   *
   * The Group's creator (the site's first account) is also a member.
   *
   * @return array{0: \Drupal\group\Entity\GroupInterface, 1: \Drupal\user\UserInterface, 2: \Drupal\user\UserInterface[]}
   *   The Group, its manager (not on the roster) and the approved members.
   */
  protected function setUpGroup(string $label, array $names, array $allowed = ['public', 'community', 'group']): array {
    $group = Group::create([
      'type' => $this->groupType,
      'label' => $label,
      'uid' => $this->siteOwner->id(),
      'field_flexible_group_visibility' => 'public',
      'field_group_allowed_visibility' => array_map(fn (string $value) => ['value' => $value], $allowed),
    ]);
    $group->save();
    $manager = $this->member($group, $label . '_organizer', TRUE);
    $templates = $this->container->get('config.storage')->listAll('localnodes_group_setup.template.');
    sort($templates);
    $this->container->get('localnodes_group_setup.application_manager')->confirm($group, (string) $this->config(reset($templates))->get('id'), [], FALSE, $manager);
    $members = [];
    foreach ($names as $name) {
      $members[$name] = $this->member($group, $name);
      $this->roster()->approve($group, $members[$name], $manager, 'Lives here');
    }
    return [$group, $manager, $members];
  }

  /**
   * Notice rows, optionally for one occurrence, as recipient => row.
   *
   * @return array<int, \Drupal\localnodes_consensus\Entity\ConsensusNotice>
   *   Rows keyed by recipient id.
   */
  protected function notices(?string $occurrence = NULL): array {
    $storage = $this->container->get('entity_type.manager')->getStorage(static::NOTICE);
    $storage->resetCache();
    $query = $storage->getQuery()->accessCheck(FALSE)->sort('id');
    if ($occurrence !== NULL) {
      $query->condition('occurrence', $occurrence);
    }
    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $row) {
      $this->assertArrayNotHasKey($row->getRecipientId() . '|' . $row->getOccurrence(), $rows, 'One row per occurrence and recipient');
      $rows[$row->getRecipientId() . '|' . $row->getOccurrence()] = $row;
    }
    $by_recipient = [];
    foreach ($rows as $row) {
      if ($occurrence !== NULL) {
        $by_recipient[$row->getRecipientId()] = $row;
      }
    }
    return $occurrence === NULL ? array_values($rows) : $by_recipient;
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
   * Sorted recipient ids of rows.
   *
   * @return int[]
   *   Ids.
   */
  protected function recipients(array $rows): array {
    $ids = array_map(fn ($row) => $row->getRecipientId(), array_values($rows));
    sort($ids);
    return $ids;
  }

  /**
   * Clears collected mail.
   */
  protected function clearMail(): void {
    $this->container->get('state')->set('system.test_mail_collector', []);
  }

  /**
   * The tracer: one opening notice reaches each current member, link only.
   */
  public function testOpeningNoticeReachesEachCurrentMember(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $owner = User::load($this->siteOwner->id());
    $off_roster = $this->member($group, 'dee');
    $leaving = $this->member($group, 'rem');
    $blocked = $this->member($group, 'blk');
    $outsider = $this->account('zed');
    $admin = $this->account('admin');
    $admin->addRole('administrator');
    $admin->save();
    $this->clearMail();

    // Nothing is recorded when the opening rolls back. The key-value table
    // exists from installation (localnodes_consensus_modules_installed());
    // had this write created it, MySQL would have committed the transaction.
    $transaction = $this->container->get('database')->startTransaction();
    $this->lifecycle()->open($group, $a, 'everyday', 'Rolled back ' . static::PLANTED);
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame([], $this->pendingKeys(), 'A rolled-back opening records nothing');

    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Paint the porch ' . static::PLANTED, ['visibility' => 'group']);
    $key = 'opened:' . $round->id();
    $this->assertSame([$key], $this->pendingKeys());
    $this->assertSame([], $this->notices(), 'Nothing is delivered before cron');

    // Changes between the event and delivery count.
    $group->removeMember($leaving);
    $blocked->block();
    $blocked->save();
    $late = $this->member($group, 'late');

    $this->cron();
    $rows = $this->notices($key);
    $this->assertSame($this->ids($owner, $manager, $a, $b, $c, $off_roster, $late), $this->recipients($rows), 'Current, active members only, roster or not');
    foreach ([$outsider, $admin, $leaving, $blocked] as $nobody) {
      $this->assertArrayNotHasKey((int) $nobody->id(), $rows, $nobody->getAccountName() . ' gets nothing');
    }
    foreach ($rows as $row) {
      $this->assertSame('opened', $row->getKind());
      $this->assertSame((int) $round->id(), $row->getRoundId());
      $this->assertSame(FixedTime::$now, $row->getCreatedTime());
      $this->assertSame('off', $row->getEmailState(), 'No existing setting covers these notices: email off');
    }
    $this->assertSame([], $this->getMails(), 'No email is sent');
    $this->assertSame([], $this->pendingKeys(), 'A fully delivered key is removed');

    // A notice row holds no text, title, amount, count or name.
    $fields = array_keys($this->container->get('entity_field.manager')->getBaseFieldDefinitions(static::NOTICE));
    sort($fields);
    $this->assertSame(['created', 'email_state', 'id', 'kind', 'occurrence', 'recipient', 'round', 'uuid'], $fields);
    foreach ($rows as $row) {
      foreach ($row->toArray() as $values) {
        $this->assertStringNotContainsString(static::PLANTED, json_encode($values));
      }
    }

    // Another run delivers nothing more.
    $this->cron();
    $this->assertCount(7, $this->notices($key));
  }

  /**
   * The email, where a setting allows one, holds only words, name and link.
   */
  public function testEmailIsLinkOnly(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $owner = User::load($this->siteOwner->id());
    $round = $this->lifecycle()->open($group, $a, 'money', 'Buy a ladder ' . static::PLANTED, ['amount_up_to_cents' => 4321]);
    $this->lifecycle()->respond($round, $b, 'stand_aside', NULL);
    $this->clearMail();

    // Stand-in for a bound setting that allows email.
    TestNotifier::$email = TRUE;
    $this->cron();
    $mails = $this->getMails();
    $url = $round->toUrl('canonical', ['absolute' => TRUE])->toString();
    $this->assertStringStartsWith('http', $url);
    $this->assertCount(4, $mails);
    $to = array_column($mails, 'to');
    sort($to);
    $expected = array_map(fn (UserInterface $u) => $u->getEmail(), [$owner, $manager, $a, $b]);
    sort($expected);
    $this->assertSame($expected, $to);
    foreach ($mails as $mail) {
      $this->assertSame('localnodes_consensus_opened', $mail['id']);
      $text = $mail['subject'] . "\n" . $mail['body'];
      $this->assertStringContainsString('A decision round has opened', $text);
      $this->assertStringContainsString('There is a new round to read in Willow.', $text);
      $this->assertSame(1, substr_count($text, $url), 'One link, the round\'s own page');
      preg_match_all('#https?://\S+#', $text, $links);
      $this->assertSame([$url], array_values(array_unique($links[0])), 'No other link');
      foreach ([static::PLANTED, 'ladder', '43.21', '4321', $a->getAccountName(), $b->getAccountName(), 'stand', 'agree', '1 of'] as $absent) {
        $this->assertStringNotContainsStringIgnoringCase($absent, $text, "No '$absent'");
      }
    }
    foreach ($this->notices('opened:' . $round->id()) as $row) {
      $this->assertSame('sent', $row->getEmailState());
    }

    // Each kind's words, with the Group's name and nothing else.
    $words = [
      'block' => ['A block was recorded', 'A round needs your attention in Willow.'],
      'withdrawn_replaced' => ['A round was withdrawn or replaced', 'A round has changed in Willow.'],
      'closed' => ['A decision round has closed', 'A recorded result is ready to read in Willow.'],
    ];
    foreach ($words as $kind => [$heading, $line]) {
      $this->clearMail();
      $this->container->get('plugin.manager.mail')->mail('localnodes_consensus', $kind, 'x@example.com', 'en', ['group' => 'Willow', 'url' => $url]);
      [$mail] = $this->getMails();
      $this->assertStringContainsString($heading, $mail['subject']);
      $this->assertStringContainsString($line, $mail['body']);
      $this->assertStringContainsString($url, $mail['body']);
      foreach (['house', 'wallet', 'chain', 'token'] as $jargon) {
        $this->assertStringNotContainsStringIgnoringCase($jargon, $mail['subject'] . $mail['body']);
      }
    }
  }

  /**
   * A notice row is read by its recipient only, rechecked by the reader.
   */
  public function testNoticeIsReadByItsRecipientOnly(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $outsider = $this->account('zed');
    $admin = $this->account('admin');
    $admin->addRole('administrator');
    $admin->save();
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack', ['visibility' => 'group']);
    $this->cron();
    $rows = $this->notices('opened:' . $round->id());
    $mine = $rows[(int) $b->id()];

    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler(static::NOTICE);
    $this->assertTrue($mine->access('view', $b), 'The recipient reads it');
    foreach (['another member' => $a, 'manager' => $manager, 'site admin, not a member' => $admin, 'outsider' => $outsider, 'signed out' => $this->anonymous()] as $label => $account) {
      $this->assertFalse($mine->access('view', $account), $label);
    }
    foreach (['update', 'delete'] as $operation) {
      $this->assertFalse($mine->access($operation, $b), $operation);
    }
    $this->assertFalse($handler->createAccess(NULL, $b), 'create');

    // After leaving the Group, the row for a group round is refused.
    $group->removeMember($b);
    $handler->resetCache();
    $this->assertFalse($mine->access('view', $b), 'Removed: refused');
  }

  /**
   * The round's own page exists and goes through entity access.
   */
  public function testRoundPageUsesEntityAccess(): void {
    [$group, , ['ada' => $a]] = $this->setUpGroup('Willow', ['ada']);
    $this->container->get('router.builder')->rebuild();
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.localnodes_consensus_round.canonical');
    $this->assertSame('/decision/{localnodes_consensus_round}', $route->getPath());
    $this->assertSame('localnodes_consensus_round.view', $route->getRequirement('_entity_access'));
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack', ['visibility' => 'group']);
    $this->assertSame('/decision/' . $round->id(), $round->toUrl()->toString());
    $this->assertTrue($round->toUrl()->access($a), 'A member opens it');
    $this->assertFalse($round->toUrl()->access($this->account('zed')), 'An outsider does not');
    $this->assertFalse($round->toUrl()->access($this->anonymous()), 'Nor a signed-out visitor');
  }


  /**
   * The closing occurrence key of a stored round.
   */
  protected function closedKey(object $round): string {
    $round = $this->container->get('entity_type.manager')->getStorage(static::ROUND)->loadUnchanged($round->id());
    $this->assertNotNull($round->getClosureKey());
    return 'closed:' . $round->id() . ':' . $round->getClosureKey();
  }

  /**
   * Asserts one occurrence reached exactly these accounts with this kind.
   */
  protected function assertDelivered(string $key, string $kind, array $accounts, string $label): void {
    $rows = $this->notices($key);
    $this->assertSame($this->ids(...$accounts), $this->recipients($rows), $label);
    foreach ($rows as $row) {
      $this->assertSame($kind, $row->getKind(), $label);
    }
  }

  /**
   * Block, withdrawal, replacement and closing notices, once each.
   */
  public function testBlockWithdrawReplaceAndCloseNotices(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b, 'cy' => $c]] = $this->setUpGroup('Willow', ['ada', 'bo', 'cy']);
    $everyone = [User::load($this->siteOwner->id()), $manager, $a, $b, $c];
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $this->cron();
    $this->assertDelivered('opened:' . $round->id(), 'opened', $everyone, 'opened');
    $this->clearMail();

    // A block row records once; a changed answer that is not a block
    // records nothing; a later block revision is a new occurrence.
    FixedTime::$now += 60;
    $first = $this->lifecycle()->respond($round, $b, 'block', 'Not this spot');
    $this->assertSame(['block:' . $first->id()], $this->pendingKeys());
    $this->lifecycle()->respond($round, $b, 'agree', NULL);
    $this->lifecycle()->respond($round, $c, 'stand_aside', NULL);
    $this->assertSame(['block:' . $first->id()], $this->pendingKeys(), 'Agree and stand aside record nothing');
    $again = $this->lifecycle()->respond($round, $b, 'block', 'Still not this spot');
    $this->assertSame(['block:' . $first->id(), 'block:' . $again->id()], $this->pendingKeys());
    $this->cron();
    $this->assertDelivered('block:' . $first->id(), 'block', $everyone, 'first block');
    $this->assertDelivered('block:' . $again->id(), 'block', $everyone, 'second block');
    $this->assertSame([], $this->pendingKeys());

    // Withdrawn.
    $this->lifecycle()->withdraw($round, $a, 'Not now');
    $withdrawn = $this->closedKey($round);
    $this->assertSame([$withdrawn], $this->pendingKeys());
    $this->cron();
    $this->assertDelivered($withdrawn, 'withdrawn_replaced', $everyone, 'withdrawn');

    // Replaced: the old round's notice and the new round's opening.
    $old = $this->lifecycle()->open($group, $a, 'everyday', 'Compost bins');
    $this->cron();
    $new = $this->lifecycle()->replace($old, 'Compost bins by the shed', $a, 'Clearer place');
    $replaced = $this->closedKey($old);
    $expected = [$replaced, 'opened:' . $new->id()];
    sort($expected);
    $this->assertSame($expected, $this->pendingKeys());
    $this->cron();
    $this->assertDelivered($replaced, 'withdrawn_replaced', $everyone, 'replaced');
    $this->assertDelivered('opened:' . $new->id(), 'opened', $everyone, 'replacement opened');

    // Two rounds closing with the same outcome each get their own notices.
    $this->lifecycle()->withdraw($new, $a, 'Tidy up');
    $this->cron();
    $one = $this->lifecycle()->open($group, $a, 'everyday', 'Rain barrel');
    $two = $this->lifecycle()->open($group, $b, 'everyday', 'Tool shelf');
    $this->cron();
    FixedTime::$now += 8 * static::DAY;
    $this->cron();
    $this->assertSame($this->container->get('entity_type.manager')->getStorage(static::ROUND)->loadUnchanged($one->id())->getClosureKey(), $this->container->get('entity_type.manager')->getStorage(static::ROUND)->loadUnchanged($two->id())->getClosureKey(), 'Same outcome, same closure key');
    $this->assertDelivered($this->closedKey($one), 'closed', $everyone, 'first close');
    $this->assertDelivered($this->closedKey($two), 'closed', $everyone, 'second close');
    $this->assertSame([], $this->pendingKeys());

    // Email stays off throughout (no existing setting covers these notices).
    foreach ($this->notices() as $row) {
      $this->assertSame('off', $row->getEmailState());
    }
    $this->assertSame([], $this->getMails());
  }

  /**
   * Repeats, a stopped run and a failed send leave one row and email each.
   */
  public function testRepeatsStopsAndFailedSendsGiveOneRowEach(): void {
    [$group, $manager, ['ada' => $a, 'bo' => $b]] = $this->setUpGroup('Willow', ['ada', 'bo']);
    $owner = User::load($this->siteOwner->id());
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Bike rack');
    $key = 'opened:' . $round->id();
    $notifier = $this->container->get('localnodes_consensus.notifier');
    $notifier->record($key, (int) $round->id(), 'opened');
    $notifier->record($key, (int) $round->id(), 'opened');
    $this->assertSame([$key], $this->pendingKeys(), 'A repeated record writes nothing new');
    $this->clearMail();

    // Stand-in for a bound setting that allows email.
    TestNotifier::$email = TRUE;

    // A run stops after two rows: the key stays, nothing is lost.
    TestNotifier::$stopAfter = 2;
    $this->cron();
    $this->assertCount(2, $this->notices($key));
    $this->assertSame([$key], $this->pendingKeys());
    $this->assertSame([], $this->getMails(), 'Rows are saved before any email');

    // The next run completes; two sends fail once.
    TestNotifier::$stopAfter = NULL;
    TestNotifier::$failOnce = [(int) $manager->id(), (int) $b->id()];
    $this->cron();
    $rows = $this->notices($key);
    $this->assertSame($this->ids($owner, $manager, $a, $b), $this->recipients($rows));
    $this->assertSame('pending', $rows[(int) $manager->id()]->getEmailState());
    $this->assertSame('pending', $rows[(int) $b->id()]->getEmailState());
    $this->assertSame([$key], $this->pendingKeys(), 'A key with an email owed stays');

    // The manager (off the roster, so no round is withdrawn) leaves before
    // the retry: that row gets no email.
    $group->removeMember($manager);
    $this->cron();
    $this->assertSame('open', $this->container->get('entity_type.manager')->getStorage(static::ROUND)->loadUnchanged($round->id())->getStatus());
    $rows = $this->notices($key);
    $this->assertSame('off', $rows[(int) $manager->id()]->getEmailState());
    $this->assertSame('sent', $rows[(int) $b->id()]->getEmailState());
    $this->assertSame([], $this->pendingKeys());
    $to = array_column($this->getMails(), 'to');
    sort($to);
    $expected = [$owner->getEmail(), $a->getEmail(), $b->getEmail()];
    sort($expected);
    $this->assertSame($expected, $to, 'One email each; none to the member who left');

    // Further runs and repeats change nothing.
    $notifier->record($key, (int) $round->id(), 'opened');
    $this->cron();
    $this->cron();
    $this->assertCount(4, $this->notices($key));
    $this->assertCount(3, $this->getMails());
  }

  /**
   * A public round notifies current members only, decided at delivery.
   */
  public function testPublicRoundNotifiesCurrentMembersOnly(): void {
    [$group, $manager, ['ada' => $a]] = $this->setUpGroup('Willow', ['ada']);
    $owner = User::load($this->siteOwner->id());
    // Dee is a member off the roster, so her leaving withdraws no round.
    $d = $this->member($group, 'dee');
    $outsider = $this->account('zed');
    $admin = $this->account('admin');
    $admin->addRole('administrator');
    $admin->save();
    $round = $this->lifecycle()->open($group, $a, 'everyday', 'Street party', ['visibility' => 'public']);
    $this->assertTrue($round->access('view', $outsider), 'Anyone may read this round');

    // Dee leaves and Cy joins between the event and delivery.
    $group->removeMember($d);
    $c = $this->member($group, 'cy');
    $this->cron();
    $this->assertDelivered('opened:' . $round->id(), 'opened', [$owner, $manager, $a, $c], 'members at delivery only');

    // A later event follows membership at its own delivery.
    $group->addMember($d);
    $this->lifecycle()->withdraw($round, $a, 'Not now');
    $this->cron();
    $this->assertDelivered($this->closedKey($round), 'withdrawn_replaced', [$owner, $manager, $a, $c, $d], 'rejoined member included');
    foreach ([$outsider, $admin] as $nobody) {
      $this->assertSame([], array_filter($this->notices(), fn ($row) => $row->getRecipientId() === (int) $nobody->id()));
    }
  }

}
