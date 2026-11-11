<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Tests\safe_smart_accounts\Kernel\SafeKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Site managers can see the network fee account (25-10, D-03).
 *
 * The install hook localnodes_web3_install() grants gas_sponsor's 'view
 * network fee account' to Open Social's sitemanager role. The grant lives in
 * the install hook, never in hook_update_N() or the entrypoint (CLAUDE.md).
 *
 * localnodes_web3 itself can't be enabled here: it depends on group_treasury
 * and social_group_treasury, which pull in Open Social's social_group (see
 * DefaultNetworksTest). So gas_sponsor is enabled for its permission, the
 * .install file is loaded with require_once, and its grant helper is called
 * directly.
 *
 * @group localnodes_web3
 */
final class NetworkFeePermissionTest extends SafeKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'siwe_login',
    'safe_smart_accounts',
    'gas_sponsor',
  ];

  /**
   * The permission.
   */
  private const PERMISSION = 'view network fee account';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/localnodes_web3.install';
  }

  /**
   * The site manager role gets the permission once, however often it runs.
   */
  public function testSiteManagerGetsThePermissionOnce(): void {
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager'])->save();

    _localnodes_web3_grant_network_fee_permission();
    _localnodes_web3_grant_network_fee_permission();

    $role = Role::load('sitemanager');
    $this->assertInstanceOf(RoleInterface::class, $role);
    $this->assertTrue($role->hasPermission(self::PERMISSION));
    $this->assertSame(1, array_count_values($role->getPermissions())[self::PERMISSION] ?? 0);
  }

  /**
   * Without a sitemanager role the helper does nothing and throws nothing.
   */
  public function testWithoutSiteManagerRoleNothingChanges(): void {
    _localnodes_web3_grant_network_fee_permission();
    $this->assertNull(Role::load('sitemanager'));
    foreach (Role::loadMultiple() as $role) {
      $this->assertFalse($role->hasPermission(self::PERMISSION), 'No other role gets the permission.');
    }
  }

  /**
   * The install hook calls the grant.
   */
  public function testInstallHookGrants(): void {
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager'])->save();
    // The SIWE default the hook also sets is idempotent and harmless here.
    localnodes_web3_install();
    $role = Role::load('sitemanager');
    $this->assertInstanceOf(RoleInterface::class, $role);
    $this->assertTrue($role->hasPermission(self::PERMISSION));
  }

}
