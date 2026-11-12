<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Drush\Commands;

use Drupal\Core\Site\Settings;
use Drupal\localnodes_platform\Backup\BackupPause;
use Drupal\localnodes_platform\Backup\BackupWrite;
use Drupal\localnodes_platform\Export\SiteExporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Drush;

/**
 * drush localnodes:backup: the nightly copy, taken while the site is closed.
 *
 * The host's nightly script runs it inside the app container, then encrypts
 * and uploads what it writes. The copy is the export archive without the
 * library, taken under the pause so the database and the files match: the
 * site answers every request with a 503 from the moment the pause is written
 * until the archive is complete.
 *
 * Everything that can refuse does so before the pause is written, so a
 * refused run changes nothing. Once paused, one deadline covers the whole
 * capture; when it passes, the run stops, deletes what it wrote and reopens
 * the site. The pause it removes is only ever its own.
 *
 * Prints one JSON line the host script reads. It holds no secret and no
 * page title. With --with-secret-record, the values a restore needs that the
 * archive must not carry (the hash salt, the provision id and token) go to
 * a separate file the host encrypts on its own.
 */
class SiteBackupCommands extends DrushCommands {

  /**
   * Seconds kept back from --max-pause, so the run ends before the pause does.
   */
  public const MARGIN = 60;

  /**
   * The file the secret record is written to, inside the destination.
   */
  public const SECRET_RECORD = 'secret-record.json';

  /**
   * Write tonight's copy to an empty folder and print one JSON line about it.
   */
  #[CLI\Command(name: 'localnodes:backup')]
  #[CLI\Option(name: 'destination', description: 'Absolute path of an empty or new folder outside the web root and the files folders.')]
  #[CLI\Option(name: 'expect-site-uuid', description: 'The site id this copy is for; the run refuses any other site.')]
  #[CLI\Option(name: 'with-secret-record', description: 'Also write secret-record.json (hash salt, provision id and token) for a restore.')]
  #[CLI\Option(name: 'drain-seconds', description: 'Seconds to wait after closing the site, so requests already running can finish.')]
  #[CLI\Option(name: 'max-pause', description: 'Longest time, in seconds, the site may stay closed.')]
  #[CLI\Usage(name: 'drush localnodes:backup --destination=/var/backup/staging --expect-site-uuid=<uuid>', description: 'Close the site, write the copy, reopen it.')]
  public function backup(array $options = [
    'destination' => self::REQ,
    'expect-site-uuid' => self::REQ,
    'with-secret-record' => FALSE,
    'drain-seconds' => 20,
    'max-pause' => 5400,
  ]): int {
    $destination = (string) $options['destination'];
    $expect = (string) $options['expect-site-uuid'];
    $drain = (int) $options['drain-seconds'];
    $max_pause = (int) $options['max-pause'];
    $private = (string) Settings::get('file_private_path', '');

    /** @var \Drupal\localnodes_platform\Export\SiteExporter $exporter */
    $exporter = \Drupal::service('localnodes_platform.site_exporter');

    // Refusals: nothing has been written yet, and nothing will be.
    $why = match (TRUE) {
      $private === '' => 'this site has no private files folder, so there is nowhere to keep the pause',
      $expect === '' => '--expect-site-uuid is required',
      $destination === '' => '--destination is required',
      $drain < 0 => '--drain-seconds cannot be negative',
      $max_pause <= $this->margin() + $drain => '--max-pause must be more than ' . ($this->margin() + $drain) . ' seconds',
      default => $this->refuseFolder($exporter, $destination),
    };
    if ($why !== NULL) {
      return $this->refuse($why);
    }

    if (!hash_equals($expect, (string) \Drupal::config('system.site')->get('uuid'))) {
      return $this->refuse('this site is not the one --expect-site-uuid names');
    }
    $uuid = $expect;
    $created_destination = !file_exists($destination);
    $started = FALSE;
    try {
      $result = (new \Drupal\localnodes_platform\Backup\BackupCapture())->run(new BackupPause($private), 'backup', $max_pause, $drain, $this->margin(), function (float $deadline) use ($destination, $options, &$started, &$capture_start, &$capture_end): array {
        $started = TRUE;
        $capture_start = gmdate('Y-m-d\TH:i:s\Z');
        $result = \Drupal::service('localnodes_platform.site_exporter')->export($destination, $this->dumper($deadline), NULL, $deadline);
        $capture_end = gmdate('Y-m-d\TH:i:s\Z');
        if ($options['with-secret-record']) $this->writeSecretRecord($destination . '/' . self::SECRET_RECORD);
        return $result;
      });
    }
    catch (\Throwable $e) {
      if (!$started) return $this->refuse(self::safeReason($e));
      $this->emptyFolder($destination, $created_destination);
      $this->logger()->error('Backup failed: ' . self::safeReason($e) . '. What it wrote was deleted and the site is open again.');
      return self::EXIT_FAILURE;
    }

    $manifest = $result['manifest'];
    $provision = Settings::get('localnodes_provision', []);
    $this->output()->writeln(json_encode([
      'site_uuid' => $uuid,
      'site_name' => $manifest['site_name'],
      'provision_id' => (string) ($provision['id'] ?? ''),
      'capture_start' => $capture_start,
      'capture_end' => $capture_end,
      'archive' => basename($result['path']),
      'archive_sha256' => hash_file('sha256', $result['path']),
      'archive_bytes' => filesize($result['path']),
      'build_commit' => $manifest['build']['commit'] ?? NULL,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return self::EXIT_SUCCESS;
  }

  /**
   * Why a run stopped, in words that can go to a log.
   *
   * Only our own fixed messages are passed on. Any other message can quote a
   * member's file name, a page title or a row (the export's secret check
   * names the file it found a secret in), so it is replaced by the
   * exception's class and code.
   */
  public static function safeReason(\Throwable $e): string {
    $own = [
      SiteExporter::DEADLINE_MESSAGE,
      'another backup or restore is running',
      'the site is already paused by another backup or restore',
      'The site is already paused by another backup or restore.',
      'web requests that began before the pause were still running at the time limit',
      'Cannot make the folder for the pause file.',
      'Cannot write the pause file.',
      'Cannot make the folder for the lock files.',
      'Cannot write the secret record (the name is taken).',
      'Cannot write the secret record.',
    ];
    if (in_array($e->getMessage(), $own, TRUE)) {
      return rtrim($e->getMessage(), '.');
    }
    return sprintf('%s (code %d), message withheld because it can name members\' files', get_class($e), (int) $e->getCode());
  }

  /**
   * The database dumper: drush sql:dump, stopped when the deadline passes.
   *
   * Protected so a test can run the command with a fake dump.
   */
  protected function dumper(float $deadline): callable {
    return function (string $sql_file, array $structure_only) use ($deadline): void {
      $process = Drush::drush(Drush::aliasManager()->getSelf(), 'sql:dump', [], [
        'result-file' => $sql_file,
        'structure-tables-list' => implode(',', $structure_only),
      ]);
      $process->setTimeout(self::timeLeft($deadline));
      // A timeout throws ProcessTimedOutException, which the exporter turns
      // into its deadline message; the process is killed first.
      $process->mustRun();
    };
  }

  /**
   * Seconds left before the deadline, at least one (a tool's time limit).
   */
  public static function timeLeft(float $deadline): float {
    return max(1.0, $deadline - microtime(TRUE));
  }

  /**
   * Seconds held back from --max-pause. Protected so a test can shorten it.
   */
  protected function margin(): int {
    return self::MARGIN;
  }

  /**
   * Why the destination is refused, or NULL.
   *
   * It must be absolute, outside the web root and the files folders (the
   * export's own check), not a link, and empty or not there yet.
   */
  private function refuseFolder(SiteExporter $exporter, string $destination): ?string {
    if ($why = $exporter->refuseDestination($destination)) {
      return "--destination is refused: $why";
    }
    if (is_link($destination)) {
      return '--destination is a link';
    }
    if (file_exists($destination)) {
      if (!is_dir($destination)) {
        return '--destination is not a folder';
      }
      if (array_diff(scandir($destination) ?: ['?'], ['.', '..']) !== []) {
        return '--destination is not empty';
      }
    }
    return NULL;
  }

  /**
   * Logs why the run refused and returns the failure code.
   */
  private function refuse(string $why): int {
    $this->logger()->error("Backup refused: $why. Nothing was written.");
    return self::EXIT_FAILURE;
  }

  /**
   * Writes the values a restore needs that the archive must not carry.
   *
   * Created exclusively, so an existing file or link of that name refuses,
   * and readable by its owner only. Never printed.
   */
  private function writeSecretRecord(string $file): void {
    $provision = Settings::get('localnodes_provision', []);
    $old_umask = umask(0077);
    try {
      $handle = @fopen($file, 'x');
      if ($handle === FALSE) {
        throw new \RuntimeException('Cannot write the secret record (the name is taken).');
      }
      $json = json_encode([
        'hash_salt' => (string) Settings::get('hash_salt', ''),
        'provision_id' => (string) ($provision['id'] ?? ''),
        'provision_callback_token' => (string) ($provision['callback_token'] ?? ''),
      ], JSON_UNESCAPED_SLASHES);
      // A short or empty record must fail the capture: a restore needs it.
      if ($json === FALSE || !BackupWrite::all($handle, $json . "\n") || !@chmod($file, 0600)) {
        @unlink($file);
        throw new \RuntimeException('Cannot write the secret record.');
      }
    }
    finally {
      umask($old_umask);
    }
  }

  /**
   * Deletes what a failed run left in the destination.
   *
   * The folder was empty or missing before the run, so everything in it is
   * this run's. A folder the run made is removed too.
   */
  private function emptyFolder(string $destination, bool $created): void {
    if (!is_dir($destination) || is_link($destination)) {
      return;
    }
    $file_system = \Drupal::service('file_system');
    foreach (array_diff(scandir($destination) ?: [], ['.', '..']) as $entry) {
      $file_system->deleteRecursive($destination . '/' . $entry);
    }
    if ($created) {
      @rmdir($destination);
    }
  }

}
