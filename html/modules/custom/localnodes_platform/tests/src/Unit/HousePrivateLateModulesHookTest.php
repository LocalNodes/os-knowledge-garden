<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\RoleInterface;

/** @group localnodes_platform */
final class HousePrivateLateModulesHookTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    $module = DRUPAL_ROOT . '/modules/custom/localnodes_platform';
    require_once $module . '/localnodes_platform.install';
    require_once $module . '/localnodes_platform.module';
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public function testActualOptionalModulesInstalledHookReappliesOnlyPrivacy(): void {
    $permissions = ['manage all groups', 'access content'];
    $role = $this->createMock(RoleInterface::class);
    $role->method('isAdmin')->willReturn(FALSE);
    $role->method('getPermissions')->willReturnCallback(static function () use (&$permissions) { return $permissions; });
    $role->method('revokePermission')->willReturnCallback(static function ($name) use (&$permissions, $role) {
      $permissions = array_values(array_diff($permissions, [$name]));
      return $role;
    });
    $roles = $this->createMock(EntityStorageInterface::class);
    $roles->method('load')->willReturnCallback(static fn ($id) => $id === 'sitemanager' ? $role : NULL);
    $admin = TRUE;
    $synced = $this->createMock(GroupRoleInterface::class);
    $synced->method('getGlobalRoleId')->willReturn('sitemanager');
    $synced->method('isAdmin')->willReturnCallback(static function () use (&$admin) { return $admin; });
    $synced->method('getPermissions')->willReturn([]);
    $synced->method('set')->willReturnCallback(static function ($name, $value) use (&$admin, $synced) {
      if ($name === 'admin') $admin = $value;
      return $synced;
    });
    $member = $this->createMock(GroupRoleInterface::class);
    $member->method('getGlobalRoleId')->willReturn('authenticated');
    $member->expects($this->never())->method('set');
    $member->expects($this->never())->method('save');
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('loadMultiple')->willReturn([$synced, $member]);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->with('group_role')->willReturn(TRUE);
    $entities->method('getStorage')->willReturnCallback(static function ($type) use ($roles, $groups) {
      if ($type === 'user_role') return $roles;
      if ($type === 'group_role') return $groups;
      throw new \LogicException('Optional installation must not load or block the host account.');
    });
    $definitions = $this->createMock(PermissionHandlerInterface::class);
    $definitions->method('getPermissions')->willReturn([]);
    // The real mail-template configure call sees no installed notice templates.
    $config = $this->createMock(Config::class);
    $config->method('isNew')->willReturn(TRUE);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->willReturn($config);
    $config_storage = $this->createMock(StorageInterface::class);
    $config_storage->method('getAllCollectionNames')->willReturn([]);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entities);
    $container->set('user.permissions', $definitions);
    $container->set('config.factory', $factory);
    $container->set('config.storage', $config_storage);
    $container->set('language_manager', $this->createMock(LanguageManagerInterface::class));
    $container->set('module_handler', $this->createMock(ModuleHandlerInterface::class));
    \Drupal::setContainer($container);

    localnodes_platform_modules_installed(['localnodes_role_grant_test'], FALSE);

    $this->assertSame(['access content'], $permissions, 'The actual optional-module hook removes a freshly restored global bypass.');
    $this->assertFalse($admin, 'The actual hook also clears a synchronized administrator grant.');
  }

}
