<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Backup;

/**
 * The pause file that closes the site while a backup or restore runs.
 *
 * The file is <private files>/.localnodes/backup-pause and holds one JSON
 * line: {"token": "<hex>", "until": <unix time>|null, "reason": "..."}. The
 * private files folder outlives the container, and the export leaves
 * .localnodes out, so a restored copy never carries a pause.
 *
 * A backup pause has an end time: a killed backup cannot leave the site
 * closed. A restore pause has none: a half-loaded site stays closed until
 * the restore that wrote it, or an operator, lifts it.
 *
 * Plain PHP with no Drupal services: the web middleware, the drush command
 * and the docker/localnodes-pause tool (used while the database may be
 * empty) all read and write it through this class. docker/worker-loop.sh
 * reads the same format in bash.
 */
final class BackupPause {

  /**
   * Folder inside the private files that holds the pause and the locks.
   */
  public const DIR = '.localnodes';

  /**
   * Lock file held while the pause is published or removed.
   */
  public const PAUSE_LOCK = 'pause.lock';

  /**
   * Why create() refused where hard links are missing.
   */
  public const NO_HARD_LINKS = 'Cannot publish the pause file: this folder does not allow hard links.';

  /**
   * Why create() refused when the pause lock stayed taken.
   */
  public const LOCK_BUSY = 'Another change to the pause is in progress.';

  /**
   * @param string $privateDir
   *   The private files folder.
   * @param float $lockWait
   *   Longest wait, in seconds, for the pause lock. Changes under it take
   *   milliseconds; tests pass a short wait.
   */
  public function __construct(
    private readonly string $privateDir,
    private readonly float $lockWait = 10.0,
  ) {}

  /**
   * Seconds a client is asked to wait before trying again.
   */
  public const RETRY_AFTER = 300;

  /**
   * The page a visitor gets while the site is paused.
   */
  public static function page(string $reason): string {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Back in a few minutes</title></head>'
      . '<body style="font-family: sans-serif; max-width: 36rem; margin: 4rem auto; padding: 0 1rem;"><h1>Back in a few minutes</h1>'
      . '<p>' . (match ($reason) { 'backup' => 'This site is saving its nightly copy.', 'export' => 'This site is preparing a requested copy.', 'unavailable' => 'This site cannot take requests right now.', default => 'This site is being restored from a saved copy.' }) . ' Please try again in a few minutes.</p></body></html>';
  }

  /**
   * Answers a web request with the 503 page, before Drupal starts, if paused.
   *
   * Called from settings.php. A restore empties the database before it loads
   * the copy; while it is empty Drupal cannot start, so the middleware never
   * runs and Drupal would send the visitor to the installer instead. The
   * container's own healthcheck (GET /user/login from the container itself)
   * gets a bare 200 so the container is not restarted mid-restore.
   *
   * @param array $server
   *   The request's $_SERVER values.
   *
   * @return array|null
   *   NULL when not paused, else [status, headers, body] to send.
   */
  public function webAnswer(array $server): ?array {
    $pause = $this->active();
    if ($pause === NULL) {
      return NULL;
    }
    $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $remote = (string) ($server['REMOTE_ADDR'] ?? '');
    if (($server['REQUEST_METHOD'] ?? '') === 'GET' && $path === '/user/login' && ($remote === '::1' || str_starts_with($remote, '127.'))) {
      return [200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'], 'paused'];
    }
    return [503, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Retry-After' => (string) self::RETRY_AFTER,
      'Cache-Control' => 'no-store',
    ], self::page($pause['reason'])];
  }

  /**
   * Lock file every web request holds, shared, while it runs.
   */
  public const REQUEST_LOCK = 'request.lock';

  /**
   * The open request lock of this web request, kept until the request ends.
   *
   * Static, so the handle outlives the settings.php include that takes it:
   * PHP closes it, and so releases the lock, only when the request is over.
   *
   * @var resource|null
   */
  private static $requestLock = NULL;

  /**
   * Starts a web request: the pause answer, or NULL with the request lock held.
   *
   * Called from settings.php for every web request. A backup or restore
   * writes the pause, then waits for the request lock exclusively
   * (waitForRequests()), so every request that began before the pause has
   * finished, session and shutdown writes included, before anything is
   * copied or deleted. The pause is read again once the lock is held: a
   * request that took the lock after the pause was written answers paused.
   *
   * The folder and file are made if missing, and a read-only file still
   * serves (flock needs no write access). When the lock cannot be opened at
   * all the request is refused with a 503: a request nobody can wait for
   * could write while a backup copies or a restore deletes. The container
   * runs everything as the web user, so this only happens on a broken
   * deployment, and then it should be loud.
   *
   * @param array $server
   *   The request's $_SERVER values.
   *
   * @return array|null
   *   NULL to carry on, else [status, headers, body] to send.
   */
  public function enterWebRequest(array $server): ?array {
    $answer = $this->webAnswer($server);
    if ($answer !== NULL || self::$requestLock !== NULL) {
      return $answer;
    }
    try {
      $handle = $this->openLock(self::REQUEST_LOCK);
    }
    catch (\Throwable) {
      // Fixed text only: nothing from the request reaches the log.
      error_log('LocalNodes: cannot open the request lock; web requests are refused until the private files folder is writable by the web user.');
      return [503, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Retry-After' => (string) self::RETRY_AFTER,
        'Cache-Control' => 'no-store',
      ], self::page('unavailable')];
    }
    if (!flock($handle, LOCK_SH | LOCK_NB)) {
      // Only a backup or restore takes it exclusively, and only after its
      // pause is written, so the pause answers. Gone by now: the run is over.
      fclose($handle);
      return $this->webAnswer($server);
    }
    $answer = $this->webAnswer($server);
    if ($answer !== NULL) {
      flock($handle, LOCK_UN);
      fclose($handle);
      return $answer;
    }
    self::$requestLock = $handle;
    return NULL;
  }

  /**
   * Waits until no web request holds the request lock, or the deadline.
   *
   * Call it after the pause is written: new requests then answer paused, so
   * once the exclusive lock is had, every request already running is over.
   * The lock is let go at once; holding it would only hold up the 503s.
   *
   * @throws \RuntimeException
   *   When requests are still running at the deadline.
   */
  public function waitForRequests(float $deadline): void {
    $handle = $this->openLock(self::REQUEST_LOCK);
    try {
      while (!flock($handle, LOCK_EX | LOCK_NB)) {
        if (microtime(TRUE) >= $deadline) {
          throw new \RuntimeException('web requests that began before the pause were still running at the time limit', 504);
        }
        usleep(100000);
      }
      flock($handle, LOCK_UN);
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Lets go of this request's lock. For tests; PHP does it at request end.
   */
  public static function leaveWebRequest(): void {
    if (self::$requestLock !== NULL) {
      flock(self::$requestLock, LOCK_UN);
      fclose(self::$requestLock);
      self::$requestLock = NULL;
    }
  }

  /**
   * The pause file's path.
   */
  public function file(): string {
    return rtrim($this->privateDir, '/') . '/' . self::DIR . '/backup-pause';
  }

  /**
   * A lock file's path in the same folder (backup.lock, worker.lock).
   */
  public function lockFile(string $name): string {
    return rtrim($this->privateDir, '/') . '/' . self::DIR . '/' . $name;
  }

  /**
   * The pause in force, or NULL when the site is open.
   *
   * An unreadable or malformed file counts as a pause with no end: closed is
   * the safe answer when the file cannot be understood.
   *
   * @return array|null
   *   ['token' => string, 'until' => ?int, 'reason' => string].
   */
  public function active(?int $now = NULL): ?array {
    $file = $this->file();
    if (!file_exists($file)) {
      return NULL;
    }
    $pause = $this->read();
    if ($pause === NULL) {
      return ['token' => '', 'until' => NULL, 'reason' => 'unreadable'];
    }
    if ($pause['until'] !== NULL && $pause['until'] <= ($now ?? time())) {
      return NULL;
    }
    return $pause;
  }

  /**
   * The pause file's contents, active or not; NULL when missing or malformed.
   */
  public function read(): ?array {
    $raw = @file_get_contents($this->file());
    if ($raw === FALSE) {
      return NULL;
    }
    $data = json_decode($raw, TRUE);
    if (!is_array($data) || !isset($data['token']) || !is_string($data['token']) || !array_key_exists('until', $data) || !(is_int($data['until']) || $data['until'] === NULL)) {
      return NULL;
    }
    return ['token' => $data['token'], 'until' => $data['until'], 'reason' => (string) ($data['reason'] ?? '')];
  }

  /**
   * Writes a new pause and returns its token.
   *
   * Runs under the pause lock, as release() does, so a release that read an
   * older token cannot remove this pause. An expired pause left by a killed
   * run is removed first; a pause still in force refuses. The JSON goes to a
   * temporary file in the same folder, published with link() only when every
   * byte was written: readers see the whole line or no file, and a failed
   * write leaves no pause and throws. Where hard links are missing it
   * refuses rather than write the pause in place, which an interrupted
   * writer could leave cut short, closing the site with no end.
   *
   * @param string $reason
   *   "backup" or "restore".
   * @param int|null $until
   *   Unix time the pause ends, or NULL for no end.
   *
   * @throws \RuntimeException
   *   When a pause is in force, the pause lock stays taken, or the file
   *   cannot be published.
   */
  public function create(string $reason, ?int $until): string {
    $dir = dirname($this->file());
    if (!is_dir($dir) && !@mkdir($dir, 0700, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException('Cannot make the folder for the pause file.');
    }
    return $this->underPauseLock(function () use ($dir, $reason, $until): string {
      if ($this->active() !== NULL) {
        throw new \RuntimeException('The site is already paused by another backup or restore.');
      }
      if (file_exists($this->file()) || is_link($this->file())) {
        @unlink($this->file());
      }
      $token = bin2hex(random_bytes(16));
      $temporary = $dir . '/.backup-pause.' . bin2hex(random_bytes(8));
      $handle = @fopen($temporary, 'x');
      if ($handle === FALSE) {
        throw new \RuntimeException('Cannot write the pause file.');
      }
      $json = json_encode(['token' => $token, 'until' => $until, 'reason' => $reason]) . "\n";
      if (!BackupWrite::all($handle, $json) || !@chmod($temporary, 0644)) {
        @unlink($temporary);
        throw new \RuntimeException('Cannot write the pause file.');
      }
      // link() fails, unlike rename(), when a pause was published meanwhile
      // by something outside the lock; that pause stays.
      $published = @link($temporary, $this->file());
      @unlink($temporary);
      if (!$published) {
        throw new \RuntimeException(file_exists($this->file()) || is_link($this->file())
          ? 'The site is already paused by another backup or restore.'
          : self::NO_HARD_LINKS);
      }
      return $token;
    });
  }

  /**
   * Removes the pause only if it still holds this token.
   *
   * The token check and the removal run under the pause lock, so a pause
   * published after the check is never removed.
   *
   * @return bool
   *   TRUE when this token's pause was removed. FALSE when the token does
   *   not match, or the lock or the file could not be had; a backup pause
   *   then ends at its time limit.
   */
  public function release(string $token): bool {
    if ($token === '') {
      return FALSE;
    }
    try {
      return $this->underPauseLock(function () use ($token): bool {
        $pause = $this->read();
        if ($pause === NULL || !hash_equals($pause['token'], $token)) {
          return FALSE;
        }
        return @unlink($this->file());
      });
    }
    catch (\RuntimeException) {
      return FALSE;
    }
  }

  /**
   * Runs a change to the pause file while holding the pause lock.
   *
   * @throws \RuntimeException
   *   When the lock cannot be opened, or is still taken after the wait.
   */
  private function underPauseLock(callable $change): mixed {
    $handle = $this->openLock(self::PAUSE_LOCK);
    try {
      $deadline = microtime(TRUE) + $this->lockWait;
      while (!flock($handle, LOCK_EX | LOCK_NB)) {
        if (microtime(TRUE) >= $deadline) {
          throw new \RuntimeException(self::LOCK_BUSY);
        }
        usleep(50000);
      }
      try {
        return $change();
      }
      finally {
        flock($handle, LOCK_UN);
      }
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Removes any pause: the operator's explicit recovery step.
   */
  public function clear(): bool {
    return !file_exists($this->file()) || @unlink($this->file());
  }

  /**
   * Opens a lock file for flock(), making the folder and file if needed.
   *
   * @return resource
   *   The open handle; flock() it, keep it open while the lock is needed.
   */
  public function openLock(string $name) {
    $file = $this->lockFile($name);
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException('Cannot make the folder for the lock files.');
    }
    $handle = @fopen($file, 'c');
    if ($handle === FALSE) {
      $handle = @fopen($file, 'r');
    }
    if ($handle === FALSE) {
      throw new \RuntimeException("Cannot open the lock file $name.");
    }
    return $handle;
  }

}
