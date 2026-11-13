<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\group\Entity\GroupRole;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Optional modules cannot restore global access to private houses.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class HousePrivateLateModuleTest extends GroupAccessKernelTestBase {

  // The fixture is deliberately NOT enabled here; the real installer enables
  // it only after the platform policy and all real shipped roles are present.
  protected static $modules = ['localnodes_platform'];

  public function testOptionalFeatureInstallReappliesPrivacyWithoutBlockingRecovery(): void {
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_protect_private_groups();
    $this->assertTrue($this->container->get('module_handler')->moduleExists('localnodes_platform'));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('localnodes_role_grant_test'));
    // This fixture enables modules bundled in the Social profile without
    // installing the profile itself. Preserve installation-mode discovery
    // across the real installer's container rebuild: KernelTestBase writes
    // core.extension without a profile key, which DrupalKernel otherwise
    // interprets as an explicitly uninstalled profile and hides its modules.
    $this->config('core.extension')->set('profile', NULL)->save(TRUE);
    $extension_config = $this->container->get('config.storage')->read('core.extension');
    $this->assertArrayHasKey('profile', $extension_config);
    $this->assertNull($extension_config['profile']);
    // KernelTestBase marks a valid test UA, so ExtensionDiscovery includes
    // modules in tests/modules. Prove discovery before asking the installer.
    $extensions = $this->container->get('extension.list.module')->reset()->getList();
    $this->assertArrayHasKey('localnodes_role_grant_test', $extensions);
    $this->assertStringEndsWith('tests/modules/localnodes_role_grant_test', $extensions['localnodes_role_grant_test']->getPath());
    $member_before = GroupRole::load('flexible_group-member')->toArray();
    $manager_before = GroupRole::load('flexible_group-group_manager')->toArray();
    $verified_before = Role::load('verified')->getPermissions();

    // A host deliberately unblocks the reserved account during server recovery.
    // Enabling an unrelated optional feature must not undo that explicit action.
    $host = User::load(1);
    $host->block()->save();
    $host->activate()->save();
    $password_before = $host->getPassword();
    $this->assertTrue($host->isActive());

    $this->assertTrue($this->container->get('module_installer')->install(['localnodes_role_grant_test']));
    $this->container = \Drupal::getContainer();
    $this->assertNull($this->container->getParameter('install_profile'));
    $this->assertNotEmpty($this->container->get('extension.list.module')->getName('entity_access_by_field'), 'Enabled profile-bundled permission providers remain discoverable after the rebuild.');
    $this->assertTrue($this->container->get('module_handler')->moduleExists('localnodes_role_grant_test'));
    $before_policy = $this->container->get('state')->get('localnodes_role_grant_test.before_policy');
    $this->assertIsArray($before_policy, 'The optional module actual install hook ran.');
    $this->assertTrue($before_policy['synced_admin'], 'The fixture really flipped the synchronized house role to administrator before the platform hook.');
    foreach (['sitemanager', 'contentmanager'] as $id) {
      foreach (['manage everything enrollments', 'view published event enrollment entities', 'unknownfeaturepermission'] as $permission) {
        $this->assertContains($permission, $before_policy['permissions'][$id], 'The fixture persisted the dangerous grant before the post-install hook.');
        $this->assertFalse(Role::load($id)->hasPermission($permission), "$id cannot regain $permission through an optional module.");
      }
    }
    $synced = GroupRole::load('flexible_group-sm-insider');
    $this->assertFalse($synced->isAdmin(), 'A synchronized site role cannot become a house administrator.');
    $this->assertSame([], $synced->getPermissions());
    $this->assertSame($member_before, GroupRole::load('flexible_group-member')->toArray(), 'Ordinary house member grants remain intact.');
    $this->assertSame($manager_before, GroupRole::load('flexible_group-group_manager')->toArray(), 'An individual house manager retains their scope.');
    $verified_after = Role::load('verified')->getPermissions();
    foreach ($verified_before as $permission) {
      $this->assertContains($permission, $verified_after, 'The ordinary member role retains its existing powers.');
    }
    $this->assertContains('late fixture ordinary access', $verified_after, 'The new ordinary feature remains usable.');
    $host = User::load(1);
    $this->assertTrue($host->isActive(), 'An optional install must not reblock a deliberately unblocked host.');
    $this->assertSame($password_before, $host->getPassword());
  }

}
