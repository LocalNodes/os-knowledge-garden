<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

/**
 * The file the pack check's --capture option appends answers to.
 *
 * A captured record holds answer text and the excerpts the model was shown,
 * so it may go to an ordinary file only: never a stream such as php://stdout,
 * where a terminal or a job log would keep it, and never through a link or
 * onto a device. The file is readable by its owner only, and a write that
 * does not complete is an error rather than a short record.
 *
 * No Drupal calls, so Unit tests use it directly.
 */
final class PackCaptureFile {

  /**
   * Why a path cannot take captured answers, or NULL when it can.
   *
   * The path must be absolute and name an ordinary file, existing or not, in
   * a folder that exists. Stream wrappers, links, folders and devices are
   * refused. The reason never repeats the path.
   */
  public static function problem(string $path): ?string {
    if ($path === '') {
      return 'no file named';
    }
    if (str_contains($path, "\0")) {
      return 'the path holds a NUL byte';
    }
    if (str_contains($path, '://') || !str_starts_with($path, '/')) {
      return 'the path must be an absolute file path, not a stream or a relative path';
    }
    clearstatcache(TRUE, $path);
    if (is_link($path)) {
      return 'the path is a link';
    }
    if (file_exists($path) && !is_file($path)) {
      return 'the path is not an ordinary file';
    }
    $dir = dirname($path);
    if (!is_dir($dir) || is_link($dir)) {
      return 'the folder does not exist';
    }
    return NULL;
  }

  /**
   * Creates the file if needed and makes it readable by its owner only.
   *
   * @throws \RuntimeException
   *   When the path is refused or the file cannot be made private.
   */
  public static function open(string $path): void {
    $problem = self::problem($path);
    if ($problem !== NULL) {
      throw new \RuntimeException("capture file refused: $problem");
    }
    $mask = umask(0077);
    try {
      $handle = @fopen($path, 'ab');
    }
    finally {
      umask($mask);
    }
    if ($handle === FALSE) {
      throw new \RuntimeException('capture file could not be opened');
    }
    fclose($handle);
    if (!@chmod($path, 0600)) {
      throw new \RuntimeException('capture file could not be made private');
    }
    clearstatcache(TRUE, $path);
    if (self::problem($path) !== NULL || (fileperms($path) & 0777) !== 0600) {
      throw new \RuntimeException('capture file is not a private ordinary file');
    }
  }

  /**
   * Appends one line to the file, whole or not at all.
   *
   * The path is checked again first, so a file swapped for a link or a
   * stream since open() is refused. Nothing is printed, and an error message
   * never holds the line.
   *
   * @throws \RuntimeException
   *   When the path is refused or the write is short or fails.
   */
  public static function append(string $path, string $line): void {
    $problem = self::problem($path);
    if ($problem !== NULL || !is_file($path)) {
      throw new \RuntimeException('capture file refused: ' . ($problem ?? 'open() has not created it'));
    }
    $handle = @fopen($path, 'ab');
    if ($handle === FALSE) {
      throw new \RuntimeException('capture file could not be opened');
    }
    try {
      if (!flock($handle, LOCK_EX)) {
        throw new \RuntimeException('capture file could not be locked');
      }
      $written = @fwrite($handle, $line);
      if ($written !== strlen($line) || !fflush($handle)) {
        throw new \RuntimeException('capture record was not written in full');
      }
    }
    finally {
      flock($handle, LOCK_UN);
      fclose($handle);
    }
  }

}
