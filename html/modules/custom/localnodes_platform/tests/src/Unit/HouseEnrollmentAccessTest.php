<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\Storage\GroupRelationshipStorageInterface;
use Drupal\user\RoleInterface;
use Drupal\localnodes_platform\Access\HouseEnrollmentAccess;
use Drupal\node\Entity\Node;
use Drupal\social_event\Entity\EventEnrollment;
use Drupal\social_event_managers\Access\AddEnrolleeAccessCheck;
use Drupal\social_event_managers\Controller\EventManagersController;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\Routing\Route;

/** @group localnodes_platform */
final class HouseEnrollmentAccessTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/localnodes_platform.module';
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public function testTaggedCheckerImplementsDrupalsRequiredAccessInterface(): void {
    $checker = new HouseEnrollmentAccess($this->createMock(EntityTypeManagerInterface::class));
    $this->assertInstanceOf(\Drupal\Core\Routing\Access\AccessInterface::class, $checker);
  }

  private function fixture(bool $denied, bool $organizer = TRUE): array {
    $actor = $this->createMock(UserInterface::class);
    $actor->method('id')->willReturn($organizer ? '7' : '8');
    $actor->method('isAuthenticated')->willReturn(TRUE);
    $actor->method('getRoles')->willReturn(['verified']);
    $actor->method('hasRole')->willReturn(TRUE);
    $event = $this->createMock(Node::class);
    $event->method('getEntityTypeId')->willReturn('node');
    $event->method('id')->willReturn('22');
    $event->method('bundle')->willReturn('event');
    $event->method('getCacheContexts')->willReturn([]);
    $event->method('getCacheTags')->willReturn(['node:22']);
    $event->method('getCacheMaxAge')->willReturn(-1);
    $event->method('getType')->willReturn('event');
    $event->method('isNew')->willReturn(FALSE);
    $event->method('getOwnerId')->willReturn('7');
    $event->method('access')->willReturn(TRUE);
    $managers = $this->createMock(FieldItemListInterface::class);
    $managers->method('isEmpty')->willReturn(FALSE);
    $managers->method('getValue')->willReturn([['target_id' => '7']]);
    $event->method('__get')->willReturn($managers);
    $visibility = $this->createMock(FieldItemList::class);
    $visibility->method('__get')->with('value')->willReturn('group');
    $event->method('get')->willReturnMap([['field_event_managers', $managers], ['field_content_visibility', $visibility]]);
    $event->method('hasField')->willReturnCallback(static fn ($name): bool => in_array($name, ['field_event_managers', 'field_content_visibility'], TRUE));
    $enrollment = $this->createMock(EventEnrollment::class);
    $enrollment->method('getEntityTypeId')->willReturn('event_enrollment');
    $event_reference = $this->createMock(FieldItemList::class);
    $event_reference->method('__get')->with('entity')->willReturn($event);
    $enrollment->method('hasField')->with('field_event')->willReturn(TRUE);
    $enrollment->method('get')->with('field_event')->willReturn($event_reference);
    $enrollment->method('getCacheContexts')->willReturn([]);
    $enrollment->method('getCacheTags')->willReturn(['event_enrollment:1']);
    $enrollment->method('getCacheMaxAge')->willReturn(-1);
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn('flexible_group');
    $group->method('hasField')->willReturn(FALSE);
    $group->method('getMember')->willReturn($denied ? FALSE : new \stdClass());
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn(['group:12']);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('getGroup')->willReturn($group);
    $relationships = $this->createMock(GroupRelationshipStorageInterface::class);
    $relationships->method('loadByEntity')->willReturn([$relationship]);
    $role = $this->createMock(RoleInterface::class);
    $role->method('isAdmin')->willReturn(FALSE);
    $roles = $this->createMock(EntityStorageInterface::class);
    $roles->method('loadMultiple')->willReturn([$role]);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->with('group_content')->willReturn(TRUE);
    $entities->method('getStorage')->willReturnMap([['user_role', $roles], ['group_content', $relationships]]);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entities);
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['user', 'user.roles', 'user.permissions']));
    \Drupal::setContainer($container);
    return [new HouseEnrollmentAccess($entities), $event, $enrollment, $actor, $entities];
  }

  public function testPrivateEventOrganizerCannotBypassHouseMembership(): void {
    [$gate, $event, , $actor, $entities] = $this->fixture(TRUE);
    // Actual vendored controller allows the explicit organizer independently of
    // event view/membership. The extra gate must override that allow.
    $route = new Route('/node/{node}/all-enrollments');
    $match = new RouteMatch('view.event_manage_enrollments.page_manage_enrollments', $route, ['node' => $event]);
    $original = (new EventManagersController($entities))->access($actor, $match);
    $this->assertTrue($original->isAllowed());
    $guard = $gate->routeAccess($route, $match, $actor);
    $this->assertTrue($guard->isForbidden());
    $this->assertFalse($original->andIf($guard)->isAllowed());
    $this->assertContains('user', $guard->getCacheContexts());
    $this->assertContains('group:12', $guard->getCacheTags());
  }

  public function testPrivateEventOrganizerCannotAddEnrolleesWithoutHouseMembership(): void {
    [$gate, $event, , $actor] = $this->fixture(TRUE);
    $route = new Route('/node/{node}/all-enrollments/add-enrollees', [], ['_enrollee_permission' => 'manage everything enrollments']);
    $match = new RouteMatch('social_event_managers.add_enrollees', $route, ['node' => $event]);
    $original = (new AddEnrolleeAccessCheck())->access($route, $match, $actor);
    $this->assertTrue($original->isAllowed());
    $this->assertFalse($original->andIf($gate->routeAccess($route, $match, $actor))->isAllowed());
  }

  public function testMembershipGateDoesNotGiveAnOrdinaryMemberOrganizerPowers(): void {
    [$gate, $event, , $actor] = $this->fixture(FALSE, FALSE);
    $route = new Route('/node/{node}/all-enrollments/add-enrollees', [], ['_enrollee_permission' => 'manage everything enrollments']);
    $match = new RouteMatch('social_event_managers.add_enrollees', $route, ['node' => $event]);
    $original = (new AddEnrolleeAccessCheck())->access($route, $match, $actor);
    $this->assertFalse($original->isAllowed());
    $this->assertTrue($gate->routeAccess($route, $match, $actor)->isAllowed());
    $this->assertFalse($original->andIf($gate->routeAccess($route, $match, $actor))->isAllowed());
  }

  public function testDirectEnrollmentEntityDoesNotBypassPrivateHouseMembership(): void {
    [$gate, , $enrollment, $actor] = $this->fixture(TRUE);
    $this->assertTrue($gate->entityAccess($enrollment, $actor)->isForbidden());
  }

  public function testEnrollmentCannotBorrowAuthorityFromAnotherEventInTheUrl(): void {
    [$gate, , $enrollment, $actor] = $this->fixture(FALSE);
    $other_event = $this->createMock(Node::class);
    $other_event->method('id')->willReturn('33');
    $route = new Route('/node/{node}/enrollment/{event_enrollment}');
    $match = new RouteMatch('social_event.update_enrollment_request', $route, ['node' => $other_event, 'event_enrollment' => $enrollment]);
    $this->assertTrue($gate->routeAccess($route, $match, $actor)->isForbidden());
  }

  public function testMatchingEnrollmentAndEventParametersKeepTheRouteOpen(): void {
    [$gate, $event, $enrollment, $actor] = $this->fixture(FALSE);
    $route = new Route('/node/{node}/enrollment/{event_enrollment}');
    $match = new RouteMatch('social_event.update_enrollment_request', $route, ['node' => $event, 'event_enrollment' => $enrollment]);
    $this->assertTrue($gate->routeAccess($route, $match, $actor)->isAllowed());
  }

  public function testCurrentMemberOrganizerKeepsTheExistingRouteAuthority(): void {
    [$gate, $event, $enrollment, $actor, $entities] = $this->fixture(FALSE);
    $route = new Route('/node/{node}/all-enrollments');
    $match = new RouteMatch('view.event_manage_enrollments.page_manage_enrollments', $route, ['node' => $event]);
    $original = (new EventManagersController($entities))->access($actor, $match);
    $this->assertTrue($original->andIf($gate->routeAccess($route, $match, $actor))->isAllowed());
    $this->assertTrue($gate->entityAccess($enrollment, $actor)->isNeutral(), 'A hook veto never invents an entity grant.');
  }

}
