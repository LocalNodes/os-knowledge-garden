---
phase: 02-content-indexing
plan: 01a
type: execute
wave: 1
depends_on: []
files_modified: [modules/custom/social_ai_indexing/social_ai_indexing.info.yml, modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/GroupMetadata.php]
autonomous: true
requirements: [IDX-01, IDX-04, IDX-05]
user_setup: []

must_haves:
  truths:
    - "social_ai_indexing custom module is enabled"
    - "GroupMetadata processor is registered with Search API"
    - "Processor can extract Group IDs from group_content relationships"
  artifacts:
    - path: "html/modules/custom/social_ai_indexing/social_ai_indexing.info.yml"
      provides: "Module definition"
      contains: "name: 'Social AI Indexing'"
    - path: "html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/GroupMetadata.php"
      provides: "Group ID metadata extraction"
      contains: "class GroupMetadata"
  key_links:
    - from: "GroupMetadata processor"
      to: "group_content entities"
      via: "EntityQuery on group_content"
      pattern: "group_content.*entity_id"
---

<objective>
Create the foundation for content indexing: a custom module and Group metadata Search API processor.

Purpose: Establish the module infrastructure and Group ID extraction capability required for all subsequent indexing.
Output: Enabled social_ai_indexing module with working GroupMetadata processor.

**Dependency on Phase 1:** Assumes Ollama (nomic-embed-text, 768 dimensions) and Milvus are configured via ai_search module.
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
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create social_ai_indexing custom module</name>
  <files>html/modules/custom/social_ai_indexing/social_ai_indexing.info.yml</files>
  <action>
Create the custom module directory and info file:

```bash
mkdir -p html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor
```

Create `html/modules/custom/social_ai_indexing/social_ai_indexing.info.yml`:
```yaml
name: 'Social AI Indexing'
type: module
description: 'Custom indexing configuration for Open Social AI Knowledge Garden'
core_version_requirement: ^10 || ^11
package: 'Open Social AI'
dependencies:
  - search_api:search_api
  - group:group
  - ai_search:ai_search
```

Enable the module:
```bash
cd html
drush en social_ai_indexing -y
```
  </action>
  <verify>
    <automated>cd html && drush pm-list --type=module --status=enabled --pipe | grep social_ai_indexing && echo "PASS: Module enabled" || echo "FAIL: Module not enabled"</automated>
    <manual>Module should appear in enabled list</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>social_ai_indexing module created and enabled</done>
</task>

<task type="auto">
  <name>Task 2: Create Group Metadata Search API processor</name>
  <files>html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/GroupMetadata.php</files>
  <action>
Create the custom Search API processor that extracts Group ID from group_content relationships:

Create `html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/GroupMetadata.php`:
```php
<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\SearchApi\Processor;

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
```

Clear cache to register the plugin:
```bash
cd html
drush cr
```
  </action>
  <verify>
    <automated>cd html && drush eval "echo class_exists('Drupal\\social_ai_indexing\\Plugin\\SearchApi\\Processor\\GroupMetadata') ? 'PASS: Processor class exists' : 'FAIL';"</automated>
    <manual>Processor class should exist and be discoverable</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>GroupMetadata processor created and registered with Search API</done>
</task>

</tasks>

<verification>
## Phase 2 Plan 01a Verification

1. **Module Creation:**
   - [ ] social_ai_indexing module exists in html/modules/custom/
   - [ ] Module enabled and listed in `drush pm-list`

2. **Group Metadata Processor:**
   - [ ] GroupMetadata.php exists with correct namespace
   - [ ] Processor registered with Search API
   - [ ] `drush eval` confirms processor exists
</verification>

<success_criteria>
1. social_ai_indexing module created and enabled
2. GroupMetadata processor registered and functional
</success_criteria>

<output>
After completion, create `.planning/phases/02-content-indexing/02-01a-SUMMARY.md` with:
- Module creation details
- GroupMetadata processor implementation notes
- Any issues encountered
</output>
