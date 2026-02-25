<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;

/**
 * Service for hybrid search combining Milvus semantic and Solr keyword search.
 *
 * Uses Reciprocal Rank Fusion (RRF) to merge results from vector similarity
 * search (Milvus) and keyword matching (Solr), with permission-aware filtering.
 */
class HybridSearchService {

  /**
   * The permission filter service.
   *
   * @var \Drupal\social_ai_indexing\Service\PermissionFilterService
   */
  protected PermissionFilterService $permissionFilter;

  /**
   * The Search API index storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $indexStorage;

  /**
   * RRF smoothing constant (industry standard).
   *
   * Higher values reduce the impact of high ranks, making the fusion more
   * democratic between the two ranking systems.
   */
  public const RRF_K = 60;

  /**
   * Constructs a HybridSearchService.
   *
   * @param \Drupal\social_ai_indexing\Service\PermissionFilterService $permission_filter
   *   The permission filter service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    PermissionFilterService $permission_filter,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->permissionFilter = $permission_filter;
    $this->indexStorage = $entity_type_manager->getStorage('search_api_index');
  }

  /**
   * Perform hybrid search combining vector and keyword results.
   *
   * @param string $query
   *   The search query string.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check permissions for.
   * @param int $limit
   *   Maximum number of results to return.
   *
   * @return array
   *   Array of search results with merged RRF scores.
   */
  public function search(string $query, AccountInterface $account, int $limit = 10): array {
    // Fetch 2x results from each source for better RRF merging.
    $fetch_limit = $limit * 2;

    // 1. Semantic search via Milvus (social_posts index).
    $vectorResults = $this->vectorSearch($query, $account, $fetch_limit);

    // 2. Keyword search via Solr (social_content index).
    $keywordResults = $this->keywordSearch($query, $account, $fetch_limit);

    // 3. Merge with Reciprocal Rank Fusion.
    $merged = $this->reciprocalRankFusion($vectorResults, $keywordResults);

    // 4. Post-retrieval access check (defense-in-depth).
    return $this->permissionFilter->filterResultsByAccess(
      array_slice($merged, 0, $limit),
      $account
    );
  }

  /**
   * Perform vector similarity search via Milvus.
   *
   * @param string $query
   *   The search query string.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param int $limit
   *   Maximum results to fetch.
   *
   * @return array
   *   Array of results with entity metadata.
   */
  protected function vectorSearch(string $query, AccountInterface $account, int $limit): array {
    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->indexStorage->load('social_posts');

    if (!$index || !$index->status()) {
      return [];
    }

    try {
      $search_query = $index->query();
      $search_query->keys($query);
      $search_query->range(0, $limit);

      // Apply permission filters to the query.
      $this->permissionFilter->applyPermissionFilters($search_query, $account);

      // Execute and get results.
      $results = $search_query->execute();

      return $this->normalizeResults($results, 'social_posts');
    }
    catch (\Exception $e) {
      // Log warning but don't fail - return empty results.
      \Drupal::logger('social_ai_indexing')->warning(
        'Vector search failed: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }
  }

  /**
   * Perform keyword search via Solr.
   *
   * @param string $query
   *   The search query string.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param int $limit
   *   Maximum results to fetch.
   *
   * @return array
   *   Array of results with entity metadata.
   */
  protected function keywordSearch(string $query, AccountInterface $account, int $limit): array {
    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->indexStorage->load('social_content');

    if (!$index || !$index->status()) {
      return [];
    }

    try {
      $search_query = $index->query();
      $search_query->keys($query);
      $search_query->range(0, $limit);

      // Filter to post and topic content types (same as social_posts index).
      $search_query->addCondition('type', ['post', 'topic'], 'IN');

      // Apply permission filters to the query.
      $this->permissionFilter->applyPermissionFilters($search_query, $account);

      // Execute and get results.
      $results = $search_query->execute();

      return $this->normalizeResults($results, 'social_content');
    }
    catch (\Exception $e) {
      // Log warning but don't fail - return empty results.
      \Drupal::logger('social_ai_indexing')->warning(
        'Keyword search failed: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }
  }

  /**
   * Merge results using Reciprocal Rank Fusion (RRF).
   *
   * RRF formula: score(d) = Σ 1/(k + rank(d))
   *
   * This rank-based fusion avoids score normalization issues between
   * different search systems (Milvus cosine similarity vs Solr BM25).
   *
   * @param array $vectorResults
   *   Results from vector/semantic search.
   * @param array $keywordResults
   *   Results from keyword search.
   *
   * @return array
   *   Merged results sorted by combined RRF score.
   */
  protected function reciprocalRankFusion(array $vectorResults, array $keywordResults): array {
    $scores = [];
    $merged = [];

    // Process vector results.
    foreach ($vectorResults as $rank => $result) {
      $id = $this->getResultId($result);
      if ($id === NULL) {
        continue;
      }

      // RRF score contribution from vector search.
      $scores[$id] = ($scores[$id] ?? 0) + (1 / ($rank + 1 + self::RRF_K));
      $merged[$id] = $result;
    }

    // Process keyword results.
    foreach ($keywordResults as $rank => $result) {
      $id = $this->getResultId($result);
      if ($id === NULL) {
        continue;
      }

      // RRF score contribution from keyword search.
      $scores[$id] = ($scores[$id] ?? 0) + (1 / ($rank + 1 + self::RRF_K));
      $merged[$id] = $result;
    }

    // Sort by combined score (descending).
    arsort($scores);

    // Return results in score order.
    return array_map(
      fn($id) => $merged[$id],
      array_keys($scores)
    );
  }

  /**
   * Get unique entity ID from a search result.
   *
   * Uses drupal_entity_id if available, falls back to parsing the Search API
   * item ID (format: entity:type:id:language).
   *
   * @param array $result
   *   A search result item.
   *
   * @return string|int|null
   *   The entity ID or NULL if not determinable.
   */
  protected function getResultId(array $result): string|int|null {
    // Primary: use explicit entity ID field.
    if (isset($result['drupal_entity_id'])) {
      return $result['drupal_entity_id'];
    }

    if (isset($result['id'])) {
      // If it's already a numeric ID.
      if (is_numeric($result['id'])) {
        return $result['id'];
      }

      // Parse Search API item ID format: entity:node:123:en
      if (is_string($result['id']) && str_contains($result['id'], ':')) {
        $parts = explode(':', $result['id']);
        if (count($parts) >= 3 && is_numeric($parts[2])) {
          return (int) $parts[2];
        }
      }
    }

    return NULL;
  }

  /**
   * Normalize search results to a consistent format.
   *
   * @param \Drupal\search_api\Query\ResultSetInterface $results
   *   The search results from a query.
   * @param string $source_index
   *   The index the results came from.
   *
   * @return array
   *   Array of normalized result arrays.
   */
  protected function normalizeResults(ResultSetInterface $results, string $source_index): array {
    $normalized = [];

    foreach ($results->getResultItems() as $item) {
      /** @var \Drupal\search_api\Item\ItemInterface $item */
      $result_data = [
        'id' => $item->getId(),
        'source_index' => $source_index,
        'score' => $item->getScore(),
      ];

      // Extract field values.
      $fields = $item->getFields();
      foreach ($fields as $field_id => $field) {
        $values = $field->getValues();
        if (!empty($values)) {
          $result_data[$field_id] = $values;
        }
      }

      // Try to extract entity type and ID from the item ID.
      // Format: entity:node/123:en or entity:node:123:en
      $item_id = $item->getId();
      if (str_contains($item_id, ':')) {
        $parts = explode(':', $item_id);
        if (count($parts) >= 3) {
          // Check if parts[1] contains a slash (e.g., "node/123")
          if (str_contains($parts[1] ?? '', '/')) {
            $entity_parts = explode('/', $parts[1]);
            $result_data['drupal_entity_type'] = $entity_parts[0] ?? 'node';
            if (is_numeric($entity_parts[1] ?? NULL)) {
              $result_data['drupal_entity_id'] = (int) $entity_parts[1];
            }
          }
          else {
            // Old format: entity:node:123:en
            $result_data['drupal_entity_type'] = $parts[1] ?? 'node';
            if (is_numeric($parts[2] ?? NULL)) {
              $result_data['drupal_entity_id'] = (int) $parts[2];
            }
          }
        }
      }

      $normalized[] = $result_data;
    }

    return $normalized;
  }

}
