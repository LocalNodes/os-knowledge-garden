<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Entity\EntityAccessCheck;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\Entity\UserRouteProvider;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Member management cannot become another member's sign-in method.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class HousePrivateAccountAccessTest extends GroupAccessKernelTestBase {

  // Load the real People/status permission definitions and shipped action alters.
  protected static $modules = ['localnodes_platform', 'role_delegation', 'social_user'];

  private UserInterface $manager;
  private UserInterface $member;

  protected function setUp(): void {
    parent::setUp();
    // Relevant actual grants from social_core_install/social_user_install.
    user_role_grant_permissions('authenticated', ['change own username']);
    user_role_grant_permissions('sitemanager', [
      'administer users', 'access user profiles', 'view users', 'block users',
      'view blocked user', 'assign verified role', 'assign contentmanager role',
      'assign sitemanager role', 'assign all roles',
    ]);
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    if (function_exists('_localnodes_platform_protect_private_groups')) {
      _localnodes_platform_protect_private_groups();
    }
    $this->manager = $this->createUser([], 'account_manager', FALSE, ['roles' => ['verified', 'sitemanager']]);
    $this->member = $this->createUser([], 'ordinary_member', FALSE, [
      'roles' => ['verified'], 'mail' => 'member@example.org', 'pass' => 'original-member-password',
    ]);
    $this->container->get('current_user')->setAccount($this->manager);
  }

  public function testManagerCannotOpenAnotherMembersCredentialForm(): void {
    $routes = (new UserRouteProvider())->getRoutes($this->container->get('entity_type.manager')->getDefinition('user'));
    $route = $routes->get('entity.user.edit_form');
    $match = new RouteMatch('entity.user.edit_form', $route, ['user' => $this->member]);
    $this->assertFalse((new EntityAccessCheck())->access($route, $match, $this->manager)->isAllowed(), 'The real edit route cannot expose another member password form.');
    foreach (['mail', 'pass', 'roles'] as $field) {
      $authorized = $this->member->access('update', $this->manager, TRUE)
        ->andIf($this->member->get($field)->access('edit', $this->manager, TRUE));
      $this->assertFalse($authorized->isAllowed(), 'Entity and field authorization deny changes to ' . $field . '.');
    }
  }

  public function testOwnAndHostCredentialEditingRemainAvailable(): void {
    foreach ([$this->member, $this->siteOwner] as $actor) {
      foreach (['mail', 'pass'] as $field) {
        $authorized = $this->member->access('update', $actor, TRUE)
          ->andIf($this->member->get($field)->access('edit', $actor, TRUE));
        $this->assertTrue($authorized->isAllowed());
      }
    }
    $this->assertTrue($this->manager->access('update', $this->manager));
  }

  public function testManagerCannotGrantAnAdministrativeRoleToSelfOrAnotherMember(): void {
    $action = $this->container->get('plugin.manager.action')->createInstance('user_add_role_action', ['rid' => 'administrator']);
    $route = $this->container->get('router.route_provider')->getRouteByName('role_delegation.edit_form');
    $this->assertSame('TRUE', $route->getRequirement('_role_delegation_access_check'));
    $this->assertSame([], $this->container->get('delegatable_roles')->getAssignableRoles($this->manager));
    foreach ([$this->member, $this->manager] as $target) {
      $this->assertFalse($this->container->get('access_manager')->checkNamedRoute(
        'role_delegation.edit_form', ['user' => $target->id()], $this->manager, TRUE,
      )->isAllowed(), 'Independent role delegation cannot bypass account-edit denial.');
      $this->assertFalse($target->get('role_change')->access('edit', $this->manager));
      $this->assertFalse($target->roles->access('edit', $this->manager));
      $this->assertFalse($action->access($target, $this->manager));
    }
    foreach (['administer users', 'administer permissions', 'assign roles', 'assign all roles', 'assign sitemanager role', 'assign contentmanager role'] as $permission) {
      $this->assertFalse($this->manager->hasPermission($permission), $permission);
    }
    $this->assertTrue($this->manager->hasPermission('view users'));
    $this->assertTrue($this->manager->hasPermission('block users'));
  }

  public function testManagerCanBlockAndUnblockWithoutChangingCredentials(): void {
    $before = $this->credentials($this->member);
    foreach (['user_block_user_action' => FALSE, 'user_unblock_user_action' => TRUE] as $id => $active) {
      $action = $this->container->get('plugin.manager.action')->createInstance($id);
      $this->assertTrue((bool) $action->access($this->member, $this->manager));
      $this->assertTrue($action->access($this->member, $this->manager, TRUE)->isAllowed());
      $action->execute($this->member);
      $stored = User::load($this->member->id());
      $this->assertSame($active, $stored->isActive());
      $this->assertSame($before, $this->credentials($stored));
    }
  }

  public function testManagerCannotBlockAnotherOrganizer(): void {
    $other = $this->createUser([], 'second_account_manager', FALSE, ['roles' => ['verified', 'sitemanager']]);
    foreach (['user_block_user_action', 'user_unblock_user_action'] as $id) {
      $action = $this->container->get('plugin.manager.action')->createInstance($id);
      $this->assertFalse((bool) $action->access($other, $this->manager));
      $this->assertFalse($action->access($other, $this->manager, TRUE)->isAllowed());
    }
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('plugin.manager.action')->createInstance('user_block_user_action')->execute($other);
  }

  public function testRecordedOwnerRemainsProtectedAfterLosingTheSiteManagerRole(): void {
    $this->container->get('state')->set('localnodes.organizer_uid', (int) $this->member->id());
    foreach (['user_block_user_action', 'user_unblock_user_action'] as $id) {
      $action = $this->container->get('plugin.manager.action')->createInstance($id);
      $this->assertFalse((bool) $action->access($this->member, $this->manager));
      $this->assertFalse($action->access($this->member, $this->manager, TRUE)->isAllowed());
    }
    $this->expectException(AccessDeniedHttpException::class);
    $this->container->get('plugin.manager.action')->createInstance('user_block_user_action')->execute($this->member);
  }

  public function testActualAdministratorMayManageAnOrganizer(): void {
    Role::create(['id' => 'recovery_administrator', 'label' => 'Recovery administrator', 'is_admin' => TRUE])->save();
    $admin = $this->createUser([], 'recovery_admin', FALSE, ['roles' => ['recovery_administrator']]);
    $this->container->get('current_user')->setAccount($admin);
    $before = $this->credentials($this->manager);
    foreach (['user_block_user_action' => FALSE, 'user_unblock_user_action' => TRUE] as $id => $active) {
      $action = $this->container->get('plugin.manager.action')->createInstance($id);
      $this->assertTrue($action->access($this->manager, $admin, TRUE)->isAllowed());
      $action->execute($this->manager);
      $stored = User::load($this->manager->id());
      $this->assertSame($active, $stored->isActive());
      $this->assertSame($before, $this->credentials($stored));
    }
  }

  public function testStatusActionsProtectHostAdministratorsAndTheCurrentManager(): void {
    Role::create(['id' => 'custom_host_role', 'label' => 'Custom host role', 'is_admin' => TRUE])->save();
    $admin = $this->createUser([], 'custom_admin', FALSE, ['roles' => ['custom_host_role']]);
    foreach ([$this->siteOwner, $admin, $this->manager] as $target) {
      foreach (['user_block_user_action', 'user_unblock_user_action'] as $id) {
        $action = $this->container->get('plugin.manager.action')->createInstance($id);
        $this->assertFalse((bool) $action->access($target, $this->manager));
        $this->assertFalse($action->access($target, $this->manager, TRUE)->isAllowed());
      }
    }
  }

  public function testForgedExecutionCannotChangeProtectedAccounts(): void {
    foreach ([$this->siteOwner, $this->manager] as $target) {
      $before = $this->credentials($target);
      $action = $this->container->get('plugin.manager.action')->createInstance('user_block_user_action');
      $denied = FALSE;
      try {
        $action->execute($target);
      }
      catch (AccessDeniedHttpException $exception) {
        $denied = TRUE;
      }
      $this->assertTrue($denied, 'Execution must repeat authorization before any write.');
      $stored = User::load($target->id());
      $this->assertTrue($stored->isActive());
      $this->assertSame($before, $this->credentials($stored));
    }
  }

  public function testHostRetainsOrdinaryMemberManagement(): void {
    $this->container->get('current_user')->setAccount($this->siteOwner);
    foreach (['user_block_user_action' => FALSE, 'user_unblock_user_action' => TRUE] as $id => $active) {
      $action = $this->container->get('plugin.manager.action')->createInstance($id);
      $this->assertTrue($action->access($this->member, $this->siteOwner, TRUE)->isAllowed());
      $action->execute($this->member);
      $this->assertSame($active, User::load($this->member->id())->isActive());
    }
  }

  private function credentials(UserInterface $account): array {
    return [$account->getEmail(), $account->getPassword(), $account->getRoles()];
  }

}
