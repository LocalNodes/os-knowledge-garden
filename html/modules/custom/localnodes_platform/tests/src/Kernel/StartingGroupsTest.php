<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\UserInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The starting groups a community template names (community template, part 1).
 *
 * A community template is a versioned config file that names the groups a new
 * site starts with and a kind of group the organizer can add more of. It only
 * names group templates (the house template, house_v1); it applies none and
 * adds no member but the organizer. The template is chosen with
 * COMMUNITY_TEMPLATE; nothing sets that variable yet, so every site
 * provisioned today takes the path without one, which must stay exactly
 * what it was.
 *
 * Runs localnodes:organizer-setup, which calls the same step as
 * localnodes:organizer-welcome and sends no mail. The welcome command's own
 * first-group tests are in OnlineOnlyCommunityTest, unedited.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class StartingGroupsTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * localnodes_platform is enabled for its class autoloading and its config
   * schema only (see TopicTypesCommandTest).
   */
  protected static $modules = ['localnodes_platform'];

  /**
   * The organizer's address: fictional, on a reserved domain; no mail is read.
   */
  private const ORGANIZER_EMAIL = 'kim.organizer@example.invalid';

  /**
   * The community's name.
   */
  private const SITE_NAME = 'Willow Housing Co-op';

  /**
   * The marker the step sets once every starting group exists.
   */
  private const MARKER = 'localnodes.install_step.first_group';

  /**
   * Where the step records the template it used and the groups it made.
   */
  private const TEMPLATE_STATE = 'localnodes.community_template';

  /**
   * What organizer-setup prints after the group lines.
   */
  private const READY = 'Organizer account ready (blocked until the first payment).';

  /**
   * What the step prints when a found group's visibility is not the template's.
   */
  private const VISIBILITY_CLASH = 'A starting group of that name already exists with another visibility; nothing was changed.';

  /**
   * What the step prints when a template names two starting groups alike.
   */
  private const DUPLICATE_NAMES = 'COMMUNITY_TEMPLATE names a template with two starting groups of the same name; nothing was made.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    putenv('ORGANIZER_EMAIL=' . self::ORGANIZER_EMAIL);
    putenv('ORGANIZER_NAME=Kim');
    putenv('SITE_NAME=' . self::SITE_NAME);
    putenv('COMMUNITY_TEMPLATE');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (['ORGANIZER_EMAIL', 'ORGANIZER_NAME', 'SITE_NAME', 'COMMUNITY_TEMPLATE'] as $variable) {
      putenv($variable);
    }
    parent::tearDown();
  }

  /**
   * Without a template (unset or empty) the step is today's: one group, same words.
   *
   * @dataProvider noTemplateProvider
   */
  public function testNoTemplateMakesTodaysOneGroup(?string $value): void {
    $this->installShippedTemplate();
    if ($value !== NULL) {
      putenv('COMMUNITY_TEMPLATE=' . $value);
    }
    [$code, $printed] = $this->runSetup();

    $this->assertSame(0, $code);
    $this->assertSame("First group created.\n" . self::READY, $printed);
    $this->assertSame([self::SITE_NAME], $this->labels());
    $this->assertTodaysGroup($this->groupNamed(self::SITE_NAME));
    $this->assertNotEmpty($this->state(self::MARKER));
    $this->assertNull($this->state(self::TEMPLATE_STATE), 'No template is recorded.');

    // A second run, and a run after the marker is lost, are today's too.
    [, $printed] = $this->runSetup();
    $this->assertSame(self::READY, $printed);
    $this->container->get('state')->delete(self::MARKER);
    [, $printed] = $this->runSetup();
    $this->assertSame("First group already exists.\n" . self::READY, $printed);
    $this->assertSame([self::SITE_NAME], $this->labels());
    $this->assertNull($this->state(self::TEMPLATE_STATE));
  }

  /**
   * No variable, and an empty one.
   */
  public static function noTemplateProvider(): array {
    return [
      'unset' => [NULL],
      'empty' => [''],
      'blank' => ['   '],
    ];
  }

  /**
   * A name that is no template, or not a name at all, falls back to today's group.
   *
   * The sentence names the variable, never its value.
   *
   * @dataProvider unknownTemplateProvider
   */
  public function testUnknownTemplateFallsBackToTodaysGroup(string $value): void {
    $this->installShippedTemplate();
    putenv('COMMUNITY_TEMPLATE=' . $value);
    [$code, $printed] = $this->runSetup();

    $this->assertSame(0, $code);
    $this->assertSame("COMMUNITY_TEMPLATE names no community template; the site gets one group.\nFirst group created.\n" . self::READY, $printed);
    $this->assertStringNotContainsString($value, str_replace(['COMMUNITY_TEMPLATE', 'community template'], '', $printed));
    $this->assertSame([self::SITE_NAME], $this->labels());
    $this->assertTodaysGroup($this->groupNamed(self::SITE_NAME));
    $this->assertNull($this->state(self::TEMPLATE_STATE));
  }

  /**
   * Names that load no template.
   */
  public static function unknownTemplateProvider(): array {
    return [
      'no such template' => ['market_hall'],
      'upper case' => ['HOUSING_COOP'],
      'another config object' => ['housing_coop.extra'],
      'path' => ['../system.site'],
      'markup' => ['<b>coop</b>'],
      'too long' => [str_repeat('a', 65)],
    ];
  }

  /**
   * A file whose id is not its name, or whose name is not a bare machine name, is no template.
   *
   * Each guard is tested alone: the first file has a good name and a wrong
   * id, the second an id that matches a name the filter refuses.
   */
  public function testTemplateFileMustBeWhatItIsCalled(): void {
    $this->saveTestTemplate();
    $data = $this->config('localnodes_platform.community_template.two_groups')->get();
    $this->config('localnodes_platform.community_template.renamed')->setData($data)->save();
    $this->config('localnodes_platform.community_template.Two-Groups')->setData(['id' => 'Two-Groups'] + $data)->save();
    $fallback = "COMMUNITY_TEMPLATE names no community template; the site gets one group.\nFirst group created.\n" . self::READY;

    foreach (['renamed', 'Two-Groups'] as $value) {
      putenv('COMMUNITY_TEMPLATE=' . $value);
      [$code, $printed] = $this->runSetup();
      $this->assertSame(0, $code);
      $this->assertSame($fallback, $printed, $value);
      $this->assertSame([self::SITE_NAME], $this->labels(), $value);
      $this->assertNull($this->state(self::TEMPLATE_STATE), $value);
      $this->container->get('state')->delete(self::MARKER);
      foreach ($this->groups() as $group) {
        $group->delete();
      }
    }
  }

  /**
   * The shipped housing co-op file is valid against its schema and says what it is.
   */
  public function testShippedHousingCoopTemplate(): void {
    $this->installShippedTemplate();
    $template = $this->config('localnodes_platform.community_template.housing_coop')->get();

    $this->assertSame('housing_coop', $template['id']);
    $this->assertSame(1, $template['version']);
    $this->assertSame('Housing co-op with several houses', $template['label']);
    $this->assertNotEmpty($template['description']);
    $this->assertSame([
      ['name' => '{site}', 'visibility' => 'community', 'everyone' => TRUE, 'group_template' => NULL],
    ], $template['groups']);
    $this->assertSame([
      ['kind' => 'house', 'label' => 'Add a house', 'group_template' => 'house_v1'],
    ], $template['repeatable']);
  }

  /**
   * The housing co-op template: the community's group, with the house kind recorded.
   *
   * The group everyone is in is the group today's step makes, so the seed step
   * (which finds it by type, name and owner) still finds it. Only the
   * organizer is added: "everyone" is named, not acted on.
   */
  public function testHousingCoopTemplate(): void {
    $this->installShippedTemplate();
    putenv('COMMUNITY_TEMPLATE=housing_coop');
    [$code, $printed] = $this->runSetup();

    $this->assertSame(0, $code);
    $this->assertSame("Starting groups created from the community template.\n" . self::READY, $printed);
    $this->assertSame([self::SITE_NAME], $this->labels());
    $group = $this->groupNamed(self::SITE_NAME);
    $this->assertTodaysGroup($group);
    $this->assertSame($group->id(), $this->findFirstGroup()?->id(), 'The seed step finds this group.');
    $this->assertNotEmpty($this->state(self::MARKER));

    $this->assertSame([
      'id' => 'housing_coop',
      'version' => 1,
      'groups' => [
        ['group' => (int) $group->id(), 'everyone' => TRUE, 'group_template' => NULL],
      ],
      'repeatable' => [
        ['kind' => 'house', 'label' => 'Add a house', 'group_template' => 'house_v1'],
      ],
    ], $this->state(self::TEMPLATE_STATE));
  }

  /**
   * A template with several groups makes each once, the organizer managing each.
   */
  public function testSeveralGroupsAreEachMadeOnce(): void {
    $this->saveTestTemplate();
    putenv('COMMUNITY_TEMPLATE=two_groups');
    [$code, $printed] = $this->runSetup();

    $this->assertSame(0, $code);
    $this->assertSame("Starting groups created from the community template.\n" . self::READY, $printed);
    $this->assertSame([self::SITE_NAME, 'Upkeep'], $this->labels());
    $commons = $this->groupNamed(self::SITE_NAME);
    $upkeep = $this->groupNamed('Upkeep');
    $this->assertTodaysGroup($commons);
    $this->assertManagedOnlyByTheOrganizer($upkeep);
    $this->assertSame('members', $upkeep->get('field_flexible_group_visibility')->value);
    $this->assertSame([
      ['group' => (int) $commons->id(), 'everyone' => TRUE, 'group_template' => NULL],
      ['group' => (int) $upkeep->id(), 'everyone' => FALSE, 'group_template' => 'house_v1'],
    ], $this->state(self::TEMPLATE_STATE)['groups']);

    // Run again: nothing new.
    [, $printed] = $this->runSetup();
    $this->assertSame(self::READY, $printed);
    $this->assertSame([self::SITE_NAME, 'Upkeep'], $this->labels());

    // The marker and the record lost: every group is found again, none doubled.
    $state = $this->container->get('state');
    $state->delete(self::MARKER);
    $state->delete(self::TEMPLATE_STATE);
    [, $printed] = $this->runSetup();
    $this->assertSame("Starting groups already exist.\n" . self::READY, $printed);
    $this->assertSame([self::SITE_NAME, 'Upkeep'], $this->labels());
    $this->assertSame((int) $upkeep->id(), $this->state(self::TEMPLATE_STATE)['groups'][1]['group']);
    $this->assertNotEmpty($this->state(self::MARKER));
  }

  /**
   * A run cut short after the first group: the next run makes only the rest.
   */
  public function testRunCutShortMakesOnlyTheRest(): void {
    $this->saveTestTemplate();
    putenv('COMMUNITY_TEMPLATE=two_groups');
    // What an earlier run left: the organizer and the first group, no marker.
    $this->runSetupWithoutTemplateThenForget();

    [$code, $printed] = $this->runSetup();
    $this->assertSame(0, $code);
    $this->assertSame("Starting groups created from the community template.\n" . self::READY, $printed);
    $this->assertSame([self::SITE_NAME, 'Upkeep'], $this->labels());
  }

  /**
   * Without SITE_NAME nothing is made, with or without a template.
   */
  public function testNoSiteNameMakesNothing(): void {
    $this->saveTestTemplate();
    putenv('COMMUNITY_TEMPLATE=two_groups');
    putenv('SITE_NAME');
    [$code, $printed] = $this->runSetup();

    $this->assertSame(0, $code);
    $this->assertSame(self::READY, $printed);
    $this->assertSame([], $this->labels());
    $this->assertNull($this->state(self::MARKER));
    $this->assertNull($this->state(self::TEMPLATE_STATE));
  }

  /**
   * A group of the same name with another visibility stops the step.
   *
   * The organizer already owns a public "Upkeep" and the template asks for a
   * members-only one. Reusing it would hand members-only plans to a public
   * group, and changing its visibility would change what members already see,
   * so the step stops with a fixed sentence before anything is recorded.
   */
  public function testExistingGroupWithOtherVisibilityStops(): void {
    $this->saveTestTemplate();
    putenv('COMMUNITY_TEMPLATE=two_groups');
    $this->runSetupWithoutTemplateThenForget();
    $public = $this->container->get('entity_type.manager')->getStorage('group')->create([
      'type' => 'flexible_group',
      'label' => 'Upkeep',
      'uid' => $this->organizer()->id(),
      'field_flexible_group_visibility' => 'public',
    ]);
    $public->save();

    [$code, $printed] = $this->runSetup();

    $this->assertSame(1, $code);
    $this->assertSame(self::VISIBILITY_CLASH, $printed);
    $this->assertSame([self::SITE_NAME, 'Upkeep'], $this->labels());
    $this->assertSame('public', $this->groupNamed('Upkeep')->get('field_flexible_group_visibility')->value);
    $this->assertNull($this->state(self::MARKER));
    $this->assertNull($this->state(self::TEMPLATE_STATE));

    // A second run stops the same way; nothing is made or changed.
    [$code, $printed] = $this->runSetup();
    $this->assertSame(1, $code);
    $this->assertSame(self::VISIBILITY_CLASH, $printed);
    $this->assertSame([self::SITE_NAME, 'Upkeep'], $this->labels());
  }

  /**
   * A clash on a later starting group stops the step before any write.
   *
   * The organizer already owns a public "Upkeep"; the template's first group
   * is new and its second is a members-only "Upkeep". Every entry is checked
   * before the first group is saved, so the new group is not made and no
   * membership is added.
   */
  public function testLaterClashStopsBeforeAnyWrite(): void {
    $this->saveTestTemplate();
    // The organizer account only: without SITE_NAME no group is made.
    putenv('SITE_NAME');
    $this->runSetup();
    putenv('SITE_NAME=' . self::SITE_NAME);
    putenv('COMMUNITY_TEMPLATE=two_groups');
    $public = $this->container->get('entity_type.manager')->getStorage('group')->create([
      'type' => 'flexible_group',
      'label' => 'Upkeep',
      'uid' => $this->organizer()->id(),
      'field_flexible_group_visibility' => 'public',
    ]);
    $public->save();
    $this->assertSame(['Upkeep'], $this->labels());
    $memberships = $this->membershipCount();

    [$code, $printed] = $this->runSetup();

    $this->assertSame(1, $code);
    $this->assertSame(self::VISIBILITY_CLASH, $printed);
    $this->assertSame(['Upkeep'], $this->labels(), 'The new first group was not made.');
    $this->assertSame($memberships, $this->membershipCount(), 'No membership was added.');
    $this->assertSame('public', $this->groupNamed('Upkeep')->get('field_flexible_group_visibility')->value);
    $this->assertNull($this->state(self::MARKER));
    $this->assertNull($this->state(self::TEMPLATE_STATE));
  }

  /**
   * Two starting groups with one name are refused when the template is read.
   *
   * They would collapse into one group, and the second entry's visibility
   * would be lost. Names are compared as the groups would be labelled, after
   * {site} is filled in and ignoring case and outer spaces.
   *
   * @dataProvider duplicateNamesProvider
   */
  public function testDuplicateNamesInTemplateAreRefused(string $first, string $second): void {
    $this->config('localnodes_platform.community_template.twice')->setData([
      'id' => 'twice',
      'version' => 1,
      'label' => 'Twice',
      'description' => 'A test template.',
      'groups' => [
        ['name' => $first, 'visibility' => 'community', 'everyone' => TRUE, 'group_template' => NULL],
        ['name' => $second, 'visibility' => 'members', 'everyone' => FALSE, 'group_template' => NULL],
      ],
      'repeatable' => [],
    ])->save();
    putenv('COMMUNITY_TEMPLATE=twice');

    [$code, $printed] = $this->runSetup();

    $this->assertSame(1, $code);
    $this->assertSame(self::DUPLICATE_NAMES, $printed);
    $this->assertSame([], $this->labels());
    $this->assertNull($this->state(self::MARKER));
    $this->assertNull($this->state(self::TEMPLATE_STATE));
  }

  /**
   * The same name, the same name in another case, and {site} beside the name.
   */
  public static function duplicateNamesProvider(): array {
    return [
      'same' => ['Upkeep', 'Upkeep'],
      'case and spaces' => ['Upkeep', ' upkeep '],
      'site filled in' => ['{site}', self::SITE_NAME],
    ];
  }

  /**
   * Installs the shipped housing co-op file, checked against the schema on save.
   */
  private function installShippedTemplate(): void {
    $name = 'localnodes_platform.community_template.housing_coop';
    $path = $this->container->get('extension.list.module')->getPath('localnodes_platform') . '/config/install';
    $data = (new FileStorage($path))->read($name);
    $this->assertIsArray($data, 'The housing co-op template ships in config/install.');
    $this->config($name)->setData($data)->save();
  }

  /**
   * A test-only template with two groups, the second suggesting a template.
   */
  private function saveTestTemplate(): void {
    $this->config('localnodes_platform.community_template.two_groups')->setData([
      'id' => 'two_groups',
      'version' => 3,
      'label' => 'Two groups',
      'description' => 'A test template.',
      'groups' => [
        ['name' => '{site}', 'visibility' => 'community', 'everyone' => TRUE, 'group_template' => NULL],
        ['name' => 'Upkeep', 'visibility' => 'members', 'everyone' => FALSE, 'group_template' => 'house_v1'],
      ],
      'repeatable' => [],
    ])->save();
  }

  /**
   * Leaves the organizer and the community's group with no marker.
   */
  private function runSetupWithoutTemplateThenForget(): void {
    putenv('COMMUNITY_TEMPLATE');
    $this->runSetup();
    $this->container->get('state')->delete(self::MARKER);
    putenv('COMMUNITY_TEMPLATE=two_groups');
    $this->assertSame([self::SITE_NAME], $this->labels());
  }

  /**
   * Runs drush localnodes:organizer-setup once.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything the command printed.
   */
  private function runSetup(): array {
    $output = new BufferedOutput();
    $command = new OrganizerCommands();
    $command->setOutput($output);
    $code = $command->organizerSetup();
    return [$code, trim($output->fetch())];
  }

  /**
   * The group the seed step would find.
   */
  private function findFirstGroup(): ?GroupInterface {
    $command = new class() extends OrganizerCommands {

      /**
       * Exposes the seed step's lookup.
       */
      public function find(UserInterface $organizer): ?GroupInterface {
        return $this->findFirstGroup($organizer);
      }

    };
    return $command->find($this->organizer());
  }

  /**
   * Today's group: named after the community, visible to it, the organizer only.
   */
  private function assertTodaysGroup(GroupInterface $group): void {
    $this->assertSame('flexible_group', $group->bundle());
    $this->assertSame(self::SITE_NAME, $group->label());
    $this->assertSame('community', $group->get('field_flexible_group_visibility')->value);
    $this->assertManagedOnlyByTheOrganizer($group);
  }

  /**
   * The organizer owns the group and is its only member, as group manager.
   */
  private function assertManagedOnlyByTheOrganizer(GroupInterface $group): void {
    $organizer = $this->organizer();
    $this->assertSame((int) $organizer->id(), (int) $group->getOwnerId());
    $ids = array_map(static fn ($membership) => (int) $membership->getUser()->id(), $group->getMembers());
    $this->assertSame([(int) $organizer->id()], $ids, 'Only the organizer is a member.');
    $this->assertArrayHasKey('flexible_group-group_manager', $group->getMember($organizer)->getRoles(FALSE));
  }

  /**
   * The organizer's account.
   */
  private function organizer(): UserInterface {
    $accounts = $this->container->get('entity_type.manager')->getStorage('user')
      ->loadByProperties(['mail' => self::ORGANIZER_EMAIL]);
    $this->assertCount(1, $accounts, 'The organizer account exists.');
    return reset($accounts);
  }

  /**
   * The one group with this name.
   */
  private function groupNamed(string $label): GroupInterface {
    $groups = array_values(array_filter($this->groups(), static fn ($group) => $group->label() === $label));
    $this->assertCount(1, $groups, "One group is named $label.");
    return $groups[0];
  }

  /**
   * Every group's name, oldest first.
   *
   * @return string[]
   *   The labels.
   */
  private function labels(): array {
    return array_values(array_map(static fn ($group) => $group->label(), $this->groups()));
  }

  /**
   * Every group, oldest first.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   The groups.
   */
  private function groups(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache();
    $groups = $storage->loadMultiple();
    ksort($groups);
    return array_values($groups);
  }

  /**
   * How many memberships all groups hold together.
   */
  private function membershipCount(): int {
    return array_sum(array_map(static fn ($group) => count($group->getMembers()), $this->groups()));
  }

  /**
   * A state value, read fresh.
   */
  private function state(string $key): mixed {
    $state = $this->container->get('state');
    $state->resetCache();
    return $state->get($key);
  }

}
