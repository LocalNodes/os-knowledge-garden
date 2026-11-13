<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\RoleInterface;

/** @group localnodes_platform */
final class HouseRolePrivacyTest extends UnitTestCase {
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/localnodes_platform.install';
  }
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /** @dataProvider permissions */
  public function testPermissionPolicy(string $permission, array $definition, bool $remove): void {
    $this->assertSame($remove, _localnodes_platform_house_permission_is_broad($permission, $definition));
  }
  public static function permissions(): array {
    $unsafe = [
      'bypass node access', 'manage all groups', 'administer nodes', 'administer comments',
      'administer post entities', 'edit any post entities', 'delete any post entities',
      'edit any topic content', 'delete any event content', 'view all revisions',
      'view topic revisions', 'revert event revisions', 'delete all revisions',
      'view any unpublished content', 'view unpublished post entities',
      'view node.topic.field_content_visibility:group content',
      'view node.document.field_content_visibility:group content',
      'access content overview', 'access group overview', 'edit group types',
      'set social group settings', 'access cross-group posting', 'administer activity entities',
      'view all published activity entities', 'view unpublished activity entities',
      'view inappropriate reports', 'close inappropriate reports', 'administer polls',
      'administer users', 'administer permissions', 'administer views', 'access site reports',
      'assign roles', 'assign all roles', 'assign administrator role', 'assign sitemanager role', 'assign verified role',
      'use text format full_html', 'manage everything enrollments', 'manage all enrollments',
      'view published event enrollment entities', 'edit event enrollment entities', 'delete event enrollment entities',
      'future unclassified privilege',
    ];
    $cases = [];
    foreach ($unsafe as $permission) $cases[$permission] = [$permission, [], TRUE];
    $cases['restricted future privilege'] = ['future dangerous privilege', ['restrict access' => TRUE], TRUE];
    foreach (['access content', 'view public posts', 'view community posts', 'edit own topic content', 'create flexible_group group', 'view members flexible_group group', 'view community flexible_group group', 'use text format imported_html', 'view network fee account', 'export localnodes site'] as $permission) {
      $cases[$permission] = [$permission, [], FALSE];
    }
    $cases['export remains explicit'] = ['export localnodes site', ['restrict access' => TRUE], FALSE];
    $cases['direct messages belong to their own policy'] = ['administer private_message entities', ['restrict access' => TRUE], FALSE];
    foreach (['view users', 'block users', 'view blocked user'] as $permission) {
      $cases['scoped member management: ' . $permission] = [$permission, ['restrict access' => TRUE], FALSE];
    }
    return $cases;
  }

  public function testContentManagerCannotInheritSiteOwnerCapabilities(): void {
    foreach (['view users', 'block users', 'view blocked user', 'export localnodes site', 'view network fee account'] as $permission) {
      $this->assertTrue(_localnodes_platform_house_permission_is_broad($permission, [], 'contentmanager'));
    }
    $this->assertFalse(_localnodes_platform_house_permission_is_broad('use private messaging system', [], 'contentmanager'));
  }

  public function testFreshRoleNarrowingPreservesScopedManagementAndIsIdempotent(): void {
    $state = [
      'sitemanager' => ['bypass node access', 'manage all groups', 'administer users', 'assign sitemanager role', 'view users', 'block users', 'view blocked user', 'access content', 'export localnodes site'],
      'contentmanager' => ['bypass node access', 'view inappropriate reports', 'view community posts'],
    ];
    $saves = [];
    $siteRoles = [];
    foreach ($state as $id => $permissions) {
      $role = $this->createMock(RoleInterface::class);
      $role->method('getPermissions')->willReturnCallback(function () use (&$state, $id) { return $state[$id]; });
      $role->method('isAdmin')->willReturn(FALSE);
      $role->method('revokePermission')->willReturnCallback(function ($permission) use (&$state, $id, $role) { $state[$id] = array_values(array_diff($state[$id], [$permission])); return $role; });
      $role->method('save')->willReturnCallback(function () use (&$saves, $id) { $saves[] = $id; return 1; });
      $siteRoles[$id] = $role;
    }
    $roles = $this->createMock(EntityStorageInterface::class);
    $roles->method('load')->willReturnCallback(function ($id) use ($siteRoles) { return $siteRoles[$id] ?? NULL; });
    $groupState = ['outside' => ['admin' => TRUE, 'permissions' => []], 'inside' => ['admin' => TRUE, 'permissions' => ['administer group']]];
    $groupRoles = [];
    foreach ($groupState as $id => $values) {
      $groupRole = $this->createMock(GroupRoleInterface::class);
      $groupRole->method('getGlobalRoleId')->willReturn($id === 'outside' ? 'sitemanager' : 'contentmanager');
      $groupRole->method('isAdmin')->willReturnCallback(function () use (&$groupState, $id) { return $groupState[$id]['admin']; });
      $groupRole->method('getPermissions')->willReturnCallback(function () use (&$groupState, $id) { return $groupState[$id]['permissions']; });
      $groupRole->method('set')->willReturnCallback(function ($key, $value) use (&$groupState, $id, $groupRole) { $groupState[$id][$key] = $value; return $groupRole; });
      $groupRole->method('save')->willReturnCallback(function () use (&$saves, $id) { $saves[] = $id; return 1; });
      $groupRoles[] = $groupRole;
    }
    $houseManager = $this->createMock(GroupRoleInterface::class);
    $houseManager->method('getGlobalRoleId')->willReturn(FALSE);
    $houseManager->expects($this->never())->method('set');
    $houseManager->expects($this->never())->method('save');
    $groupRoles[] = $houseManager;
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('loadMultiple')->willReturn($groupRoles);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([['user_role', $roles], ['group_role', $groups]]);
    $entities->method('hasDefinition')->with('group_role')->willReturn(TRUE);
    $permissions = $this->createMock(PermissionHandlerInterface::class);
    $permissions->method('getPermissions')->willReturn(array_fill_keys(['view users', 'block users', 'view blocked user'], ['restrict access' => TRUE]));
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entities);
    $container->set('user.permissions', $permissions);
    \Drupal::setContainer($container);
    _localnodes_platform_protect_private_groups();
    $this->assertSame(['view users', 'block users', 'view blocked user', 'access content', 'export localnodes site'], $state['sitemanager']);
    $this->assertSame(['view community posts'], $state['contentmanager']);
    $this->assertSame(['outside' => ['admin' => FALSE, 'permissions' => []], 'inside' => ['admin' => FALSE, 'permissions' => []]], $groupState);
    $this->assertCount(4, $saves);
    _localnodes_platform_protect_private_groups();
    $this->assertCount(4, $saves, 'A second install-policy application writes nothing.');
  }
}
