<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Symfony\Component\Yaml\Yaml;

/**
 * A member's save is keyword-searchable without cron (Phase 22.2 D-05).
 *
 * localnodes_platform_install() calls _localnodes_platform_index_solr_directly(),
 * which sets index_directly TRUE on Open Social's social_content and
 * social_all indexes (shipped FALSE). With it set, search_api registers a
 * saved item with search_api.post_request_indexing, which indexes it when the
 * request ends. This test builds both indexes from Open Social's shipped
 * social_search YAML, with the server swapped for a search_api_db test server
 * (Solr itself isn't needed here; tests/phase222/keyword-no-cron.sh covers
 * real Solr on the lane), runs the helper, and checks:
 * - both options are TRUE, and a second call saves nothing;
 * - a member's new Topic is found after post-request indexing runs, and not
 *   before, with no cron run;
 * - a missing index is skipped.
 *
 * localnodes_platform itself isn't installed (that would pull in the AI
 * stack); its .install file is loaded by path.
 *
 * @group phase222
 * @group localnodes_platform
 */
final class SolrIndexDirectlyTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;

  /**
   * The two Open Social Solr indexes the helper changes.
   */
  private const SOLR_INDEXES = ['social_content', 'social_all'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // social_search lives in the Open Social profile and isn't enabled here;
    // this list still finds it, so its shipped YAML can be read.
    if ($container->hasDefinition('extension.list.module')) {
      $container->getDefinition('extension.list.module')->setClass(AllProfilesModuleExtensionList::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once DRUPAL_ROOT . '/'
      . $this->container->get('extension.list.module')->getPath('localnodes_platform')
      . '/localnodes_platform.install';
  }

  /**
   * After the helper both options are TRUE; a second call saves nothing.
   */
  public function testOptionsSet(): void {
    $server_id = $this->createTestSearchServer();
    foreach (self::SOLR_INDEXES as $index_id) {
      $index = $this->createSocialSearchIndex($index_id, $server_id);
      $this->assertFalse((bool) $index->getOption('index_directly'), "Open Social ships $index_id with index_directly off");
    }

    $saved = $this->recordConfigSaves();
    _localnodes_platform_index_solr_directly();
    $this->assertSame(
      ['search_api.index.social_all', 'search_api.index.social_content'],
      $this->sortedUnique($saved),
      'The first call saves both indexes.',
    );
    foreach (self::SOLR_INDEXES as $index_id) {
      $this->assertTrue(Index::load($index_id)->getOption('index_directly'), "$index_id has index_directly TRUE");
    }

    $saved->exchangeArray([]);
    _localnodes_platform_index_solr_directly();
    $this->assertSame([], $saved->getArrayCopy(), 'A second call saves no config.');
  }

  /**
   * A member's saved Topic is found after request-end indexing, without cron.
   */
  public function testSaveSearchableWithoutCron(): void {
    $server_id = $this->createTestSearchServer();
    foreach (self::SOLR_INDEXES as $index_id) {
      $this->createSocialSearchIndex($index_id, $server_id);
    }
    _localnodes_platform_index_solr_directly();
    $state = $this->container->get('state');
    $cron_last = $state->get('system.cron_last');

    $member = $this->createPersona('topic_author');
    $this->setCurrentUser($member);
    $word = 'p222kernel' . strtolower($this->randomMachineName(8));
    $node = Node::create([
      'type' => 'topic',
      'title' => "$word topic",
      'body' => ['value' => "<p>$word keyword check</p>", 'format' => 'plain_text'],
      'field_content_visibility' => 'public',
      'uid' => $member->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();

    $index = Index::load('social_content');
    $this->assertSame(0, $this->countHits($index, $word), 'The Topic is not indexed during the save itself.');
    $this->assertSame(1, $index->getTrackerInstance()->getRemainingItemsCount(), 'The Topic is tracked, waiting to be indexed.');

    // The end of the request: what the kernel's terminate event does.
    $this->container->get('search_api.post_request_indexing')->destruct();

    $this->assertSame(1, $this->countHits($index, $word), 'The Topic is found once request-end indexing has run.');
    $this->assertSame(0, $index->getTrackerInstance()->getRemainingItemsCount(), 'Nothing is left for cron.');
    $this->assertSame($cron_last, $state->get('system.cron_last'), 'Cron did not run.');
  }

  /**
   * With no social_all index the helper sets social_content and throws nothing.
   */
  public function testMissingIndexIsSkipped(): void {
    $server_id = $this->createTestSearchServer();
    $this->createSocialSearchIndex('social_content', $server_id);
    $this->assertNull(Index::load('social_all'));

    _localnodes_platform_index_solr_directly();

    $this->assertNull(Index::load('social_all'), 'The helper does not create a missing index.');
    $this->assertTrue(Index::load('social_content')->getOption('index_directly'));
  }

  /**
   * Saves one of Open Social's shipped social_search indexes on a test server.
   *
   * Like IndexFixtureTrait::createShippedIndex(), but from social_search's
   * config/install. Only the server changes. Datasources and processors whose
   * plugins this Kernel test doesn't have (entity:profile; social_search's own
   * processors) are dropped, with the fields that use a dropped datasource.
   * The options, index_directly FALSE and cron_limit 50, stay as shipped.
   */
  private function createSocialSearchIndex(string $index_id, string $server_id): IndexInterface {
    $path = $this->container->get('extension.list.module')->getPath('social_search')
      . "/config/install/search_api.index.$index_id.yml";
    $values = Yaml::parseFile(DRUPAL_ROOT . '/' . $path);
    $values['server'] = $server_id;
    unset($values['dependencies']);

    $datasources = $this->container->get('plugin.manager.search_api.datasource');
    foreach (array_keys($values['datasource_settings']) as $datasource_id) {
      if (!$datasources->hasDefinition($datasource_id)) {
        unset($values['datasource_settings'][$datasource_id]);
      }
    }
    foreach ($values['field_settings'] as $field_id => $field) {
      $datasource_id = $field['datasource_id'] ?? NULL;
      if ($datasource_id !== NULL && !isset($values['datasource_settings'][$datasource_id])) {
        unset($values['field_settings'][$field_id]);
      }
    }
    $processors = $this->container->get('plugin.manager.search_api.processor');
    foreach (array_keys($values['processor_settings']) as $processor_id) {
      if (!$processors->hasDefinition($processor_id)) {
        unset($values['processor_settings'][$processor_id]);
      }
    }

    Index::create($values)->save();
    $index = Index::load($index_id);
    $this->assertInstanceOf(IndexInterface::class, $index);
    $this->assertSame(50, (int) $index->getOption('cron_limit'), "$index_id keeps the shipped cron_limit");
    return $index;
  }

  /**
   * Counts the results a keyword query returns on an index.
   */
  private function countHits(IndexInterface $index, string $keys): int {
    return (int) $index->query()->keys($keys)->execute()->getResultCount();
  }

  /**
   * Records the name of every config object saved from now on.
   */
  private function recordConfigSaves(): \ArrayObject {
    $saved = new \ArrayObject();
    $this->container->get('event_dispatcher')->addListener(
      ConfigEvents::SAVE,
      static function (ConfigCrudEvent $event) use ($saved): void {
        $saved[] = $event->getConfig()->getName();
      },
    );
    return $saved;
  }

  /**
   * Sorted, de-duplicated copy of the recorded config names.
   */
  private function sortedUnique(\ArrayObject $names): array {
    $list = array_values(array_unique($names->getArrayCopy()));
    sort($list);
    return $list;
  }

}
