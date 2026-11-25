<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Export\ConversationRows;
use Drupal\localnodes_platform\Export\SiteExporter;

/**
 * Files used only in direct messages, and temporary files, stay out of the
 * copy, however the export is run, and also without a write pause.
 *
 * @group localnodes_platform
 */
final class ExportMessageFilesTest extends KernelTestBase {

  use ExportMessageFilesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'key', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * A message-only file stored under a path with "./" in it, or with a
   * leading backslash, stays out, as one stored under its plain path does.
   */
  public function testDotSegmentPathStaysOut(): void {
    $this->messageFile('private://inline-images/plain-word.txt', 'Larchmere-Plain-1201', 1);
    $this->messageFile('private://inline-images/./dotted-word.txt', 'Sedgebrook-Dotted-1302', 1);
    $this->messageFile('private://\\inline-images/slashed-word.txt', 'Ashcombe-Backslash-1706', 1);
    $text = $this->archiveText($this->dumper());
    $this->assertStringNotContainsString('Larchmere-Plain-1201', $text, 'The plainly stored message-only file is out.');
    $this->assertStringNotContainsString('Sedgebrook-Dotted-1302', $text, 'The message-only file stored under a "./" path is out.');
    $this->assertStringNotContainsString('Ashcombe-Backslash-1706', $text, 'The message-only file stored under a path with a leading backslash is out.');
  }

  /**
   * A message, an attachment or a read time written while the copy is made
   * stops the export, and nothing of the run is kept.
   */
  public function testWriteDuringExportStopsIt(): void {
    // A conversation already on the site, so the export filters.
    $this->messageFile('private://inline-images/before.txt', 'Brackenford-Before-1403', 1);
    $text = $this->archiveText($this->dumper());
    $this->assertStringNotContainsString('Brackenford-Before-1403', $text, 'Without a write during the export, it finishes and leaves the message file out.');
    // Archive names carry the time to the second; clear the folder so the
    // runs below cannot collide with this one's name.
    array_map('unlink', glob($this->base . '/exports/*.tar'));

    $writes = [
      'attachment' => function (): void {
        $this->messageFile('private://inline-images/during.txt', 'Thornmead-During-1504', 2);
      },
      'last read' => function (): void {
        $this->container->get('user.data')->set('private_message', 1, 'private_message_thread:7', 'Wychbury-Read-1605');
      },
    ];
    foreach ($writes as $label => $write) {
      $before = scandir($this->base . '/exports');
      try {
        $this->archiveText($this->dumper($write));
        $this->fail("A $label written during the export must stop it.");
      }
      catch (\RuntimeException $e) {
        $this->assertSame(SiteExporter::MESSAGES_CHANGED_MESSAGE, $e->getMessage(), $label);
      }
      $this->assertSame($before, scandir($this->base . '/exports'), "No archive or staging folder is left after the $label write.");
    }
  }

  /**
   * Paths as tar names them.
   */
  public function testCanonicalPath(): void {
    $this->assertSame('inline-images/a.png', SiteExporter::canonicalPath('inline-images/./a.png'));
    $this->assertSame('inline-images/a.png', SiteExporter::canonicalPath('./inline-images//a.png'));
    $this->assertSame('a.png', SiteExporter::canonicalPath('inline-images/../a.png'));
    $this->assertSame('a.png', SiteExporter::canonicalPath('../../a.png'));
  }

  /**
   * A queued job naming a conversation's activity, queued and done while
   * the export runs, is not in the copy.
   */
  public function testJobQueuedAndDoneDuringExportStaysOut(): void {
    $schema = $this->container->get('database')->schema();
    $schema->createTable('activity__field_activity_entity', ['fields' => [
      'entity_id' => ['type' => 'int', 'not null' => TRUE],
      'field_activity_entity_target_type' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
      'field_activity_entity_target_id' => ['type' => 'int', 'not null' => TRUE],
    ]]);
    $this->container->get('database')->insert('activity__field_activity_entity')->fields([
      'entity_id' => 5,
      'field_activity_entity_target_type' => 'private_message',
      'field_activity_entity_target_id' => 1,
    ])->execute();
    $queue = $this->container->get('queue')->get('activity_send_email_worker');
    // The queue table exists on every site; empty when the export starts.
    $queue->deleteItem($queue->claimItem() ?: (object) ['item_id' => $queue->createItem(['warm' => 1])]);
    $this->assertSame(0, $queue->numberOfItems());
    $text = $this->archiveText($this->dumper(function () use ($queue): void {
      $queue->createItem(['entity_id' => '5', 'note' => 'Mallowdene-Job-1908']);
    }, function () use ($queue): void {
      while ($item = $queue->claimItem()) {
        $queue->deleteItem($item);
      }
    }));
    $this->assertStringNotContainsString('Mallowdene-Job-1908', $text, 'The job naming the conversation is not in the copy.');
  }

  /**
   * Temporary files (uploads never saved to anything) are left out of the
   * site copy, on both folders, with their records and extracted text. The
   * backup keeps them; a permanent file stays in both.
   */
  public function testTemporaryFilesStayOut(): void {
    $texts = $this->container->get('keyvalue')->get(ConversationRows::FILE_TEXT_COLLECTION);
    $files = [];
    foreach (['private://inline-images/unsent.txt' => 'Ravenholt-Unsent-2301', 'public://draft-upload.txt' => 'Sallowby-Draft-2402', 'private://inline-images/kept.txt' => 'Tamworthy-Kept-2503'] as $uri => $word) {
      [$scheme, $target] = explode('://', $uri, 2);
      file_put_contents($this->base . "/$scheme/$target", "Contents $word\n");
      $file = File::create(['uri' => $uri, 'filename' => "$word.txt", 'uid' => 0]);
      if ($word === 'Tamworthy-Kept-2503') {
        $file->setPermanent();
      }
      else {
        $file->setTemporary();
      }
      $file->save();
      $texts->set((string) $file->id(), ['sha256' => str_repeat('a', 64), 'text' => "Extracted $word", 'extracted_at' => 1]);
      $files[$word] = $file;
    }
    $text = $this->archiveText($this->dumper());
    foreach (['Ravenholt-Unsent-2301', 'Sallowby-Draft-2402'] as $word) {
      $this->assertStringNotContainsString($word, $text, "The temporary file $word is nowhere in the copy: no record, file name, contents or extracted text.");
    }
    $this->assertStringContainsString('Contents Tamworthy-Kept-2503', $text, 'A permanent file is in the copy.');
    $this->assertStringContainsString('Extracted Tamworthy-Kept-2503', $text, 'With its extracted text.');
    array_map('unlink', glob($this->base . '/exports/*.tar'));

    $backup = $this->container->get('localnodes_platform.site_exporter')->export($this->base . '/exports', $this->dumper(), NULL, NULL, NULL, TRUE);
    $kept = $this->archiveTextAt($backup['path']);
    foreach (['Contents Ravenholt-Unsent-2301', 'Contents Sallowby-Draft-2402', 'Contents Tamworthy-Kept-2503'] as $word) {
      $this->assertStringContainsString($word, $kept, "The backup keeps $word.");
    }
  }

  /**
   * Download counts of a file in a conversation, or of a file used only in
   * messages, are left out of the copy; the other download counts stay.
   */
  public function testDownloadCountsOfMessageFilesStayOut(): void {
    // Open Social's download counter, with its own table definition.
    require_once DRUPAL_ROOT . '/profiles/contrib/social/modules/custom/download_count/download_count.install';
    $database = $this->container->get('database');
    $database->schema()->createTable('download_count', download_count_schema()['download_count']);
    $message_file = $this->messageFile('private://inline-images/counted.txt', 'Umberleigh-Counted-2604', 4);
    $page = 'public://page-file.txt';
    file_put_contents($this->base . '/public/page-file.txt', "Contents of a page file\n");
    $page_file = File::create(['uri' => $page, 'filename' => 'page-file.txt', 'uid' => 0]);
    $page_file->setPermanent();
    $page_file->save();
    $rows = [
      [(int) $message_file->id(), 'private_message', 4, '/private-messages/9?Vexford-Message-2705'],
      [(int) $page_file->id(), 'private_message_thread', 9, '/private-messages/9?Wrayhill-Thread-2806'],
      [(int) $message_file->id(), 'node', 3, '/node/3?Yarrowby-Only-2907'],
      [(int) $page_file->id(), 'node', 3, '/node/3?Zennor-Page-3008'],
    ];
    foreach ($rows as [$fid, $type, $id, $referrer]) {
      $database->insert('download_count')->fields(['fid' => $fid, 'uid' => 1, 'type' => $type, 'id' => $id, 'ip_address' => '203.0.113.9', 'referrer' => $referrer, 'timestamp' => 1])->execute();
    }
    $text = $this->archiveText($this->dumper());
    $this->assertStringNotContainsString('Vexford-Message-2705', $text, 'A download from a message is not in the copy.');
    $this->assertStringNotContainsString('Wrayhill-Thread-2806', $text, 'A download from a conversation is not in the copy.');
    $this->assertStringNotContainsString('Yarrowby-Only-2907', $text, 'A download of a file used only in messages is not in the copy.');
    $this->assertStringContainsString('Zennor-Page-3008', $text, 'Other downloads stay in the copy.');
  }

}
