<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Indexes content visibility field for permission filtering.
 *
 * @SearchApiProcessor(
 *   id = "content_visibility",
 *   label = @Translation("Content Visibility"),
 *   description = @Translation("Indexes the content visibility field (public/community/group_content)"),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class ContentVisibility extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL): array {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Content Visibility'),
        'description' => $this->t('Visibility setting: public, community, or group_content'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['content_visibility'] = new ProcessorProperty($definition);
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

    // Only process node entities (comments don't have field_content_visibility).
    if ($entity->getEntityTypeId() !== 'node') {
      return;
    }

    // Check if entity has field_content_visibility field.
    if (!$entity->hasField('field_content_visibility')) {
      return;
    }

    $visibility_field = $entity->get('field_content_visibility');

    // Default to 'group_content' if field is empty (safest default).
    $visibility = 'group_content';
    if (!$visibility_field->isEmpty()) {
      $visibility = $visibility_field->value ?? 'group_content';
    }

    $fields = $item->getFields(FALSE);
    foreach ($fields as $field) {
      if ($field->getPropertyPath() === 'content_visibility') {
        $field->addValue($visibility);
      }
    }
  }

}
