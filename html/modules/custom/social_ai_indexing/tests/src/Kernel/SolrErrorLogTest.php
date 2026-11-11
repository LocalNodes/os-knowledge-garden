<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;

/**
 * A Solr error that repeats the request leaves no member text in the logs.
 *
 * The real Solr backend runs on a connector that fails like Solr does: its
 * error for a rejected document quotes the field values, and its error for a
 * bad query quotes the keys. Each case checks that the failing request was
 * made with the canary in it (the exception's text before the backend sees
 * it), and that neither the log nor the exception the backend throws holds
 * the canary afterwards.
 *
 * Every text is fictional.
 *
 * @group social_ai_indexing
 */
final class SolrErrorLogTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'language',
    'key',
    'ai',
    'search_api',
    'search_api_solr',
    'social_ai_indexing_test',
  ];

  /**
   * In a page's text.
   */
  private const PAGE_CANARY = 'QZXSOLRPAGE3307';

  /**
   * In a member's search.
   */
  private const QUESTION_CANARY = 'QZXSOLRQUESTION3308';

  /**
   * The index on the echoing Solr server.
   */
  private IndexInterface $index;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['system', 'node', 'filter', 'search_api', 'search_api_solr']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // The node module's own config brings the body field's storage.
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'page',
    ])->save();

    Server::create([
      'id' => 'echo_solr',
      'name' => 'Echo Solr',
      'status' => TRUE,
      'backend' => 'search_api_solr',
      'backend_config' => [
        'connector' => 'social_ai_indexing_echo',
        'connector_config' => [
          'scheme' => 'http',
          'host' => 'localhost',
          'port' => 8983,
          'path' => '/',
          'core' => 'fictional',
          'timeout' => 5,
          'index_timeout' => 5,
          'optimize_timeout' => 10,
          'finalize_timeout' => 30,
          'commit_within' => 1000,
          'solr_version' => '9',
        ],
        // One document at a time after a failed batch: the second path that
        // logs a Solr error.
        'index_single_documents_fallback_count' => 10,
      ],
    ])->save();

    $this->index = Index::create([
      'id' => 'echo_content',
      'name' => 'Echo content',
      'status' => TRUE,
      'server' => 'echo_solr',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        'body' => [
          'label' => 'Body',
          'datasource_id' => 'entity:node',
          'property_path' => 'body',
          'type' => 'text',
        ],
      ],
      'options' => ['index_directly' => FALSE, 'cron_limit' => 50],
    ]);
    $this->index->save();

    Node::create([
      'type' => 'page',
      'title' => 'Fictional shed note',
      'body' => 'The fictional shed code is ' . self::PAGE_CANARY . ' and the shed opens at nine.',
    ])->save();
    $this->spy()->reset();
  }

  /**
   * Indexing one item at a time after the batch fails: each failure is logged.
   */
  public function testIndexingFallback(): void {
    $this->index->indexItems();
    $this->assertNotSame([], $this->spy()->records(), 'The failed update was logged.');
    $this->assertStringContainsString('while indexing item', $this->allLogged(), 'The one-document fallback ran.');
    $this->assertNoCanary($this->allLogged());
  }

  /**
   * Indexing with no fallback: the error is rethrown to the caller.
   */
  public function testIndexingRethrown(): void {
    $server = $this->index->getServerInstance();
    $config = $server->getBackendConfig();
    $config['index_single_documents_fallback_count'] = 0;
    $server->setBackendConfig($config)->save();
    $this->index = Index::load('echo_content');

    // The backend itself, for the exception it throws; search_api's own
    // indexing catches and logs it, which the second call covers.
    $items = [];
    foreach ($this->index->loadItemsMultiple($this->index->getTrackerInstance()->getRemainingItems()) as $id => $object) {
      $items[$id] = $this->container->get('search_api.fields_helper')->createItemFromObject($this->index, $object, $id);
    }
    $this->assertNotSame([], $items);
    $thrown = NULL;
    try {
      $this->index->getServerInstance()->getBackend()->indexItems($this->index, $items);
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertInstanceOf(SearchApiSolrException::class, $thrown, 'The update failed.');
    $this->index->indexItems();
    $this->assertNoCanary(SpyLogger::flatten(['message' => '', 'context' => ['exception' => $thrown]]));
    $this->assertStringContainsString('while indexing', $this->allLogged());
    $this->assertNoCanary($this->allLogged());
  }

  /**
   * A search whose keys Solr quotes in its error.
   */
  public function testSearch(): void {
    $thrown = NULL;
    try {
      $this->index->query()->keys('fictional ' . self::QUESTION_CANARY)->execute();
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertInstanceOf(SearchApiSolrException::class, $thrown, 'The search failed.');
    $this->assertNoCanary(SpyLogger::flatten(['message' => '', 'context' => ['exception' => $thrown]]));
    $this->assertStringContainsString('while searching', $this->allLogged());
    $this->assertNoCanary($this->allLogged());
  }

  /**
   * The double really echoes: its error holds what it was sent.
   */
  public function testConnectorEchoes(): void {
    $connector = $this->index->getServerInstance()->getBackend()->getSolrConnector();
    $query = $connector->getSelectQuery()->setQuery(self::QUESTION_CANARY);
    try {
      $connector->search($query);
      $this->fail('The connector did not fail.');
    }
    catch (SearchApiSolrException $e) {
      $this->assertStringContainsString(self::QUESTION_CANARY, $e->getMessage());
    }
  }

  /**
   * The spy logger on every channel.
   */
  private function spy(): SpyLogger {
    return $this->container->get('social_ai_indexing_test.spy_logger');
  }

  /**
   * Every record the spy holds, flattened to one string.
   */
  private function allLogged(): string {
    return implode("\n", array_map([SpyLogger::class, 'flatten'], $this->spy()->records()));
  }

  /**
   * Asserts $text holds neither canary.
   */
  private function assertNoCanary(string $text): void {
    foreach ([self::PAGE_CANARY, self::QUESTION_CANARY] as $canary) {
      $this->assertStringNotContainsString($canary, $text);
    }
  }

}
