<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;

class RelatedContentService {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected PermissionFilterService $permissionFilter;

  const SIMILARITY_THRESHOLD = 0.7;
  const DEFAULT_LIMIT = 5;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    PermissionFilterService $permission_filter
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->permissionFilter = $permission_filter;
  }

  public function findRelated(EntityInterface $entity, AccountInterface $account, int $limit = self::DEFAULT_LIMIT): array {
    if (!$entity instanceof NodeInterface) {
      return [];
    }

    $index = $this->entityTypeManager->getStorage('search_api_index')->load('social_posts');
    if (!$index || !$index->status()) {
      return [];
    }

    try {
      $query = $index->query();

      $sourceId = 'entity:node/' . $entity->id() . ':' . $entity->language()->getId();
      $query->addCondition('search_api_id', $sourceId, '<>');

      $this->permissionFilter->applyPermissionFilters($query, $account);

      $query->setFulltextFields(['rendered_item']);

      $queryText = $this->extractQueryText($entity);
      $query->keys($queryText);

      $query->range(0, $limit);

      $results = $query->execute();

      return $this->formatResults($results->getResultItems(), (int) $entity->id());
    }
    catch (\Exception $e) {
      \Drupal::logger('social_ai_indexing')->warning(
        'Related content search failed: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }
  }

  protected function extractQueryText(EntityInterface $entity): string {
    $text = $entity->label() ?? '';

    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $body = $entity->get('body')->value ?? '';
      $text .= ' ' . strip_tags($body);
    }

    return substr($text, 0, 500);
  }

  protected function formatResults(array $items, int $excludeNodeId = 0): array {
    $results = [];
    
    foreach ($items as $item) {
      $fields = $item->getFields();
      
      $title = '';
      if (isset($fields['citation_title'])) {
        $vals = $fields['citation_title']->getValues();
        $title = $this->extractString($vals[0] ?? '');
      }

      $url = '';
      if (isset($fields['citation_url'])) {
        $vals = $fields['citation_url']->getValues();
        $url = $this->extractString($vals[0] ?? '');
      }

      // Parse entity ID from item ID
      $entityId = null;
      $itemId = $item->getId();
      if (preg_match('/entity:node\/(\d+):/', $itemId, $m)) {
        $entityId = (int) $m[1];
      }

      // Generate URL if not in index
      if (empty($url) && $entityId) {
        $node = $this->entityTypeManager->getStorage('node')->load($entityId);
        if ($node) {
          try {
            $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
            if (empty($title)) {
              $title = $node->label();
            }
          } catch (\Exception $e) {
            // URL generation failed
          }
        }
      }

      if ($entityId && $entityId === $excludeNodeId) {
        continue;
      }

      $results[] = [
        'id' => $entityId,
        'title' => $title,
        'url' => $url,
        'score' => $item->getScore(),
      ];
    }

    return $results;
  }

  protected function extractString(mixed $value): string {
    if (is_string($value)) {
      return $value;
    }
    if (is_object($value) && method_exists($value, '__toString')) {
      return (string) $value;
    }
    if (is_object($value) && method_exists($value, 'getText')) {
      return $value->getText();
    }
    return '';
  }

}
