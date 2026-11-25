<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\Core\Entity\EntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Export\ConversationRows;

/**
 * A deleted message's file stays out of a site copy, even when its bytes
 * cannot be removed.
 *
 * @group localnodes_platform
 */
final class DeletedMessageFileExportTest extends KernelTestBase {

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
   * Deleting a message runs the rule that keeps its file out of a site
   * copy.
   */
  public function testDeleteRuleRunsOnMessageDelete(): void {
    $this->assertTrue($this->container->get('module_handler')->hasImplementations('private_message_predelete', ['localnodes_platform']), 'Deleting a message runs the platform rule.');
  }

  /**
   * A deleted message's file stays out of a site copy when its bytes
   * cannot be removed.
   */
  public function testUndeletableMessageFileStaysOut(): void {
    $file = $this->messageFile('private://inline-images/stuck.txt', 'Pennyroyal-Stuck-1807', 3);
    $texts = $this->container->get('keyvalue')->get(ConversationRows::FILE_TEXT_COLLECTION);
    $texts->set((string) $file->id(), ['sha256' => str_repeat('f', 64), 'text' => 'Extracted Tansyford-Text-1808', 'extracted_at' => 1]);
    $message = $this->createMock(EntityInterface::class);
    $message->method('id')->willReturn(3);
    $folder = $this->base . '/private/inline-images';
    // The test runs as an ordinary user, so the bytes cannot be unlinked.
    chmod($folder, 0555);
    try {
      // On a site, Drupal logs PHP warnings and carries on; PHPUnit would
      // turn the failed unlink's warning into an exception instead.
      set_error_handler(fn (): bool => TRUE, E_WARNING);
      try {
        localnodes_platform_private_message_predelete($message);
      }
      finally {
        restore_error_handler();
      }
      // What the editor does next: it removes its own usage record.
      $this->container->get('file.usage')->delete($file, 'editor', 'private_message', '3', 0);
      $this->assertFileExists("$folder/stuck.txt", 'The bytes could not be removed.');
      $this->assertNotNull($texts->get((string) $file->id()), 'The extracted text stays with the file.');
    }
    finally {
      // Writable again: the export only reads it, and its own checks unpack
      // the folder with the mode it has.
      chmod($folder, 0775);
    }
    $text = $this->archiveText($this->dumper());
    $this->assertStringNotContainsString('Pennyroyal-Stuck-1807', $text, 'A deleted message\'s file stays out of the site copy.');
    $this->assertStringNotContainsString('Tansyford-Text-1808', $text, 'So does its extracted text.');
  }

}
