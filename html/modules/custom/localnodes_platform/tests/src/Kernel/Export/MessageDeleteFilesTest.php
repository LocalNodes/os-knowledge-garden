<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Export\ConversationRows;
use Drupal\private_message\Entity\PrivateMessage;
use Drupal\private_message\Entity\PrivateMessageThread;
use Drupal\user\Entity\User;

/**
 * Deleting a message or a conversation deletes the files used only in it,
 * with the direct-message module off: their bytes, their records and the text
 * extracted from them. A file the site cannot remove stays a message's
 * file, and a site copy leaves it out.
 *
 * @group localnodes_platform
 */
final class MessageDeleteFilesTest extends KernelTestBase {

  use ExportMessageFilesTrait {
    setUp as filesSetUp;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'field', 'text', 'filter', 'key', 'localnodes_platform', 'private_message'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The two members of the conversation.
   *
   * @var \Drupal\user\Entity\User[]
   */
  private array $members = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->filesSetUp();
    $this->installEntitySchema('private_message');
    $this->installEntitySchema('private_message_thread');
    $this->installSchema('private_message', ['pm_thread_history']);
    // A file field on messages: its own usage records are removed when the
    // message's fields are deleted.
    FieldStorageConfig::create([
      'field_name' => 'field_pm_attachment',
      'entity_type' => 'private_message',
      'type' => 'file',
      'settings' => ['uri_scheme' => 'private'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_pm_attachment',
      'entity_type' => 'private_message',
      'bundle' => 'private_message',
    ])->save();
    User::create(['name' => 'root', 'status' => 1])->save();
    foreach (['Writer', 'Reader'] as $name) {
      $member = User::create(['name' => $name, 'status' => 1]);
      $member->save();
      $this->members[] = $member;
    }
  }

  /**
   * The delete rule runs before a message's or a conversation's own fields
   * let go of their files.
   */
  public function testDeleteRuleRunsBeforeDelete(): void {
    $handler = $this->container->get('module_handler');
    $this->assertTrue($handler->hasImplementations('private_message_predelete', ['localnodes_platform']), 'Deleting a message runs the platform rule.');
    $this->assertTrue($handler->hasImplementations('private_message_thread_predelete', ['localnodes_platform']), 'Deleting a conversation runs the platform rule.');
  }

  /**
   * Deleting a conversation deletes the files used only in its messages or
   * in the conversation itself, and their extracted text; a file also used
   * elsewhere stays.
   */
  public function testDeletedConversationLeavesNoFile(): void {
    $field = $this->file('Mosswater-Field-3101');
    $inline = $this->file('Gallowmere-Inline-3202');
    $shared = $this->file('Copperdale-Shared-3303');
    $own = $this->file('Hartsend-Thread-3404');
    $message = PrivateMessage::create([
      'owner' => $this->members[0]->id(),
      'message' => ['value' => '<p>See these.</p>', 'format' => 'plain_text'],
      'field_pm_attachment' => ['target_id' => $field->id()],
    ]);
    $message->save();
    $thread = PrivateMessageThread::create(['members' => $this->members, 'private_messages' => [$message]]);
    $thread->save();
    $usage = $this->container->get('file.usage');
    $usage->add($inline, 'editor', 'private_message', (string) $message->id());
    $usage->add($shared, 'editor', 'private_message', (string) $message->id());
    $usage->add($shared, 'localnodes_test', 'user', (string) $this->members[1]->id());
    $usage->add($own, 'editor', 'private_message_thread', (string) $thread->id());
    $this->assertEquals(['file' => ['private_message' => [$message->id() => '1']]], $usage->listUsage($field), 'The message\'s file field records its file.');
    $texts = $this->seedTexts([$field, $inline, $shared, $own]);

    $thread->delete();

    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache();
    foreach (['field' => $field, 'inline' => $inline, 'conversation' => $own] as $label => $file) {
      $this->assertNull($storage->load($file->id()), "The $label file's record is gone.");
      $this->assertFileDoesNotExist($this->base . '/private/inline-images/' . $file->getFilename(), "The $label file is gone from the disk.");
      $this->assertNull($texts->get((string) $file->id()), "The $label file's extracted text is gone.");
    }
    $this->assertNotNull($storage->load($shared->id()), 'The shared file stays.');
    $this->assertFileExists($this->base . '/private/inline-images/' . $shared->getFilename());
    $this->assertNotNull($texts->get((string) $shared->id()), 'The shared file keeps its extracted text.');

    $text = $this->archiveText($this->dumper());
    foreach (['Mosswater-Field-3101', 'Gallowmere-Inline-3202', 'Hartsend-Thread-3404'] as $word) {
      $this->assertStringNotContainsString($word, $text, "$word is nowhere in the copy: no row, file name, file contents or extracted text.");
    }
    $this->assertStringContainsString('Contents Copperdale-Shared-3303', $text, 'The shared file is in the copy.');
    $this->assertStringContainsString('Extracted Copperdale-Shared-3303', $text, 'The shared file\'s extracted text is in the copy.');
  }

  /**
   * A message's file the site cannot remove stays on the site as a
   * message's file, with its extracted text, and a site copy leaves it out:
   * when the folder refuses the delete, and when the folder cannot be
   * searched.
   *
   * @dataProvider folderModes
   */
  public function testUndeletableFileStaysMarked(int $mode): void {
    $file = $this->file('Pinchbeck-Stuck-3505');
    $message = PrivateMessage::create([
      'owner' => $this->members[0]->id(),
      'message' => ['value' => '<p>See this.</p>', 'format' => 'plain_text'],
      'field_pm_attachment' => ['target_id' => $file->id()],
    ]);
    $message->save();
    $texts = $this->seedTexts([$file]);
    $folder = $this->base . '/private/inline-images';
    chmod($folder, $mode);
    try {
      // A failed delete's warning is logged on a site and the delete goes
      // on; it goes on here as well.
      set_error_handler(fn (): bool => TRUE, E_WARNING);
      try {
        $message->delete();
      }
      finally {
        restore_error_handler();
      }
    }
    finally {
      chmod($folder, 0775);
    }
    $this->assertFileExists("$folder/Pinchbeck-Stuck-3505.txt", 'The bytes could not be removed.');
    $this->container->get('entity_type.manager')->getStorage('file')->resetCache();
    $this->assertNotNull(File::load($file->id()), 'The file record stays.');
    $this->assertEquals(['localnodes_platform' => ['private_message' => [$message->id() => '1']]], $this->container->get('file.usage')->listUsage(File::load($file->id())), 'The file stays marked as a message\'s file.');
    $this->assertNotNull($texts->get((string) $file->id()), 'The extracted text stays with the file.');
    $text = $this->archiveText($this->dumper());
    $this->assertStringNotContainsString('Pinchbeck-Stuck-3505', $text, 'The file and its extracted text are nowhere in the copy.');
  }

  /**
   * Folder modes under which a file in it cannot be removed.
   */
  public static function folderModes(): array {
    return [
      'the folder refuses the delete' => [0555],
      'the folder cannot be searched' => [0666],
    ];
  }

  /**
   * A permanent file in the private inline-images folder holding $word.
   */
  private function file(string $word): File {
    $uri = "private://inline-images/$word.txt";
    file_put_contents($this->base . "/private/inline-images/$word.txt", "Contents $word\n");
    $file = File::create(['uri' => $uri, 'filename' => "$word.txt", 'uid' => $this->members[0]->id()]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Stores extracted text for each file, as FileTextStore stores it.
   *
   * @param \Drupal\file\Entity\File[] $files
   */
  private function seedTexts(array $files): \Drupal\Core\KeyValueStore\KeyValueStoreInterface {
    $texts = $this->container->get('keyvalue')->get(ConversationRows::FILE_TEXT_COLLECTION);
    foreach ($files as $file) {
      $word = substr($file->getFilename(), 0, -4);
      $texts->set((string) $file->id(), ['sha256' => str_repeat('e', 64), 'text' => "Extracted $word", 'extracted_at' => 1]);
    }
    return $texts;
  }

}
