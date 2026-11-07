<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;

/**
 * F-4: text inside files attached to a Topic reaches the file_content field.
 *
 * Runs the shipped social_posts index's file_content_extractor processor on
 * a Topic whose field_files holds one of the 22-04 import fixtures, stored
 * under a real (non-vfs) private:// directory, so the extractors read the
 * file's real path exactly as on a site.
 *
 * PDF extraction is smalot/pdfparser: the test image has no pdftotext or
 * pdftohtml on the PATH, so ai_file_to_text's Poppler extractor is not
 * available (manifest.yml pins `pdf_extractor: smalot`).
 *
 * @group phase22
 * @group social_ai_indexing
 *
 * @see .planning/phases/22-document-importer-cited-group-answers/22-10-PLAN.md
 */
final class FileContentExtractorTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_file_to_text'];

  /**
   * The 22-04 import fixture directory, relative to the Drupal root.
   */
  private const FIXTURE_DIR = 'modules/custom/localnodes_import/tests/fixtures/import/files';

  /**
   * Real on-disk directory behind private:// for this test run.
   */
  private string $privateDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // KernelTestBase puts public:// on vfsStream, where realpath() gives a
    // vfs:// URL and ZipArchive (DOCX, ODT) cannot open files. private://
    // gets a real directory. It must be set before the container is built,
    // or the private stream wrapper is never registered.
    $this->privateDirectory = sys_get_temp_dir() . '/phase22_f4_' . $this->databasePrefix;
    if (!is_dir($this->privateDirectory)) {
      mkdir($this->privateDirectory, 0775, TRUE);
    }
    $this->setSetting('file_private_path', $this->privateDirectory);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->privateDirectory) && is_dir($this->privateDirectory)) {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->privateDirectory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
      }
      rmdir($this->privateDirectory);
    }
    parent::tearDown();
  }

  /**
   * Fixture files: name, MIME type, expected phrase (NULL: no text).
   *
   * Phrases come from tests/fixtures/import/manifest.yml (22-04).
   */
  public static function attachmentProvider(): array {
    return [
      'pdf' => [
        'pdf-01-tool-share-rules.pdf',
        'application/pdf',
        'fixture-phrase heron-01',
      ],
      'docx' => [
        'volunteer-rota.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'fixture-phrase heron-14',
      ],
      'doc' => [
        'pond-cleanup-plan.doc',
        'application/msword',
        'fixture-phrase heron-20',
      ],
      'odt' => [
        'shared-kitchen-guidelines.odt',
        'application/vnd.oasis.opendocument.text',
        'fixture-phrase heron-23',
      ],
      'xlsx' => [
        'budget.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        NULL,
      ],
      'truncated pdf' => [
        'truncated-report.pdf',
        'application/pdf',
        NULL,
      ],
    ];
  }

  /**
   * The attached file's text is in file_content, or nothing and no error.
   *
   * @dataProvider attachmentProvider
   */
  public function testAttachedFileTextReachesFileContent(string $fixture, string $mime, ?string $phrase): void {
    $file = $this->createPrivateFile($fixture, $mime);
    $topic = $this->createTopicWithFile($file);
    $index = $this->createShippedIndex('social_posts', $this->createTestSearchServer());
    $this->assertShippedProcessorLoaded($index, 'file_content_extractor');
    $this->assertNotNull($index->getField('file_content'), 'Shipped social_posts index has the file_content field.');

    // The real extractor service is ai_file_to_text.extractor_manager; there
    // is no ai_file_to_text.extractor (the id F-4 looked up).
    $this->assertTrue($this->container->has('ai_file_to_text.extractor_manager'));
    $this->assertFalse($this->container->has('ai_file_to_text.extractor'));

    if ($phrase !== NULL) {
      // The fixture and the installed extractor are fine: the real service
      // reads the phrase from the file's real path. Only the processor can
      // be at fault when file_content lacks it.
      $real_path = $this->container->get('file_system')->realpath($file->getFileUri());
      $this->assertNotFalse($real_path, 'private:// resolves to a real path.');
      $this->assertStringNotContainsString('vfs://', (string) $real_path);
      $direct = $this->extractDirectly((string) $real_path, pathinfo($fixture, PATHINFO_EXTENSION));
      $this->assertStringContainsString($phrase, $direct, "ai_file_to_text.extractor_manager reads '$phrase' from $fixture directly.");
    }

    // Any \Throwable escaping the processor fails the test here.
    $values = $this->runFileContentExtractor($index, $topic);
    $text = implode("\n", array_map('strval', $values));

    if ($phrase === NULL) {
      $this->assertSame('', trim($text), "file_content stays empty for $fixture.");
      return;
    }
    $this->assertStringContainsString($phrase, $text, "F-4: file_content for the Topic with $fixture is missing its text '$phrase' (processor returned " . count($values) . ' value(s)).');
  }

  /**
   * A second index pass reads the stored text instead of parsing again.
   *
   * The stored text is swapped for a sentinel (same sha256): if the
   * processor parsed the PDF again, file_content would hold the phrase.
   */
  public function testSecondPassReusesStoredText(): void {
    $file = $this->createPrivateFile('pdf-01-tool-share-rules.pdf', 'application/pdf');
    $topic = $this->createTopicWithFile($file);
    $index = $this->createShippedIndex('social_posts', $this->createTestSearchServer());

    $first = implode("\n", $this->runFileContentExtractor($index, $topic));
    $this->assertStringContainsString('fixture-phrase heron-01', $first);

    $store = $this->container->get('keyvalue')->get('social_ai_indexing.file_text');
    $record = $store->get((string) $file->id());
    $this->assertIsArray($record, 'The first pass stored the text under the fid.');
    $real_path = (string) $this->container->get('file_system')->realpath($file->getFileUri());
    $this->assertSame(hash_file('sha256', $real_path), $record['sha256'], 'Stored with the file\'s sha256.');
    $this->assertStringContainsString('fixture-phrase heron-01', $record['text']);
    $this->assertIsInt($record['extracted_at']);

    $store->set((string) $file->id(), ['text' => 'STORE-HIT-SENTINEL'] + $record);
    $second = $this->runFileContentExtractor($index, $topic);
    $this->assertSame(['STORE-HIT-SENTINEL'], array_map('strval', $second), 'The second pass used the stored text; the extractor manager was not called.');

    // Text stored for other bytes (a different sha256) is not reused.
    $store->set((string) $file->id(), ['sha256' => str_repeat('0', 64)] + $record);
    $third = implode("\n", $this->runFileContentExtractor($index, $topic));
    $this->assertStringContainsString('fixture-phrase heron-01', $third, 'A sha256 mismatch re-extracts.');
  }

  /**
   * Deleting a file removes its stored text.
   */
  public function testDeletingFileRemovesStoredText(): void {
    $file = $this->createPrivateFile('volunteer-rota.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    $topic = $this->createTopicWithFile($file);
    $index = $this->createShippedIndex('social_posts', $this->createTestSearchServer());
    $this->runFileContentExtractor($index, $topic);

    $store = $this->container->get('keyvalue')->get('social_ai_indexing.file_text');
    $fid = (string) $file->id();
    $this->assertTrue($store->has($fid), 'Text was stored for the file.');

    $file->delete();
    $this->assertFalse($store->has($fid), 'No stored text remains for the deleted file.');
  }

  /**
   * Copies a fixture into private:// and saves a permanent file entity.
   */
  private function createPrivateFile(string $fixture, string $mime): FileInterface {
    $source = DRUPAL_ROOT . '/' . self::FIXTURE_DIR . '/' . $fixture;
    $this->assertFileExists($source);
    $uri = 'private://' . $fixture;
    $this->assertTrue(copy($source, $uri), "Copied $fixture into private://");
    $file = File::create([
      'uri' => $uri,
      'filename' => $fixture,
      'filemime' => $mime,
      'uid' => $this->siteOwner->id(),
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Creates a published Topic with the file in field_files.
   */
  private function createTopicWithFile(FileInterface $file): NodeInterface {
    $topic = Node::create([
      'type' => 'topic',
      'title' => 'Fictional attachment holder',
      'body' => ['value' => 'Fictional body with no fixture phrase.', 'format' => 'plain_text'],
      'field_content_visibility' => 'public',
      'field_files' => [['target_id' => $file->id(), 'display' => 1]],
      'uid' => $this->siteOwner->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $topic->save();
    return $topic;
  }

  /**
   * Runs the shipped file_content_extractor processor on a Topic.
   *
   * @return array
   *   The file_content field values.
   */
  private function runFileContentExtractor(IndexInterface $index, NodeInterface $topic): array {
    $item_id = 'entity:node/' . $topic->id() . ':' . $topic->language()->getId();
    $item = $this->container->get('search_api.fields_helper')
      ->createItemFromObject($index, $topic->getTypedData(), $item_id);
    $index->getProcessor('file_content_extractor')->addFieldValues($item);
    return $item->getField('file_content')->getValues();
  }

  /**
   * Extracts text with the installed ai_file_to_text manager.
   *
   * PhpWord's MsDoc reader raises ~56k warnings per .doc file (22-04), which
   * PHPUnit would turn into exceptions, so they are counted here.
   */
  private function extractDirectly(string $real_path, string $extension): string {
    set_error_handler(static fn (): bool => TRUE, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
    try {
      return (string) $this->container->get('ai_file_to_text.extractor_manager')
        ->extract($real_path, strtolower($extension), 'text');
    }
    finally {
      restore_error_handler();
    }
  }

}
