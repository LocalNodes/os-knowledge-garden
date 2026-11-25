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
   *
   * A pack is loaded with --pack=<name>, from packs/<name>/ in the pack
   * module, as docker/entrypoint.sh loads it for DEMO_PACK.
   */
  #[CLI\Command(name: 'localnodes-demo:install', aliases: ['lnd-install'])]
  #[CLI\Argument(name: 'module', description: 'Demo module name (localnodes_demo, boulder_demo, neighbors_demo, portland_demo or localnodes_demo_packs)')]
  #[CLI\Option(name: 'pack', description: 'The pack to load, a directory name under localnodes_demo_packs/packs. Required for localnodes_demo_packs, refused for any other module.')]
  #[CLI\Usage(name: 'localnodes-demo:install localnodes_demo', description: 'Install the content of the localnodes_demo module')]
  #[CLI\Usage(name: 'localnodes-demo:install localnodes_demo_packs --pack=lanternrow', description: 'Install the demo pack named lanternrow')]
  public function installDemoContent(string $module, array $options = ['pack' => self::REQ]): int {
    $pack = isset($options['pack']) && is_string($options['pack']) ? $options['pack'] : NULL;
    $profile = self::checkModule($module, $pack);

    if (!\Drupal::moduleHandler()->moduleExists($module)) {
      $this->logger()->error("Module $module is not enabled. Run: drush en $module");
      return self::EXIT_FAILURE;
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
      $plugin->setProfile($profile);
      $plugin->createContent();
      $count = $plugin->count();
      if ($count !== FALSE) {
        $this->logger()->success("$plugin_id: $count created");
      }
    }

    $this->logger()->success("Demo content from $module installed successfully.");
    $this->logger()->notice('Run "drush search-api:reset-tracker && drush search-api:index" to index content.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Print the demo content plugin ids for a demo module, in load order.
   *
   * Any other module gets social_demo's unprefixed plugins, as the
   * entrypoint has always loaded for it.
   */
  #[CLI\Command(name: 'localnodes-demo:plugins')]
  #[CLI\Argument(name: 'module', description: 'Demo module name (localnodes_demo, boulder_demo, neighbors_demo or portland_demo)')]
  #[CLI\Usage(name: 'localnodes-demo:plugins boulder_demo', description: 'The plugin ids docker/entrypoint.sh passes to drush social-demo:add')]
  public function demoPlugins(string $module): string {
    return implode(' ', DemoPlugins::ids($module));
  }

  /**
   * Refuses a call that names no demo module or a pack that cannot load.
   *
   * @return string
   *   The profile to hand each plugin: packs/<name>/ for a pack, as the
   *   entrypoint passes it to --profile, and an empty string otherwise.
   */
  private static function checkModule(string $module, ?string $pack): string {
    if (!isset(DemoPlugins::PREFIXES[$module])) {
      throw new \InvalidArgumentException("Invalid module: $module. Must be one of: " . implode(', ', array_keys(DemoPlugins::PREFIXES)));
    }
    if (!in_array($module, DemoPlugins::PACK_MODULES, TRUE)) {
      if ($pack !== NULL) {
        throw new \InvalidArgumentException("--pack is only for a pack module, not $module.");
      }
      return '';
    }
    if ($pack === NULL) {
      throw new \InvalidArgumentException("$module loads a pack: name it with --pack=<name>, a directory under its packs folder.");
    }
    // The name becomes part of a path, so it is vetted as a plain name
    // before any path is built; then the pack must ship with the module.
    // This is the entrypoint's rule (check_demo_pack).
    if (!preg_match('/^[a-z0-9_]+$/D', $pack)) {
      throw new \InvalidArgumentException('--pack must be lower-case letters, digits and underscores.');
    }
    $dir = \Drupal::root() . '/' . \Drupal::service('extension.list.module')->getPath($module) . "/packs/$pack";
    if (!is_dir($dir)) {
      throw new \InvalidArgumentException("No pack directory named $pack in $module.");
    }
    return "packs/$pack/";
  }

}
