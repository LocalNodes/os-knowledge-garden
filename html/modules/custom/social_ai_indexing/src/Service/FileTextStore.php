<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\file\FileInterface;

/**
 * Stores the text extracted from a file, once per file.
 *
 * Extraction (smalot, PhpWord) is slow and parses untrusted bytes, so it
 * runs once, at import or at the first index pass, and the text is reused
 * on every reindex (22-RESEARCH Pattern 4, T-22-25). The stored value is
 * tied to the file's sha256: when the bytes behind a fid change, the old
 * text is ignored.
 *
 * Key: fid. Value: ['sha256' => string, 'text' => string,
 * 'extracted_at' => int]. An empty 'text' records "extracted, no text" (a
 * scan, or a file the parser could not read), so a hostile file is not
 * re-parsed on every reindex.
 *
 * Text is personal data: social_ai_indexing_file_delete() removes it with
 * the file (AI-SPEC 1b, erasure).
 */
class FileTextStore {

  /**
   * The key_value collection.
   */
  public const COLLECTION = 'social_ai_indexing.file_text';

  /**
   * The key_value store.
   */
  protected KeyValueStoreInterface $store;

  /**
   * Constructs a FileTextStore.
   */
  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    protected FileSystemInterface $fileSystem,
  ) {
    $this->store = $key_value_factory->get(self::COLLECTION);
  }

  /**
   * Returns the stored text for a file.
   *
   * @return string|null
   *   The text ('' when extraction found none), or NULL when nothing is
   *   stored, the file can't be read, or its bytes changed since the text
   *   was stored.
   */
  public function get(FileInterface $file): ?string {
    $record = $this->store->get((string) $file->id());
    if (!is_array($record) || !isset($record['sha256'], $record['text'])) {
      return NULL;
    }
    $sha256 = $this->sha256($file);
    if ($sha256 === NULL || !hash_equals((string) $record['sha256'], $sha256)) {
      return NULL;
    }
    return (string) $record['text'];
  }

  /**
   * Stores the text for a file, keyed by fid with its current sha256.
   *
   * Does nothing when the file can't be read (no hash to tie the text to).
   */
  public function put(FileInterface $file, string $text): void {
    $sha256 = $this->sha256($file);
    if ($sha256 === NULL) {
      return;
    }
    $this->store->set((string) $file->id(), [
      'sha256' => $sha256,
      'text' => $text,
      'extracted_at' => time(),
    ]);
  }

  /**
   * Removes the stored text for a file id.
   */
  public function delete(int $fid): void {
    $this->store->delete((string) $fid);
  }

  /**
   * The sha256 of the file's bytes, or NULL when it can't be read.
   */
  protected function sha256(FileInterface $file): ?string {
    $path = $this->fileSystem->realpath($file->getFileUri());
    if ($path === FALSE || $path === '' || !is_file($path) || !is_readable($path)) {
      return NULL;
    }
    $hash = hash_file('sha256', $path);
    return $hash === FALSE ? NULL : $hash;
  }

}
