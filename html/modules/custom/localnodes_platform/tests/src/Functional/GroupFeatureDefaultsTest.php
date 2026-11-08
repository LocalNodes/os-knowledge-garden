<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationship;
use Drupal\group\Entity\GroupType;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Server;
use Drupal\social_event\Entity\Node\Event;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * The four Open Social 13.1 group features for an online-only community.
 *
 * Phase 22.2 criterion 2 (D-06): a flexible group with no location, an event
 * with a custom_link meeting and no address, built only inside this test, so
 * no demo content changes. In that group:
 * - a member can name the group as an affiliation on their profile;
 * - the group manager sees the "Request to join" message settings;
 * - the member enrolled in the event gets its meeting link;
 * - the group manager can edit and delete a member's topic and post, and a
 *   plain member can't.
 * The first two are switched on by localnodes_platform_install()'s
 * _localnodes_platform_enable_group_features(); the last two ship on in 13.1.
 *
 * Why Functional, not Kernel: Open Social's event, meeting and profile stack
 * (social_event with meeting_api, meeting_api_manual, inline_entity_form,
 * datetime_range_timezone, address, profile and the group affiliation widget)
 * doesn't boot in a Kernel test, so this installs the social profile and
 * drives real pages (RESEARCH "Wave 0 Gaps").
 *
 * localnodes_platform itself is not installed: it pulls in the AI, Gemini and
 * Qdrant stack. Its .install file is loaded by path and the helper is called
 * in setUp, exactly as hook_install calls it.
 *
 * Runs in the DDEV web container (scripts/run-phpunit.sh, PHPUNIT_MODE=web),
 * in lane fresh3-p222 only (D-08).
 *
 * @group localnodes_platform
 * @group phase222
 * @group phase22_functional
 */
final class GroupFeatureDefaultsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   *
   * Open Social's shipped config carries third-party settings from modules
   * this test doesn't enable (as in OverviewFunctionalTestBase).
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The online meeting link the event uses.
   */
  private const MEETING_URL = 'https://meet.example.org/guild-call';

  /**
   * An allow_customize value for another group type, set before the helper.
   */
  private const OTHER_GROUP_TYPE = 'organization';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to the DDEV Solr prints its response; PHPUnit would fail the
    // test for unexpected output (as OverviewFunctionalTestBase does).
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }

    // Keep the fixture out of the lane's one Solr core: point Open Social's
    // Solr server at a host that doesn't resolve before any content exists.
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }

    // Another group type's choice, made before our helper runs; it must
    // survive (the helper only adds flexible_group).
    $this->config('social_group_request.settings')
      ->set('allow_customize', [self::OTHER_GROUP_TYPE => TRUE])
      ->save();

    $path = $this->container->get('extension.list.module')->getPath('localnodes_platform');
    require_once DRUPAL_ROOT . '/' . $path . '/localnodes_platform.install';
    _localnodes_platform_enable_group_features();
  }

  /**
   * Both defaults are on, a second run changes nothing, other keys survive.
   */
  public function testDefaultsAreIdempotent(): void {
    $group_type = GroupType::load('flexible_group');
    $this->assertNotNull($group_type, 'Open Social ships flexible_group.');
    $this->assertTrue($group_type->getThirdPartySetting('social_profile', 'affiliation_enabled'), 'flexible_group has group affiliation on.');
    $this->assertTrue($this->container->get('social_profile.group_affiliation')->isGroupAffiliationEnabled(), 'Open Social sees group affiliation as enabled.');

    $allow = $this->config('social_group_request.settings')->get('allow_customize');
    $this->assertTrue($allow['flexible_group'] ?? NULL, 'flexible_group managers can customise the request-to-join form.');
    $this->assertTrue($allow[self::OTHER_GROUP_TYPE] ?? NULL, 'Another group type\'s allow_customize value survives.');

    // Second run: no config object is saved, and the stored values are the
    // same bytes.
    $before_settings = $this->rawConfig('social_group_request.settings');
    $before_type = $this->rawConfig('group.type.flexible_group');
    $saved = [];
    $this->container->get('event_dispatcher')->addListener(
      ConfigEvents::SAVE,
      static function (ConfigCrudEvent $event) use (&$saved): void {
        $saved[] = $event->getConfig()->getName();
      },
    );

    _localnodes_platform_enable_group_features();

    $this->assertSame([], $saved, 'A second run saves no config object.');
    $this->assertSame($before_settings, $this->rawConfig('social_group_request.settings'), 'social_group_request.settings is unchanged.');
    $this->assertSame($before_type, $this->rawConfig('group.type.flexible_group'), 'group.type.flexible_group is unchanged.');
  }

  /**
   * An online-only guild gets all four criterion-2 features.
   */
  public function testOnlineOnlyCommunity(): void {
    $timezone = $this->config('system.date')->get('timezone.default');
    $site_manager = $this->createAccount('guild_site_manager', ['verified', 'sitemanager'], $timezone);
    $manager = $this->createAccount('guild_manager', ['verified'], $timezone);
    $member = $this->createAccount('guild_member', ['verified'], $timezone);
    $other_member = $this->createAccount('guild_other_member', ['verified'], $timezone);

    // A site manager creates the group through the form, with no location.
    $this->drupalLogin($site_manager);
    $this->drupalGet('/group/add/flexible_group');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'label[0][value]' => 'Online Guild',
      'field_flexible_group_visibility' => 'community',
      'field_group_allowed_visibility[community]' => TRUE,
      'field_group_allowed_visibility[group]' => TRUE,
      'field_group_allowed_join_method' => 'direct',
    ], 'Save');
    $groups = $this->container->get('entity_type.manager')->getStorage('group')
      ->loadByProperties(['label' => 'Online Guild']);
    $this->assertCount(1, $groups, 'The group form saved the online-only group.');
    $group = reset($groups);
    $this->assertInstanceOf(GroupInterface::class, $group);
    $this->assertSame('flexible_group', $group->bundle());
    $this->assertTrue($group->get('field_group_address')->isEmpty(), 'The group has no address.');
    $this->assertTrue($group->get('field_group_location')->isEmpty(), 'The group has no location name.');

    $group->addMember($manager, ['group_roles' => ['flexible_group-group_manager']]);
    $group->addMember($member);
    $group->addMember($other_member);
    $group = Group::load($group->id());

    // The group manager creates an online event in the group, with no
    // address. The meeting sub-form appears only after the "Online" AJAX
    // rebuild, which a no-JS browser can't trigger, so the link is added on
    // the edit form, where the meeting form is built from the saved meeting.
    $this->drupalLogin($manager);
    $start = new \DateTimeImmutable('-1 hour', new \DateTimeZone($timezone));
    $end = new \DateTimeImmutable('+2 hours', new \DateTimeZone($timezone));
    $this->drupalGet('/node/add/event');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'title[0][value]' => 'Guild call',
      'body[0][value]' => 'Our monthly call, online only.',
      'field_event_date[0][value][date]' => $start->format('Y-m-d'),
      'field_event_date[0][value][time]' => $start->format('H:i:s'),
      'field_event_date_end[0][value][date]' => $end->format('Y-m-d'),
      'field_event_date_end[0][value][time]' => $end->format('H:i:s'),
      'groups' => (string) $group->id(),
      'field_event_meeting[meeting_form][is_online]' => TRUE,
    ], 'Create event');
    $event = $this->loadNodeByTitle('Guild call');
    $this->assertInstanceOf(Event::class, $event, 'The event form saved the event.');
    $this->assertTrue($event->isOnline(), 'The event is online.');

    $this->drupalGet('/node/' . $event->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([
      'field_event_meeting[meeting_form][backend_settings][url]' => self::MEETING_URL,
    ], 'Save');

    $event = $this->loadNodeByTitle('Guild call');
    $this->assertInstanceOf(Event::class, $event);
    $this->assertTrue($event->get('field_event_address')->isEmpty(), 'The event has no address.');
    $meeting = $event->getMeeting();
    $this->assertNotNull($meeting, 'The event has a meeting.');
    $this->assertSame('custom_link', $meeting->bundle());
    $this->assertSame(self::MEETING_URL, $meeting->getSettings()['url'] ?? NULL, 'The meeting holds the link.');
    $event_groups = array_map(static fn(GroupRelationship $relationship): string => (string) $relationship->getGroupId(), GroupRelationship::loadByEntity($event));
    $this->assertContains((string) $group->id(), $event_groups, 'The event is in the group.');

    // The member enrolls, and the event page offers the link.
    $this->container->get('entity_type.manager')->getStorage('event_enrollment')->create([
      'user_id' => $member->id(),
      'field_event' => $event->id(),
      'field_account' => $member->id(),
      'field_enrollment_status' => '1',
    ])->save();
    $this->assertSame(self::MEETING_URL, $event->getMeetingLink($member), 'The enrolled member gets the meeting link.');

    $this->drupalLogin($member);
    $this->drupalGet('/node/' . $event->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Join now');
    $this->assertSession()->pageTextNotContains('Contact organizers to join');
    $this->assertSame(self::MEETING_URL, $this->pressJoinNow(), '"Join now" sends the member to the meeting link.');

    // Affiliation: the member's profile form offers the group.
    $this->drupalGet('/user/' . $member->id() . '/profile');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->optionExists('field_group_affiliation[0][container][group]', (string) $group->id());

    // Request to join: the group manager sees the message settings.
    $this->drupalLogin($manager);
    $this->drupalGet('/group/' . $group->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Personalize request to join form:');
    $this->assertSession()->fieldExists('field_grequest_form_description');
    $this->assertSession()->fieldExists('field_grequest_form_default');

    // Moderation: a member's topic and post in the group.
    $topic = Node::create([
      'type' => 'topic',
      'title' => 'Member topic in the guild',
      'body' => ['value' => 'A member topic.', 'format' => 'basic_html'],
      'field_content_visibility' => 'group',
      'uid' => $member->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $topic->save();
    $group->addRelationship($topic, 'group_node:topic');

    $post = Post::create([
      'type' => 'post',
      'user_id' => $member->id(),
      'field_post' => 'A member post in the guild stream.',
      'field_recipient_group' => $group->id(),
      'field_visibility' => '3',
      'status' => 1,
    ]);
    $post->save();

    $paths = [
      'topic edit' => '/node/' . $topic->id() . '/edit',
      'topic delete' => '/node/' . $topic->id() . '/delete',
      'post edit' => '/post/' . $post->id() . '/edit',
      'post delete' => '/post/' . $post->id() . '/delete',
    ];
    foreach ($paths as $label => $path) {
      $this->drupalGet($path);
      $this->assertSame(200, $this->getSession()->getStatusCode(), "The group manager can open the member's $label form.");
    }

    $this->drupalLogin($other_member);
    foreach ($paths as $label => $path) {
      $this->drupalGet($path);
      $this->assertSame(403, $this->getSession()->getStatusCode(), "A plain member gets 403 on another member's $label form.");
    }
  }

  /**
   * Creates an account with roles and a timezone.
   */
  private function createAccount(string $name, array $roles, string $timezone): UserInterface {
    $account = $this->createUser([], $name, FALSE, [
      'roles' => $roles,
      'timezone' => $timezone,
    ]);
    $this->assertInstanceOf(UserInterface::class, $account);
    return $account;
  }

  /**
   * Loads the one node with a title, uncached.
   */
  private function loadNodeByTitle(string $title): ?NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $nodes = $storage->loadByProperties(['title' => $title]);
    $this->assertLessThanOrEqual(1, count($nodes));
    $node = reset($nodes);
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Stored config data for a config name, as the database holds it.
   */
  private function rawConfig(string $name): string {
    $data = $this->container->get('database')
      ->select('config', 'c')
      ->fields('c', ['data'])
      ->condition('collection', '')
      ->condition('name', $name)
      ->execute()
      ->fetchField();
    $this->assertIsString($data, "$name is stored.");
    return $data;
  }

  /**
   * Presses "Join now" the way its AJAX handler receives it.
   *
   * The enroll form's button is AJAX-only: EnrollActionForm::submitForm() is
   * empty and ajaxSubmitEnrollForm() answers with a redirect command to the
   * meeting link. This posts the form as Drupal's AJAX framework does.
   *
   * @return string|null
   *   The URL of the redirect command, or NULL if there is none.
   */
  private function pressJoinNow(): ?string {
    $form = $this->getSession()->getPage()->find('css', 'form[name="enroll_action_form"]');
    $this->assertNotNull($form, 'The event page has the enroll form.');
    $values = [];
    foreach ($form->findAll('css', 'input[type="hidden"]') as $input) {
      $values[(string) $input->getAttribute('name')] = (string) $input->getAttribute('value');
    }
    $values['op'] = 'Join now';
    $values['_triggering_element_name'] = 'op';
    $values['_triggering_element_value'] = 'Join now';
    $values['_drupal_ajax'] = '1';

    $action = (string) $form->getAttribute('action');
    $url = $this->getAbsoluteUrl($action);
    $url .= (str_contains($url, '?') ? '&' : '?') . 'ajax_form=1&_wrapper_format=drupal_ajax';

    $client = $this->getSession()->getDriver()->getClient();
    $client->request('POST', $url, $values);
    $commands = Json::decode((string) $client->getResponse()->getContent());
    $this->assertIsArray($commands, 'The enroll form answered with AJAX commands.');
    foreach ($commands as $command) {
      if (($command['command'] ?? NULL) === 'redirect') {
        return $command['url'] ?? NULL;
      }
    }
    return NULL;
  }

}
