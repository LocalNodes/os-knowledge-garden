<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * A fresh install turns on Group set-up and decision records.
 *
 * A fresh install runs `site:install social`, then `drush en
 * localnodes_platform` (CLAUDE.md). localnodes_platform requires both
 * modules, so module installation alone brings their storage, settings and
 * template; there is no update hook, entrypoint step or config import.
 * Group type and template id are read from installed config, so this file
 * names neither.
 *
 * @group localnodes_consensus
 */
final class FreshInstallTest extends GroupAccessKernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * The Group set-up and decision record modules.
   */
  protected const MODULES = ['localnodes_group_setup', 'localnodes_consensus'];

  /**
   * The optional web3 stack; none of it may be needed or installed.
   */
  protected const WEB3 = [
    'localnodes_web3',
    'siwe_login',
    'safe_smart_accounts',
    'group_treasury',
    'safe_governance',
    'gas_sponsor',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    if ($container->hasDefinition('extension.list.module')) {
      $container->getDefinition('extension.list.module')->setClass(AllProfilesModuleExtensionList::class);
    }
    if ($container->hasDefinition('config.storage.schema')) {
      $container->getDefinition('config.storage.schema')->setClass(AllProfilesSchemaStorage::class);
    }
  }

  /**
   * localnodes_platform requires both modules, and the feed through one.
   */
  public function testPlatformRequiresBothModules(): void {
    $handler = $this->container->get('module_handler');
    $this->assertFalse($handler->moduleExists('localnodes_platform'), 'Read from the info files, not from an install');
    $list = $this->container->get('extension.list.module')->getList();

    $direct = array_map(static::moduleName(...), $list['localnodes_platform']->info['dependencies']);
    foreach (self::MODULES as $module) {
      $this->assertContains($module, $direct, "localnodes_platform names $module");
      $this->assertArrayHasKey($module, $list['localnodes_platform']->requires);
    }
    $this->assertArrayHasKey('localnodes_proposal_feed', $list['localnodes_consensus']->requires);
    $this->assertArrayHasKey('localnodes_proposal_feed', $list['localnodes_platform']->requires, 'The feed comes in through localnodes_consensus');

    foreach (['localnodes_platform', ...self::MODULES] as $module) {
      $this->assertSame([], array_values(array_intersect(self::WEB3, array_keys($list[$module]->requires))), "$module needs no web3 module");
    }
  }

  /**
   * Installing the modules brings storage, config, services and a preview.
   */
  public function testInstallerEnablesBothModulesFromTheirOwnSources(): void {
    $this->installAsOnAFreshInstall();
    $handler = $this->container->get('module_handler');
    foreach ([...self::MODULES, 'localnodes_proposal_feed'] as $module) {
      $this->assertTrue($handler->moduleExists($module), "$module is on");
    }

    // Storage: every entity type of both modules has its tables, and the
    // installed schema matches the definitions.
    $entity_type_manager = $this->container->get('entity_type.manager');
    $schema = $this->container->get('database')->schema();
    $changes = $this->container->get('entity.definition_update_manager')->getChangeList();
    $found = [];
    foreach ($entity_type_manager->getDefinitions() as $id => $type) {
      if (!in_array($type->getProvider(), self::MODULES, TRUE)) {
        continue;
      }
      $found[] = $id;
      foreach ([$type->getBaseTable(), $type->getDataTable(), $type->getRevisionTable(), $type->getRevisionDataTable()] as $table) {
        if ($table) {
          $this->assertTrue($schema->tableExists($table), "$id has its table $table");
        }
      }
      $this->assertArrayNotHasKey($id, $changes, "$id needs no entity update");
    }
    sort($found);
    $this->assertSame([
      'localnodes_consensus_notice',
      'localnodes_consensus_response',
      'localnodes_consensus_round',
      'localnodes_decision_rules',
      'localnodes_meeting_report',
      'localnodes_roster_audit',
      'localnodes_roster_entry',
      'localnodes_template_application',
    ], $found);

    // Settings and the template, from config/install, valid against schema.
    $typed = $this->container->get('config.typed');
    $templates = $this->container->get('config.storage')->listAll('localnodes_group_setup.template.');
    $this->assertNotEmpty($templates, 'A template definition is installed');
    foreach (['localnodes_group_setup.settings', 'localnodes_consensus.settings', ...$templates] as $name) {
      $data = $this->config($name)->get();
      $this->assertNotEmpty($data, "$name is installed");
      $this->assertConfigSchema($typed, $name, $data);
    }

    // Every service either module declares resolves.
    $extensions = $this->container->get('extension.list.module');
    foreach (self::MODULES as $module) {
      $file = DRUPAL_ROOT . '/' . $extensions->getPath($module) . "/$module.services.yml";
      $services = Yaml::decode(file_get_contents($file))['services'] ?? [];
      unset($services['_defaults']);
      $this->assertNotEmpty($services, "$module declares services");
      foreach (array_keys($services) as $id) {
        $this->assertIsObject($this->container->get($id), "$id resolves");
      }
    }

    // The installed template previews for a Group of the listed type, with
    // no route, update or deploy command.
    $types = $this->config('localnodes_group_setup.settings')->get('group_types');
    $this->assertNotEmpty($types);
    $template_id = (string) $this->config($templates[0])->get('id');
    $this->assertNotSame('', $template_id);
    $group = $this->createFlexibleGroup('Fresh', 'members');
    $this->assertArrayHasKey($group->bundle(), $types, 'The fixture Group is of a listed type');
    $preview = $this->container->get('localnodes_group_setup.application_manager')->preview($group, $template_id);
    $this->assertSame($template_id, $preview['template']);
    $this->assertSame($this->config($templates[0])->get('version'), $preview['definition']['version']);
    $this->assertTrue($preview['allowed']);
    $this->assertNull($preview['application'], 'Previewing applies nothing');

    // The optional web3 stack stays off.
    foreach (self::WEB3 as $module) {
      $this->assertFalse($handler->moduleExists($module), "$module is not installed");
    }
  }

  /**
   * The decision sweep's cron job installs with its every-minute rule.
   */
  public function testCronJobRunsEveryMinute(): void {
    // Open Social installs ultimate_cron with the profile, before
    // localnodes_platform, so the job comes from config/optional.
    $this->container->get('module_installer')->install(['ultimate_cron'], FALSE);
    $this->installAsOnAFreshInstall();

    $job = $this->container->get('entity_type.manager')->getStorage('ultimate_cron_job')->load('localnodes_consensus_cron');
    $this->assertNotNull($job, 'The sweep has its cron job');
    $this->assertTrue($job->status());
    $config = $this->config('ultimate_cron.job.localnodes_consensus_cron');
    $this->assertSame('localnodes_consensus_cron', $config->get('callback'));
    $this->assertSame('simple', $config->get('scheduler.id'));
    $this->assertSame(['* * * * *'], $config->get('scheduler.configuration.rules'));
  }

  /**
   * Neither module has a permission, an update hook or a deployment step.
   *
   * Reading is CurrentEligibleReader's
   * alone, and writes are checked by GroupScope::isCurrentManager() or
   * RosterManager::standing(), so a permission would have nothing to grant
   * except a way around them. No hook_update_N(), post-update,
   * entrypoint line or config/sync export for either module.
   */
  public function testNoPermissionUpdateHookOrDeploymentStep(): void {
    $this->installAsOnAFreshInstall();
    $extensions = $this->container->get('extension.list.module');
    foreach (self::MODULES as $module) {
      $dir = DRUPAL_ROOT . '/' . $extensions->getPath($module);
      $this->assertFileExists("$dir/$module.info.yml");
      $this->assertSame([], static::permissionFiles($dir, $module), "$module ships no permission file");
      $this->assertSame([], static::updateHooks($dir, $module), "$module has no update or post-update hook");
    }

    $definitions = $this->container->get('entity_type.manager')->getDefinitions();
    $this->assertNotEmpty(array_filter($definitions, static fn ($type): bool => in_array($type->getProvider(), self::MODULES, TRUE)));
    $this->assertSame([], static::adminPermissions($definitions, self::MODULES), 'No entity type of either module has an admin permission');
    $declared = array_filter($this->container->get('user.permissions')->getPermissions(), static fn (array $permission): bool => in_array($permission['provider'], self::MODULES, TRUE));
    $this->assertSame([], array_keys($declared), 'Neither module declares a permission');

    $repo = dirname(DRUPAL_ROOT);
    $this->assertFileExists("$repo/docker/entrypoint.sh");
    $this->assertDirectoryExists("$repo/config/sync");
    $this->assertSame([], static::entrypointLines("$repo/docker/entrypoint.sh", self::MODULES), 'The entrypoint names neither module');
    $this->assertSame([], static::syncFiles("$repo/config/sync", self::MODULES), 'config/sync holds nothing of either module');
  }

  /**
   * Each check above reports a violation planted in a temporary fixture.
   */
  public function testChecksCatchAPlantedViolation(): void {
    $root = sys_get_temp_dir() . '/fresh_install_' . bin2hex(random_bytes(6));
    $module = 'fixture_module';
    $clean = "$root/clean";
    $dirty = "$root/dirty";
    mkdir($clean, 0777, TRUE);
    mkdir($dirty, 0777, TRUE);
    try {
      // A clean module: hook_install() only, nothing flagged.
      file_put_contents("$clean/$module.install", "<?php\n\nfunction {$module}_install() {}\n");
      $this->assertSame([], static::permissionFiles($clean, $module));
      $this->assertSame([], static::updateHooks($clean, $module));

      file_put_contents("$dirty/$module.permissions.yml", "administer fixture:\n  title: 'Administer'\n");
      file_put_contents("$dirty/$module.group.permissions.yml", "bypass fixture:\n  title: 'Bypass'\n");
      file_put_contents("$dirty/$module.install", "<?php\n\nfunction {$module}_install() {}\n\nfunction {$module}_update_10001() {}\n");
      file_put_contents("$dirty/$module.post_update.php", "<?php\n\nfunction {$module}_post_update_seed() {}\n");
      $this->assertSame(["$module.permissions.yml", "$module.group.permissions.yml"], static::permissionFiles($dirty, $module));
      $this->assertSame(["{$module}_update_10001", "$module.post_update.php"], static::updateHooks($dirty, $module));

      $definitions = [
        'fixture_thing' => new ContentEntityType(['id' => 'fixture_thing', 'provider' => $module, 'admin_permission' => 'administer fixture']),
        'fixture_plain' => new ContentEntityType(['id' => 'fixture_plain', 'provider' => $module]),
        'other_thing' => new ContentEntityType(['id' => 'other_thing', 'provider' => 'other', 'admin_permission' => 'administer other']),
      ];
      $this->assertSame(['fixture_thing'], static::adminPermissions($definitions, [$module]));

      file_put_contents("$root/entrypoint.sh", "#!/bin/sh\ndrush en other -y\ndrush cset {$module}.settings enabled 1 -y\n");
      $this->assertSame(["3: drush cset {$module}.settings enabled 1 -y"], static::entrypointLines("$root/entrypoint.sh", [$module]));

      mkdir("$root/sync");
      file_put_contents("$root/sync/README.md", "Placeholder.\n");
      file_put_contents("$root/sync/$module.settings.yml", "enabled: true\n");
      $this->assertSame(["$module.settings.yml"], static::syncFiles("$root/sync", [$module]));
    }
    finally {
      $this->container->get('file_system')->deleteRecursive($root);
    }
    $this->assertDirectoryDoesNotExist($root);
  }

  /**
   * Permission files a module ships.
   */
  protected static function permissionFiles(string $dir, string $module): array {
    return array_values(array_filter(["$module.permissions.yml", "$module.group.permissions.yml"], static fn (string $name): bool => file_exists("$dir/$name")));
  }

  /**
   * Update and post-update hooks, and a post-update file, a module ships.
   */
  protected static function updateHooks(string $dir, string $module): array {
    $found = [];
    if (is_file("$dir/$module.install")) {
      preg_match_all('/function\s+(\w+_(?:post_)?update_\w+)\s*\(/i', file_get_contents("$dir/$module.install"), $matches);
      $found = $matches[1];
    }
    if (file_exists("$dir/$module.post_update.php")) {
      $found[] = "$module.post_update.php";
    }
    return $found;
  }

  /**
   * Entity types of the given modules that declare an admin permission.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface[] $definitions
   *   Entity type definitions keyed by id.
   * @param string[] $modules
   *   Providers to check.
   */
  protected static function adminPermissions(array $definitions, array $modules): array {
    $found = [];
    foreach ($definitions as $id => $type) {
      if (in_array($type->getProvider(), $modules, TRUE) && !empty($type->getAdminPermission())) {
        $found[] = $id;
      }
    }
    return $found;
  }

  /**
   * Entrypoint lines ("line: text") that name any of the modules.
   */
  protected static function entrypointLines(string $file, array $modules): array {
    $found = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $number => $line) {
      foreach ($modules as $module) {
        if (stripos($line, $module) !== FALSE) {
          $found[] = ($number + 1) . ': ' . $line;
          break;
        }
      }
    }
    return $found;
  }

  /**
   * Files in a config directory whose name contains any of the modules.
   */
  protected static function syncFiles(string $dir, array $modules): array {
    $found = [];
    foreach (scandir($dir) as $name) {
      foreach ($modules as $module) {
        if (stripos($name, $module) !== FALSE) {
          $found[] = $name;
          break;
        }
      }
    }
    return $found;
  }

  /**
   * Installs the modules as a fresh `drush en localnodes_platform` would.
   *
   * The kernel base enables a slice of Open Social without its own
   * dependencies, so installing with dependencies would pull in the rest of
   * the profile. Instead this derives the list from localnodes_consensus's
   * requirements: everything missing is either one of ours, which must be
   * exactly set-up and the feed, or part of what `site:install social`
   * already installs. Ours go in through the module installer.
   */
  protected function installAsOnAFreshInstall(): void {
    $extensions = $this->container->get('extension.list.module');
    $handler = $this->container->get('module_handler');
    $list = $extensions->getList();
    $missing = array_values(array_filter(array_keys($list['localnodes_consensus']->requires), static fn (string $module): bool => !$handler->moduleExists($module)));
    $ours = array_values(array_filter($missing, static fn (string $module): bool => str_starts_with($extensions->getPath($module), 'modules/custom/')));
    sort($ours);
    $this->assertSame(['localnodes_group_setup', 'localnodes_proposal_feed'], $ours);

    $profile = Yaml::decode(file_get_contents(DRUPAL_ROOT . '/' . $this->container->get('extension.list.profile')->getPath('social') . '/social.info.yml'));
    $profile_modules = [];
    foreach (array_merge($profile['dependencies'] ?? [], $profile['install'] ?? []) as $dependency) {
      $name = static::moduleName($dependency);
      $profile_modules[$name] = TRUE;
      if (isset($list[$name])) {
        $profile_modules += $list[$name]->requires;
      }
    }
    $this->assertSame([], array_values(array_diff($missing, $ours, array_keys($profile_modules))), 'Every other requirement comes with site:install social');

    $this->container->get('module_installer')->install([...$ours, 'localnodes_consensus'], FALSE);
  }

  /**
   * The module name of an info-file dependency ("project:module").
   */
  protected static function moduleName(string $dependency): string {
    $position = strrpos($dependency, ':');
    return $position === FALSE ? $dependency : substr($dependency, $position + 1);
  }

}
