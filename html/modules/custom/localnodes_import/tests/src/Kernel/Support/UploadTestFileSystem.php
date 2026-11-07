<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel\Support;

use Drupal\Core\File\FileSystem;

/**
 * Test-only file system that accepts files a Kernel test "uploads".
 *
 * PHP's move_uploaded_file() refuses any file that didn't arrive in a real
 * HTTP upload, so core's form upload path can't run in a Kernel test. When
 * the source isn't an HTTP upload, this falls back to a plain move. Real
 * uploads keep core's behaviour. Never used outside tests.
 */
class UploadTestFileSystem extends FileSystem {

  /**
   * {@inheritdoc}
   */
  public function moveUploadedFile($filename, $uri) {
    if (is_uploaded_file($filename)) {
      return parent::moveUploadedFile($filename, $uri);
    }
    $target = $this->realpath($uri) ?: $uri;
    return @rename($filename, $target);
  }

}
