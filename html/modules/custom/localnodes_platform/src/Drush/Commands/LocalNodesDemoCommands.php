<?php

namespace Drupal\localnodes_platform\Drush\Commands;

use Drupal\localnodes_platform\Demo\DemoPlugins;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for LocalNodes demo content management.
 *
 * Both commands use DemoPlugins, the list docker/entrypoint.sh loads.
 */
class LocalNodesDemoCommands extends DrushCommands {

  /**
   * Install demo content from a specified demo module.
   */
  #[CLI\Command(name: 'localnodes-demo:install', aliases: ['lnd-install'])]
  #[CLI\Argument(name: 'module', description: 'Demo module name (localnodes_demo, boulder_demo or portland_demo)')]
  #[CLI\Usage(name: 'localnodes-demo:install localnodes_demo', description: 'Install Cascadia demo content')]
  #[CLI\Usage(name: 'localnodes-demo:install boulder_demo', description: 'Install Boulder demo content')]
  public function installDemoContent(string $module): void {
    self::checkModule($module);

    if (!\Drupal::moduleHandler()->moduleExists($module)) {
      $this->logger()->error("Module $module is not enabled. Run: drush en $module");
      return;
    }

    // As drush social-demo:add: run as the site's first account, so the
    // plugins may create every kind of content.
    $current_user = \Drupal::currentUser();
    $current_user->setAccount(\Drupal::entityTypeManager()->getStorage('user')->load(1));

    $manager = \Drupal::service('plugin.manager.demo_content');
    foreach (DemoPlugins::ids($module) as $plugin_id) {
      // A missing plugin is an error: the list names only plugins the
      // module has.
      $plugin = $manager->createInstance($plugin_id);
      $plugin->setProfile('');
      $plugin->createContent();
      $count = $plugin->count();
      if ($count !== FALSE) {
        $this->logger()->success("$plugin_id: $count created");
      }
    }

    $this->logger()->success("Demo content from $module installed successfully.");
    $this->logger()->notice('Run "drush search-api:reset-tracker && drush search-api:index" to index content.');
  }

  /**
   * Print the demo content plugin ids for a demo module, in load order.
   *
   * Any other module gets social_demo's unprefixed plugins, as the
   * entrypoint has always loaded for it.
   */
  #[CLI\Command(name: 'localnodes-demo:plugins')]
  #[CLI\Argument(name: 'module', description: 'Demo module name (localnodes_demo, boulder_demo or portland_demo)')]
  #[CLI\Usage(name: 'localnodes-demo:plugins boulder_demo', description: 'The plugin ids docker/entrypoint.sh passes to drush social-demo:add')]
  public function demoPlugins(string $module): string {
    return implode(' ', DemoPlugins::ids($module));
  }

  /**
   * Refuses a module that is not a demo module.
   */
  private static function checkModule(string $module): void {
    if (!isset(DemoPlugins::PREFIXES[$module])) {
      throw new \InvalidArgumentException("Invalid module: $module. Must be one of: " . implode(', ', array_keys(DemoPlugins::PREFIXES)));
    }
  }

}
