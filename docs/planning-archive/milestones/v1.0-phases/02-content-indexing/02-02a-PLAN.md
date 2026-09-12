---
phase: 02-content-indexing
plan: 02a
type: execute
wave: 2
depends_on: [02-01a]
files_modified: [modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/CommentParentContext.php]
autonomous: true
requirements: [IDX-02, IDX-04, IDX-05]
user_setup: []

must_haves:
  truths:
    - "CommentParentContext processor is registered with Search API"
    - "social_comments index exists with parent context fields"
    - "Comments are configured for indexing with Group metadata"
  artifacts:
    - path: "html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/CommentParentContext.php"
      provides: "Parent post context enrichment"
      contains: "class CommentParentContext"
    - path: "search_api.index.social_comments"
      provides: "Comment index configuration"
      contains: "social_comments"
  key_links:
    - from: "CommentParentContext processor"
      to: "parent node entity"
      via: "comment.entity_id"
      pattern: "parent.*title|parent.*body"
---

<objective>
Create a Search API processor for comment parent context and the comment index.

Purpose: Enable comment indexing where each comment chunk includes context from its parent post.
Output: CommentParentContext processor and social_comments index with parent context fields.

**Dependency on 02-01a:** Requires the GroupMetadata processor created in Plan 02-01a.
</objective>

<execution_context>
@/Users/proofoftom/.config/opencode/get-shit-done/workflows/execute-plan.md
@/Users/proofoftom/.config/opencode/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/REQUIREMENTS.md
@.planning/phases/02-content-indexing/02-RESEARCH.md
@.planning/phases/02-content-indexing/02-01a-SUMMARY.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create Comment Parent Context processor</name>
  <files>html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/CommentParentContext.php</files>
  <action>
Create a processor that enriches comment items with parent post context:

Create `html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/CommentParentContext.php`:
```php
<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\SearchApi\Processor;

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
    if ($entity->getEntityTypeId() === 'node' && $entity->hasField('title')) {
      return $entity->label();
    }
    return NULL;
  }

  /**
   * Get a summary from a parent entity's body.
   */
  protected function getParentSummary(EntityInterface $entity): ?string {
    if ($entity->getEntityTypeId() !== 'node') {
      return NULL;
    }

    $body_field = NULL;
    foreach (['body', 'field_post_body', 'field_body'] as $field_name) {
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
```

Clear cache to register the plugin:
```bash
cd html
drush cr
```
  </action>
  <verify>
    <automated>cd html && drush eval "echo class_exists('Drupal\\social_ai_indexing\\Plugin\\SearchApi\\Processor\\CommentParentContext') ? 'PASS: Processor class exists' : 'FAIL';"</automated>
    <manual>Processor class should exist and be discoverable</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>CommentParentContext processor created and registered with Search API</done>
</task>

<task type="auto">
  <name>Task 2: Create Search API index for comments</name>
  <files>N/A (database configuration)</files>
  <action>
Create the Search API index for comments with parent context fields:

```bash
cd html

drush eval "
\$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->create([
  'id' => 'social_comments',
  'name' => 'Social Comments',
  'description' => 'Vector index for Open Social comments with parent context',
  'read_only' => FALSE,
  'field_settings' => [
    'rendered_item' => [
      'label' => 'Comment body',
      'property_path' => 'rendered_item',
      'type' => 'text',
      'configuration' => [
        'view_mode' => [
          'entity:comment' => [
            'comment' => 'search_index',
          ],
        ],
      ],
    ],
    'subject' => [
      'label' => 'Subject',
      'datasource_id' => 'entity:comment',
      'property_path' => 'subject',
      'type' => 'string',
    ],
    'parent_post_title' => [
      'label' => 'Parent Post Title',
      'property_path' => 'parent_post_title',
      'type' => 'string',
    ],
    'parent_post_summary' => [
      'label' => 'Parent Post Summary',
      'property_path' => 'parent_post_summary',
      'type' => 'text',
    ],
    'group_id' => [
      'label' => 'Group ID',
      'property_path' => 'group_id',
      'type' => 'integer',
    ],
  ],
  'datasource_settings' => [
    'entity:comment' => [
      'bundles' => [
        'default' => TRUE,
        'selected' => [],
      ],
      'languages' => [
        'default' => TRUE,
        'selected' => [],
      ],
    ],
  ],
  'processor_settings' => [
    'add_url' => [],
    'rendered_item' => [],
    'comment_parent_context' => [
      'weights' => ['add_properties' => 0],
    ],
    'group_metadata' => [
      'weights' => ['add_properties' => 5],
    ],
  ],
  'tracker_settings' => [
    'default' => ['indexing_order' => 'fifo'],
  ],
  'options' => [
    'index_directly' => TRUE,
    'cron_limit' => 50,
  ],
  'server' => 'ai_knowledge_garden',
  'status' => TRUE,
]);
\$index->save();
echo 'Created social_comments index';
"
```

Verify the index:
```bash
drush search-api:list
```
  </action>
  <verify>
    <automated>cd html && drush eval "print_r(array_keys(\Drupal::entityTypeManager()->getStorage('search_api_index')->loadMultiple()));" | grep social_comments</automated>
    <manual>social_comments index should exist</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>social_comments Search API index created with parent context fields</done>
</task>

</tasks>

<verification>
## Phase 2 Plan 02a Verification

1. **Comment Parent Context Processor:**
   - [ ] CommentParentContext.php exists
   - [ ] Processor registered with Search API
   - [ ] Extracts parent title and summary

2. **Search API Index:**
   - [ ] social_comments index exists
   - [ ] Parent context fields configured
   - [ ] Group ID field configured
</verification>

<success_criteria>
1. CommentParentContext processor registered and functional
2. social_comments index created with parent context fields
</success_criteria>

<output>
After completion, create `.planning/phases/02-content-indexing/02-02a-SUMMARY.md` with:
- CommentParentContext processor implementation
- Index configuration details
- Any issues encountered
</output>
