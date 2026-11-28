<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\localnodes_platform\Account\AdministratorPermissions;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * The site administrator role holds a fixed allow list.
 *
 * The role holds the named allowed permissions and nothing else. A
 * permission a later module adds is not given to it, and is reported until
 * someone classifies it. A site adds a permission on purpose through its
 * setting; a withheld one cannot be added that way. Nobody but account 1
 * changes another person's password or email, edits account 1, or hands out
 * the administrator role or a role holding a withheld permission. The full
 * install is checked by AdministratorAllowListInstallTest.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class AdministratorAllowListTest extends GroupAccessKernelTestBase {

  protected static $modules = ['block', 'localnodes_platform', 'role_delegation'];

  private const FIXTURE_PERMISSION = 'use the unclassified fixture feature';

  protected function setUp(): void {
    parent::setUp();
    // The role starts as an all-permissions role, as on a new site.
    Role::load('administrator')->setIsAdmin(TRUE)->save();
    $this->applyPolicy();
  }

  /** The role holds exactly the allowed permissions this site defines. */
  public function testRoleHoldsOnlyTheAllowList(): void {
    $role = Role::load('administrator');
    $this->assertFalse($role->isAdmin(), 'The administrator role is not an all-permissions role.');
    $existing = array_keys($this->container->get('user.permissions')->getPermissions());
    $expected = AdministratorPermissions::grantable($existing);
    sort($expected);
    $held = $role->getPermissions();
    sort($held);
    $this->assertNotEmpty($expected);
    $this->assertSame($expected, $held, 'The role holds the allowed permissions and nothing else.');
    foreach (['administer permissions', 'administer modules', 'assign administrator role', 'assign all roles', 'bypass node access', 'manage all groups'] as $permission) {
      $this->assertNotNull(AdministratorPermissions::withheldReason($permission), "$permission is withheld on purpose.");
      $this->assertFalse($role->hasPermission($permission), "The role does not hold $permission.");
    }
    foreach (['administer users', 'administer site configuration', 'access user profiles', 'assign sitemanager role'] as $permission) {
      $this->assertTrue($role->hasPermission($permission), "The role holds $permission.");
    }
  }

  /** A permission nobody classified is not given, and is reported. */
  public function testUnclassifiedPermissionIsNotGivenAndIsReported(): void {
    $this->assertNotContains(self::FIXTURE_PERMISSION, array_keys($this->container->get('user.permissions')->getPermissions()));
    $this->installFixture();
    $existing = array_keys($this->container->get('user.permissions')->getPermissions());
    $this->assertContains(self::FIXTURE_PERMISSION, $existing);
    $this->assertContains(self::FIXTURE_PERMISSION, AdministratorPermissions::unclassified($existing), 'The new permission is reported as unclassified.');
    $this->assertFalse(Role::load('administrator')->hasPermission(self::FIXTURE_PERMISSION), 'The role does not get a permission nobody classified, even when the module grants it.');
  }

  /** A site adds a permission on purpose; a withheld one cannot be added. */
  public function testSiteAddsPermissionOnPurpose(): void {
    $this->installFixture();
    $this->config('localnodes_platform.settings')
      ->set('administrator_permissions_added', [self::FIXTURE_PERMISSION, 'administer permissions', 'administer modules'])
      ->save();
    $this->applyPolicy();
    $role = Role::load('administrator');
    $this->assertTrue($role->hasPermission(self::FIXTURE_PERMISSION), 'A permission the site added on purpose is held.');
    $this->assertFalse($role->hasPermission('administer permissions'), 'A withheld permission cannot be added through the setting.');
    $this->assertFalse($role->hasPermission('administer modules'), 'A withheld permission cannot be added through the setting.');
    // A later module install applies the policy again; the addition stays.
    $this->container->get('module_installer')->install(['contact']);
    $this->container = \Drupal::getContainer();
    $this->assertTrue(Role::load('administrator')->hasPermission(self::FIXTURE_PERMISSION), 'The addition survives a later module install.');
    $this->assertFalse(Role::load('administrator')->hasPermission('administer contact forms'), 'The later module\'s own permissions wait for classification.');
  }

  /** Nobody but account 1 changes another person's password or email. */
  public function testAnotherPersonsPasswordAndEmailAreClosed(): void {
    $admin = $this->createUser([], 'allow_admin', FALSE, ['roles' => ['verified', 'administrator']]);
    $manager = $this->createUser([], 'allow_manager', FALSE, ['roles' => ['verified', 'sitemanager']]);
    $member = $this->createUser([], 'allow_member', FALSE, ['roles' => ['verified']]);
    $this->assertSame(1, (int) $this->siteOwner->id());
    foreach (['the administrator' => $admin, 'the site manager' => $manager] as $who => $actor) {
      foreach (['mail', 'pass'] as $field) {
        $this->assertFalse($member->get($field)->access('edit', $actor), "$who cannot change another person's $field.");
        $this->assertTrue($actor->get($field)->access('edit', $actor), "$who changes their own $field.");
      }
      $this->assertFalse($this->siteOwner->access('update', $actor), "$who cannot edit account 1.");
      $this->assertFalse($this->siteOwner->access('delete', $actor), "$who cannot cancel account 1.");
    }
    $this->assertTrue($member->access('update', $admin), 'The administrator still edits another person\'s account.');
    $this->assertTrue($member->get('status')->access('edit', $admin), 'The administrator still blocks and unblocks.');
    $this->assertTrue($member->access('delete', $admin), 'The administrator still cancels an account.');
    $this->assertFalse($member->get('roles')->access('edit', $admin), 'The administrator does not edit roles directly.');
    // A new account is not another person's yet.
    $new = User::create(['name' => 'allow_new']);
    $this->assertTrue($new->get('mail')->access('edit', $admin), 'Adding a person still takes an email address.');
    // Account 1 can do everything.
    $this->siteOwner->activate()->save();
    foreach (['mail', 'pass'] as $field) {
      $this->assertTrue($member->get($field)->access('edit', $this->siteOwner), "Account 1 changes another person's $field.");
    }
  }

  /** Only roles below the administrator's own, holding nothing withheld, are handed out. */
  public function testRolesHandedOut(): void {
    $admin = $this->createUser([], 'hand_admin', FALSE, ['roles' => ['verified', 'administrator']]);
    $member = $this->createUser([], 'hand_member', FALSE, ['roles' => ['verified']]);
    $delegatable = $this->container->get('delegatable_roles');
    $assignable = array_keys($delegatable->getAssignableRoles($admin));
    sort($assignable);
    $this->assertSame(['contentmanager', 'sitemanager', 'verified'], $assignable);

    // Account 1 later gives the site manager role a withheld permission.
    Role::load('sitemanager')->grantPermission('administer modules')->save();
    $assignable = array_keys($delegatable->getAssignableRoles($admin));
    $this->assertNotContains('sitemanager', $assignable, 'A role holding a withheld permission is not handed out.');
    $this->assertContains('contentmanager', $assignable);
    $this->siteOwner->activate()->save();
    $this->assertContains('sitemanager', array_keys($delegatable->getAssignableRoles($this->siteOwner)), 'Account 1 hands out every role.');
    $this->assertContains('administrator', array_keys($delegatable->getAssignableRoles($this->siteOwner)));

    // The account form's role choice: the administrator role, and a role
    // holding a withheld permission, are given only by account 1.
    $this->setCurrentUser($admin);
    $member = User::load($member->id());
    $member->set('role_change', ['administrator', 'sitemanager', 'contentmanager']);
    $member->save();
    $member = User::load($member->id());
    $this->assertFalse($member->hasRole('administrator'), 'The administrator cannot hand out the administrator role.');
    $this->assertFalse($member->hasRole('sitemanager'), 'The administrator cannot hand out a role holding a withheld permission.');
    $this->assertTrue($member->hasRole('contentmanager'), 'The administrator hands out a role below its own.');

    // The bulk role actions.
    $actions = $this->container->get('plugin.manager.action');
    foreach (['user_add_role_action', 'user_remove_role_action'] as $id) {
      $action = $actions->createInstance($id, ['rid' => 'administrator']);
      $this->assertFalse($action->access($member, $admin), "The bulk action $id cannot use the administrator role.");
    }
    foreach (['administrator', 'sitemanager'] as $rid) {
      $actions->createInstance('user_add_role_action', ['rid' => $rid])->execute(User::load($member->id()));
      $this->assertFalse(User::load($member->id())->hasRole($rid), "The bulk action does not hand out the $rid role.");
    }
  }

  /**
   * The optional OpenRouter provider's permissions are classified.
   *
   * localnodes_openrouter is turned on per site (AI_PROVIDER=openrouter), so
   * the fresh-install inventory does not include it.
   */
  public function testOptionalOpenRouterPermissionsAreClassified(): void {
    // Keep profile-bundled modules discoverable across the container rebuild
    // (as in installFixture()).
    $this->config('core.extension')->set('profile', NULL)->save(TRUE);
    $this->enableModules(['ai_provider_openrouter', 'localnodes_openrouter']);
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertArrayHasKey('use ai provider openrouter', $permissions);
    $this->assertSame([], AdministratorPermissions::unclassified(array_keys($permissions)), 'Every permission the OpenRouter modules bring is classified.');
  }

  /**
   * The administrator uses only text formats that filter markup.
   *
   * Every text format this codebase ships is read from its YAML. A format
   * without a filter that limits or escapes markup must have its permission
   * withheld from the role.
   */
  public function testAdministratorUsesOnlyFilteredTextFormats(): void {
    $root = $this->root;
    $files = [];
    foreach (['core', 'modules', 'profiles'] as $dir) {
      $finder = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
      foreach ($finder as $file) {
        $path = $file->getPathname();
        if (preg_match('#/config/(install|optional)/filter\.format\.[^/]+\.yml$#', $path) && !str_contains($path, '/tests/')) {
          $files[] = $path;
        }
      }
    }
    $this->assertNotEmpty($files);
    $unfiltered = [];
    foreach ($files as $path) {
      $format = \Drupal\Component\Serialization\Yaml::decode(file_get_contents($path));
      $id = (string) ($format['format'] ?? '');
      if (!self::filtersMarkup($format['filters'] ?? [])) {
        $unfiltered[$id] = $id;
      }
    }
    $this->assertContains('full_html', $unfiltered, 'The check sees an unfiltered format.');
    foreach ($unfiltered as $id) {
      $permission = "use text format $id";
      $this->assertNotNull(AdministratorPermissions::withheldReason($permission), "$permission (a format that does not filter markup) is withheld.");
      $this->assertFalse(Role::load('administrator')->hasPermission($permission), "The role does not hold $permission.");
    }
  }

  /** Whether a format's enabled filters limit or escape markup. */
  public static function filtersMarkup(array $filters): bool {
    foreach (['filter_html', 'filter_html_escape'] as $id) {
      if (!empty($filters[$id]['status'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function applyPolicy(): void {
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_protect_private_groups();
  }

  private function installFixture(): void {
    // Keep profile-bundled modules discoverable across the installer's
    // container rebuild (as in HousePrivateLateModuleTest).
    $this->config('core.extension')->set('profile', NULL)->save(TRUE);
    $this->assertTrue($this->container->get('module_installer')->install(['localnodes_unclassified_permission_test']));
    $this->container = \Drupal::getContainer();
  }

}
