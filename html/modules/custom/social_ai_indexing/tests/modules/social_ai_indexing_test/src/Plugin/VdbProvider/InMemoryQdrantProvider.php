<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing_test\Plugin\VdbProvider;

use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\ai_vdb_provider_qdrant\Exception\InsertIntoCollectionException;
use Drupal\social_ai_indexing\Plugin\VdbProvider\ThrottledQdrantProvider;

/**
 * The shipped ThrottledQdrantProvider on in-memory storage (22.2-10).
 *
 * Overrides only the storage helpers that indexItems() uses: collection
 * create and check, lookup by item id, delete by point id, insert. The
 * indexItems() logic under test is the shipped one. No network connection is
 * opened.
 *
 * Points live in key_value collection 'social_ai_indexing_test.qdrant_points',
 * keyed by point id (md5 of the chunk id, as QdrantClient does), and carry the
 * 'generation' read from state 'social_ai_indexing_test.qdrant_generation'
 * when they were inserted. A test sets the generation before each index run to
 * tell the old points from the new ones.
 *
 * With state 'social_ai_indexing_test.qdrant_insert_echo' set, every insert
 * fails with an exception whose message repeats the point, and the message is
 * kept in state 'social_ai_indexing_test.qdrant_insert_echoed'.
 */
class InMemoryQdrantProvider extends ThrottledQdrantProvider {

  /**
   * The key_value collection that holds the points.
   */
  public const POINTS = 'social_ai_indexing_test.qdrant_points';

  /**
   * The key_value collection that holds the collection names.
   */
  public const COLLECTIONS = 'social_ai_indexing_test.qdrant_collections';

  /**
   * {@inheritdoc}
   */
  public function getCollections(string $database = 'default'): array {
    return array_keys(\Drupal::keyValue(self::COLLECTIONS)->getAll());
  }

  /**
   * {@inheritdoc}
   */
  public function createCollection(
    string $collection_name,
    int $dimension,
    VdbSimilarityMetrics $metric_type = VdbSimilarityMetrics::CosineSimilarity,
    string $database = 'default',
  ): void {
    \Drupal::keyValue(self::COLLECTIONS)->set($collection_name, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getVdbIds(string $collection_name, array $drupalIds, string $database = 'default'): array {
    $ids = [];
    foreach (\Drupal::keyValue(self::POINTS)->getAll() as $point_id => $point) {
      if ($point['collection'] === $collection_name && in_array($point['drupal_entity_id'], $drupalIds, TRUE)) {
        $ids[] = $point_id;
      }
    }
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFromCollection(string $collection_name, array $ids, string $database = 'default'): void {
    \Drupal::keyValue(self::POINTS)->deleteMultiple(array_values($ids));
  }

  /**
   * {@inheritdoc}
   */
  public function insertIntoCollection(string $collection_name, array $data, string $database = 'default'): void {
    if (\Drupal::state()->get('social_ai_indexing_test.qdrant_insert_echo')) {
      // Fail the way the Qdrant client does, with the store's error in the
      // message; here the worst case, an error that repeats the whole point.
      $message = 'Failed to insert into collection: ' . json_encode($data);
      \Drupal::state()->set('social_ai_indexing_test.qdrant_insert_echoed', $message);
      throw new InsertIntoCollectionException($message, 400);
    }
    $long_id = $data['drupal_long_id']['value'];
    \Drupal::keyValue(self::POINTS)->set(md5($long_id), [
      'collection' => $collection_name,
      'drupal_long_id' => $long_id,
      'drupal_entity_id' => $data['drupal_entity_id']['value'],
      'generation' => (int) \Drupal::state()->get('social_ai_indexing_test.qdrant_generation', 1),
    ]);
  }

}
