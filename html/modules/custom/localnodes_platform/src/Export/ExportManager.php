<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\localnodes_platform\Backup\BackupCapture;
use Drupal\localnodes_platform\Backup\BackupPause;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One durable slot, processed only by the dedicated CLI consumer. */
class ExportManager {

  public const TTL = 86400;
  public const CEILING = 5368709120;
  public const FLOOR = 1073741824;

  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $config,
    protected readonly EntityTypeManagerInterface $entities,
    protected readonly ExportAccess $access,
    protected readonly TimeInterface $time,
    protected readonly SiteExporter $exporter,
    protected readonly LibraryWriter $library,
    protected readonly BackupCapture $capture,
    protected readonly LoggerChannelFactoryInterface $logger,
    protected readonly mixed $emailFactory = NULL,
  ) {}

  public function files(): ExportFiles {
    return new ExportFiles((string) Settings::get('file_private_path', ''), DRUPAL_ROOT);
  }

  public function current(): array { return $this->read('current'); }
  public function last(): array { return $this->read('last'); }

  protected function read(string $key): array {
    $data = $this->database->select('localnodes_site_export', 'e')->fields('e', ['data'])->condition('id', $key)->execute()->fetchField();
    $value = $data ? @unserialize($data, ['allowed_classes' => FALSE]) : [];
    return is_array($value) ? $value : [];
  }

  protected function save(string $key, array $value): void {
    $this->database->merge('localnodes_site_export')->key(['id' => $key])->fields(['data' => serialize($value)])->execute();
  }

  private function locked(callable $operation): mixed {
    $pause = new BackupPause((string) Settings::get('file_private_path', ''));
    // Validate the site's private mount before creating any lock file.
    $this->files()->root();
    $lock = $pause->openLock('export-slot.lock');
    try {
      if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Export is unavailable.');
      return $operation();
    }
    finally { flock($lock, LOCK_UN); fclose($lock); }
  }

  public function request(int $uid): array {
    if (!$this->access->allows($uid)) throw new \RuntimeException('Export is unavailable.');
    return $this->locked(function () use ($uid): array {
      if (!$this->access->allows($uid)) throw new \RuntimeException('Export is unavailable.');
      $job = $this->current();
      if ($this->available($job) || in_array($job['status'] ?? '', ['queued', 'running'], TRUE)) return $job;
      // A previous artifact must be removed before replacing its one slot.
      if ($job && !$this->files()->remove($job['id'])) throw new \RuntimeException('Export is unavailable.');
      $job = [
        'id' => bin2hex(random_bytes(16)), 'site' => $this->site(), 'uid' => $uid,
        'requested' => $this->time->getCurrentTime(), 'status' => 'queued',
        'parts' => [], 'notices' => ['requested' => []],
      ];
      try { $this->space(); }
      catch (\Throwable $e) { $job['status'] = 'failed'; $job['failure'] = $e instanceof ExportSpaceException ? 'space' : 'prepare'; $job['notices']['failed'] = []; }
      $this->save('current', $job);
      return $job;
    });
  }

  public function available(array $job): bool {
    return ($job['status'] ?? '') === 'ready' && ($job['site'] ?? '') === $this->site()
      && ($job['expires'] ?? 0) > $this->time->getCurrentTime()
      && $this->access->allows((int) ($job['uid'] ?? 0)) && $this->files()->archive($job) !== NULL;
  }

  public function download(int $uid, string $id): ?StreamedResponse {
    $job = $this->current();
    if (!$this->access->allows($uid) || ($job['id'] ?? '') !== $id || !$this->available($job)) return NULL;
    $file = $this->files()->archive($job);
    if (!$file) return NULL;
    return ExportStream::response($file);
  }

  public function delete(int $uid, string $id): bool {
    if (!$this->access->allows($uid)) return FALSE;
    return $this->locked(function () use ($id, $uid): bool {
      $job = $this->current();
      if (!$this->access->allows($uid) || ($job['id'] ?? '') !== $id || ($job['status'] ?? '') !== 'ready') return FALSE;
      // Deny bytes first, even if physical removal must be retried.
      $job['status'] = 'expired';
      $this->save('current', $job);
      return $this->files()->remove($id);
    });
  }

  public function cleanup(): void {
    $this->locked(function (): void {
      $job = $this->current();
      if (!$job) return;
      $invalid = ($job['site'] ?? '') !== $this->site() || !$this->access->allows((int) $job['uid']);
      if (($job['status'] === 'ready' && !$this->available($job)) || ($invalid && in_array($job['status'], ['queued', 'running'], TRUE))) {
        $job['status'] = 'expired';
        $this->save('current', $job);
      }
      if (in_array($job['status'], ['expired', 'failed'], TRUE) && !$this->files()->remove($job['id'])) {
        $this->logger->get('localnodes_platform')->warning('Site export cleanup is pending.');
      }
    });
  }

  /** CLI only; no Drupal queue plugin or web cron can invoke a capture. */
  public function work(): void {
    $this->files()->root(TRUE);
    $pause = new BackupPause((string) Settings::get('file_private_path', ''));
    $consumer = $pause->openLock('export-consumer.lock');
    if (!flock($consumer, LOCK_EX | LOCK_NB)) { fclose($consumer); return; }
    try {
      $this->cleanup();
      $this->notify();
      $job = $this->current();
      if (!in_array($job['status'] ?? '', ['queued', 'running'], TRUE)) return;
      try {
        $this->space();
        if (!$this->versionKnown()) throw new \RuntimeException('The software version is unavailable.');
        $result = $this->capture->run($pause, 'export', 5400, 20, 60, function (float $deadline) use ($job): ?array {
          $current = $this->current();
          if (($current['id'] ?? '') !== $job['id'] || !$this->access->allows((int) $job['uid'])) return NULL;
          // The exclusive consumer lock proves a running record is orphaned.
          if ($current['status'] === 'running') throw new \RuntimeException('A previous export was interrupted.');
          $current['status'] = 'running';
          $this->save('current', $current);
          $dir = $this->files()->directory($job['id'], TRUE);
          $result = $this->produce($dir, $deadline, function (string $part, int $bytes) use ($job): void {
            $this->locked(function () use ($job, $part, $bytes): void {
              $latest = $this->current();
              if (($latest['id'] ?? '') === $job['id'] && $latest['status'] === 'running') {
                $latest['parts'][$part] = $bytes;
                $this->save('current', $latest);
              }
            });
          });
          $this->space();
          return $result + ['deadline' => $deadline];
        });
        if ($result !== NULL) {
          $this->locked(function () use ($job, $result): void {
            $latest = $this->current();
            if (($latest['id'] ?? '') !== $job['id'] || $latest['status'] !== 'running' || !$this->access->allows((int) $job['uid']) || $job['site'] !== $this->site() || microtime(TRUE) >= $result['deadline']) {
              if (($latest['id'] ?? '') === $job['id']) { $latest['status'] = 'expired'; $this->save('current', $latest); }
              $this->files()->remove($job['id']);
              return;
            }
            $latest['archive'] = basename($result['path']);
            if ($this->files()->archive($latest) !== $result['path']) throw new \RuntimeException('Export storage is unavailable.');
            $latest['status'] = 'ready';
            $latest['ready'] = $this->time->getCurrentTime();
            $latest['expires'] = $latest['ready'] + self::TTL;
            $latest['bytes'] = filesize($result['path']);
            $latest['notices']['ready'] = [];
            $this->save('current', $latest);
            $this->save('last', ['uid' => $job['uid'], 'time' => $latest['ready']]);
          });
        }
      }
      catch (\Throwable $e) {
        if ($e->getCode() !== 409) {
          $this->locked(function () use ($job, $e): void {
            $latest = $this->current();
            if (($latest['id'] ?? '') !== $job['id']) return;
            $latest['status'] = 'failed';
            $latest['failure'] = $e instanceof ExportSpaceException ? 'space' : 'prepare';
            $latest['notices']['failed'] = [];
            $this->save('current', $latest);
            $this->files()->remove($job['id']);
          });
          // Never include exception messages, paths, account data or links.
          $this->logger->get('localnodes_platform')->warning('Site export preparation failed.');
        }
      }
      $this->notify();
    }
    finally { flock($consumer, LOCK_UN); fclose($consumer); }
  }

  protected function produce(string $directory, float $deadline, callable $progress): array {
    $dumper = function (string $file, array $structure) use ($deadline): void {
      $process = \Drush\Drush::drush(\Drush\Drush::aliasManager()->getSelf(), 'sql:dump', [], ['result-file' => $file, 'structure-tables-list' => implode(',', $structure)]);
      $process->setTimeout(max(1, $deadline - microtime(TRUE)));
      $process->mustRun();
    };
    return $this->exporter->export($directory, $dumper, $this->library, $deadline, $progress);
  }

  protected function versionKnown(): bool { return SiteExporter::buildCommit() !== NULL; }

  protected function site(): string { return (string) $this->config->get('system.site')->get('uuid'); }

  protected function estimate(): int {
    if ($this->database->databaseType() === 'mysql') {
      $db = (int) $this->database->query('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchField();
    }
    else {
      $db = (int) $this->database->query('PRAGMA page_count')->fetchField() * (int) $this->database->query('PRAGMA page_size')->fetchField();
    }
    $bytes = 0;
    foreach ([(string) Settings::get('file_public_path', ''), (string) Settings::get('file_private_path', '')] as $path) {
      if (!$path || !is_dir($path)) continue;
      $root = realpath($path);
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ($file->isFile() && !$file->isLink() && !in_array(explode('/', $relative)[0], array_merge(SiteExporter::PUBLIC_SKIP, SiteExporter::PRIVATE_SKIP), TRUE)) $bytes += $file->getSize();
      }
    }
    // Avoid rendering every library page during preflight. Allow one further
    // copy of eligible file bytes for library attachments, independent of pages.
    return max(1048576, $db + 2 * $bytes);
  }

  protected function space(): void {
    $estimate = $this->estimate();
    $free = $this->freeBytes();
    if ($estimate > self::CEILING || $free === FALSE || $free < 3 * $estimate + self::FLOOR) throw new ExportSpaceException();
  }

  protected function freeBytes(): float|false {
    return @disk_free_space($this->files()->root(TRUE));
  }

  /** Fixed text, page-only links; retry unsent recipients without losing ready. */
  protected function notify(): void {
    $job = $this->current();
    if (!$job || !$this->emailFactory) return;
    $ids = $this->entities->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('status', 1)->condition('roles', 'sitemanager')->execute();
    foreach ($job['notices'] as $event => $sent) {
      if ($event === 'ready' && !$this->available($job)) continue;
      foreach ($ids as $uid) {
        if (isset($sent[$uid])) continue;
        try {
          $user = $this->access->account((int) $uid);
          // Transport errors may name a recipient. Filter all logger-factory
          // output during this send and give the mailer a non-admin caller,
          // so its own messenger also chooses its generic error sentence.
          $switcher = \Drupal::service('account_switcher');
          $switcher->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
          try {
            $delivered = \Drupal\localnodes_platform\Logger\ExportNoticeLogs::run(fn () => $this->emailFactory->newTypedEmail('localnodes_export', $event, $user, $job)->send());
          }
          finally { $switcher->switchBack(); }
          if (!$delivered) continue;
          $this->locked(function () use ($job, $event, $uid): void {
            $latest = $this->current();
            if (($latest['id'] ?? '') === $job['id']) {
              $latest['notices'][$event][$uid] = TRUE;
              $this->save('current', $latest);
            }
          });
        }
        catch (\Throwable) { $this->logger->get('localnodes_platform')->warning('Site export notice is pending.'); }
      }
    }
  }

}
