<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\search_api\processor;

use Drupal\comment\CommentInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds citation metadata (URL, title, type) to indexed items for AI responses.
 *
 * @SearchApiProcessor(
 *   id = "citation_metadata",
 *   label = @Translation("Citation Metadata"),
 *   description = @Translation("Adds citation URL, title, and type metadata for AI response citations."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class CitationMetadata extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL): array {
    $properties = [];

    if (!$datasource) {
      $properties['citation_url'] = new ProcessorProperty([
        'label' => $this->t('Citation URL'),
        'description' => $this->t('The canonical URL for the indexed item.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ]);

      $properties['citation_title'] = new ProcessorProperty([
        'label' => $this->t('Citation Title'),
        'description' => $this->t('The title of the indexed item.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ]);

      $properties['citation_type'] = new ProcessorProperty([
        'label' => $this->t('Citation Type'),
        'description' => $this->t('The content type or entity type of the indexed item.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ]);
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

    // For comments, link to the parent entity.
    if ($entity_type === 'comment' && $entity instanceof CommentInterface) {
      $parent_entity = $entity->getCommentedEntity();
      if ($parent_entity) {
        $this->addCitationFields($item, $parent_entity, 'comment');
        return;
      }
    }

    // For other entity types, use the entity directly.
    $this->addCitationFields($item, $entity, $entity->bundle() ?: $entity_type);
  }

  /**
   * Add citation fields to the index item.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The search item.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to get citation data from.
   * @param string $type
   *   The content type to use for citation.
   */
  protected function addCitationFields(ItemInterface $item, $entity, string $type): void {
    $fields = $item->getFields(FALSE);

    // Generate canonical URL.
    $url = NULL;
    try {
      if (method_exists($entity, 'toUrl')) {
        $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('social_ai_indexing')->warning(
        'Failed to generate URL for @type @id: @message',
        ['@type' => $entity->getEntityTypeId(), '@id' => $entity->id(), '@message' => $e->getMessage()]
      );
      return;
    }

    if (!$url) {
      return;
    }

    // Get title/label.
    $title = '';
    if (method_exists($entity, 'label')) {
      $title = $entity->label();
    }

    // Add values to fields.
    foreach ($fields as $field) {
      $property_path = $field->getPropertyPath();
      
      switch ($property_path) {
        case 'citation_url':
          $field->addValue($url);
          break;

        case 'citation_title':
          if ($title) {
            $field->addValue($title);
          }
          break;

        case 'citation_type':
          $field->addValue($type);
          break;
      }
    }
  }

}
