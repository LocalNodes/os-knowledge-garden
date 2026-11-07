<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel\Support;

use Drupal\ai_file_to_text\Extractor\FileExtractorManager;

/**
 * The real ai_file_to_text extractor manager, recording every extract() call.
 *
 * Swapped in for ai_file_to_text.extractor_manager by tests that need to
 * show a file never reached a parser (an unsupported spreadsheet, a
 * duplicate). Same constructor, same behaviour; only the call list is added.
 */
class RecordingExtractorManager extends FileExtractorManager {

  /**
   * Extensions passed to extract(), in call order.
   *
   * Static, because the container is rebuilt when modules are installed and
   * the test keeps no handle on the instance it ends up with.
   *
   * @var string[]
   */
  public static array $calls = [];

  /**
   * {@inheritdoc}
   */
  public function extract(string $file_path, string $extension, string $format = 'text', array $options = []): ?string {
    static::$calls[] = $extension;
    return parent::extract($file_path, $extension, $format, $options);
  }

}
