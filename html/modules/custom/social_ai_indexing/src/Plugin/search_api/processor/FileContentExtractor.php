<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\search_api\processor;

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
