<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\search_api\processor;

use Drupal\comment\CommentInterface;
use Drupal\group\Entity\GroupContent;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds Group ID metadata to indexed items for permission filtering.
 *
 * @SearchApiProcessor(
 *   id = "group_metadata",
 *   label = @Translation("Group Metadata"),
 *   description = @Translation("Adds Group ID from group_content relationships for permission filtering."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class GroupMetadata extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL): array {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Group ID'),
        'description' => $this->t('The ID of the group this content belongs to.'),
        'type' => 'integer',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['group_id'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $entity = $item->getOriginalObject()->getValue();

    if (!$entity || !method_exists($entity, 'getEntityTypeId')) {
      return;
    }

    $entity_type = $entity->getEntityTypeId();
    $entity_id = $entity->id();

    if (!$entity_id) {
      return;
    }

    // For comments, get group from parent entity.
    if ($entity_type === 'comment' && $entity instanceof CommentInterface) {
      $parent_type = $entity->getCommentedEntityTypeId();
      $parent_id = $entity->getCommentedEntityId();
      if ($parent_type && $parent_id) {
        $entity_type = $parent_type;
        $entity_id = $parent_id;
      }
    }

    $group_ids = $this->getGroupIdsForEntity($entity_type, (int) $entity_id);

    if (empty($group_ids)) {
      return;
    }

    $fields = $item->getFields(FALSE);
    foreach ($fields as $field) {
      if ($field->getPropertyPath() === 'group_id') {
        foreach ($group_ids as $group_id) {
          $field->addValue((int) $group_id);
        }
      }
    }
  }

  /**
   * Get Group IDs for an entity via group_content relationships.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return array
   *   Array of Group IDs.
   */
  protected function getGroupIdsForEntity(string $entity_type, int $entity_id): array {
    $group_ids = [];

    try {
      $storage = \Drupal::entityTypeManager()->getStorage('group_content');
      
      $query = $storage->getQuery()
        ->condition('entity_id', $entity_id)
        ->accessCheck(FALSE);

      $group_content_ids = $query->execute();

      if (empty($group_content_ids)) {
        return $group_ids;
      }

      $group_contents = $storage->loadMultiple($group_content_ids);

      foreach ($group_contents as $group_content) {
        if ($group_content instanceof GroupContent) {
          $gid = $group_content->get('gid')->target_id;
          if ($gid) {
            $group_ids[] = (int) $gid;
          }
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('social_ai_indexing')->warning(
        'Failed to get Group IDs for @type @id: @message',
        ['@type' => $entity_type, '@id' => $entity_id, '@message' => $e->getMessage()]
      );
    }

    return array_unique($group_ids);
  }

}
