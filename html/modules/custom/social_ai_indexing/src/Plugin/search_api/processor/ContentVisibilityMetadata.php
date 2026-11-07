<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\search_api\processor;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds the post's visibility to indexed items for permission filtering.
 *
 * Comments have no field_content_visibility of their own; who may see a
 * comment follows the post it is on. Without this value on the
 * social_comments index, the vector DB drops the visibility condition
 * silently (QdrantProvider::convertConditionToQdrant()) and a comment under
 * a members-only post reaches non-members (F-2, D-18).
 *
 * For a comment the value is the commented entity's
 * field_content_visibility; for a node it is the node's own. An entity with
 * no such field (for example a comment on a Post entity, which uses
 * field_visibility) gets no value, so every visibility condition excludes
 * it: the AI search fails closed for it.
 *
 * @SearchApiProcessor(
 *   id = "content_visibility_metadata",
 *   label = @Translation("Content visibility metadata"),
 *   description = @Translation("Adds field_content_visibility from the post (or the comment's parent post) for permission filtering."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class ContentVisibilityMetadata extends ProcessorPluginBase {

  /**
   * The processor property name.
   */
  public const PROPERTY = 'parent_content_visibility';

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Content visibility (from the post)'),
        'description' => $this->t('field_content_visibility of the post, or of the post a comment is on.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties[self::PROPERTY] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $entity = $item->getOriginalObject()->getValue();

    $host = $entity instanceof CommentInterface ? $entity->getCommentedEntity() : $entity;
    if (!$host instanceof FieldableEntityInterface || !$host->hasField('field_content_visibility')) {
      return;
    }

    $value = $host->get('field_content_visibility')->value;
    if (!is_string($value) || $value === '') {
      return;
    }

    $fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(FALSE), NULL, self::PROPERTY);
    foreach ($fields as $field) {
      $field->addValue($value);
    }
  }

}
