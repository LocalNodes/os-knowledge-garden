<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_group_setup\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\localnodes_group_setup\Entity\TemplateApplication;
use Drupal\localnodes_group_setup\Exception\SetupRefusedException;
use Drupal\localnodes_group_setup\Service\TemplateApplicationManager;
use Drupal\node\Entity\Node;
use Drupal\Tests\localnodes_group_setup\Kernel\Support\InterruptibleApplicationManager;
use Drupal\Tests\localnodes_group_setup\Kernel\Support\StandInDecisionRules;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Group set-up: a Group manager confirms a template once, headlessly.
 *
 * The module goes in through the module installer, so its config/install
 * applies as on a fresh install. Group type, manager role and template id
 * are read from shipped config and YAML, never written here, so this file
 * names no Group type or kind either.
 *
 * @group localnodes_group_setup
 */
final class TemplateApplicationTest extends GroupAccessKernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * Whether the stand-in consensus rules service is in the container.
   */
  protected bool $consensusOn = TRUE;

  /**
   * The listed Group type (first entry of the module settings).
   */
  protected string $groupType;

  /**
   * That type's manager role id, from the module settings.
   */
  protected string $managerRole;

  /**
   * The template id the shipped community templates name.
   */
  protected string $templateId;

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
    if ($container->hasDefinition('localnodes_group_setup.application_manager')) {
      $container->getDefinition('localnodes_group_setup.application_manager')->setClass(InterruptibleApplicationManager::class);
    }
    if ($this->consensusOn) {
      $container->register('localnodes_consensus.decision_rules', StandInDecisionRules::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    StandInDecisionRules::reset();
    InterruptibleApplicationManager::reset();
    $this->container->get('module_installer')->install(['localnodes_group_setup'], FALSE);

    $types = $this->config('localnodes_group_setup.settings')->get('group_types');
    $this->groupType = (string) array_key_first($types);
    $this->managerRole = (string) $types[$this->groupType];
    $this->templateId = $this->communityTemplateIds()[0];

    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
  }

  /**
   * A manager previews and confirms; the Group gets one record and starter set.
   */
  public function testManagerConfirmsOnceAndGetsOneStarterSet(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $other = $this->createFlexibleGroup('Juniper', 'community');
    $this->assertSame($this->groupType, $group->bundle(), 'The fixture Group is of the listed type');
    $manager = $this->member($group, 'avery', TRUE);
    $member = $this->member($group, 'maya', FALSE);
    $outsider = $this->createVerifiedUser('outsider');
    $other_topic = $this->createGroupTopic($other, 'Juniper notes', 'Kept as is.', 'group');
    $other_before = $this->groupState($other);
    $config_before = $this->allConfig();
    $members_before = $this->memberIds($group);

    $preview = $this->manager()->preview($group, $this->templateId);
    $this->assertSame($this->templateId, $preview['template']);
    $this->assertNull($preview['application'], 'Previewing applies nothing');
    $this->assertNull($this->manager()->forGroup($group));

    $application = $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager);
    $definition = $this->manager()->definition($this->templateId);

    $this->assertSame(TemplateApplication::STATUS_APPLIED, $application->getStatus());
    $this->assertSame((int) $group->id(), $application->getGroupId());
    $this->assertSame($definition['kind'], $application->getKind());
    $this->assertSame($this->templateId, $application->getTemplateId());
    $this->assertSame($definition['version'], $application->getTemplateVersion());
    $this->assertFalse($application->isCommunityWide());
    $this->assertSame((int) $manager->id(), (int) $application->getOwnerId());
    $this->assertGreaterThan(0, $application->getCreatedTime());
    $this->assertSame($definition['words'], $application->getAppliedSnapshot()['words']);
    $this->assertSame('confirmed', $application->getHistory()[0]['action']);
    $this->assertSame((int) $manager->id(), $application->getHistory()[0]['uid']);

    // One starter page per starter item, Group-only, fictional and unadopted.
    $ids = $application->getGeneratedIds();
    $this->assertSame(array_column($definition['starter'], 'key'), array_keys($ids));
    $this->assertCount(count($definition['starter']), $this->groupTopicIds($group));
    foreach ($definition['starter'] as $item) {
      $node = Node::load($ids[$item['key']]);
      $this->assertSame($item['title'], $node->label());
      $this->assertSame('group', $node->get('field_content_visibility')->value);
      $this->assertStringContainsString('not adopted', mb_strtolower($node->label() . ' ' . $node->get('body')->value));
      $this->assertNotEmpty($group->getRelationshipsByEntity($node), 'Starter page belongs to the Group');
      $this->assertTrue($node->access('view', $member), 'A Group member reads the starter page');
      $this->assertFalse($node->access('view', $outsider), 'Someone outside the Group does not');
    }

    // A second application is refused, and nothing else changed.
    $this->assertRefused('already_applied', fn () => $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager));
    $this->assertSame($application->id(), $this->manager()->forGroup($group)->id());
    $this->assertCount(1, $this->applicationsFor($group));
    $this->assertCount(count($definition['starter']), $this->groupTopicIds($group));
    $this->assertSame($other_before, $this->groupState($other), 'The other Group is unchanged');
    $this->assertSame('Juniper notes', Node::load($other_topic->id())->label());
    $this->assertNull($this->manager()->forGroup($other));
    $this->assertEquals($config_before, $this->allConfig(), 'No configuration changed, the assistant settings included');
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []), 'No notice or welcome mail');
    $this->assertSame($members_before, $this->memberIds($group), 'Membership untouched');
  }

  /**
   * An interrupted application resumes the same record without duplicates.
   */
  public function testInterruptedApplicationResumesWithoutDuplicates(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $definition = $this->manager()->definition($this->templateId);
    $second = $definition['starter'][1]['key'];

    InterruptibleApplicationManager::$interruptAfterStarter = $second;
    try {
      $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager);
      $this->fail('The interruption was not thrown');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Interrupted', $e->getMessage());
    }

    $application = $this->manager()->forGroup($group);
    $this->assertSame(TemplateApplication::STATUS_IN_PROGRESS, $application->getStatus());
    // The second page exists, but the record has not noted it yet.
    $this->assertCount(2, $this->groupTopicIds($group));
    $this->assertCount(1, $application->getGeneratedIds());
    $this->assertRefused('in_progress', fn () => $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager));

    $resumed = $this->manager()->resume($application, $manager);
    $this->assertSame($application->id(), $resumed->id());
    $this->assertSame(TemplateApplication::STATUS_APPLIED, $resumed->getStatus());
    $this->assertCount(count($definition['starter']), $this->groupTopicIds($group), 'No duplicate starter page');
    $this->assertCount(count($definition['starter']), array_unique($resumed->getGeneratedIds()));
    $this->assertSame(array_values($resumed->getGeneratedIds()), $this->groupTopicIds($group));
    $this->assertCount(1, $this->applicationsFor($group));

    // Resuming a finished application changes nothing.
    $again = $this->manager()->resume($resumed, $manager);
    $this->assertSame($resumed->getGeneratedIds(), $again->getGeneratedIds());
    $this->assertCount(count($definition['starter']), $this->groupTopicIds($group));
  }

  /**
   * Only a current, active member holding the manager role individually.
   */
  public function testEveryOtherPersonaAndUnlistedTypeIsRefused(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $other = $this->createFlexibleGroup('Juniper', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $other_manager = $this->member($other, 'quinn', TRUE);
    $ordinary = $this->member($group, 'maya', FALSE);
    $admin = $this->createVerifiedUser('admin');
    $admin->addRole('administrator');
    $admin->save();
    // A member whose site role gives the Group's admin-flagged insider role.
    $insider = $this->createVerifiedUser('sitemanager');
    $insider->addRole('sitemanager');
    $insider->save();
    $group->addMember($insider);
    $outsider = $this->createVerifiedUser('outsider');
    $blocked = $this->member($group, 'blocked', TRUE);
    $blocked->block();
    $blocked->save();

    $scope = $this->container->get('localnodes_group_setup.group_scope');
    $this->assertTrue($scope->isCurrentManager($group, $manager));
    $this->assertTrue($scope->isCurrentMember($group, $ordinary));
    $this->assertFalse($scope->isCurrentManager($group, $ordinary));
    $this->assertFalse($scope->isCurrentManager($group, $other_manager));
    $this->assertFalse($scope->isCurrentMember($group, $admin));
    $this->assertFalse($scope->isCurrentManager($group, $insider), 'A synchronized insider role grants nothing');
    $this->assertFalse($scope->isCurrentMember($group, $outsider));
    $this->assertFalse($scope->isCurrentMember($group, $blocked), 'A blocked account is not a current member');
    $this->assertFalse($scope->isCurrentManager($group, $blocked));
    $this->assertFalse($scope->isCurrentMember($group, $this->anonymous()));

    // A suggestion alone applies nothing.
    $this->manager()->preview($group, $this->templateId);
    $this->manager()->preview($group, 'no_such_template');
    $this->assertNull($this->manager()->forGroup($group));

    foreach (['other manager' => $other_manager, 'ordinary' => $ordinary, 'admin' => $admin, 'insider' => $insider, 'outsider' => $outsider, 'blocked' => $blocked, 'anonymous' => $this->anonymous()] as $label => $account) {
      $this->assertRefused('not_manager', fn () => $this->manager()->confirm($group, $this->templateId, [], FALSE, $account), $label);
    }
    $this->assertNull($this->manager()->forGroup($group));
    $this->assertSame([], $this->groupTopicIds($group));

    // A Group of a type the settings do not list, even for its own manager.
    GroupType::create(['id' => 'neutral', 'label' => 'Neutral', 'creator_membership' => FALSE])->save();
    GroupRole::create([
      'id' => 'neutral-lead',
      'label' => 'Lead',
      'group_type' => 'neutral',
      'scope' => 'individual',
      'admin' => FALSE,
    ])->save();
    $unlisted = Group::create(['type' => 'neutral', 'label' => 'Unlisted', 'uid' => $this->siteOwner->id()]);
    $unlisted->save();
    $lead = $this->createVerifiedUser('lead');
    $unlisted->addMember($lead, ['group_roles' => ['neutral-lead']]);
    $this->assertFalse($scope->isAllowedGroup($unlisted));
    $this->assertFalse($scope->isCurrentMember($unlisted, $lead));
    $this->assertRefused('group_type_not_listed', fn () => $this->manager()->confirm($unlisted, $this->templateId, [], FALSE, $lead));

    // With an empty list every Group is refused.
    $this->config('localnodes_group_setup.settings')->set('group_types', [])->save();
    $this->assertFalse($scope->isAllowedGroup($group));
    $this->assertFalse($scope->isCurrentManager($group, $manager));
    $this->assertRefused('group_type_not_listed', fn () => $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager));
    $this->assertNull($this->manager()->forGroup($group));
  }

  /**
   * The community templates' id resolves; words are plain; schema is valid.
   */
  public function testCommunityTemplateIdResolvesAndWordsArePlain(): void {
    foreach ($this->communityTemplateIds() as $id) {
      $definition = $this->manager()->definition($id);
      $this->assertSame($id, $definition['id']);
    }
    $installed = $this->container->get('config.storage')->listAll('localnodes_group_setup.template.');
    $this->assertSame(['localnodes_group_setup.template.' . $this->templateId], $installed, 'Only the one definition is installed');

    $typed = $this->container->get('config.typed');
    foreach (array_merge(['localnodes_group_setup.settings'], $installed) as $name) {
      $this->assertConfigSchema($typed, $name, $this->config($name)->get());
    }

    try {
      $this->manager()->definition('../system.site');
      $this->fail('A malformed id was accepted');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertNotEmpty($e->getMessage());
    }

    $definition = $this->manager()->definition($this->templateId);
    $words = [$definition['label'], $definition['description']];
    array_push($words, ...array_values($definition['words']));
    foreach ($definition['starter'] as $item) {
      $words[] = $item['title'];
      $words[] = $item['body'];
    }
    foreach ($words as $text) {
      $this->assertDoesNotMatchRegularExpression('/\b(wallet|chain|blockchain|on-chain|crypto\w*|token\w*|signer\w*|network fee\w*|gas fee\w*)\b/i', $text);
    }
  }

  /**
   * No module PHP names a listed Group type id or an installed kind.
   */
  public function testSourceNamesNoGroupTypeOrKind(): void {
    $needles = array_keys($this->config('localnodes_group_setup.settings')->get('group_types'));
    foreach ($this->container->get('config.storage')->listAll('localnodes_group_setup.template.') as $name) {
      $needles[] = (string) $this->config($name)->get('kind');
    }
    $needles = array_filter($needles);
    $this->assertNotEmpty($needles);

    $root = DRUPAL_ROOT . '/modules/custom';
    $files = [];
    foreach (['localnodes_group_setup', 'localnodes_consensus'] as $module) {
      if (is_dir("$root/$module/src")) {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$module/src", \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
          if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
          }
        }
      }
    }
    if (is_file("$root/localnodes_consensus/localnodes_consensus.module")) {
      $files[] = "$root/localnodes_consensus/localnodes_consensus.module";
    }
    $this->assertNotEmpty($files);
    foreach ($files as $file) {
      $source = file_get_contents($file);
      foreach ($needles as $needle) {
        $this->assertFalse(stripos($source, $needle) !== FALSE, "$file names '$needle'");
      }
    }
  }

  /**
   * The shipped rule values, both paths, and shape checks before any save.
   */
  public function testShippedRulesAndBothPathsAreHandedOver(): void {
    $definition = $this->manager()->definition($this->templateId);
    $rule = fn (int $days) => [
      'method' => 'consensus',
      'quorum_numerator' => 2,
      'quorum_denominator' => 3,
      'threshold_numerator' => NULL,
      'threshold_denominator' => NULL,
      'response_days' => $days,
    ];
    $this->assertSame(['path' => 'simple', 'everyday' => $rule(7), 'money' => $rule(7), 'rule_change' => $rule(14)], $definition['decision_rules']);

    // The template's own values, unchanged.
    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $application = $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager);
    $this->assertCount(1, StandInDecisionRules::$calls);
    $this->assertSame([
      'group' => (int) $group->id(),
      'rules' => $definition['decision_rules'],
      'actor' => (int) $manager->id(),
      'setup' => (int) $application->id(),
    ], StandInDecisionRules::$calls[0]);
    $this->assertSame(StandInDecisionRules::$records[(int) $application->id()], $application->getLink('decision_rules'));
    $this->assertSame($definition['decision_rules'], $application->getAppliedSnapshot()['decision_rules']);

    // Simple path, changed before confirming: one shared rule, own days for
    // rule changes.
    $simple = ['path' => 'simple', 'everyday' => ['quorum_numerator' => 1, 'quorum_denominator' => 1, 'response_days' => 10] + $rule(10)];
    $simple['money'] = $simple['everyday'];
    $simple['rule_change'] = ['response_days' => 21] + $simple['everyday'];
    $second = $this->createFlexibleGroup('Juniper', 'community');
    $second_manager = $this->member($second, 'quinn', TRUE);
    $application = $this->manager()->confirm($second, $this->templateId, ['decision_rules' => $simple], FALSE, $second_manager);
    $this->assertSame($simple, StandInDecisionRules::$calls[1]['rules']);
    $this->assertSame($simple, $application->getAppliedSnapshot()['decision_rules']);

    // Advanced path: one rule per type.
    $advanced = ['path' => 'advanced', 'everyday' => $rule(5), 'money' => ['quorum_numerator' => 3, 'quorum_denominator' => 4] + $rule(7), 'rule_change' => $rule(30)];
    $third = $this->createFlexibleGroup('Aspen', 'community');
    $third_manager = $this->member($third, 'rowan', TRUE);
    $application = $this->manager()->confirm($third, $this->templateId, ['decision_rules' => $advanced], FALSE, $third_manager);
    $this->assertSame($advanced, StandInDecisionRules::$calls[2]['rules']);
    $this->assertSame(TemplateApplication::STATUS_APPLIED, $application->getStatus());

    // Shapes set-up refuses before saving anything.
    $fourth = $this->createFlexibleGroup('Birch', 'community');
    $fourth_manager = $this->member($fourth, 'sage', TRUE);
    $base = $definition['decision_rules'];
    $bad = [
      'quorum above the whole' => array_replace_recursive($base, ['money' => ['quorum_numerator' => 4]]),
      'zero quorum' => array_replace_recursive($base, ['money' => ['quorum_numerator' => 0]]),
      'quorum not an integer' => array_replace_recursive($base, ['money' => ['quorum_numerator' => '2']]),
      'threshold above the whole' => array_replace_recursive($base, ['everyday' => ['threshold_numerator' => 5, 'threshold_denominator' => 4]]),
      'zero days' => array_replace_recursive($base, ['rule_change' => ['response_days' => 0]]),
      'unknown path' => ['path' => 'fancy'] + $base,
      'unknown type' => $base + ['social' => $rule(7)],
      'missing type' => array_diff_key($base, ['money' => TRUE]),
      'no method' => array_replace_recursive($base, ['everyday' => ['method' => '']]),
    ];
    foreach ($bad as $label => $rules) {
      try {
        $this->manager()->confirm($fourth, $this->templateId, ['decision_rules' => $rules], FALSE, $fourth_manager);
        $this->fail("Accepted: $label");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertNotEmpty($e->getMessage(), $label);
      }
    }
    try {
      $this->manager()->confirm($fourth, $this->templateId, ['visibility' => 'public'], FALSE, $fourth_manager);
      $this->fail('Accepted an unknown confirmed value');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertNotEmpty($e->getMessage());
    }
    $this->assertSame([], $this->applicationsFor($fourth));
    $this->assertSame([], $this->groupTopicIds($fourth));
    $this->assertCount(3, StandInDecisionRules::$calls);
  }

  /**
   * The snapshot, links and history are write-once; nothing reads it as live.
   */
  public function testSnapshotAndLinksAreWriteOnceHistory(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $application = $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager);
    $id = (int) $application->id();
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_template_application');
    $snapshot = $application->getAppliedSnapshot();
    $links = $application->getLinks();
    $history = $application->getHistory();
    $this->assertArrayHasKey('decision_rules', $links);

    $changed_snapshot = $snapshot;
    $changed_snapshot['decision_rules']['everyday']['response_days'] = 1;
    $tampering = [
      'snapshot changed' => fn ($record) => $record->set('applied_snapshot', json_encode($changed_snapshot)),
      'snapshot emptied' => fn ($record) => $record->set('applied_snapshot', '{}'),
      'link replaced' => fn ($record) => $record->addLink('decision_rules', 99999),
      'link removed' => fn ($record) => $record->set('links', '{}'),
      'link added after apply' => fn ($record) => $record->addLink('fund', 5),
      'history rewritten' => fn ($record) => $record->set('history', json_encode(array_slice($history, 1))),
      'Group changed' => fn ($record) => $record->set('gid', $this->createFlexibleGroup('Other', 'community')->id()),
      'template changed' => fn ($record) => $record->set('template_version', 99),
      'status reopened' => fn ($record) => $record->set('status', TemplateApplication::STATUS_IN_PROGRESS),
      'flag changed without history' => fn ($record) => $record->set('community_wide', TRUE),
    ];
    foreach ($tampering as $label => $change) {
      $record = $storage->loadUnchanged($id);
      $change($record);
      try {
        $record->save();
        $this->fail("Saved: $label");
      }
      catch (\Exception $e) {
        $this->assertStringContainsString('set-up record', $e->getMessage(), $label);
      }
    }
    $stored = $storage->loadUnchanged($id);
    $this->assertSame($snapshot, $stored->getAppliedSnapshot());
    $this->assertSame($links, $stored->getLinks());
    $this->assertSame($history, $stored->getHistory());

    // No public manager method hands the snapshot out as live values.
    foreach ((new \ReflectionClass(TemplateApplicationManager::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      $this->assertStringNotContainsStringIgnoringCase('snapshot', $method->getName());
    }
    $this->assertArrayNotHasKey('applied_snapshot', $this->manager()->preview($group, NULL)['application']);
  }

  /**
   * One seed call, linked; an interruption between seed and link reuses it.
   */
  public function testRulesAreSeededOnceAndLinkedAcrossInterruptions(): void {
    $definition = $this->manager()->definition($this->templateId);
    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);

    // Interrupted after the rules were seeded, before the link was saved.
    InterruptibleApplicationManager::$interruptAfterSeed = TRUE;
    try {
      $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager);
      $this->fail('The interruption was not thrown');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Interrupted', $e->getMessage());
    }
    $application = $this->manager()->forGroup($group);
    $this->assertSame(TemplateApplication::STATUS_IN_PROGRESS, $application->getStatus());
    $this->assertNull($application->getLink('decision_rules'));
    $this->assertSame([], $this->groupTopicIds($group), 'No starter page before the rules are linked');
    $this->assertCount(1, StandInDecisionRules::$records);

    $resumed = $this->manager()->resume($application, $manager);
    $this->assertCount(2, StandInDecisionRules::$calls, 'Resume hands the same record over again');
    $this->assertSame(StandInDecisionRules::$calls[0], StandInDecisionRules::$calls[1]);
    $this->assertCount(1, StandInDecisionRules::$records, 'No second rules record');
    $this->assertSame(StandInDecisionRules::$records[(int) $resumed->id()], $resumed->getLink('decision_rules'));
    $this->assertSame(TemplateApplication::STATUS_APPLIED, $resumed->getStatus());
    $this->assertCount(count($definition['starter']), $this->groupTopicIds($group));

    // Once linked, a resume makes no call.
    $this->manager()->resume($resumed, $manager);
    $this->assertCount(2, StandInDecisionRules::$calls);

    // Interrupted after the link, during the starter pages: no second call.
    $second = $this->createFlexibleGroup('Juniper', 'community');
    $second_manager = $this->member($second, 'quinn', TRUE);
    InterruptibleApplicationManager::$interruptAfterStarter = $definition['starter'][0]['key'];
    try {
      $this->manager()->confirm($second, $this->templateId, [], FALSE, $second_manager);
      $this->fail('The interruption was not thrown');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Interrupted', $e->getMessage());
    }
    $this->assertCount(3, StandInDecisionRules::$calls);
    $resumed = $this->manager()->resume($this->manager()->forGroup($second), $second_manager);
    $this->assertCount(3, StandInDecisionRules::$calls);
    $this->assertNotNull($resumed->getLink('decision_rules'));
    $this->assertCount(count($definition['starter']), $this->groupTopicIds($second));
  }

  /**
   * A refusal from consensus leaves a refused record and nothing half-made.
   */
  public function testRulesRefusalLeavesRefusedRecordAndAllowsLaterConfirm(): void {
    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);

    StandInDecisionRules::$refuse = 'quorum_invalid';
    $this->assertRefused('rules_refused', fn () => $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager));
    $records = $this->applicationsFor($group);
    $this->assertCount(1, $records);
    $refused = reset($records);
    $this->assertSame(TemplateApplication::STATUS_REFUSED, $refused->getStatus());
    $last = $refused->getHistory()[count($refused->getHistory()) - 1];
    $this->assertSame('refused', $last['action']);
    $this->assertSame('quorum_invalid', $last['reason']);
    $this->assertSame([], $refused->getLinks());
    $this->assertSame([], $this->groupTopicIds($group), 'No starter page');
    $this->assertNull($this->manager()->forGroup($group));
    $this->assertRefused('not_resumable', fn () => $this->manager()->resume($refused, $manager));

    StandInDecisionRules::$refuse = NULL;
    $application = $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager);
    $this->assertNotSame($refused->id(), $application->id());
    $this->assertSame(TemplateApplication::STATUS_APPLIED, $application->getStatus());
    $this->assertNotNull($application->getLink('decision_rules'));
    $this->assertSame(TemplateApplication::STATUS_REFUSED, $this->container->get('entity_type.manager')->getStorage('localnodes_template_application')->loadUnchanged($refused->id())->getStatus());
  }

  /**
   * With consensus off, apply completes and stores no link.
   */
  public function testConsensusOffAppliesWithoutLink(): void {
    $this->consensusOn = FALSE;
    $this->container->get('kernel')->rebuildContainer();
    $this->container = \Drupal::getContainer();
    $this->assertFalse($this->container->has('localnodes_consensus.decision_rules'));

    $group = $this->createFlexibleGroup('Willow', 'community');
    $manager = $this->member($group, 'avery', TRUE);
    $application = $this->manager()->confirm($group, $this->templateId, [], FALSE, $manager);
    $this->assertSame(TemplateApplication::STATUS_APPLIED, $application->getStatus());
    $this->assertSame([], $application->getLinks());
    $this->assertSame([], StandInDecisionRules::$calls);
    $this->assertNotEmpty($this->groupTopicIds($group));
  }

  /**
   * The flag is data; corrections are append-only and never touch rules.
   */
  public function testCommunityFlagIsDataAndCorrectionsKeepHistory(): void {
    // The lower Group id is not the community-wide one here.
    $willow = $this->createFlexibleGroup('Willow', 'community');
    $commons = $this->createFlexibleGroup('Everyone', 'community');
    $this->assertLessThan((int) $commons->id(), (int) $willow->id());
    $manager = $this->member($willow, 'avery', TRUE);
    $commons_manager = $this->member($commons, 'quinn', TRUE);
    $ordinary = $this->member($willow, 'maya', FALSE);

    // The suggestion can change before confirming; previews apply nothing.
    $this->manager()->preview($willow, 'no_such_template');
    $this->manager()->preview($willow, $this->templateId);
    $this->assertNull($this->manager()->forGroup($willow));

    $application = $this->manager()->confirm($willow, $this->templateId, [], FALSE, $manager);
    $commons_application = $this->manager()->confirm($commons, $this->templateId, [], TRUE, $commons_manager);
    $this->assertFalse($application->isCommunityWide());
    $this->assertTrue($commons_application->isCommunityWide());

    // A member adopts a starter page by rewriting it.
    $ids = $application->getGeneratedIds();
    $first_key = array_key_first($ids);
    $adopted = Node::load($ids[$first_key]);
    $adopted->setTitle('Our agreed rules');
    $adopted->save();
    $commons_before = $this->groupState($commons);

    // Correction of the flag, with history.
    $this->manager()->recordCorrection($commons_application, ['community_wide' => FALSE], 'The wrong Group was flagged.', $commons_manager);
    $commons_application = $this->manager()->forGroup($commons);
    $this->assertFalse($commons_application->isCommunityWide());
    $entry = $commons_application->getHistory()[count($commons_application->getHistory()) - 1];
    $this->assertSame('correction', $entry['action']);
    $this->assertSame((int) $commons_manager->id(), $entry['uid']);
    $this->assertSame('The wrong Group was flagged.', $entry['reason']);
    $this->assertSame(['community_wide' => TRUE], $entry['before']);
    $this->assertSame(['community_wide' => FALSE], $entry['after']);
    $this->assertIsInt($entry['at']);

    // Correction of set-up content: a starter page no longer counts as one.
    $before = $application->getGeneratedIds();
    $this->manager()->recordCorrection($application, ['generated' => array_diff_key($before, [$first_key => TRUE])], 'Adopted as our own rules.', $manager);
    $application = $this->manager()->forGroup($willow);
    $this->assertArrayNotHasKey($first_key, $application->getGeneratedIds());
    $entry = $application->getHistory()[count($application->getHistory()) - 1];
    $this->assertSame($before, $entry['before']['generated']);
    $this->assertSame('Our agreed rules', Node::load($ids[$first_key])->label(), 'Adopted content unchanged');
    $this->assertNotNull(Node::load($ids[$first_key]), 'Correcting the record deletes no page');

    // Refused: snapshot, links, rule keys, unknown keys, no reason, others.
    $history_count = count($application->getHistory());
    $snapshot = $application->getAppliedSnapshot();
    $links = $application->getLinks();
    foreach (['applied_snapshot', 'links', 'decision_rules', 'path', 'everyday', 'money', 'rule_change', 'quorum_numerator', 'response_days', 'status', 'template_id'] as $key) {
      try {
        $this->manager()->recordCorrection($application, [$key => 1], 'Trying.', $manager);
        $this->fail("Correction accepted: $key");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertNotEmpty($e->getMessage(), $key);
      }
    }
    try {
      $this->manager()->recordCorrection($application, ['generated' => [$first_key => 12345]], 'Adding a page.', $manager);
      $this->fail('A correction added generated content');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertNotEmpty($e->getMessage());
    }
    try {
      $this->manager()->recordCorrection($application, ['community_wide' => TRUE], '  ', $manager);
      $this->fail('A correction without a reason was accepted');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertNotEmpty($e->getMessage());
    }
    $this->assertRefused('not_manager', fn () => $this->manager()->recordCorrection($application, ['community_wide' => TRUE], 'Me.', $ordinary));
    $this->assertRefused('not_manager', fn () => $this->manager()->recordCorrection($application, ['community_wide' => TRUE], 'Me.', $commons_manager));

    $application = $this->manager()->forGroup($willow);
    $this->assertCount($history_count, $application->getHistory());
    $this->assertSame($snapshot, $application->getAppliedSnapshot());
    $this->assertSame($links, $application->getLinks());

    // Retry after correction changes nothing either.
    $this->manager()->resume($application, $manager);
    $this->assertRefused('already_applied', fn () => $this->manager()->confirm($willow, $this->templateId, [], FALSE, $manager));
    $this->assertSame('Our agreed rules', Node::load($ids[$first_key])->label());
    $this->assertCount(count($ids), $this->groupTopicIds($willow));
    $this->assertSame($commons_before, $this->groupState($commons), 'The other Group is unchanged');
    $this->assertCount(2, StandInDecisionRules::$calls, 'Corrections never reach the rules');
  }

  /**
   * The application manager service.
   */
  protected function manager(): TemplateApplicationManager {
    return $this->container->get('localnodes_group_setup.application_manager');
  }

  /**
   * Group template ids the shipped community templates name.
   *
   * Read from every shipped community template file (some of them name one),
   * so this test holds no template id of its own.
   *
   * @return string[]
   *   Distinct non-empty ids.
   */
  protected function communityTemplateIds(): array {
    $ids = [];
    $dir = DRUPAL_ROOT . '/modules/custom/localnodes_platform/config/install';
    $files = glob("$dir/localnodes_platform.community_template.*.yml");
    sort($files);
    foreach ($files as $file) {
      $data = Yaml::decode(file_get_contents($file));
      foreach (array_merge($data['groups'] ?? [], $data['repeatable'] ?? []) as $entry) {
        if (!empty($entry['group_template'])) {
          $ids[] = (string) $entry['group_template'];
        }
      }
    }
    $ids = array_values(array_unique($ids));
    $this->assertNotEmpty($ids, 'The community templates name a group template');
    return $ids;
  }

  /**
   * Creates an active account in the Group, as manager or ordinary member.
   */
  protected function member(GroupInterface $group, string $name, bool $manager): UserInterface {
    $user = User::load($this->createPersona($name)->id());
    $group->addMember($user, $manager ? ['group_roles' => [$this->managerRole]] : []);
    return $user;
  }

  /**
   * Creates an active community account.
   */
  protected function createVerifiedUser(string $name): UserInterface {
    return User::load($this->createPersona($name)->id());
  }

  /**
   * Asserts that a call is refused with the given reason.
   */
  protected function assertRefused(string $reason, callable $call, string $label = ''): void {
    try {
      $call();
      $this->fail("Not refused: $label");
    }
    catch (SetupRefusedException $e) {
      $this->assertSame($reason, $e->reason, $label);
    }
  }

  /**
   * Node ids of every Topic in a Group, ascending.
   *
   * @return int[]
   *   Node ids.
   */
  protected function groupTopicIds(GroupInterface $group): array {
    $ids = [];
    foreach ($group->getRelationships('group_node:topic') as $relationship) {
      $ids[] = (int) $relationship->getEntityId();
    }
    sort($ids);
    return $ids;
  }

  /**
   * Set-up records of a Group, any status.
   *
   * @return \Drupal\localnodes_group_setup\Entity\TemplateApplication[]
   *   Records.
   */
  protected function applicationsFor(GroupInterface $group): array {
    return $this->container->get('entity_type.manager')
      ->getStorage('localnodes_template_application')
      ->loadByProperties(['gid' => $group->id()]);
  }

  /**
   * A Group's relationships and the labels of its content, for comparison.
   */
  protected function groupState(GroupInterface $group): array {
    $state = [];
    foreach ($this->container->get('entity_type.manager')->getStorage('group_content')->loadByProperties(['gid' => $group->id()]) as $relationship) {
      $entity = $relationship->getEntity();
      $state[(int) $relationship->id()] = [$relationship->getPluginId(), (int) $entity->id(), (string) $entity->label()];
    }
    ksort($state);
    return $state;
  }

  /**
   * Member account ids of a Group, ascending.
   *
   * @return int[]
   *   Account ids.
   */
  protected function memberIds(GroupInterface $group): array {
    $ids = array_map(fn ($membership) => (int) $membership->getUser()->id(), $group->getMembers());
    sort($ids);
    return $ids;
  }

  /**
   * Every stored configuration object.
   */
  protected function allConfig(): array {
    $storage = $this->container->get('config.storage');
    return $storage->readMultiple($storage->listAll());
  }

}
