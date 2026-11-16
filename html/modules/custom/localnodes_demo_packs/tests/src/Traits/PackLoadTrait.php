<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Traits;

use Drupal\search_api\Entity\Server;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Installs the pack loader as the entrypoint does and runs pack plugins.
 *
 * For BrowserTestBase tests on the social profile.
 */
trait PackLoadTrait {

  /**
   * Every pack plugin, in the order the entrypoint loads them.
   */
  protected const PLUGINS = [
    'pack_file',
    'pack_user_terms',
    'pack_event_type',
    'pack_user',
    'pack_group',
    'pack_topic',
    'pack_event',
    'pack_event_enrollment',
    'pack_post',
    'pack_comment',
    'pack_like',
    'pack_quick_poll',
  ];

  /**
   * Installs the site, then the platform and the pack loader.
   *
   * Call from setUp() in place of parent::setUp().
   */
  protected function setUpPackSite(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }
    // As the entrypoint: the platform first, then the demo module.
    $this->container->get('module_installer')->install(['localnodes_platform', 'symfony_mailer_test']);
    $this->container->get('module_installer')->install(['localnodes_demo_packs']);
    $this->rebuildAll();
  }

  /**
   * Runs plugins as drush social-demo:add and social-demo:remove do.
   */
  protected function runPlugins(array $ids, string $method, string $profile): void {
    $this->container->get('request_stack')->push(Request::create('/'));
    $this->container->get('current_user')->setAccount(User::load(1));
    try {
      foreach ($this->container->get('plugin.manager.demo_content')->createInstances($ids) as $plugin) {
        $plugin->setProfile($profile);
        $plugin->$method();
      }
    }
    finally {
      $this->container->get('current_user')->setAccount(User::getAnonymousUser());
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * Drops cached entities so loads read the database.
   */
  protected function resetEntityCaches(): void {
    $manager = $this->container->get('entity_type.manager');
    foreach (['user', 'group', 'node', 'group_content', 'group_relationship', 'poll', 'comment', 'post', 'event_enrollment', 'vote', 'file', 'taxonomy_term'] as $type) {
      if ($manager->hasDefinition($type)) {
        $manager->getStorage($type)->resetCache();
      }
    }
  }

}
