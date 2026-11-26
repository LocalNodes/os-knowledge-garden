<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_events\Functional;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Server;
use Drupal\social_event\Entity\EventEnrollment;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Who may open an event's attendee list, as page requests.
 *
 * Only the event's maker, its co-hosts, and a site manager for events outside
 * members-only groups. User 1's sign-in is blocked by the platform's install,
 * so its row is in the kernel test.
 *
 * @group localnodes_events
 * @group phase22_functional
 */
final class AttendeeListAccessTest extends BrowserTestBase {

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  /** @var \Drupal\user\UserInterface[] */
  private array $people = [];

  private NodeInterface $openEvent;

  private NodeInterface $membersEvent;

  protected function setUp(): void {
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }
    // As the entrypoint does after site:install; our events module comes in
    // as the platform's dependency.
    $this->container->get('module_installer')->install(['localnodes_platform']);
    $this->rebuildAll();
    $this->container->get('module_handler')->loadAll();

    $open = $this->group('Hillside Builders', 'public', 'maker');
    foreach (['maker', 'cohost', 'member', 'attendee', 'other_maker', 'river'] as $name) {
      $open->addMember($this->person($name));
    }
    $open->addMember($this->person('group_org'), ['group_roles' => ['flexible_group-group_manager']]);
    $this->person('sm', ['verified', 'sitemanager']);
    $this->openEvent = $this->event('Builders evening', 'maker', 'public', ['cohost'], $open);
    $this->event('Another evening', 'other_maker', 'public', [], $open);
    foreach (['attendee', 'river', 'member'] as $name) {
      $this->enroll($this->openEvent, $name);
    }

    $members = $this->group('Evening Hosts', 'members', 'mo_maker');
    foreach (['mo_maker', 'mo_member'] as $name) {
      $members->addMember($this->person($name));
    }
    $members->addMember($this->person('sm_in', ['verified', 'sitemanager']));
    $this->membersEvent = $this->event('Hosts planning chat', 'mo_maker', 'group', [], $members);
    $this->enroll($this->membersEvent, 'mo_member');
  }

  /** Each kind of person, as requests. */
  public function testAttendeeListRefusals(): void {
    $open = (int) $this->openEvent->id();
    $mo = (int) $this->membersEvent->id();
    $this->assertRefusedSignedOut("/node/$open/all-enrollments");
    $this->assertRefusedSignedOut("/node/$open/all-enrollments/add-enrollees");
    $this->assertRefusedSignedOut("/node/$mo/all-enrollments");
    $table = [
      // person => [open-group list, add enrollees, members-only list]
      'member' => [403, 403, 403],
      'attendee' => [403, 403, NULL],
      'other_maker' => [403, 403, NULL],
      'group_org' => [403, 403, NULL],
      'sm' => [200, 200, 403],
      'sm_in' => [NULL, NULL, 403],
      'mo_member' => [NULL, NULL, 403],
      'maker' => [200, 200, NULL],
      'cohost' => [200, 200, NULL],
      'mo_maker' => [NULL, NULL, 200],
    ];
    foreach ($table as $who => [$list, $add, $members]) {
      $this->drupalLogin($this->people[$who]);
      foreach (["/node/$open/all-enrollments" => $list, "/node/$open/all-enrollments/add-enrollees" => $add, "/node/$mo/all-enrollments" => $members] as $path => $status) {
        if ($status === NULL) {
          continue;
        }
        $this->drupalGet($path);
        $this->assertSame($status, $this->getSession()->getStatusCode(), "$who on $path");
      }
      // The names tab with "Hide enrollments" off: anyone who may view the
      // event; the members-only event only for its members.
      $this->drupalGet("/node/$open/enrollments");
      $this->assertSame(200, $this->getSession()->getStatusCode(), "$who on the open event's names tab");
      $this->drupalLogout();
    }
    // The site manager outside members-only scope gets the organizer's
    // controls: the select column of the list.
    $this->drupalLogin($this->people['sm']);
    $this->drupalGet("/node/$open/all-enrollments");
    $this->assertSession()->elementExists('css', 'input[name^="social_views_bulk_operations_bulk_form_enrollments_1"]');
    $this->drupalLogout();
    $this->drupalLogin($this->people['maker']);
    $this->drupalGet("/node/$open/all-enrollments");
    $this->assertSession()->elementExists('css', 'input[name^="social_views_bulk_operations_bulk_form_enrollments_1"]');
  }

  /** The box on: members get neither list; the organizers still do. */
  public function testHiddenEnrollmentsRefuseBothListsToMembers(): void {
    $nid = (int) $this->openEvent->id();
    $this->openEvent->set('field_hide_enrollments', 1)->save();
    $this->drupalLogin($this->people['member']);
    foreach (["/node/$nid/all-enrollments", "/node/$nid/enrollments"] as $path) {
      $this->drupalGet($path);
      $this->assertSame(403, $this->getSession()->getStatusCode(), "member on $path");
    }
    $this->drupalGet("/node/$nid");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefNotExists("/node/$nid/all-enrollments");
    $this->assertSession()->linkByHrefNotExists("/node/$nid/enrollments");
    $this->drupalLogout();
    $this->drupalLogin($this->people['maker']);
    $this->drupalGet("/node/$nid/all-enrollments");
    $this->assertSession()->statusCodeEquals(200);
  }

  /** The bulk routes answer 403 to anyone who may not manage the list. */
  public function testBulkActionRoutesRefuseNonManagers(): void {
    $nid = (int) $this->openEvent->id();
    $before = $this->enrollmentCount($nid);
    $this->drupalLogin($this->people['member']);
    foreach (["/node/$nid/all-enrollments/configure-action", "/node/$nid/all-enrollments/confirm-action"] as $path) {
      $this->drupalGet($path);
      $this->assertSame(403, $this->getSession()->getStatusCode(), "member on $path");
    }
    // A crafted submission of the list's form with a "remove" selection.
    $client = $this->getSession()->getDriver()->getClient();
    $client->request('POST', $this->buildUrl("/node/$nid/all-enrollments"), [
      'action' => 'social_event_managers_delete_event_enrollment_action',
      'social_views_bulk_operations_bulk_form_enrollments_1' => ['0' => 'x'],
      'op' => 'Apply to selected items',
      'form_id' => 'views_form_event_manage_enrollments_page_manage_enrollments',
    ]);
    $this->assertSame(403, $client->getResponse()->getStatusCode(), 'member posting the list form');
    $this->assertSame($before, $this->enrollmentCount($nid), 'Nothing removed.');
  }

  /** The site manager outside members-only groups, with no enrollment, removes an attendee. */
  public function testThePermittedSiteManagerUsesTheControls(): void {
    $nid = (int) $this->openEvent->id();
    $before = $this->enrollmentCount($nid);
    $this->drupalLogin($this->people['sm']);
    $this->drupalGet("/node/$nid/all-enrollments");
    $page = $this->getSession()->getPage();
    $boxes = $page->findAll('css', 'input[type="checkbox"][name^="social_views_bulk_operations_bulk_form_enrollments_1"]');
    $this->assertNotEmpty($boxes, 'The select column is there.');
    $boxes[0]->check();
    // The list offers each action as its own button ("Remove selected enrollees").
    $this->pressFirstButton('/^Remove selected/i');
    $this->checkForMetaRefresh();
    if ($this->enrollmentCount($nid) === $before) {
      $this->pressFirstButton('/execute|confirm|remove|delete|apply/i');
      $this->checkForMetaRefresh();
    }
    $this->assertSame($before - 1, $this->enrollmentCount($nid), 'One attendee removed. Page: ' . $this->getUrl());
  }

  private function pressFirstButton(string $pattern): void {
    foreach ($this->getSession()->getPage()->findAll('css', 'input[type="submit"], button[type="submit"]') as $button) {
      if (preg_match($pattern, (string) ($button->getAttribute('value') ?: $button->getText()))) {
        $button->press();
        return;
      }
    }
    $this->fail('No button matching ' . $pattern . ' on ' . $this->getUrl());
  }

  private function assertRefusedSignedOut(string $path): void {
    $this->drupalGet($path);
    $status = $this->getSession()->getStatusCode();
    $this->assertTrue($status === 403 || str_contains($this->getUrl(), '/user/login'), "signed out on $path: $status");
  }

  private function enrollmentCount(int $nid): int {
    \Drupal::entityTypeManager()->getStorage('event_enrollment')->resetCache();
    return (int) \Drupal::entityQuery('event_enrollment')->accessCheck(FALSE)->condition('field_event', $nid)->count()->execute();
  }

  private function person(string $name, array $roles = ['verified']): UserInterface {
    return $this->people[$name] ??= $this->createUser([], $name, FALSE, ['roles' => $roles]);
  }

  private function group(string $label, string $visibility, string $owner): GroupInterface {
    $group = Group::create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $this->person($owner)->id(),
      'field_flexible_group_visibility' => $visibility,
      'field_group_allowed_visibility' => $visibility === 'members' ? ['group'] : ['public', 'community', 'group'],
      'field_group_allowed_join_method' => 'added',
    ]);
    $group->save();
    return $group;
  }

  private function event(string $title, string $maker, string $visibility, array $cohosts, GroupInterface $group): NodeInterface {
    $event = Node::create([
      'type' => 'event',
      'title' => $title,
      'uid' => $this->people[$maker]->id(),
      'status' => NodeInterface::PUBLISHED,
      'field_event_date' => '2027-03-11T01:00:00',
      'field_event_date_end' => '2027-03-11T03:00:00',
      'field_content_visibility' => $visibility,
      'field_event_enroll' => 1,
      'field_enroll_method' => 1,
      'field_hide_enrollments' => 0,
      'field_event_managers' => array_map(fn (string $n) => ['target_id' => $this->people[$n]->id()], $cohosts),
    ]);
    $event->save();
    $group->addRelationship($event, 'group_node:event');
    return $event;
  }

  private function enroll(NodeInterface $event, string $who): void {
    EventEnrollment::create([
      'user_id' => $this->people[$who]->id(),
      'status' => 1,
      'field_event' => $event->id(),
      'field_account' => $this->people[$who]->id(),
      'field_enrollment_status' => 1,
    ])->save();
  }

}
