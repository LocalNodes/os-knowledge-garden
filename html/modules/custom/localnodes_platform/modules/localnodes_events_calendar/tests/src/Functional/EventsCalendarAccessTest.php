<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_events_calendar\Functional;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * The month calendars show each visitor only the events they may see.
 *
 * A members-only group holds one group-only event; two public events sit
 * outside it. On the community calendar a member of the group sees all three,
 * while a signed-in outsider and a signed-out visitor see only the public
 * two. A group's calendar opens for its members only, as its events list
 * does.
 *
 * The site runs in Denver time and both public events start in the evening,
 * after midnight UTC, so each must appear on its local day, not the next.
 *
 * Why Functional: the calendar is a view over Open Social's event and group
 * access, which needs the social profile installed. localnodes_platform
 * itself is not installed (it pulls in the AI stack); this module is.
 *
 * @group localnodes_platform
 * @group phase22_functional
 */
final class EventsCalendarAccessTest extends BrowserTestBase {

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
   * this test doesn't enable.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The site's time zone, west of UTC so evening events cross midnight UTC.
   */
  private const TIMEZONE = 'America/Denver';

  /**
   * The calendar's query for the month every event is in.
   */
  private const MONTH = ['query' => ['calendar_timestamp' => '2027-03-01']];

  private UserInterface $member;

  private UserInterface $outsider;

  private GroupInterface $group;

  private UserInterface $houseManager;

  private NodeInterface $privateEvent;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response; PHPUnit would fail the test for
    // unexpected output.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }

    // Keep the test's events out of the development Solr core.
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }

    $this->config('system.date')
      ->set('timezone.default', self::TIMEZONE)
      ->set('timezone.user.configurable', FALSE)
      ->save();

    $this->container->get('module_installer')->install(['localnodes_events_calendar']);
    $this->rebuildContainer();
    // Saving a group renders activity messages, which needs every module's
    // code loaded in this process, as a page request would have it.
    $this->container->get('module_handler')->loadAll();

    $platform = DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('localnodes_platform');
    require_once $platform . '/localnodes_platform.install';
    if (function_exists('_localnodes_platform_protect_private_groups')) {
      _localnodes_platform_protect_private_groups();
    }

    $organizer = $this->createAccount('cal_organizer');
    $this->member = $this->createAccount('cal_member');
    $this->outsider = $this->createAccount('cal_outsider');
    $this->houseManager = $this->createAccount('cal_house_manager');

    $this->group = Group::create([
      'type' => 'flexible_group',
      'label' => 'Juniper House',
      'uid' => $organizer->id(),
      'field_flexible_group_visibility' => 'members',
      'field_group_allowed_visibility' => ['group'],
      'field_group_allowed_join_method' => 'added',
    ]);
    $this->group->save();
    $this->group->addMember($this->member);
    $this->group->addMember($this->houseManager, ['group_roles' => ['flexible_group-group_manager']]);

    // 18:00 MST on 10 March is 01:00 UTC on the 11th.
    $this->createEvent('Cedar Row repair cafe', '2027-03-11T01:00:00', 'public', $organizer);
    // 23:30 MST on 12 March is 06:30 UTC on the 13th.
    $this->createEvent('Late lantern walk', '2027-03-13T06:30:00', 'public', $organizer);
    $this->privateEvent = $this->createEvent('Juniper House supper planning', '2027-03-16T02:00:00', 'group', $organizer);
    $this->group->addRelationship($this->privateEvent, 'group_node:event');
  }

  /**
   * The community calendar lists only the events each visitor may see.
   */
  public function testCommunityCalendar(): void {
    $this->drupalLogin($this->member);
    $this->drupalGet('/community-events/calendar', self::MONTH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEventOnDay('Cedar Row repair cafe', 10);
    $this->assertEventOnDay('Late lantern walk', 12);
    $this->assertEventOnDay('Juniper House supper planning', 15);
    $this->assertSession()->linkExists('List');

    // The same page, straight after, for someone outside the group: the
    // member's rendering must not be served from cache.
    $this->drupalLogin($this->outsider);
    $this->drupalGet('/community-events/calendar', self::MONTH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEventOnDay('Cedar Row repair cafe', 10);
    $this->assertEventOnDay('Late lantern walk', 12);
    $this->assertSession()->pageTextNotContains('Juniper House supper planning');

    $this->drupalLogout();
    $this->drupalGet('/community-events/calendar', self::MONTH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEventOnDay('Cedar Row repair cafe', 10);
    $this->assertEventOnDay('Late lantern walk', 12);
    $this->assertSession()->pageTextNotContains('Juniper House supper planning');
  }

  /**
   * The events list links to the calendar for every visitor.
   */
  public function testCalendarLinkOnEventsList(): void {
    $this->drupalGet('/community-events');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists('/community-events/calendar');
  }

  /**
   * A group's calendar opens for its members, as its events list does.
   */
  public function testGroupCalendar(): void {
    $path = '/group/' . $this->group->id() . '/events/calendar';

    $this->drupalLogin($this->member);
    $this->drupalGet('/group/' . $this->group->id() . '/events');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists('/group/' . $this->group->id() . '/events/calendar');
    $this->drupalGet($path, self::MONTH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEventOnDay('Juniper House supper planning', 15);
    // The group's calendar lists the group's events only.
    $this->assertSession()->pageTextNotContains('Cedar Row repair cafe');

    $this->drupalLogin($this->outsider);
    $this->drupalGet($path, self::MONTH);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Juniper House supper planning');

    $this->drupalLogout();
    $this->drupalGet($path, self::MONTH);
    $this->assertSession()->pageTextNotContains('Juniper House supper planning');
    $this->assertStringContainsString('/user/login', $this->getSession()->getCurrentUrl(), 'A signed-out visitor is sent to sign in.');
  }

  /** @dataProvider siteRoleAndHouseVisibility */
  public function testSiteRoleDoesNotOpenPrivateEvents(string $role, string $visibility): void {
    $this->group->set('field_flexible_group_visibility', $visibility)->save();
    $manager = $this->createAccount('calendar_' . $role, ['verified', $role]);
    $this->assertFalse($this->group->getMember($manager));
    $this->drupalLogin($manager);
    $this->drupalGet('/community-events/calendar', self::MONTH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEventOnDay('Cedar Row repair cafe', 10);
    $this->assertSession()->pageTextNotContains('Juniper House supper planning');
    $this->drupalGet('/group/' . $this->group->id() . '/events/calendar', self::MONTH);
    if ($visibility === 'members') {
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->assertSession()->pageTextNotContains('Juniper House supper planning');
    $this->drupalGet('/node/' . $this->privateEvent->id());
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Juniper House supper planning');

    $this->drupalLogin($this->member);
    $this->drupalGet('/group/' . $this->group->id() . '/events/calendar', self::MONTH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEventOnDay('Juniper House supper planning', 15);
    $this->drupalLogin($this->houseManager);
    $this->drupalGet('/node/' . $this->privateEvent->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Juniper House supper planning');
  }

  public static function siteRoleAndHouseVisibility(): array {
    return [
      ['sitemanager', 'community'], ['sitemanager', 'members'],
      ['contentmanager', 'community'], ['contentmanager', 'members'],
    ];
  }

  /**
   * Asserts an event's link sits in the cell of a day of March 2027.
   */
  private function assertEventOnDay(string $title, int $day): void {
    $xpath = sprintf(
      '//td[number(@data-calendar-view-day)=%d and number(@data-calendar-view-month)=3 and number(@data-calendar-view-year)=27 and contains(concat(" ", @class, " "), " current-month ")]//a[normalize-space(.)="%s"]',
      $day,
      $title,
    );
    $this->assertSession()->elementExists('xpath', $xpath);
  }

  /**
   * Creates a verified account.
   */
  private function createAccount(string $name, array $roles = ['verified']): UserInterface {
    $account = $this->createUser([], $name, FALSE, [
      'roles' => $roles,
      'timezone' => self::TIMEZONE,
    ]);
    $this->assertInstanceOf(UserInterface::class, $account);
    return $account;
  }

  /**
   * Creates a published event starting at a UTC time, two hours long.
   */
  private function createEvent(string $title, string $start_utc, string $visibility, UserInterface $owner): NodeInterface {
    $end = (new \DateTimeImmutable($start_utc, new \DateTimeZone('UTC')))->modify('+2 hours')->format('Y-m-d\TH:i:s');
    $event = Node::create([
      'type' => 'event',
      'title' => $title,
      'uid' => $owner->id(),
      'status' => NodeInterface::PUBLISHED,
      'field_event_date' => $start_utc,
      'field_event_date_end' => $end,
      'field_content_visibility' => $visibility,
    ]);
    $event->save();
    return $event;
  }

}
