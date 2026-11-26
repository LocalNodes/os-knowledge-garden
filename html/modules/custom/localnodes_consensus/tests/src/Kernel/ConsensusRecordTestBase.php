<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\localnodes_consensus\Kernel\Support\FixedTime;
use Drupal\Tests\localnodes_consensus\Kernel\Support\RecordingDispatcher;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Shared set-up for the meeting report, register and record page tests.
 *
 * The three modules go in through the module installer, as on a fresh
 * install. The Group type, its manager role and the template id are read
 * from shipped config, never written here. The server clock is fixed and
 * every dispatched event name is noted.
 */
abstract class ConsensusRecordTestBase extends GroupAccessKernelTestBase {

  /**
   * Entity type ids.
   */
  protected const ROUND = 'localnodes_consensus_round';
  protected const RESPONSE = 'localnodes_consensus_response';
  protected const REPORT = 'localnodes_meeting_report';
  protected const RULES = 'localnodes_decision_rules';
  protected const NOTICE = 'localnodes_consensus_notice';

  /**
   * The pending notice collection.
   */
  protected const PENDING = 'localnodes_consensus.notice_pending';

  /**
   * One day in seconds.
   */
  protected const DAY = 86400;

  /**
   * The non-authority line.
   */
  protected const LINE = 'This records a decision. It does not authorize or send a payment.';

  /**
   * The listed Group type and its manager role, from the set-up settings.
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
    if ($container->hasDefinition('event_dispatcher')) {
      $container->getDefinition('event_dispatcher')->setClass(RecordingDispatcher::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    FixedTime::$now = 1800000000;
    RecordingDispatcher::$names = [];
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
   * The meeting records service.
   */
  protected function meetings(): object {
    return $this->container->get('localnodes_consensus.meeting_records');
  }

  /**
   * The decision register service.
   */
  protected function decisions(): object {
    return $this->container->get('localnodes_consensus.decision_register');
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
   * A site administrator who is not a member of any Group.
   */
  protected function siteAdmin(string $name = 'admin'): UserInterface {
    $admin = $this->account($name);
    $admin->addRole('administrator');
    $admin->save();
    return $admin;
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
   *   The Group, its manager and the approved members keyed by name.
   */
  protected function setUpGroup(string $label, array $names, string $own = 'public', array $allowed = ['public', 'community', 'group']): array {
    $group = $this->visibilityGroup($label, $own, $allowed);
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
   * Valid meeting report values, with the confirmer given.
   */
  protected function reportValues(AccountInterface $confirmer, array $overrides = []): array {
    return $overrides + [
      'title' => 'Paint the hallway green',
      'decision_text' => "The hallway is painted green in March.\nJamie buys the paint.",
      'meeting_date' => '2026-11-12',
      'minutes' => 'Minutes of the November meeting, item 4.',
      'deciding_body' => 'The general meeting',
      'method' => 'Consensus at the meeting',
      'confirmer' => $confirmer,
    ];
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
   * Pending notice keys, sorted.
   *
   * @return string[]
   *   Keys.
   */
  protected function pendingKeys(): array {
    $keys = array_keys($this->container->get('keyvalue.database')->get(static::PENDING)->getAll());
    sort($keys);
    return $keys;
  }

  /**
   * Our own events seen since the last reset (names starting localnodes_).
   *
   * @return string[]
   *   Names.
   */
  protected function ourEvents(): array {
    return array_values(array_filter(RecordingDispatcher::$names, fn (string $name) => str_starts_with($name, 'localnodes_')));
  }

  /**
   * Forgets every cached access result for the record types.
   */
  protected function resetAccess(): void {
    foreach ([static::ROUND, static::RESPONSE, static::REPORT] as $type) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    }
  }

  /**
   * The ids in one part of the register, as "type:id", in order.
   *
   * @return string[]
   *   Keys.
   */
  protected function part(GroupInterface $group, AccountInterface $account, string $part): array {
    $this->resetAccess();
    $register = $this->decisions()->forGroup($group, $account);
    $this->assertSame(['open', 'recorded'], array_keys($register));
    return array_map(fn (array $item) => $item['type'] . ':' . $item['id'], $register[$part]);
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
   * Asserts storage refuses a call with a LogicException.
   */
  protected function assertStorageRefused(callable $call, string $label): void {
    try {
      $call();
      $this->fail("Storage allowed: $label");
    }
    catch (\LogicException $e) {
      $this->addToAssertionCount(1);
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      $this->assertInstanceOf(\LogicException::class, $e->getPrevious(), $label);
    }
  }

}
