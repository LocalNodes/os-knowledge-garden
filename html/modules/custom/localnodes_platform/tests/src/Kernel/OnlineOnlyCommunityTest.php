<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\File\FileExists;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Form\UploadFilesForm;
use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\meeting_api\Entity\Meeting;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\symfony_mailer_test\MailerTestTrait;
use Drupal\Tests\localnodes_import\Kernel\ImportKernelTestBase;
use Drupal\Tests\localnodes_import\Kernel\Support\UploadRequestTrait;
use Drupal\user\UserInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * A community with no place at all works end to end (D-23, PLACE-02).
 *
 * Phase 23 plan 09. The assumption this plan removes is "a community is
 * somewhere": Open Social 13.1 ships all five location fields as optional, and
 * this test pins that so a later config change fails here. It then builds an
 * online-only guild that has no address anywhere and checks that each piece
 * saves and shows to a member: a flexible group, an event with a custom
 * meeting link, a Topic, and one imported text file. It also checks that
 * drush localnodes:organizer-welcome makes the first group (named after the
 * community, no address, no location, the organizer as group manager).
 *
 * The event here is a node of Open Social's `event` type with its shipped
 * fields and a custom-link meeting, saved and viewed through the entity API.
 * Open Social's event module does not boot in a Kernel test (22.2 D-06), so the
 * real event forms, the join button and the meeting link on the page are
 * covered by the Functional GroupFeatureDefaultsTest.
 *
 * The profile address field is not installable in this stack (the profile
 * module and its types are not enabled), so its required flag is read from the
 * YAML that `site:install` applies. The live site was read the same day.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class OnlineOnlyCommunityTest extends ImportKernelTestBase {

  use UploadRequestTrait;
  use MailerTestTrait;

  /**
   * {@inheritdoc}
   *
   * localnodes_platform is enabled for its class autoloading, its theme hooks
   * and the mail builder only; its own AI-stack dependencies stay off (see
   * LocalNodesEmailTest). The mailer modules are the 22.1-07 mail set-up. The
   * rest is what the event and its meeting link need.
   */
  protected static $modules = [
    'localnodes_platform',
    'symfony_mailer',
    'symfony_mailer_test',
    'social_swiftmail',
    'address',
    'datetime_range',
    'datetime_range_timezone',
    'meeting_api',
    'meeting_api_manual',
  ];

  /**
   * The five Open Social location fields: config name, shipped by module.
   *
   * @var array<string, string>
   */
  private const LOCATION_FIELDS = [
    'field.field.group.flexible_group.field_group_address' => 'social_group_flexible_group',
    'field.field.group.flexible_group.field_group_location' => 'social_group_flexible_group',
    'field.field.node.event.field_event_address' => 'social_event',
    'field.field.node.event.field_event_location' => 'social_event',
    'field.field.profile.profile.field_profile_address' => 'social_profile',
  ];

  /**
   * The organizer's address: fictional, on a domain the mail filter lets pass.
   */
  private const ORGANIZER_EMAIL = 'sam.organizer@mail.localnodes.xyz';

  /**
   * The community's name, the first group's name.
   */
  private const SITE_NAME = 'Repair Collective';

  /**
   * The meeting link the event uses.
   */
  private const MEETING_URL = 'https://meet.example.org/guild-call';

  /**
   * The group the import goes into.
   */
  private GroupInterface $group;

  /**
   * A member of the community, in the group.
   */
  private UserInterface $member;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The mail set-up of LocalNodesEmailKernelTestBase (22.1-07): symfony
    // mailer config, a stub default theme with a plain email.html.twig, the
    // community's name and sender.
    $this->installConfig(['symfony_mailer']);
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    $this->config('system.site')
      ->set('name', self::SITE_NAME)
      ->set('mail', 'noreply@localnodes.xyz')
      ->save();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_configure_email();

    // Shipped config, verbatim: the location fields that exist in this stack
    // (group), the event type with its fields, and the custom-link meeting.
    $this->installShippedConfig('social_group', [
      'field.storage.group.field_group_address',
      'field.storage.group.field_group_location',
    ]);
    $this->installShippedConfig('social_group_flexible_group', [
      'field.storage.group.field_group_allowed_visibility',
      'field.field.group.flexible_group.field_group_address',
      'field.field.group.flexible_group.field_group_location',
      'field.field.group.flexible_group.field_group_allowed_visibility',
    ]);
    $this->installEntitySchema('meeting_api_meeting');
    $this->installShippedConfig('social_event', [
      'meeting_api.meeting_api_server.manual_url',
      'meeting_api.meeting_api_meeting_type.custom_link',
      'node.type.event',
      'field.storage.node.field_event_address',
      'field.storage.node.field_event_location',
      'field.storage.node.field_event_date',
      'field.storage.node.field_event_date_end',
      'field.storage.node.field_event_meeting',
      'field.field.node.event.body',
      'field.field.node.event.field_content_visibility',
      'field.field.node.event.field_event_address',
      'field.field.node.event.field_event_location',
      'field.field.node.event.field_event_date',
      'field.field.node.event.field_event_date_end',
      'field.field.node.event.field_event_meeting',
    ]);

    // The page that lists Topics of one page type: social_topic links each
    // Topic's page type to it when the Topic is rendered.
    $this->installShippedConfig('social_topic', ['views.view.latest_topics']);
    $this->container->get('router.builder')->rebuild();

    // Reference topic type (import default) and a member of the community.
    $this->container->get('module_handler')->loadInclude('localnodes_import', 'install');
    _localnodes_import_create_reference_term();
    user_role_grant_permissions('verified', [
      'view node.event.field_content_visibility:community content',
    ]);

    $this->group = $this->createFlexibleGroup('Online guild', 'community');
    $this->group->set('field_group_allowed_visibility', [['value' => 'group'], ['value' => 'community']])->save();
    $this->member = $this->createVerifiedUser('guild_member');
    $this->addMember($this->group, $this->member);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach (['ORGANIZER_EMAIL', 'ORGANIZER_NAME', 'SITE_NAME'] as $variable) {
      putenv($variable);
    }
    parent::tearDown();
  }

  /**
   * No location field is required: shipped config, installed config, custom code.
   *
   * Fails if any of the five fields becomes required, and if any custom
   * module ships a field of its own whose name says address or location with
   * required set.
   */
  public function testNoLocationFieldIsRequired(): void {
    $modules = $this->container->get('extension.list.module');
    foreach (self::LOCATION_FIELDS as $name => $module) {
      $source = new FileStorage($modules->getPath($module) . '/config/install');
      $data = $source->read($name);
      $this->assertIsArray($data, "$name ships in $module.");
      $this->assertFalse($data['required'], "$name ships optional.");
    }

    // Installed config, for the four that exist in this stack.
    foreach ([
      ['group', 'flexible_group', 'field_group_address'],
      ['group', 'flexible_group', 'field_group_location'],
      ['node', 'event', 'field_event_address'],
      ['node', 'event', 'field_event_location'],
    ] as [$entity_type, $bundle, $field_name]) {
      $field = FieldConfig::loadByName($entity_type, $bundle, $field_name);
      $this->assertNotNull($field, "$field_name is installed.");
      $this->assertFalse($field->isRequired(), "$field_name is optional after install.");
    }

    // Nothing of ours makes a place compulsory: no custom module ships a
    // location-like field as required, and no custom code sets one of the five
    // fields required.
    $custom = DRUPAL_ROOT . '/modules/custom';
    foreach (glob($custom . '/*/config/{install,optional}/field.field.*', GLOB_BRACE) ?: [] as $path) {
      if (preg_match('/address|location/', basename($path))) {
        $this->assertFalse((bool) (Yaml::parseFile($path)['required'] ?? FALSE), basename($path) . ' is optional.');
      }
    }
    $names = ['field_group_address', 'field_group_location', 'field_event_address', 'field_event_location', 'field_profile_address'];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($custom, \FilesystemIterator::SKIP_DOTS));
    $checked = 0;
    foreach ($files as $file) {
      $path = $file->getPathname();
      if (str_contains($path, '/tests/') || !preg_match('/\.(php|install|module|inc)$/', $path)) {
        continue;
      }
      $checked++;
      $code = (string) file_get_contents($path);
      foreach ($names as $name) {
        $this->assertDoesNotMatchRegularExpression('/' . $name . '.{0,200}setRequired\(\s*TRUE/s', $code, "$path does not require $name.");
      }
    }
    $this->assertGreaterThan(10, $checked, 'The code scan looked at the custom modules.');
  }

  /**
   * A group with neither location field saves and shows to a member.
   */
  public function testGroupWithNoLocationSavesAndRenders(): void {
    $group = $this->createFlexibleGroup('Remote reading circle', 'community');
    $this->assertTrue($group->get('field_group_address')->isEmpty(), 'No address.');
    $this->assertTrue($group->get('field_group_location')->isEmpty(), 'No location name.');
    $this->assertNotEmpty($group->id(), 'It saved.');
    // Saving does not check required fields; validation does, so a location
    // that became compulsory would show up here.
    $this->assertSame([], $this->violations($group), 'The group is valid with no location.');

    $this->setCurrentUser($this->member);
    $this->assertTrue($group->access('view', $this->member), 'A signed-in member can view it.');
    $html = $this->renderEntity($group, ['label' => ['type' => 'string', 'label' => 'hidden']]);
    $this->assertStringContainsString('Remote reading circle', $html);
  }

  /**
   * An event with a custom meeting link and no address saves and shows.
   */
  public function testEventWithMeetingLinkAndNoAddressSavesAndRenders(): void {
    $meeting = Meeting::create([
      'bundle' => 'custom_link',
      'label' => 'Join link',
      'datetime' => [[
        'value' => '2030-03-01T18:00:00',
        'end_value' => '2030-03-01T19:00:00',
        'timezone' => 'UTC',
      ]],
      'backend_settings' => ['url' => self::MEETING_URL],
    ]);
    $meeting->save();

    $event = Node::create([
      'type' => 'event',
      'title' => 'Monthly guild call',
      'body' => ['value' => 'Our monthly call, online only.', 'format' => 'plain_text'],
      'field_content_visibility' => 'community',
      'field_event_date' => '2030-03-01T18:00:00',
      'field_event_date_end' => '2030-03-01T19:00:00',
      'field_event_meeting' => $meeting->id(),
      'uid' => $this->siteOwner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $event->save();

    $event = Node::load($event->id());
    $this->assertSame([], $this->violations($event), 'The event is valid with no address and no location.');
    $this->assertTrue($event->get('field_event_address')->isEmpty(), 'No address.');
    $this->assertTrue($event->get('field_event_location')->isEmpty(), 'No location name.');
    $saved_meeting = $event->get('field_event_meeting')->entity;
    $this->assertSame('custom_link', $saved_meeting->bundle());
    $this->assertSame(self::MEETING_URL, $saved_meeting->getSettings()['url'] ?? NULL, 'The meeting holds the link.');

    $this->setCurrentUser($this->member);
    $this->assertTrue($event->access('view', $this->member), 'A signed-in member can view it.');
    $html = $this->renderEntity($event, [
      'title' => ['type' => 'string', 'label' => 'hidden'],
      'body' => ['type' => 'text_default', 'label' => 'hidden'],
    ]);
    $this->assertStringContainsString('Monthly guild call', $html);
    $this->assertStringContainsString('Our monthly call, online only.', $html);
  }

  /**
   * A Topic in the group saves and shows to a member.
   */
  public function testTopicInTheGroupSavesAndRenders(): void {
    $topic = $this->createGroupTopic($this->group, 'How we meet online', '<p>We meet on a video call.</p>', 'community');
    $topic->set('field_topic_type', $this->referenceTermId())->save();

    $this->setCurrentUser($this->member);
    $this->assertTrue($topic->access('view', $this->member), 'A signed-in member can view it.');
    $html = $this->renderEntity($topic, [
      'title' => ['type' => 'string', 'label' => 'hidden'],
      'body' => ['type' => 'text_default', 'label' => 'hidden'],
    ]);
    $this->assertStringContainsString('How we meet online', $html);
    $this->assertStringContainsString('We meet on a video call.', $html);
  }

  /**
   * One imported text file becomes a Topic in the group, with no place needed.
   */
  public function testOneImportedFileSavesAndRenders(): void {
    $organizer = $this->createVerifiedUser('guild_organizer');
    $this->addManager($this->group, $organizer);
    $this->setCurrentUser($organizer);

    $file = $this->boundUpload($organizer, 'meeting-rules.txt', 'We meet every second Tuesday on a video call. Everyone speaks once before anyone speaks twice.');
    [, $state] = $this->submitUpload(['fids' => (string) $file->id(), 'rights_confirmed' => 1]);
    $this->assertSame([], array_map('strval', $state->getErrors()), 'The upload validates.');

    $runs = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->loadMultiple();
    $this->assertCount(1, $runs);
    $run = reset($runs);
    $this->assertSame(ImportRun::STATUS_FINISHED, $run->get('status')->value);
    $items = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->loadMultiple();
    $this->assertCount(1, $items);
    $item = reset($items);
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $item->get('outcome')->value, 'Outcome: ' . $item->get('outcome')->value . ' / ' . $item->get('reason')->value);

    $relationships = $this->group->getRelationships('group_node:topic');
    $this->assertCount(1, $relationships, 'One Topic in the group.');
    $topic = reset($relationships)->getEntity();
    $this->assertInstanceOf(NodeInterface::class, $topic);
    $this->assertTrue($topic->isPublished());

    // The file is attached, and the Topic is titled from the file's name.
    $attached = $topic->get('field_files')->entity;
    $this->assertInstanceOf(File::class, $attached);
    $this->assertTrue($attached->isPermanent());
    $this->assertSame('Meeting rules', $topic->label());

    // A member of the group can open it.
    $this->setCurrentUser($this->member);
    $this->assertTrue($topic->access('view', $this->member), 'A member of the group can view the imported Topic.');
    $html = $this->renderEntity($topic, ['title' => ['type' => 'string', 'label' => 'hidden']]);
    $this->assertStringContainsString('Meeting rules', $html);
  }

  /**
   * The command makes one first group named after the community, no place.
   */
  public function testFirstGroupHasNoLocationAndTheOrganizerManagesIt(): void {
    $this->setOrganizerEnv();
    [$code, $printed] = $this->runWelcome();

    $this->assertSame(0, $code);
    $this->assertSame("First group created.\nWelcome mail sent.", $printed);
    $this->readMail();

    $group = $this->firstGroup();
    $this->assertSame([], $this->violations($group), 'The first group is valid with no location.');
    $this->assertSame('flexible_group', $group->bundle());
    $this->assertTrue($group->get('field_group_address')->isEmpty(), 'No address.');
    $this->assertTrue($group->get('field_group_location')->isEmpty(), 'No location.');
    $this->assertNotEmpty($this->container->get('state')->get('localnodes.install_step.first_group'));

    $organizer = $this->organizer();
    $this->assertSame((int) $organizer->id(), (int) $group->getOwnerId());
    $member = $group->getMember($organizer);
    $this->assertNotNull($member, 'The organizer is a member.');
    $this->assertArrayHasKey('flexible_group-group_manager', $member->getRoles(FALSE), 'The organizer is the group manager.');
    $this->assertCount(1, $group->getMembers(), 'Nobody else is added.');
    $this->assertSame('community', $group->get('field_flexible_group_visibility')->value, 'Visible to signed-in members only.');
  }

  /**
   * A second run makes no second group; a group saved without its marker is reused.
   */
  public function testFirstGroupIsMadeOncePerSite(): void {
    $this->setOrganizerEnv();
    $this->runWelcome();
    $this->readMail();
    [$code, $printed] = $this->runWelcome();
    $this->assertSame(0, $code);
    $this->assertSame('Welcome mail already sent.', $printed);
    $this->assertCount(1, $this->groupsNamed(self::SITE_NAME));

    // The marker lost after the group was saved: found again, not duplicated.
    $state = $this->container->get('state');
    $state->delete('localnodes.install_step.first_group');
    $state->delete('localnodes.install_step.organizer_mail_sent');
    [$code, $printed] = $this->runWelcome();
    $this->assertSame(0, $code);
    $this->assertSame("First group already exists.\nWelcome mail sent.", $printed);
    $this->assertCount(1, $this->groupsNamed(self::SITE_NAME));
    $this->assertNotEmpty($state->get('localnodes.install_step.first_group'));
  }

  /**
   * Without SITE_NAME there is nothing to name the group after: none is made.
   */
  public function testNoSiteNameMakesNoGroup(): void {
    $this->setOrganizerEnv();
    putenv('SITE_NAME');
    [$code, $printed] = $this->runWelcome();

    $this->assertSame(0, $code);
    $this->assertSame('Welcome mail sent.', $printed);
    $this->assertSame([], $this->groupsNamed(self::SITE_NAME));
    $this->assertEmpty($this->container->get('state')->get('localnodes.install_step.first_group'));
  }

  /**
   * An existing organizer account that has no role is not made a manager of more.
   *
   * Only the organizer is added to the first group (T-23-31): a member who is
   * already in the community is not.
   */
  public function testOnlyTheOrganizerJoinsTheFirstGroup(): void {
    $this->setOrganizerEnv();
    $this->runWelcome();
    $group = $this->firstGroup();
    $ids = array_map(static fn ($membership) => (int) $membership->getUser()->id(), $group->getMembers());
    $this->assertSame([(int) $this->organizer()->id()], $ids);
    $this->assertNotContains((int) $this->member->id(), $ids);
  }

  /**
   * Sets the organizer variables the way the container does.
   */
  private function setOrganizerEnv(): void {
    putenv('ORGANIZER_EMAIL=' . self::ORGANIZER_EMAIL);
    putenv('ORGANIZER_NAME=' . self::SITE_NAME);
    putenv('SITE_NAME=' . self::SITE_NAME);
  }

  /**
   * Runs drush localnodes:organizer-welcome once.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything the command printed.
   */
  private function runWelcome(): array {
    $output = new BufferedOutput();
    $command = new OrganizerCommands();
    $command->setOutput($output);
    $code = $command->organizerWelcome();
    return [$code, trim($output->fetch())];
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
   * Groups with the community's name.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   The groups.
   */
  private function groupsNamed(string $label): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('group');
    $storage->resetCache();
    return array_values($storage->loadByProperties(['label' => $label]));
  }

  /**
   * The one first group.
   */
  private function firstGroup(): GroupInterface {
    $groups = $this->groupsNamed(self::SITE_NAME);
    $this->assertCount(1, $groups, 'One group is named after the community.');
    return $groups[0];
  }

  /**
   * The validation messages of an entity, as strings.
   *
   * @return string[]
   *   Messages keyed by field path, empty when the entity is valid.
   */
  private function violations($entity): array {
    $messages = [];
    foreach ($entity->validate() as $violation) {
      $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    return $messages;
  }

  /**
   * The "Reference" topic type's id.
   */
  private function referenceTermId(): int {
    $terms = $this->container->get('entity_type.manager')->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'topic_types', 'name' => 'Reference']);
    return (int) reset($terms)->id();
  }

  /**
   * Renders an entity for the current user with a small view display.
   *
   * The shipped displays reach into modules this stack does not enable, so a
   * display with just the named components stands in. What is proven is that
   * the entity renders for this user without a location, and shows its words.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param array<string, array<string, mixed>> $components
   *   Field name to display options.
   */
  private function renderEntity($entity, array $components): string {
    $display = EntityViewDisplay::load($entity->getEntityTypeId() . '.' . $entity->bundle() . '.default') ?? EntityViewDisplay::create([
      'targetEntityType' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'mode' => 'default',
      'status' => TRUE,
    ]);
    foreach ($components as $name => $options) {
      $display->setComponent($name, $options);
    }
    $display->save();
    $build = $this->container->get('entity_type.manager')
      ->getViewBuilder($entity->getEntityTypeId())
      ->view($entity, 'default');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * A temporary file bound to the organizer and group, as the endpoint leaves it.
   */
  private function boundUpload(UserInterface $organizer, string $name, string $contents): File {
    $folder = 'private://localnodes_import/' . $this->group->id();
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    $uri = $file_system->getDestinationFilename($folder . '/' . $name, FileExists::Rename);
    file_put_contents($uri, $contents);
    $file = File::create(['uri' => $uri, 'filename' => $name, 'uid' => $organizer->id()]);
    $file->setTemporary();
    $file->save();
    $store = $this->container->get('tempstore.private')->get('localnodes_import');
    $bound = $store->get('uploads:' . $this->group->id()) ?? [];
    $bound[(int) $file->id()] = $name;
    $store->set('uploads:' . $this->group->id(), $bound);
    return $file;
  }

  /**
   * A programmed submission of the Upload files form (runs the batch).
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormStateInterface}
   *   The form and its state.
   */
  private function submitUpload(array $values): array {
    $this->routedRequest('localnodes_import.files', $this->group);
    $builder = $this->container->get('form_builder');
    $state = (new FormState())->setValues($values);
    $state->addBuildInfo('args', [$this->group]);
    $state->setUserInput($values);
    $state->setProgrammed();
    $form_id = $builder->getFormId(UploadFilesForm::class, $state);
    $form = $builder->retrieveForm($form_id, $state);
    $state->setSubmitted();
    $state->setValidationEnforced();
    $state->clearErrors();
    $builder->prepareForm($form_id, $form, $state);
    $builder->processForm($form_id, $form, $state);
    return [$form, $state];
  }

}
