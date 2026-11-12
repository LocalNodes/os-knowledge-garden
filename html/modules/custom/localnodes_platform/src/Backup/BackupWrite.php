<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Backup;

/**
 * Writes a small file's bytes and reports whether all of them landed.
 *
 * Plain PHP, like BackupPause: the pause tool uses it while the database
 * may be empty.
 */
final class BackupWrite {

  /**
   * Replaces the write in tests, to fake a full disk. NULL everywhere else.
   *
   * @var \Closure|null
   */
  public static ?\Closure $writer = NULL;

  /**
   * Writes $data to the open handle and closes it.
   *
   * @param resource $handle
   *   An open, writable handle; always closed on return.
   *
   * @return bool
   *   FALSE on a short write or a failed flush or close.
   */
  public static function all($handle, string $data): bool {
    if (self::$writer !== NULL) {
      return (self::$writer)($handle, $data);
    }
    $written = @fwrite($handle, $data);
    $flushed = $written === strlen($data) && @fflush($handle);
    return @fclose($handle) && $flushed;
  }

}
