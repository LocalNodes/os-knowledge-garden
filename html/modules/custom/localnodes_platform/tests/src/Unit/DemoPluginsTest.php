<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    foreach (['localnodes_demo', 'boulder_demo', 'portland_demo', 'social_demo'] as $module) {
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
    $this->assertSame('social-demo:add $DEMO_PLUGINS', trim($loads[1][0]), 'The load passes the list as it is.');
  }

  /**
   * drush localnodes-demo:install loads exactly the list, in order.
   */
  public function testInstallLoadsTheList(): void {
    foreach (array_keys(DemoPlugins::PREFIXES) as $module) {
      $this->assertSame(DemoPlugins::ids($module), $this->install($module));
    }
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
    $manager = new class($this, $loaded) {

      public function __construct(private DemoPluginsTest $test, private array &$loaded) {}

      public function createInstance(string $id): DemoContentInterface {
        $this->loaded[] = $id;
        return $this->test->plugin();
      }

    };

    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->willReturn(TRUE);
    $admin = $this->createMock(UserInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(1)->willReturn($admin);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getStorage')->with('user')->willReturn($storage);
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->expects($this->once())->method('setAccount')->with($admin);

    $container = new ContainerBuilder();
    $container->set('module_handler', $module_handler);
    $container->set('entity_type.manager', $entity_type_manager);
    $container->set('current_user', $current_user);
    $container->set('plugin.manager.demo_content', $manager);
    \Drupal::setContainer($container);

    $commands = new LocalNodesDemoCommands();
    $commands->setLogger(new DrushLoggerManager());
    $commands->installDemoContent($module);
    return $loaded;
  }

  /**
   * A demo content plugin that is loaded once.
   */
  public function plugin(): DemoContentInterface {
    $plugin = $this->createMock(DemoContentInterface::class);
    $plugin->expects($this->once())->method('createContent');
    $plugin->method('count')->willReturn(1);
    return $plugin;
  }

}
