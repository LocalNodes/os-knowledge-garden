<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\file\Entity\File;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Controller\RunReportController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * E7: the 25-file manifest set in one run gives exactly the manifest report.
 *
 * Every fixture is placed in the group's private folder as a temporary file
 * entity with a queued item row, the way UploadFilesForm leaves them, and the
 * batch operation runs once per item in manifest order as a group manager.
 *
 * @group phase22
 * @group localnodes_import
 */
final class ImportManifestTest extends ImportKernelTestBase {

  use IndexFixtureTrait;

  /**
   * The fixture folder (22-04).
   */
  private const FIXTURES = __DIR__ . '/../../fixtures/import';

  /**
   * Manifest 'expect' values as item outcome codes.
   */
  private const OUTCOMES = [
    'imported' => ImportItem::OUTCOME_IMPORTED,
    'not_searchable' => ImportItem::OUTCOME_NOT_SEARCHABLE,
    'duplicate' => ImportItem::OUTCOME_DUPLICATE,
    'failed' => ImportItem::OUTCOME_FAILED,
    'refused' => ImportItem::OUTCOME_REFUSED,
  ];

  /**
   * Log records written during the test.
   */
  private LoggerInterface $spy;

  /**
   * File ids by item id, as queued (the duplicate's file is deleted).
   *
   * @var int[]
   */
  private array $fids = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->spy = new class() implements LoggerInterface {
      use RfcLoggerTrait;

      /**
       * Every record: [level, message, context].
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = [$level, (string) $message, $context];
      }

    };
    $this->container->get('logger.factory')->addLogger($this->spy);
  }

  /**
   * One run over all 25 files: outcome, reason and title per row, totals.
   */
  public function testManifestRun(): void {
    $manifest = Yaml::decode((string) file_get_contents(self::FIXTURES . '/manifest.yml'));
    $server = $this->createTestSearchServer();
    $this->createShippedIndex('social_posts', $server);
    $this->createShippedIndex('social_comments', $server);

    $group = $this->createFlexibleGroup('Garden group');
    $organizer = $this->createVerifiedUser('garden_organizer');
    $this->addManager($group, $organizer);
    $this->setCurrentUser($organizer);

    [$run, $item_ids] = $this->queueRun($group, $organizer, array_column($manifest['files'], 'file'));
    $context = [];
    foreach ($item_ids as $item_id) {
      ImportBatch::importItem($item_id, $context);
    }
    ImportBatch::finished(TRUE, $context['results'], [], '1 sec');

    $entity_type_manager = $this->container->get('entity_type.manager');
    $item_storage = $entity_type_manager->getStorage('localnodes_import_item');
    $node_storage = $entity_type_manager->getStorage('node');
    $item_storage->resetCache();
    /** @var \Drupal\localnodes_import\Entity\ImportItem[] $items */
    $items = array_values($item_storage->loadMultiple($item_ids));
    $this->assertCount(25, $items, 'One item row per submitted file');

    // Each row's outcome, reason and Topic title, against the manifest.
    $expected = [];
    $actual = [];
    foreach ($manifest['files'] as $i => $row) {
      $item = $items[$i];
      $nid = $item->get('nid')->target_id;
      $node = $nid ? $node_storage->load($nid) : NULL;
      $expected[$row['file']] = [
        'outcome' => self::OUTCOMES[$row['expect']],
        'reason' => $row['reason'],
        'title' => $row['title'],
      ];
      $actual[$row['file']] = [
        'outcome' => $item->get('outcome')->value,
        'reason' => $item->get('reason')->value,
        'title' => $node?->label(),
      ];
    }
    $this->assertSame($expected, $actual, 'Every file has exactly the manifest outcome, reason and title');

    // Totals: 21 imported (20 searchable), 1 duplicate, 2 failed, 1 refused.
    $outcomes = array_count_values(array_column($actual, 'outcome'));
    $totals = $manifest['totals'];
    $this->assertSame($totals['searchable'], $outcomes[ImportItem::OUTCOME_IMPORTED] ?? 0, 'Searchable');
    $this->assertSame($totals['not_searchable'], $outcomes[ImportItem::OUTCOME_NOT_SEARCHABLE] ?? 0, 'Not searchable');
    $this->assertSame($totals['imported'], ($outcomes[ImportItem::OUTCOME_IMPORTED] ?? 0) + ($outcomes[ImportItem::OUTCOME_NOT_SEARCHABLE] ?? 0), 'Imported');
    $this->assertSame($totals['duplicate'], $outcomes[ImportItem::OUTCOME_DUPLICATE] ?? 0, 'Duplicate');
    $this->assertSame($totals['failed'], $outcomes[ImportItem::OUTCOME_FAILED] ?? 0, 'Failed');
    $this->assertSame($totals['refused'], $outcomes[ImportItem::OUTCOME_REFUSED] ?? 0, 'Refused');
    $this->assertCount(21, $group->getRelationships('group_node:topic'), 'One Topic per imported file, in the group');

    $by_file = array_combine(array_column($manifest['files'], 'file'), $items);
    $run = $entity_type_manager->getStorage('localnodes_import_run')->load($run->id());
    $this->assertSame(ImportRun::STATUS_FINISHED, $run->get('status')->value);

    // Every item past the type check records the bytes it was judged on
    // (Pattern 8); a refused type is turned away before any work.
    foreach ($by_file as $file_name => $item) {
      if ($item->get('outcome')->value === ImportItem::OUTCOME_REFUSED) {
        $this->assertNull($item->get('content_hash')->value, "$file_name: refused unread");
        continue;
      }
      $this->assertSame(hash_file('sha256', self::FIXTURES . '/files/' . $file_name), $item->get('content_hash')->value, "$file_name: content hash stored");
    }

    // D-15: the copy links the original Topic, and its temporary is gone.
    $original = $by_file['pdf-03-rainwater-barrels.pdf'];
    $copy = $by_file['copy-of-pdf-03.pdf'];
    $this->assertSame($original->get('nid')->target_id, $copy->get('nid')->target_id, 'The duplicate links the existing Topic');
    $copy_fid = $this->fids[(int) $copy->id()];
    $this->assertNull(File::load($copy_fid), "The duplicate's temporary file entity is deleted");
    $this->assertFileDoesNotExist($this->privateDirectory . '/localnodes_import/' . $group->id() . '/copy-of-pdf-03_0.pdf', "The duplicate's bytes are deleted");

    // Attached files became permanent through the Topic; the text is stored
    // once (FileTextStore), so indexing reuses it.
    $store = $this->container->get('social_ai_indexing.file_text_store');
    foreach ($manifest['files'] as $row) {
      if ($row['phrase'] === NULL) {
        continue;
      }
      $node = $node_storage->load($by_file[$row['file']]->get('nid')->target_id);
      $file = $node->get('field_files')->entity;
      $this->assertTrue($file->isPermanent(), $row['file'] . ': attached file is permanent');
      $text = $store->get($file);
      $this->assertIsString($text, $row['file'] . ': text stored at import');
      $this->assertStringContainsString($row['phrase'], $text, $row['file'] . ': stored text holds the phrase');
    }

    // One report row per submitted file.
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(RunReportController::class);
    $build = $controller->view($group, $run);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertSame(25, substr_count($html, 'localnodes-import-report__row '), 'One report row per file');

    // The same file into a different group imports normally (D-15).
    $other = $this->createFlexibleGroup('Orchard group');
    $this->addManager($other, $organizer);
    [, $other_ids] = $this->queueRun($other, $organizer, ['pdf-03-rainwater-barrels.pdf']);
    $other_context = [];
    ImportBatch::importItem($other_ids[0], $other_context);
    $item_storage->resetCache();
    $other_item = $item_storage->load($other_ids[0]);
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $other_item->get('outcome')->value, 'Same file, other group: imported');
    $this->assertNotSame($original->get('nid')->target_id, $other_item->get('nid')->target_id, 'A new Topic in the other group');
    $this->assertCount(1, $other->getRelationships('group_node:topic'));
    $this->assertSame('Rainwater Barrel Guide', $node_storage->load($other_item->get('nid')->target_id)->label());

    // T-22-34: no extracted text in any log record.
    $this->assertNotEmpty($this->spy->records, 'The spy logger saw the import (warning counts, failures)');
    foreach ($this->spy->records as [$level, $message, $log_context]) {
      $logged = $message . ' ' . json_encode($log_context, JSON_PARTIAL_OUTPUT_ON_ERROR);
      $this->assertStringNotContainsString('fixture-phrase', $logged, 'No fixture phrase in the logs');
      $this->assertStringNotContainsString('heron-', $logged, 'No fixture text in the logs');
    }
  }

  /**
   * Places files in the group folder and queues one item per file.
   *
   * @return array
   *   [the run, the item ids in submitted order].
   */
  private function queueRun(GroupInterface $group, UserInterface $owner, array $file_names): array {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $run = $entity_type_manager->getStorage('localnodes_import_run')->create([
      'gid' => $group->id(),
      'uid' => $owner->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => 'group',
    ]);
    $run->save();

    $folder = 'private://localnodes_import/' . $group->id();
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    $ids = [];
    foreach (array_values($file_names) as $weight => $name) {
      // Stored under a renamed name, as core renames an upload whose name is
      // taken (notes_0.pdf): file-name titles must come from source_name.
      $stored = (string) preg_replace('/(\.[^.]+)$/', '_0$1', $name);
      $this->assertTrue(copy(self::FIXTURES . '/files/' . $name, $file_system->realpath($folder) . '/' . $stored));
      // Temporary, as file_save_upload() leaves an upload.
      $file = File::create(['uri' => $folder . '/' . $stored, 'filename' => $stored, 'uid' => $owner->id(), 'status' => 0]);
      $file->save();
      $item = $entity_type_manager->getStorage('localnodes_import_item')->create([
        'run' => $run->id(),
        'gid' => $group->id(),
        'weight' => $weight,
        'source_name' => $name,
        'fid' => $file->id(),
        'outcome' => ImportItem::OUTCOME_QUEUED,
      ]);
      $item->save();
      $ids[] = (int) $item->id();
      $this->fids[$item->id()] = (int) $file->id();
    }
    return [$run, $ids];
  }

}
