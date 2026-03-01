<?php

namespace Drupal\localnodes_platform\Drush\Commands;

use Drupal\search_api\Entity\Index;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for LocalNodes demo content management.
 */
class LocalNodesDemoCommands extends DrushCommands {

  /**
   * Install demo content from a specified demo module.
   */
  #[CLI\Command(name: 'localnodes-demo:install', aliases: ['lnd-install'])]
  #[CLI\Argument(name: 'module', description: 'Demo module name (localnodes_demo or boulder_demo)')]
  #[CLI\Usage(name: 'localnodes-demo:install localnodes_demo', description: 'Install Cascadia demo content')]
  #[CLI\Usage(name: 'localnodes-demo:install boulder_demo', description: 'Install Boulder demo content')]
  public function installDemoContent(string $module): void {
    $valid_modules = ['localnodes_demo', 'boulder_demo'];
    if (!in_array($module, $valid_modules, TRUE)) {
      throw new \InvalidArgumentException("Invalid module: $module. Must be one of: " . implode(', ', $valid_modules));
    }

    if (!\Drupal::moduleHandler()->moduleExists($module)) {
      $this->logger()->error("Module $module is not enabled. Run: drush en $module");
      return;
    }

    $content_types = [
      'file' => 'files',
      'user' => 'users',
      'group' => 'groups',
      'topic' => 'topics',
      'event' => 'events',
      'event_enrollment' => 'event enrollments',
      'event_type' => 'event types',
      'post' => 'posts',
      'comment' => 'comments',
      'like' => 'likes',
      'user_terms' => 'user terms',
    ];

    $manager = \Drupal::service('plugin.manager.demo_content');

    foreach ($content_types as $type => $description) {
      $plugins = $manager->createInstances([$type]);
      foreach ($plugins as $plugin) {
        $plugin->createContent();
        $count = $plugin->count();
        $this->logger()->success("Created $count $description");
      }
    }

    // Re-index search.
    $this->logger()->notice('Re-indexing search...');
    $indexes = Index::loadMultiple();
    foreach ($indexes as $index) {
      $index->reindex();
    }

    $this->logger()->success("Demo content from $module installed successfully.");
  }

}
