<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Plugin\search_api\processor;

use Drupal\ai_file_to_text\Extractor\FileExtractorManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\social_ai_indexing\Service\FileTextStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts text content from file fields for indexing.
 *
 * Text comes from the social_ai_indexing.file_text_store first (the importer
 * stores it once at import). On a miss it is extracted with
 * ai_file_to_text.extractor_manager from the file's real path and stored, so
 * a reindex never parses the same bytes twice. Which files are read follows
 * the installed extractors (FileExtractorManager::isSupported()), not a
 * MIME list. Without ai_file_to_text, nothing is extracted.
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
   * PHP notice and warning levels a parser may raise (counted, not logged).
   */
  protected const PARSER_NOTICE_LEVELS = E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;

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
   * Constructs a FileContentExtractor.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\social_ai_indexing\Service\FileTextStore $fileTextStore
   *   The extracted-text store.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system (stream wrapper aware realpath()).
   * @param \Psr\Log\LoggerInterface $logger
   *   The social_ai_indexing logger.
   * @param \Drupal\ai_file_to_text\Extractor\FileExtractorManager|null $extractorManager
   *   The ai_file_to_text extractor manager, or NULL when ai_file_to_text
   *   is not installed.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected FileTextStore $fileTextStore,
    protected FileSystemInterface $fileSystem,
    protected LoggerInterface $logger,
    protected ?FileExtractorManager $extractorManager = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $processor = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('social_ai_indexing.file_text_store'),
      $container->get('file_system'),
      $container->get('logger.factory')->get('social_ai_indexing'),
      $container->has('ai_file_to_text.extractor_manager') ? $container->get('ai_file_to_text.extractor_manager') : NULL,
    );
    $processor->setStringTranslation($container->get('string_translation'));
    $processor->setFieldsHelper($container->get('search_api.fields_helper'));
    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
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
      if ($content !== NULL && $content !== '') {
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
        if ($referenced instanceof FileInterface && $this->isSupportedFileType($referenced)) {
          $files[] = $referenced;
        }
      }
    }

    return $files;
  }

  /**
   * Whether an installed extractor reads this file's extension.
   */
  protected function isSupportedFileType(FileInterface $file): bool {
    return $this->extractorManager !== NULL
      && $this->extractorManager->isSupported($this->getExtension($file));
  }

  /**
   * The file's extension, lowercase, without the dot.
   */
  protected function getExtension(FileInterface $file): string {
    $name = (string) $file->getFilename();
    if ($name === '') {
      $name = (string) $file->getFileUri();
    }
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
  }

  /**
   * Returns a file's text: stored text first, else extract once and store.
   *
   * @return string|null
   *   The text, or NULL when the file has none or can't be read. Never
   *   throws: a parser failure of any kind leaves the file without text.
   */
  protected function extractFileContent(FileInterface $file): ?string {
    if (!$this->isSupportedFileType($file)) {
      return NULL;
    }

    $stored = $this->fileTextStore->get($file);
    if ($stored !== NULL) {
      return $stored === '' ? NULL : $stored;
    }

    $real_path = $this->fileSystem->realpath($file->getFileUri());
    if ($real_path === FALSE || $real_path === '' || !is_file($real_path)) {
      return NULL;
    }

    $text = '';
    $notices = 0;
    // PhpWord's MsDoc reader raises tens of thousands of warnings per legacy
    // .doc file. Count them here so they reach neither the watchdog nor a
    // test's error handler, and log the count once.
    set_error_handler(static function () use (&$notices): bool {
      $notices++;
      return TRUE;
    }, self::PARSER_NOTICE_LEVELS);
    try {
      $text = trim((string) $this->extractorManager->extract($real_path, $this->getExtension($file), 'text'));
    }
    catch (\Throwable $e) {
      // File id and exception class only: the message may quote file text.
      $this->logger->warning('Text extraction failed for file @fid (@class).', [
        '@fid' => $file->id(),
        '@class' => get_class($e),
      ]);
      $text = '';
    }
    finally {
      restore_error_handler();
    }

    if ($notices > 0) {
      $this->logger->notice('Text extraction for file @fid raised @count PHP notices or warnings (suppressed).', [
        '@fid' => $file->id(),
        '@count' => $notices,
      ]);
    }

    // Stored even when empty, so an unreadable or hostile file is not parsed
    // again on every reindex.
    $this->fileTextStore->put($file, $text);

    return $text === '' ? NULL : $text;
  }

}
