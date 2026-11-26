<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_events\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\social_event\Entity\EventEnrollment;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Who may open an event's attendee list route by route.
 *
 * Only the event's maker, its co-hosts, user 1, and a site manager for events
 * outside members-only groups; and each only while they may view the event.
 *
 * @group localnodes_events
 * @group house_privacy
 */
final class AttendeeListAccessKernelTest extends GroupAccessKernelTestBase {

  protected static $modules = ['localnodes_platform', 'localnodes_events', 'social_user', 'role_delegation', 'social_event', 'social_event_managers', 'better_exposed_filters', 'profile', 'block'];

  /** The routes of the management list: the rule decides alone (with the house check). */
  private const MANAGE_ROUTES = [
    'view.event_manage_enrollments.page_manage_enrollments',
    'social_event_managers.add_enrollees',
    'social_event_managers.vbo.execute_configurable',
    'social_event_managers.vbo.confirm',
    'view.event_manage_enrollment_requests.page_manage_enrollment_requests',
    'view.event_manage_enrollment_invites.page_manage_enrollment_invites',
  ];

  /**
   * Event routes with {node} that carry no attendee-list requirement, and why.
   */
  private const NODE_ROUTES_WITHOUT_THE_RULE = [
    // A person asking to join the event; no one else's enrollment is shown.
    // The platform's group membership check is on it.
    'social_event.request_enroll_dialog',
    // The event's recording: the event's own access decides.
    'social_event.view_recording',
  ];

  private array $people = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('event_enrollment');
    $this->installEntitySchema('profile');
    $this->installShippedConfig('social_event', [
      'node.type.event', 'field.field.node.event.body',
      'field.field.node.event.field_content_visibility',
    ]);
    foreach (['field_event_enroll', 'field_hide_enrollments', 'field_event_send_confirmation', 'field_enroll_method'] as $name) {
      $this->installShippedConfig('social_event', ["field.storage.node.$name", "field.field.node.event.$name"]);
    }
    $this->installShippedConfig('social_event_managers', ['field.storage.node.field_event_managers', 'field.field.node.event.field_event_managers']);
    foreach (['field_event', 'field_account', 'field_enrollment_status', 'field_request_or_invite_status', 'field_request_message'] as $name) {
      $this->installShippedConfig('social_event', ["field.storage.event_enrollment.$name", "field.field.event_enrollment.event_enrollment.$name"]);
    }
    $this->config('core.extension')->set('profile', NULL)->save();
    $this->enableModules(['social_event_invite']);
    $path = $this->container->get('extension.list.module')->getPath('social_event_invite');
    $source = new \Drupal\Core\Config\FileStorage($path . '/config/optional');
    foreach (['field_storage_config' => 'field.storage.event_enrollment.field_email', 'field_config' => 'field.field.event_enrollment.event_enrollment.field_email'] as $type => $name) {
      $this->container->get('entity_type.manager')->getStorage($type)->createFromStorageRecord($source->read($name))->save();
    }
    $this->installShippedConfig('social_group_flexible_group', ['taxonomy.vocabulary.group_type', 'group.content_type.flexible_group-group_node-event']);
    $this->installShippedConfig('social_group', ['flag.flag.mute_group_notifications']);
    $this->container->get('views.views_data')->clear();
    $this->installShippedConfig('social_event', ['views.view.event_enrollments', 'views.view.event_manage_enrollment_requests']);
    $this->installShippedConfig('social_event_invite', ['views.view.event_manage_enrollment_invites']);
    $this->installShippedConfig('social_event_managers', ['views.view.event_manage_enrollments']);
    $viewing = [
      'access user profiles', 'view public flexible_group group', 'view community flexible_group group', 'view members flexible_group group',
      'view node.event.field_content_visibility:public content', 'view node.event.field_content_visibility:community content',
      'create event content', 'edit own event content', 'delete own event content',
    ];
    user_role_grant_permissions('verified', $viewing);
    // The site manager as Open Social's installers leave it, before our prune.
    user_role_grant_permissions('sitemanager', array_merge($viewing, ['manage everything enrollments']));
    require_once DRUPAL_ROOT . '/core/includes/module.inc';
    $this->container->get('module_handler')->loadInclude('social_group_flexible_group', 'install');
    social_group_flexible_group_install();
    // The real order of a fresh install: our module's install runs, then the
    // platform's install and its prune of the site roles.
    $this->container->get('module_handler')->loadInclude('localnodes_events', 'install');
    if (function_exists('localnodes_events_install')) {
      localnodes_events_install();
    }
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_protect_private_groups();
    $this->container->get('router.builder')->rebuild();
  }

  public function testTheSiteManagerKeepsTheScopedPermissionAfterThePrune(): void {
    $role = Role::load('sitemanager');
    $this->assertTrue($role->hasPermission('manage enrollments outside members-only groups'), 'Granted at install and kept by the prune.');
    $this->assertFalse($role->hasPermission('manage everything enrollments'), 'Open Social\'s site-wide grant stays pruned.');
  }

  public function testTheManagementListFollowsTheRuleOnAnOpenGroupEvent(): void {
    [$group, $event] = $this->openEvent();
    $expected = [
      'member' => FALSE, 'attendee' => FALSE, 'other_maker' => FALSE, 'group_org' => FALSE,
      'maker' => TRUE, 'cohost' => TRUE, 'sm' => TRUE, 'owner' => TRUE,
    ];
    foreach ($expected as $who => $allowed) {
      foreach (self::MANAGE_ROUTES as $route) {
        if ($allowed && str_starts_with($route, 'social_event_managers.vbo.')) {
          // The bulk routes also need a selection; only refusals are pinned here.
          continue;
        }
        $this->assertSame($allowed, $this->allowed($route, $event, $this->people[$who]), "$who on $route (open group)");
      }
    }
  }

  public function testTheManagementListInAMembersOnlyGroup(): void {
    [$group, $event] = $this->membersOnlyEvent();
    $expected = ['mo_member' => FALSE, 'sm_in' => FALSE, 'sm' => FALSE, 'outsider' => FALSE, 'mo_maker' => TRUE, 'owner' => TRUE];
    foreach ($expected as $who => $allowed) {
      foreach (self::MANAGE_ROUTES as $route) {
        if ($allowed && str_starts_with($route, 'social_event_managers.vbo.')) {
          continue;
        }
        $this->assertSame($allowed, $this->allowed($route, $event, $this->people[$who]), "$who on $route (members-only group)");
      }
    }
  }

  public function testACoHostWhoLeftTheMembersOnlyGroupIsRefused(): void {
    [$group, $event] = $this->membersOnlyEvent();
    $this->assertTrue($this->allowed('view.event_manage_enrollments.page_manage_enrollments', $event, $this->people['mo_cohost']));
    $group->removeMember($this->people['mo_cohost']);
    $this->resetAccess();
    $this->assertFalse($event->access('view', $this->people['mo_cohost']), 'No longer may view the event.');
    foreach (self::MANAGE_ROUTES as $route) {
      $this->assertFalse($this->allowed($route, $event, $this->people['mo_cohost']), "departed co-host on $route");
    }
  }

  public function testTheNamesTabFollowsHideEnrollments(): void {
    [$group, $event] = $this->openEvent();
    $tab = 'view.event_enrollments.view_enrollments';
    foreach (['member', 'attendee', 'maker', 'cohost', 'sm'] as $who) {
      $this->assertTrue($this->allowed($tab, $event, $this->people[$who]), "$who reads the names while the box is off");
    }
    $event->set('field_hide_enrollments', 1)->save();
    $this->resetAccess();
    foreach (['member' => FALSE, 'attendee' => FALSE, 'group_org' => FALSE, 'maker' => TRUE, 'cohost' => TRUE, 'sm' => TRUE] as $who => $allowed) {
      $this->assertSame($allowed, $this->allowed($tab, $event, $this->people[$who]), "$who on the names tab with the box on");
    }
    $this->assertFalse($this->allowed('view.event_manage_enrollments.page_manage_enrollments', $event, $this->people['member']), 'The box on: the member gets neither list.');
  }

  public function testOneEnrollmentIsForTheListsPeopleAndTheEnrolleeOnly(): void {
    [$group, $event, $enrollment] = $this->openEvent();
    foreach (['entity.event_enrollment.canonical', 'entity.event_enrollment.edit_form', 'entity.event_enrollment.delete_form'] as $route) {
      foreach (['member', 'other_maker', 'group_org'] as $who) {
        $result = $this->container->get('access_manager')->checkNamedRoute($route, ['event_enrollment' => $enrollment->id()], $this->people[$who], TRUE);
        $this->assertFalse($result->isAllowed(), "$who on $route");
      }
    }
  }

  /** Every route that carries one enrollment is covered by a named rule. */
  public function testEveryEnrollmentRouteCarriesTheRule(): void {
    $covered = [
      'entity.event_enrollment.canonical' => 'enrollment',
      'entity.event_enrollment.edit_form' => 'enrollment',
      'entity.event_enrollment.delete_form' => 'enrollment',
      'social_event.update_enrollment_request' => 'manage',
      'social_event.enrollment_request_decline_form' => 'manage',
      'social_event_invite.cancel_enrollment_invite' => 'manage',
      // Answering an invitation: only the person it is for.
      'social_event_invite.update_enrollment_invite' => 'invitee',
    ];
    $seen = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      if (str_contains($route->getPath(), '{event_enrollment}')) {
        $seen[] = $name;
        $this->assertArrayHasKey($name, $covered, "$name carries one enrollment and must be listed here.");
        $this->assertSame($covered[$name], $route->getRequirement('_localnodes_attendee_list'), $name);
      }
    }
    sort($seen);
    $names = array_keys($covered);
    sort($names);
    $this->assertSame($names, $seen, 'The list matches the installed routes exactly.');
    foreach (self::MANAGE_ROUTES as $name) {
      $route = $this->container->get('router.route_provider')->getRouteByName($name);
      $this->assertSame('manage', $route->getRequirement('_localnodes_attendee_list'), $name);
      $this->assertSame('TRUE', $route->getRequirement('_localnodes_house_enrollment'), "$name keeps the house check.");
      $this->assertNull($route->getRequirement('_custom_access'), "$name: Open Social's organizer check is replaced.");
      $this->assertNull($route->getRequirement('_social_event_enrollments_access'), "$name: replaced.");
      $this->assertNull($route->getRequirement('_enrollee_permission'), "$name: replaced.");
    }
    $tab = $this->container->get('router.route_provider')->getRouteByName('view.event_enrollments.view_enrollments');
    $this->assertSame('names', $tab->getRequirement('_localnodes_attendee_list'));
    foreach (['social_event.update_enrollment_request', 'social_event.enrollment_request_decline_form', 'social_event_invite.cancel_enrollment_invite'] as $name) {
      $route = $this->container->get('router.route_provider')->getRouteByName($name);
      $this->assertNull($route->getRequirement('_custom_access'), "$name: Open Social's organizer check is replaced.");
    }
    $this->assertSame('TRUE', $this->container->get('router.route_provider')->getRouteByName('social_event.update_enrollment_request')->getRequirement('_csrf_token'), 'Approving keeps its CSRF check.');
    foreach (['views_bulk_operations.execute_batch', 'views_bulk_operations.update_selection', 'views_bulk_operations.execute_configurable', 'views_bulk_operations.confirm'] as $name) {
      $this->assertSame('bulk', $this->container->get('router.route_provider')->getRouteByName($name)->getRequirement('_localnodes_attendee_list'), $name);
    }
    // Every event route with {node} from Open Social's event modules is named here.
    $node_routes = [
      'social_event_invite.invite_email' => 'invite',
      'social_event_invite.invite_user' => 'invite',
    ];
    $exempt = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      if (str_starts_with($name, 'social_event') && str_contains($route->getPath(), '{node}')) {
        $requirement = $route->getRequirement('_localnodes_attendee_list');
        if (isset($node_routes[$name])) {
          $this->assertSame($node_routes[$name], $requirement, $name);
        }
        elseif ($requirement === NULL) {
          $exempt[] = $name;
        }
      }
    }
    sort($exempt);
    $this->assertSame(self::NODE_ROUTES_WITHOUT_THE_RULE, $exempt, 'Event routes without the rule: each one named and reasoned.');
  }

  /** An invitation is answered only by the person it is for. */
  public function testAnInvitationIsAnsweredOnlyByThePersonInvited(): void {
    [$group, $event, $enrollment] = $this->openEvent();
    $route = 'social_event_invite.update_enrollment_invite';
    foreach (['member' => FALSE, 'other_maker' => FALSE, 'attendee' => TRUE] as $who => $expected) {
      $account = $this->people[$who];
      $this->pushRequest('/user/' . $account->id() . '/event-invites/' . $enrollment->id() . '/1', ['user' => (string) $account->id()]);
      $this->resetAccess();
      $result = $this->container->get('access_manager')->checkNamedRoute($route, ['user' => $account->id(), 'event_enrollment' => $enrollment->id(), 'accept_decline' => 1], $account, TRUE);
      $this->assertSame($expected, $result->isAllowed(), "$who answering the invitation of attendee");
    }
  }

  /** The enrollments shown on the event page follow the same rule as the names tab. */
  public function testWhoMaySeeTheEnrollmentsOnTheEventPage(): void {
    [$group, $event] = $this->openEvent();
    $this->person('cm', ['verified', 'contentmanager']);
    $this->addMember($group, $this->people['cm']);
    $event->set('field_hide_enrollments', 1)->save();
    $wrong = [];
    foreach (['member' => FALSE, 'cm' => FALSE, 'maker' => TRUE, 'sm' => TRUE] as $who => $expected) {
      if ($this->blockShown($event, $this->people[$who]) !== $expected) {
        $wrong[] = "$who, open group, the box on";
      }
    }
    [$mo_group, $mo_event] = $this->membersOnlyEvent();
    $mo_event->set('field_hide_enrollments', 1)->save();
    foreach (['sm_in' => FALSE, 'mo_member' => FALSE, 'mo_maker' => TRUE] as $who => $expected) {
      if ($this->blockShown($mo_event, $this->people[$who]) !== $expected) {
        $wrong[] = "$who, members-only group, the box on";
      }
    }
    $this->assertSame([], $wrong, 'Not as the rule says: ' . implode('; ', $wrong));
  }

  /** The list's bulk tool, on any of its routes, follows the rule for the list's event. */
  public function testTheBulkToolFollowsTheRuleOnEveryRoute(): void {
    [$group, $event] = $this->openEvent();
    $params = ['view_id' => 'event_manage_enrollments', 'display_id' => 'page_manage_enrollments'];
    $wrong = [];
    foreach (['views_bulk_operations.confirm', 'views_bulk_operations.execute_configurable', 'views_bulk_operations.update_selection', 'views_bulk_operations.execute_batch'] as $route) {
      foreach (['member' => FALSE, 'maker' => TRUE, 'sm' => TRUE] as $who => $expected) {
        $this->setCurrentUser($this->people[$who]);
        $this->container->get('tempstore.private')->get('views_bulk_operations_event_manage_enrollments_page_manage_enrollments')
          ->set((string) $this->people[$who]->id(), ['arguments' => [$event->id()], 'list' => []]);
        $this->resetAccess();
        $result = $this->container->get('access_manager')->checkNamedRoute($route, $params, $this->people[$who], TRUE);
        if ($result->isAllowed() !== $expected) {
          $wrong[] = "$who on $route";
        }
      }
      $this->setCurrentUser($this->people['maker']);
      $this->container->get('tempstore.private')->get('views_bulk_operations_event_manage_enrollments_page_manage_enrollments')->delete((string) $this->people['maker']->id());
      if ($this->container->get('access_manager')->checkNamedRoute($route, $params, $this->people['maker'], TRUE)->isAllowed()) {
        $wrong[] = "no list stored, on $route";
      }
    }
    $this->assertSame([], $wrong, 'Not as the rule says: ' . implode('; ', $wrong));
  }

  /** The bulk actions require the rule, also for the maker who left the members-only group. */
  public function testTheBulkActionsRequireTheRule(): void {
    [$group, $event] = $this->membersOnlyEvent();
    $enrollment = $this->enroll($event, $this->person('mo_attendee'));
    $this->enableModules(['social_email_broadcast']);
    $actions = $this->container->get('plugin.manager.action');
    foreach (['social_event_managers_delete_event_enrollment_action', 'social_event_managers_send_email_action'] as $id) {
      $this->resetAccess();
      $this->setCurrentUser($this->people['mo_maker']);
      $this->assertTrue($actions->createInstance($id)->access($enrollment, $this->people['mo_maker']), "$id: the maker, in the group");
    }
    $group->removeMember($this->people['mo_maker']);
    foreach (['social_event_managers_delete_event_enrollment_action', 'social_event_managers_send_email_action'] as $id) {
      $this->resetAccess();
      $this->setCurrentUser($this->people['mo_maker']);
      $this->assertFalse($actions->createInstance($id)->access($enrollment, $this->people['mo_maker']), "$id: the maker who left the group");
      $this->assertFalse($this->allowed('social_event_invite.cancel_enrollment_invite', $event, $this->people['mo_maker'], ['event_enrollment' => $enrollment->id()]), 'the maker who left: cancelling an invitation');
    }
  }

  /** Inviting to an event needs the event and the group, as for the list. */
  public function testInvitingNeedsTheEventAndTheGroup(): void {
    [$group, $event] = $this->membersOnlyEvent();
    $this->onEventRoute('social_event_invite.invite_email', $event);
    $this->assertTrue($this->allowed('social_event_invite.invite_email', $event, $this->people['mo_cohost']), 'the co-host, in the group');
    $group->removeMember($this->people['mo_cohost']);
    $wrong = [];
    foreach (['social_event_invite.invite_email', 'social_event_invite.invite_user'] as $route) {
      $this->onEventRoute($route, $event);
      foreach (['mo_cohost' => 'the co-host who left', 'outsider' => 'outsider'] as $who => $label) {
        if ($this->allowed($route, $event, $this->people[$who])) {
          $wrong[] = "$label, on $route";
        }
      }
    }
    $this->assertSame([], $wrong, 'Not as the rule says: ' . implode('; ', $wrong));
  }

  /** A site manager outside members-only groups has the organizer's controls. */
  public function testThePermittedSiteManagerHasTheOrganizersControls(): void {
    [$group, $event, $enrollment] = $this->openEvent();
    foreach (['entity.event_enrollment.canonical', 'entity.event_enrollment.edit_form', 'entity.event_enrollment.delete_form', 'social_event.enrollment_request_decline_form', 'social_event_invite.cancel_enrollment_invite'] as $route) {
      $this->assertTrue($this->allowed($route, $event, $this->people['sm'], ['event_enrollment' => $enrollment->id()]), "sm on $route");
    }
  }

  private function blockShown(NodeInterface $event, AccountInterface $who): bool {
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.node.canonical');
    $this->pushRequest('/node/' . $event->id(), [
      'node' => $event,
      \Drupal\Core\Routing\RouteObjectInterface::ROUTE_NAME => 'entity.node.canonical',
      \Drupal\Core\Routing\RouteObjectInterface::ROUTE_OBJECT => $route,
      '_raw_variables' => new \Symfony\Component\HttpFoundation\ParameterBag(['node' => (string) $event->id()]),
    ]);
    $this->resetAccess();
    $this->container->get('entity_type.manager')->getAccessControlHandler('block')->resetCache();
    $this->setCurrentUser($who);
    $block = \Drupal\block\Entity\Block::create(['id' => 'enrollments_' . $event->id() . '_' . $who->id(), 'theme' => 'stark', 'plugin' => 'views_block:event_enrollments-event_enrollments', 'region' => 'content']);
    return $block->access('view', $who);
  }

  private function onEventRoute(string $name, NodeInterface $event): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $this->pushRequest(str_replace('{node}', (string) $event->id(), $route->getPath()), [
      'node' => $event,
      \Drupal\Core\Routing\RouteObjectInterface::ROUTE_NAME => $name,
      \Drupal\Core\Routing\RouteObjectInterface::ROUTE_OBJECT => $route,
      '_raw_variables' => new \Symfony\Component\HttpFoundation\ParameterBag(['node' => (string) $event->id()]),
    ]);
  }

  private function pushRequest(string $path, array $attributes): void {
    $request = \Symfony\Component\HttpFoundation\Request::create($path);
    $request->setSession($this->container->get('session'));
    foreach ($attributes as $key => $value) {
      $request->attributes->set($key, $value);
    }
    $this->container->get('request_stack')->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
  }

  private function allowed(string $route, NodeInterface $event, AccountInterface $who, array $more = []): bool {
    $this->resetAccess();
    // Open Social's organizer checks read the current user, as in a request.
    $this->setCurrentUser($who);
    return $this->container->get('access_manager')->checkNamedRoute($route, ['node' => $event->id()] + $more, $who, TRUE)->isAllowed();
  }

  private function resetAccess(): void {
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('event_enrollment')->resetCache();
    drupal_static_reset('social_event_manager_or_organizer');
    drupal_static_reset('social_event_get_current_event');
  }

  private function person(string $name, array $roles = ['verified']): AccountInterface {
    return $this->people[$name] ??= $this->createUser([], $name, FALSE, ['roles' => $roles]);
  }

  /** "Hillside Builders", an open group, and an event in it with three enrollees. */
  private function openEvent(): array {
    $group = $this->createFlexibleGroup('Hillside Builders', 'public');
    $this->people['owner'] = $this->siteOwner;
    foreach (['maker', 'cohost', 'member', 'attendee', 'other_maker'] as $name) {
      $this->addMember($group, $this->person($name));
    }
    $group->addMember($this->person('group_org'), ['group_roles' => ['flexible_group-group_manager']]);
    $this->person('sm', ['verified', 'sitemanager']);
    $event = $this->event('Builders’ evening', $this->people['maker'], 'public', [$this->people['cohost']], $group);
    $this->event('Another evening', $this->people['other_maker'], 'public', [], $group);
    $enrollment = $this->enroll($event, $this->people['attendee']);
    $this->enroll($event, $this->person('river'));
    return [$group, $event, $enrollment];
  }

  /** "Evening Hosts", a members-only group. */
  private function membersOnlyEvent(): array {
    $group = $this->createFlexibleGroup('Evening Hosts', 'members');
    $this->people['owner'] = $this->siteOwner;
    foreach (['mo_maker', 'mo_cohost', 'mo_member'] as $name) {
      $this->addMember($group, $this->person($name));
    }
    $this->addMember($group, $this->person('sm_in', ['verified', 'sitemanager']));
    $this->person('sm', ['verified', 'sitemanager']);
    $this->person('outsider');
    $event = $this->event('Hosts’ planning chat', $this->people['mo_maker'], 'group', [$this->people['mo_cohost']], $group);
    $this->enroll($event, $this->people['mo_member']);
    return [$group, $event];
  }

  private function event(string $title, AccountInterface $maker, string $visibility, array $cohosts, GroupInterface $group): NodeInterface {
    $event = Node::create([
      'type' => 'event', 'uid' => $maker->id(), 'status' => 1, 'title' => $title,
      'field_content_visibility' => $visibility, 'field_event_enroll' => 1, 'field_hide_enrollments' => 0,
      'field_event_send_confirmation' => 0, 'field_enroll_method' => 1,
      'field_event_managers' => array_map(static fn ($a) => ['target_id' => $a->id()], $cohosts),
    ]);
    $event->save();
    $group->addRelationship($event, 'group_node:event');
    return $event;
  }

  private function enroll(NodeInterface $event, AccountInterface $who): EventEnrollment {
    $enrollment = EventEnrollment::create([
      'user_id' => $who->id(), 'status' => 1, 'field_event' => $event->id(),
      'field_account' => $who->id(), 'field_enrollment_status' => 1,
    ]);
    $enrollment->save();
    return $enrollment;
  }

}
