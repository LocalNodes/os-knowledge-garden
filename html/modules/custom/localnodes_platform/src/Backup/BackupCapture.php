<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Backup;

use Drupal\localnodes_platform\Export\SiteExporter;

/** The shared pause, drain and exclusive capture boundary. */
class BackupCapture {

  /**
   * Longest wait, in seconds, for web requests already running at the pause.
   */
  public const REQUEST_WAIT = 300;

  /**
   * Why a capture failed when its pause could not be lifted afterwards.
   */
  public const RELEASE_FAILED = 'The pause could not be lifted; the site stays closed until the pause ends or an operator clears it.';

  public function run(BackupPause $pause, string $reason, int $maxPause, int $drain, int $margin, callable $copy): mixed {
    $backup = $pause->openLock('backup.lock');
    if (!flock($backup, LOCK_EX | LOCK_NB)) {
      fclose($backup);
      throw new \RuntimeException('another backup or restore is running', 409);
    }
    $worker = NULL;
    $token = NULL;
    $result = NULL;
    $error = NULL;
    try {
      if ($pause->active() !== NULL) {
        throw new \RuntimeException('the site is already paused by another backup or restore', 409);
      }
      $until = time() + $maxPause;
      $deadline = (float) ($until - $margin);
      $token = $pause->create($reason, $until);
      $worker = $pause->openLock('worker.lock');
      while (!flock($worker, LOCK_EX | LOCK_NB)) {
        if (microtime(TRUE) >= $deadline) throw new \RuntimeException(SiteExporter::DEADLINE_MESSAGE);
        usleep(250000);
      }
      // Web requests that began before the pause may still write; wait for
      // them, but not long: the site is already closed while this waits.
      $pause->waitForRequests(min($deadline, microtime(TRUE) + self::REQUEST_WAIT));
      $end = min($deadline, microtime(TRUE) + $drain);
      while (($left = $end - microtime(TRUE)) > 0) usleep((int) min(1000000, $left * 1000000));
      if (microtime(TRUE) >= $deadline) throw new \RuntimeException(SiteExporter::DEADLINE_MESSAGE);
      $result = $copy($deadline);
      if (microtime(TRUE) >= $deadline) throw new \RuntimeException(SiteExporter::DEADLINE_MESSAGE);
    }
    catch (\Throwable $e) {
      $error = $e;
    }
    finally {
      // FALSE from release() with no file, or another valid pause in it,
      // means this run's pause is already gone. An unreadable file still
      // closes the site, so it counts as not lifted.
      $released = $token === NULL || $pause->release($token) || $this->goneOrReplaced($pause, $token);
      if ($worker !== NULL) { flock($worker, LOCK_UN); fclose($worker); }
      flock($backup, LOCK_UN);
      fclose($backup);
    }
    // This run's pause left in place is reported, never passed as done; the
    // copy's own error, if any, stays attached.
    if (!$released) throw new \RuntimeException(self::RELEASE_FAILED, 0, $error);
    if ($error !== NULL) throw $error;
    return $result;
  }

  /**
   * Whether the pause file is missing or holds another run's valid pause.
   */
  private function goneOrReplaced(BackupPause $pause, string $token): bool {
    $current = $pause->read();
    return $current === NULL ? !file_exists($pause->file()) && !is_link($pause->file()) : !hash_equals($current['token'], $token);
  }

}
