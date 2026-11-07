<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Traits;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\social_ai_indexing_test\Plugin\search_api\backend\StubAiSearchBackend;

/**
 * Runs shipped AI indexes on the stub AI search backend (Phase 22, 22-09).
 *
 * Use with GroupAccessKernelTestBase and IndexFixtureTrait; the test class
 * must list 'social_ai_indexing_test' in $modules.
 */
trait StubSearchTrait {

  /**
   * Creates (once) a server on the stub backend.
   */
  protected function createStubSearchServer(string $server_id = 'phase22_stub'): string {
    if (Server::load($server_id) === NULL) {
      Server::create([
        'id' => $server_id,
        'name' => 'Phase 22 stub AI server',
        'status' => TRUE,
        'backend' => 'social_ai_indexing_test_stub',
        'backend_config' => [],
      ])->save();
    }
    return $server_id;
  }

  /**
   * Sets the stub backend mode: honour, ignore or throw.
   */
  protected function setStubMode(string $mode): void {
    $this->container->get('state')->set(StubAiSearchBackend::MODE_KEY, $mode);
  }

  /**
   * The condition group the stub last received, or NULL.
   */
  protected function lastStubConditions(): ?array {
    return $this->container->get('state')->get(StubAiSearchBackend::LAST_CONDITIONS_KEY);
  }

  /**
   * Clears the recorded condition group (aborted queries never reach it).
   */
  protected function resetStubConditions(): void {
    $this->container->get('state')->delete(StubAiSearchBackend::LAST_CONDITIONS_KEY);
    $this->container->get('state')->delete(StubAiSearchBackend::LAST_QUERY_KEY);
  }

  /**
   * The last query's bypass option, processing level and limit, or NULL.
   */
  protected function lastStubQuery(): ?array {
    return $this->container->get('state')->get(StubAiSearchBackend::LAST_QUERY_KEY);
  }

  /**
   * Sets result scores by entity (others score 1.0); higher ranks first.
   *
   * @param array $scores
   *   Item id => score.
   */
  protected function setStubScores(array $scores): void {
    $this->container->get('state')->set(StubAiSearchBackend::SCORES_KEY, $scores);
  }

  /**
   * The stub's stored records (the "vector DB" contents).
   */
  protected function stubStore(): KeyValueStoreInterface {
    return $this->container->get('keyvalue')->get(StubAiSearchBackend::COLLECTION);
  }

  /**
   * Indexes every tracked item and returns the number indexed.
   */
  protected function indexAllItems(IndexInterface $index): int {
    return $index->indexItems();
  }

  /**
   * Runs a query on an index as the given account, with an optional hint.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The asker; becomes the current user.
   * @param mixed $hint
   *   Value for the ai_search_scope_group_id option; NULL leaves it unset.
   */
  protected function runAsPersona(IndexInterface $index, $account, mixed $hint = NULL): QueryInterface {
    $this->setCurrentUser($account);
    $this->resetStubConditions();
    $query = $index->query();
    if ($hint !== NULL) {
      $query->setOption('ai_search_scope_group_id', $hint);
    }
    $query->execute();
    return $query;
  }

  /**
   * Result item ids of an executed query.
   *
   * @return string[]
   *   Item ids such as entity:node/12:en.
   */
  protected function resultIds(QueryInterface $query): array {
    return array_keys($query->getResults()->getResultItems());
  }

  /**
   * Search API item id of a content entity.
   */
  protected function itemId($entity): string {
    return 'entity:' . $entity->getEntityTypeId() . '/' . $entity->id() . ':' . $entity->language()->getId();
  }

  /**
   * Finds condition arrays on a field anywhere in a recorded group.
   *
   * @return array
   *   List of ['field', 'value', 'operator'] arrays.
   */
  protected function findConditions(?array $group, string $field): array {
    $found = [];
    foreach ($group['conditions'] ?? [] as $condition) {
      if (isset($condition['conditions'])) {
        $found = array_merge($found, $this->findConditions($condition, $field));
        continue;
      }
      if ($condition['field'] === $field) {
        $found[] = $condition;
      }
    }
    return $found;
  }

}
