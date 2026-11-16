<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\localnodes_platform\Demo\DemoPlugins;
use Drupal\localnodes_platform\Drush\Commands\LocalNodesDemoCommands;
use Drupal\social_demo\DemoContentInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drush\Log\DrushLoggerManager;

/**
 * Every demo loader uses one plugin list, and a site loads no demo unasked.
 *
 * docker/entrypoint.sh loads what drush localnodes-demo:plugins prints, and
 * drush localnodes-demo:install loads the same list itself.
 *
 * @group localnodes_platform
 */
final class DemoPluginsTest extends UnitTestCase {

  /**
   * The list, in load order. Direct messages are off: no message thread.
   */
  public function testTheList(): void {
    $order = ['file', 'user_terms', 'event_type', 'user', 'group', 'topic', 'event', 'event_enrollment', 'post', 'comment', 'like'];
    $this->assertSame(array_merge(array_map(fn ($name) => "boulder_$name", $order), ['boulder_quick_poll']), DemoPlugins::ids('boulder_demo'));
    $this->assertSame(array_merge(array_map(fn ($name) => "localnodes_$name", $order), ['localnodes_quick_poll']), DemoPlugins::ids('localnodes_demo'));
    $this->assertSame(array_map(fn ($name) => "portland_$name", $order), DemoPlugins::ids('portland_demo'));
    $this->assertSame(array_merge(array_map(fn ($name) => "pack_$name", $order), ['pack_quick_poll']), DemoPlugins::ids('localnodes_demo_packs'));
    $this->assertSame($order, DemoPlugins::ids('social_demo'));
    foreach (array_keys(DemoPlugins::PREFIXES) as $module) {
      foreach (DemoPlugins::ids($module) as $id) {
        $this->assertStringNotContainsString('private_message', $id);
      }
    }
  }

  /**
   * The entrypoint's list is the command's output.
   */
  public function testPluginsCommandPrintsTheList(): void {
    $commands = new LocalNodesDemoCommands();
    foreach (['localnodes_demo', 'boulder_demo', 'portland_demo', 'localnodes_demo_packs', 'social_demo'] as $module) {
      $this->assertSame(DemoPlugins::ids($module), explode(' ', $commands->demoPlugins($module)));
    }
  }

  /**
   * docker/entrypoint.sh loads what the command prints, and nothing else.
   */
  public function testEntrypointLoadsTheList(): void {
    $script = file_get_contents(dirname(__DIR__, 7) . '/docker/entrypoint.sh');
    $this->assertNotFalse($script);
    $this->assertStringContainsString('DEMO_PLUGINS="$($DRUSH localnodes-demo:plugins "$DEMO_MODULE")"', $script);
    $this->assertSame(1, preg_match_all('/\$DRUSH (social-demo:add\b.*)$/m', $script, $loads), 'One demo load.');
    $this->assertSame('social-demo:add $DEMO_PLUGINS $DEMO_PROFILE_OPT', trim($loads[1][0]), 'The load passes the list as it is, plus a pack\'s profile option.');
  }

  /**
   * drush localnodes-demo:install loads exactly the list, in order.
   */
  public function testInstallLoadsTheList(): void {
    foreach (array_diff(array_keys(DemoPlugins::PREFIXES), DemoPlugins::PACK_MODULES) as $module) {
      $this->assertSame(DemoPlugins::ids($module), $this->install($module));
    }
  }

  /**
   * A pack module is refused without --pack: the pack is named by the caller.
   */
  public function testInstallRefusesPackModuleWithoutPack(): void {
    foreach ([[], ['pack' => NULL]] as $options) {
      $this->assertRefused('localnodes_demo_packs', $options, '--pack');
    }
  }

  /**
   * A pack name that is not a plain name, or has no directory, is refused.
   *
   * The name rule comes first: "../packs/smoke" is malformed yet its path
   * exists, so only the name rule stops it.
   */
  public function testInstallRefusesABadPackName(): void {
    foreach (['Bad_Name', '../x', '../packs/smoke', "smoke\n", 'smoke/', 'sm oke', ''] as $name) {
      $this->assertRefused('localnodes_demo_packs', ['pack' => $name], 'lower-case letters', "pack name '$name'");
    }
    $this->assertRefused('localnodes_demo_packs', ['pack' => 'nosuchpack'], 'No pack directory', 'a missing directory');
  }

  /**
   * --pack on any module but the pack module is refused.
   */
  public function testInstallRefusesPackOnOtherModules(): void {
    foreach (array_diff(array_keys(DemoPlugins::PREFIXES), DemoPlugins::PACK_MODULES) as $module) {
      $this->assertRefused($module, ['pack' => 'smoke'], '--pack');
    }
  }

  /**
   * A good pack name loads the pack's plugins in order, from packs/<name>/.
   */
  public function testInstallLoadsAPackInOrder(): void {
    $loaded = [];
    $this->assertSame(0, $this->runInstall('localnodes_demo_packs', ['pack' => 'smoke'], $loaded, 'packs/smoke/'));
    $this->assertSame(DemoPlugins::ids('localnodes_demo_packs'), $loaded);
  }

  /**
   * A module that is not enabled fails the command, with nothing loaded.
   */
  public function testInstallFailsWhenTheModuleIsNotEnabled(): void {
    $loaded = [];
    $this->assertSame(1, $this->runInstall('localnodes_demo', [], $loaded, '', FALSE));
    $this->assertSame([], $loaded);
  }

  /**
   * A module that is not a demo module is refused before anything loads.
   */
  public function testInstallRefusesOtherModules(): void {
    // No container: the refusal comes before anything is read.
    $this->expectException(\InvalidArgumentException::class);
    (new LocalNodesDemoCommands())->installDemoContent('social_demo');
  }

  /**
   * Every DEMO_MODULE default in compose and the entrypoint is none.
   *
   * A self-hosted site that sets nothing gets a blank site, not fixture
   * people and posts.
   */
  public function testNoDemoByDefault(): void {
    $root = dirname(__DIR__, 7);
    $found = 0;
    foreach (['docker-compose.yaml', 'docker/entrypoint.sh'] as $file) {
      $text = file_get_contents("$root/$file");
      $this->assertNotFalse($text, $file);
      preg_match_all('/\$\{DEMO_MODULE:-([^}]*)\}/', $text, $matches);
      foreach ($matches[1] as $default) {
        $this->assertSame('none', $default, "$file: DEMO_MODULE defaults to none.");
        $found++;
      }
    }
    $this->assertGreaterThanOrEqual(3, $found, 'The defaults are where this test looks.');
  }

  /**
   * Runs drush localnodes-demo:install; returns the plugin ids it loaded.
   */
  private function install(string $module): array {
    $loaded = [];
    $this->assertSame(0, $this->runInstall($module, [], $loaded, ''));
    return $loaded;
  }

  /**
   * Runs the command and asserts it throws, with nothing created or loaded.
   */
  private function assertRefused(string $module, array $options, string $message, string $what = ''): void {
    $loaded = [];
    try {
      $this->runInstall($module, $options, $loaded, NULL);
      $this->fail("Not refused: $module " . ($what ?: json_encode($options)));
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString($message, $e->getMessage(), $what);
    }
    $this->assertSame([], $loaded, "A refused call creates no plugin: $module " . ($what ?: json_encode($options)));
  }

  /**
   * Sets up the container, runs localnodes-demo:install, returns its exit code.
   *
   * @param string|null $profile
   *   The profile each plugin must be handed, or NULL when the call must not
   *   reach a plugin.
   */
  private function runInstall(string $module, array $options, array &$loaded, ?string $profile, bool $enabled = TRUE): int {
    $manager = new class($this, $loaded, $profile) {

      public function __construct(private DemoPluginsTest $test, private array &$loaded, private ?string $profile) {}

      public function createInstance(string $id): DemoContentInterface {
        $this->loaded[] = $id;
        return $this->test->plugin($this->profile);
      }

    };

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->willReturn($enabled);
    $admin = $this->createMock(UserInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(1)->willReturn($admin);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($storage);
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->expects($enabled && $profile !== NULL ? $this->once() : $this->never())->method('setAccount')->with($admin);
    // The pack directories are resolved from the Drupal root and the module's
    // path. Here the module's path is the test fixtures, so packs/smoke is the
    // shipped pack.
    $module_list = $this->createMock(ModuleExtensionList::class);
    $module_list->method('getPath')->willReturn('modules/custom/localnodes_demo_packs/tests/fixtures');

    $container = new ContainerBuilder();
    $container->setParameter('app.root', dirname(__DIR__, 6));
    $container->set('module_handler', $module_handler);
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('current_user', $current_user);
    $container->set('plugin.manager.demo_content', $manager);
    $container->set('extension.list.module', $module_list);
    \Drupal::setContainer($container);

    $commands = new LocalNodesDemoCommands();
    $commands->setLogger(new DrushLoggerManager());
    return $commands->installDemoContent($module, $options);
  }

  /**
   * A demo content plugin that is loaded once, with the given profile.
   */
  public function plugin(?string $profile): DemoContentInterface {
    $plugin = $this->createMock(DemoContentInterface::class);
    $plugin->expects($this->once())->method('setProfile')->with($profile ?? '');
    $plugin->expects($this->once())->method('createContent');
    $plugin->method('count')->willReturn(1);
    return $plugin;
  }

}
