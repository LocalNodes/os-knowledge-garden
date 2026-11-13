<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\node\Entity\Node;
use Drupal\social_event\Entity\EventEnrollment;
use Drupal\social_event_managers\Controller\EventManagersController;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\views\Views;
use Drupal\user\Entity\Role;
use PhpParser\Node as PhpNode;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\HttpFoundation\Request;

/**
 * Real group-list and event-enrollment paths keep their house membership gate.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class HousePrivateEventAccessTest extends GroupAccessKernelTestBase {

  protected static $modules = ['localnodes_platform', 'social_user', 'role_delegation', 'social_event', 'social_event_managers', 'better_exposed_filters', 'profile'];

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
    // The invitation user-insert hook queries enrollment fields. Enable it
    // only after the base fixture's first user and the actual fields exist.
    $this->config('core.extension')->set('profile', NULL)->save();
    $this->enableModules(['social_event_invite']);
    $path = $this->container->get('extension.list.module')->getPath('social_event_invite');
    $source = new \Drupal\Core\Config\FileStorage($path . '/config/optional');
    foreach (['field_storage_config' => 'field.storage.event_enrollment.field_email', 'field_config' => 'field.field.event_enrollment.event_enrollment.field_email'] as $type => $name) {
      $this->container->get('entity_type.manager')->getStorage($type)->createFromStorageRecord($source->read($name))->save();
    }
    // The real flexible-group installer grants per-vocabulary term powers.
    // Install the vocabulary so core's permission handler recognizes them.
    $this->installShippedConfig('social_group_flexible_group', ['taxonomy.vocabulary.group_type', 'group.content_type.flexible_group-group_node-event']);
    $this->installShippedConfig('social_group', ['flag.flag.mute_group_notifications']);
    // The shipped Views calculate their field dependencies while being saved.
    // Supply the real relationship fields before loading those definitions.
    $this->container->get('views.views_data')->clear();
    $this->installShippedConfig('social_event', ['views.view.event_enrollments', 'views.view.event_manage_enrollment_requests']);
    $this->installShippedConfig('social_event_invite', ['views.view.event_manage_enrollment_invites']);
    $this->installShippedConfig('social_event_managers', ['views.view.event_manage_enrollments']);
    $this->installShippedConfig('social_group', ['views.view.groups']);
    user_role_grant_permissions('verified', [
      'access user profiles', 'view public flexible_group group', 'view community flexible_group group', 'view members flexible_group group',
      'view node.event.field_content_visibility:community content', 'create event content', 'edit own event content', 'delete own event content',
    ]);
    // Read every literal site-role grant directly from the real install hooks.
    // This is a grant fixture, not a claim to run the full profile installation.
    foreach ($this->shippedSiteRoleGrants() as $id => $permissions) {
      user_role_grant_permissions($id, $permissions);
    }
    require_once DRUPAL_ROOT . '/core/includes/module.inc';
    $this->container->get('module_handler')->loadInclude('social_group_flexible_group', 'install');
    social_group_flexible_group_install();
    $this->container->get('module_handler')->loadInclude('social_event_managers', 'install');
    social_event_managers_install();
    $this->assertTrue(Role::load('sitemanager')->hasPermission('create terms in group_type'), 'The complete real flexible-group installer ran.');
    $this->assertTrue(Role::load('sitemanager')->hasPermission('manage all groups'));
    $this->assertTrue(Role::load('sitemanager')->hasPermission('manage everything enrollments'));
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_protect_private_groups();
    $this->container->get('router.builder')->rebuild();
  }

  public static function staffRoles(): array {
    return [['sitemanager'], ['contentmanager']];
  }

  /** @dataProvider staffRoles */
  public function testFullGroupViewGrantsStillRequireSecretHouseMembership(string $role): void {
    $house = $this->createFlexibleGroup('Secret house', 'members');
    $public = $this->createFlexibleGroup('Public house', 'public');
    $community = $this->createFlexibleGroup('Site-visible house', 'community');
    $staff = $this->createUser([], 'outside_staff', FALSE, ['roles' => [$role]]);
    $member = $this->createPersona('actual_member');
    foreach ([$house, $public, $community] as $group) $this->addMember($group, $member);
    // Both permissions remain safe ordinary capabilities, not global bypasses.
    $this->assertTrue($staff->hasPermission('view members flexible_group group'));
    $this->assertFalse($house->access('view', $staff));
    $this->assertFalse($this->container->get('access_manager')->checkNamedRoute('entity.group.canonical', ['group' => $house->id()], $staff, TRUE)->isAllowed());
    $this->assertTrue($community->access('view', $staff));
    $this->assertTrue($house->access('view', $member));
    $this->assertTrue($this->container->get('access_manager')->checkNamedRoute('entity.group.canonical', ['group' => $house->id()], $member, TRUE)->isAllowed());
    $this->assertNotContains((int) $house->id(), $this->profileGroups($staff, $member));
    $this->assertContains((int) $public->id(), $this->profileGroups($staff, $member));
    $this->assertContains((int) $house->id(), $this->profileGroups($member, $member));
    // An SM-only provisioned organizer must still see a secret house after joining.
    $this->addMember($house, $staff);
    $this->container->get('entity_type.manager')->getAccessControlHandler('group')->resetCache();
    $this->assertTrue($house->access('view', $staff));
    $this->assertTrue($this->container->get('access_manager')->checkNamedRoute('entity.group.canonical', ['group' => $house->id()], $staff, TRUE)->isAllowed());
  }

  /** All literal SM/CM grant calls in actual feature install functions. */
  private function shippedSiteRoleGrants(): array {
    static $grants;
    if ($grants !== NULL) return $grants;
    $grants = ['sitemanager' => [], 'contentmanager' => []];
    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    $finder = new NodeFinder();
    foreach ([DRUPAL_ROOT . '/profiles/contrib/social/modules', DRUPAL_ROOT . '/modules/custom'] as $directory) {
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'install') continue;
        $module = $file->getBasename('.install');
        foreach ($finder->findInstanceOf($parser->parse(file_get_contents($file->getPathname())), PhpNode\Stmt\Function_::class) as $function) {
          if ($function->name->toString() !== $module . '_install') continue;
          foreach ($finder->findInstanceOf($function->stmts, PhpNode\Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof PhpNode\Name || $call->name->toString() !== 'user_role_grant_permissions') continue;
            $role = $call->args[0]->value ?? NULL;
            $permissions = $call->args[1]->value ?? NULL;
            if (!$role instanceof PhpNode\Scalar\String_ || !isset($grants[$role->value]) || !$permissions instanceof PhpNode\Expr\Array_) continue;
            foreach ($permissions->items as $item) {
              if ($item && $item->value instanceof PhpNode\Scalar\String_) $grants[$role->value][$item->value->value] = $item->value->value;
            }
          }
        }
      }
    }
    foreach ($grants as &$permissions) $permissions = array_values($permissions);
    return $grants;
  }

  private function profileGroups($actor, $profile): array {
    $this->container->get('current_user')->setAccount($actor);
    $view = Views::getView('groups');
    $view->setDisplay('block_user_groups');
    $view->setArguments([(string) $profile->id()]);
    $view->execute();
    return array_map(static fn ($row): int => (int) $row->_entity->id(), $view->result);
  }

  /** @dataProvider staffRoles */
  public function testUnrelatedSiteRoleCannotOpenPrivateEventEnrollmentPaths(string $role): void {
    [$house, $event, $enrollment, $author, $manager] = $this->event();
    $staff = $this->createUser([], 'event_outside_staff', FALSE, ['roles' => ['verified', $role]]);
    $this->assertFalse($staff->hasPermission('manage everything enrollments'));
    $this->assertFalse($staff->hasPermission('view published event enrollment entities'));
    $this->assertFalse($event->access('view', $staff));
    $this->context($staff, $event);
    $this->assertFalse($enrollment->access('view', $staff));
    foreach (['view.event_manage_enrollments.page_manage_enrollments', 'view.event_enrollments.view_enrollments', 'social_event_managers.add_enrollees'] as $route) {
      $this->assertFalse($this->container->get('access_manager')->checkNamedRoute($route, ['node' => $event->id()], $staff, TRUE)->isAllowed());
    }
    $this->context($manager, $event);
    $this->assertTrue($event->access('update', $manager));
    $this->assertTrue($enrollment->access('view', $manager));
    foreach (['view.event_manage_enrollments.page_manage_enrollments', 'view.event_enrollments.view_enrollments', 'social_event_managers.add_enrollees'] as $route) {
      $this->assertTrue($this->container->get('access_manager')->checkNamedRoute($route, ['node' => $event->id()], $manager, TRUE)->isAllowed(), 'A current member event organizer retains this route.');
    }
  }

  public function testAllThirteenEnrollmentRoutesExistAndKeepTheirMembershipGate(): void {
    $names = [
      'view.event_enrollments.view_enrollments',
      'view.event_manage_enrollment_invites.page_manage_enrollment_invites',
      'view.event_manage_enrollments.page_manage_enrollments',
      'view.event_manage_enrollment_requests.page_manage_enrollment_requests',
      'social_event_managers.add_enrollees',
      'social_event_managers.vbo.execute_configurable',
      'social_event_managers.vbo.confirm',
      'social_event.update_enrollment_request',
      'social_event.enrollment_request_decline_form',
      'social_event.request_enroll_dialog',
      'entity.event_enrollment.canonical',
      'entity.event_enrollment.edit_form',
      'entity.event_enrollment.delete_form',
    ];
    $routes = $this->container->get('router.route_provider')->getRoutesByNames($names);
    $this->assertCount(13, $routes, 'Every protected route exists in the actual installed route collection.');
    foreach ($names as $name) {
      $this->assertArrayHasKey($name, $routes, $name);
      $this->assertSame('TRUE', $routes[$name]->getRequirement('_localnodes_house_enrollment'), $name);
      $original = $routes[$name]->getRequirements();
      unset($original['_localnodes_house_enrollment']);
      $this->assertNotEmpty($original, $name . ' preserves its original access requirements.');
    }
  }

  private function event(): array {
    $house = $this->createFlexibleGroup('Private event house', 'members');
    $author = $this->createPersona('event_author');
    $manager = $this->createPersona('event_manager');
    $this->addMember($house, $author);
    $house->addMember($manager, ['group_roles' => ['flexible_group-group_manager']]);
    $event = Node::create([
      'type' => 'event', 'uid' => $author->id(), 'status' => 1, 'title' => 'Private event',
      'field_content_visibility' => 'group', 'field_event_enroll' => 1, 'field_hide_enrollments' => 0,
      'field_event_send_confirmation' => 0, 'field_enroll_method' => 1,
      'field_event_managers' => [['target_id' => $author->id()], ['target_id' => $manager->id()]],
    ]);
    $event->save();
    $house->addRelationship($event, 'group_node:event');
    $enrollment = EventEnrollment::create([
      'user_id' => $this->siteOwner->id(), 'status' => 1, 'field_event' => $event->id(),
      'field_account' => $manager->id(), 'field_enrollment_status' => 1,
    ]);
    $enrollment->save();
    return [$house, $event, $enrollment, $author, $manager];
  }

  private function context($actor, $event): void {
    $this->container->get('current_user')->setAccount($actor);
    $request = Request::create('/node/' . $event->id() . '/all-enrollments');
    $route = $this->container->get('router.route_provider')->getRouteByName('view.event_manage_enrollments.page_manage_enrollments');
    $request->attributes->set('_route', 'view.event_manage_enrollments.page_manage_enrollments');
    $request->attributes->set('_route_object', $route);
    $request->attributes->set('node', $event);
    $this->container->get('request_stack')->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
    drupal_static_reset('social_event_get_current_event');
    drupal_static_reset('social_event_manager_or_organizer');
    $this->container->get('entity_type.manager')->getAccessControlHandler('event_enrollment')->resetCache();
  }

}
