<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\search_api\processor;

use Drupal\comment\CommentInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds parent post context to comments for better retrieval.
 *
 * @SearchApiProcessor(
 *   id = "comment_parent_context",
 *   label = @Translation("Comment Parent Context"),
 *   description = @Translation("Adds parent post title and summary to comments for context-aware retrieval."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class CommentParentContext extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL): array {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Parent Post Title'),
        'description' => $this->t('The title of the parent post for context.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['parent_post_title'] = new ProcessorProperty($definition);

      $definition = [
        'label' => $this->t('Parent Post Summary'),
        'description' => $this->t('A summary of the parent post body for context.'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['parent_post_summary'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $entity = $item->getOriginalObject()->getValue();

    if (!$entity instanceof CommentInterface) {
      return;
    }

    $parent = $this->getParentEntity($entity);
    if (!$parent) {
      return;
    }

    $parent_title = $this->getParentTitle($parent);
    $parent_summary = $this->getParentSummary($parent);

    $fields = $item->getFields(FALSE);
    foreach ($fields as $field) {
      $property_path = $field->getPropertyPath();
      
      if ($property_path === 'parent_post_title' && $parent_title) {
        $field->addValue($parent_title);
      }
      
      if ($property_path === 'parent_post_summary' && $parent_summary) {
        $field->addValue($parent_summary);
      }
    }
  }

  /**
   * Get the parent entity for a comment.
   */
  protected function getParentEntity(CommentInterface $comment): ?EntityInterface {
    try {
      $parent_type = $comment->getCommentedEntityTypeId();
      $parent_id = $comment->getCommentedEntityId();
      
      if (!$parent_type || !$parent_id) {
        return NULL;
      }

      return \Drupal::entityTypeManager()
        ->getStorage($parent_type)
        ->load($parent_id);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Get the title from a parent entity.
   */
  protected function getParentTitle(EntityInterface $entity): ?string {
    // Nodes have a title via label().
    $label = $entity->label();
    if ($label) {
      return $label;
    }
    // Posts use field_post as their main content — truncate for a title.
    if ($entity->hasField('field_post') && !$entity->get('field_post')->isEmpty()) {
      $text = strip_tags($entity->get('field_post')->value);
      $text = trim($text);
      return strlen($text) > 80 ? substr($text, 0, 77) . '...' : $text;
    }
    return NULL;
  }

  /**
   * Get a summary from a parent entity's body.
   */
  protected function getParentSummary(EntityInterface $entity): ?string {
    $body_field = NULL;
    foreach (['body', 'field_post_body', 'field_body', 'field_post'] as $field_name) {
      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        $body_field = $entity->get($field_name);
        break;
      }
    }

    if (!$body_field) {
      return NULL;
    }

    $body_value = $body_field->value ?? '';
    if (empty($body_value)) {
      return NULL;
    }

    $summary = text_summary($body_value, 'basic_html', 200);
    return $summary ?: NULL;
  }

}
