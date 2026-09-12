---
phase: 02-content-indexing
plan: 03b
type: execute
wave: 2
depends_on: [02-01a, 02-03a]
files_modified: [modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/FileContentExtractor.php]
autonomous: true
requirements: [IDX-03, IDX-06]
user_setup: []

must_haves:
  truths:
    - "FileContentExtractor processor is registered with Search API"
    - "social_posts index has file_content field configured"
    - "File content extraction is integrated with indexing pipeline"
  artifacts:
    - path: "html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/FileContentExtractor.php"
      provides: "File content extraction for indexing"
      contains: "class FileContentExtractor"
  key_links:
    - from: "FileContentExtractor processor"
      to: "ai_file_to_text service"
      via: "file content extraction"
      pattern: "extractText\(\)"
---

<objective>
Create the FileContentExtractor processor and add file content field to post index.

Purpose: Enable indexing of file uploads (PDFs, Office docs) alongside post content.
Output: FileContentExtractor processor and updated social_posts index with file_content field.

**Dependencies:**
- 02-01a: Requires GroupMetadata processor infrastructure
- 02-03a: Requires ai_file_to_text module for file parsing
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
@.planning/phases/02-content-indexing/02-03a-SUMMARY.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Create File Content Extractor processor</name>
  <files>html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/FileContentExtractor.php</files>
  <action>
Create a processor that extracts text content from file fields:

Create `html/modules/custom/social_ai_indexing/src/Plugin/SearchApi/Processor/FileContentExtractor.php`:
```php
<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\SearchApi\Processor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Extracts text content from file fields for indexing.
 *
 * @SearchApiProcessor(
 *   id = "file_content_extractor",
 *   label = @Translation("File Content Extractor"),
 *   description = @Translation("Extracts text from PDFs and Office documents for indexing."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class FileContentExtractor extends ProcessorPluginBase {

  /**
   * Supported MIME types for extraction.
   */
  const SUPPORTED_MIME_TYPES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  ];

  /**
   * File fields to check on entities.
   */
  const FILE_FIELD_NAMES = [
    'field_files',
    'field_attachments',
    'field_document',
    'field_media',
  ];

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL): array {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('File Content'),
        'description' => $this->t('Extracted text content from attached files.'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['file_content'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $entity = $item->getOriginalObject()->getValue();

    if (!$entity instanceof EntityInterface) {
      return;
    }

    $files = $this->getAttachedFiles($entity);
    if (empty($files)) {
      return;
    }

    $extracted_content = [];
    foreach ($files as $file) {
      $content = $this->extractFileContent($file);
      if (!empty($content)) {
        $extracted_content[] = $content;
      }
    }

    if (empty($extracted_content)) {
      return;
    }

    $fields = $item->getFields(FALSE);
    foreach ($fields as $field) {
      if ($field->getPropertyPath() === 'file_content') {
        foreach ($extracted_content as $content) {
          $field->addValue($content);
        }
      }
    }
  }

  /**
   * Get attached files from an entity.
   *
   * @return \Drupal\file\FileInterface[]
   *   Array of file entities.
   */
  protected function getAttachedFiles(EntityInterface $entity): array {
    $files = [];

    foreach (self::FILE_FIELD_NAMES as $field_name) {
      if (!$entity->hasField($field_name)) {
        continue;
      }

      $field = $entity->get($field_name);
      if ($field->isEmpty()) {
        continue;
      }

      foreach ($field->referencedEntities() as $referenced) {
        if ($referenced instanceof FileInterface) {
          if ($this->isSupportedFileType($referenced)) {
            $files[] = $referenced;
          }
        }
      }
    }

    return $files;
  }

  /**
   * Check if file type is supported for extraction.
   */
  protected function isSupportedFileType(FileInterface $file): bool {
    $mime_type = $file->getMimeType();
    return in_array($mime_type, self::SUPPORTED_MIME_TYPES);
  }

  /**
   * Extract text content from a file.
   */
  protected function extractFileContent(FileInterface $file): ?string {
    if (!$this->isSupportedFileType($file)) {
      return NULL;
    }

    try {
      if (\Drupal::hasService('ai_file_to_text.extractor')) {
        $extractor = \Drupal::service('ai_file_to_text.extractor');
        $content = $extractor->extract($file);
        return $content;
      }

      $uri = $file->getFileUri();
      $real_path = \Drupal::service('file_system')->realpath($uri);
      
      if (!file_exists($real_path)) {
        return NULL;
      }

      return NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('social_ai_indexing')->warning(
        'Failed to extract content from file @fid: @message',
        ['@fid' => $file->id(), '@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

}
```

Clear cache:
```bash
cd html
drush cr
```
  </action>
  <verify>
    <automated>cd html && drush eval "echo class_exists('Drupal\\social_ai_indexing\\Plugin\\SearchApi\\Processor\\FileContentExtractor') ? 'PASS: Processor class exists' : 'FAIL';"</automated>
    <manual>Processor class should exist</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>FileContentExtractor processor created for PDF and Office document text extraction</done>
</task>

<task type="auto">
  <name>Task 2: Add file content field to post index</name>
  <files>N/A (database configuration)</files>
  <action>
Add the file_content field to the social_posts index:

```bash
cd html

# Load and update the index
drush eval "
\$index = \Drupal::entityTypeManager()->getStorage('search_api_index')->load('social_posts');
if (\$index) {
  // Add file_content field
  \$field_settings = \$index->get('field_settings');
  \$field_settings['file_content'] = [
    'label' => 'File Content',
    'property_path' => 'file_content',
    'type' => 'text',
  ];
  \$index->set('field_settings', \$field_settings);
  
  // Add processor
  \$processor_settings = \$index->get('processor_settings');
  \$processor_settings['file_content_extractor'] = [
    'weights' => ['add_properties' => 0],
  ];
  \$index->set('processor_settings', \$processor_settings);
  
  \$index->save();
  echo 'Updated social_posts index with file_content field';
}
"
```

Verify the update:
```bash
drush config:get search_api.index.social_posts field_settings | grep file_content
```
  </action>
  <verify>
    <automated>cd html && drush config:get search_api.index.social_posts field_settings | grep file_content</automated>
    <manual>file_content field should appear in index field settings</manual>
    <sampling_rate>run after this task commits, before next task begins</sampling_rate>
  </verify>
  <done>social_posts index updated with file_content field for document indexing</done>
</task>

</tasks>

<verification>
## Phase 2 Plan 03b Verification

1. **File Content Extractor:**
   - [ ] FileContentExtractor.php exists
   - [ ] Processor registered with Search API
   - [ ] Supported MIME types configured

2. **Index Configuration:**
   - [ ] social_posts has file_content field
   - [ ] file_content_extractor processor enabled
</verification>

<success_criteria>
1. FileContentExtractor processor registered and functional
2. social_posts index updated with file_content field
</success_criteria>

<output>
After completion, create `.planning/phases/02-content-indexing/02-03b-SUMMARY.md` with:
- FileContentExtractor processor implementation
- File content field configuration
- Any issues encountered
</output>
