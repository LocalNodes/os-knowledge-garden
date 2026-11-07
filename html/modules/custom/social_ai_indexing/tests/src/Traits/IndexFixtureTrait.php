<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Traits;

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the shipped social_posts / social_comments indexes on a test server.
 *
 * The index YAML is read from localnodes_platform/config/install and saved
 * with only `server` swapped for a search_api_db server, so field settings
 * and processor settings are exactly as shipped (F-2 is a shipped-config
 * defect; the tests must see the shipped config).
 *
 * Processor plugins from modules the Kernel base does not enable (for
 * example search_api_solr's solr_date_range) cannot be instantiated and are
 * dropped by search_api when the index is saved. Tests that rely on a
 * processor should assert it survived with assertShippedProcessorLoaded().
 */
trait IndexFixtureTrait {

  /**
   * Path of the shipped index config, relative to the Drupal root.
   */
  protected function shippedIndexPath(string $index_id): string {
    return $this->container->get('extension.list.module')->getPath('localnodes_platform')
      . "/config/install/search_api.index.{$index_id}.yml";
  }

  /**
   * Creates (once) a search_api_db test server.
   */
  protected function createTestSearchServer(string $server_id = 'phase22_test_db'): string {
    if (Server::load($server_id) === NULL) {
      Server::create([
        'id' => $server_id,
        'name' => 'Phase 22 test server',
        'status' => TRUE,
        'backend' => 'search_api_db',
        'backend_config' => [
          'database' => 'default:default',
          'min_chars' => 1,
          'matching' => 'words',
        ],
      ])->save();
    }
    return $server_id;
  }

  /**
   * Saves the shipped index config with its server swapped.
   *
   * @param string $index_id
   *   The shipped index id: social_posts or social_comments.
   * @param string $server_id
   *   An existing search_api server (see createTestSearchServer()).
   */
  protected function createShippedIndex(string $index_id, string $server_id): IndexInterface {
    $file = DRUPAL_ROOT . '/' . $this->shippedIndexPath($index_id);
    $values = Yaml::parseFile($file);
    $values['server'] = $server_id;
    // The shipped dependency list names the production server and Solr; let
    // search_api recalculate dependencies for the test server.
    unset($values['dependencies']);
    $index = Index::create($values);
    $index->save();
    return Index::load($index_id);
  }

  /**
   * Asserts the shipped index config declares a field.
   */
  protected function assertShippedIndexHasField(string $index_id, string $field): void {
    $values = Yaml::parseFile(DRUPAL_ROOT . '/' . $this->shippedIndexPath($index_id));
    $this->assertArrayHasKey($field, $values['field_settings'] ?? [], "Shipped search_api.index.$index_id declares field $field");
  }

  /**
   * Asserts a shipped processor was instantiated on the saved index.
   */
  protected function assertShippedProcessorLoaded(IndexInterface $index, string $processor_id): void {
    $this->assertTrue($index->isValidProcessor($processor_id), "Processor $processor_id is loaded on index {$index->id()}");
  }

}
