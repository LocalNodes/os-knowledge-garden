<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Backup\BackupPause;
use Drupal\localnodes_platform\Drush\Commands\SiteBackupCommands;
use Drupal\localnodes_platform\Export\SiteExporter;
use Drush\Log\DrushLoggerManager;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * drush localnodes:backup: refusals, the pause, the deadline, the outputs.
 *
 * The command closes the site while it copies it. A refused run must change
 * nothing; a failed or late run must reopen the site and leave nothing in
 * the destination; the pause it removes must be its own. The dumper is a
 * fake (SQL text, or a slow process), so the test runs on SQLite as well.
 *
 * @group localnodes_platform
 * @group phase22
 */
final class BackupCommandTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'key', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  private const SALT = 'backup-test-hash-salt-3c9e71d04b2a';
  private const PROVISION_TOKEN = 'backup-test-callback-token-e5a2f9';

  /**
   * Real folder holding public, private and the destinations.
   */
  private string $base;

  /**
   * Messages the command logged.
   *
   * @var string[]
   */
  private array $logged = [];

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->base = sys_get_temp_dir() . '/ln-backup-test-' . bin2hex(random_bytes(6));
    mkdir($this->base . '/public', 0775, TRUE);
    mkdir($this->base . '/private', 0775, TRUE);
    $settings = Settings::getAll();
    $settings['file_public_path'] = $this->base . '/public';
    $settings['file_private_path'] = $this->base . '/private';
    $settings['hash_salt'] = self::SALT;
    $settings['localnodes_provision'] = ['id' => 'prov-test-1', 'callback_token' => self::PROVISION_TOKEN];
    new Settings($settings);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->config('system.site')->set('name', 'Juniper Test House')->set('uuid', '5f0c2a8e-1b7d-4c3e-9a61-0d4e8b2f7c19')->save();
    file_put_contents($this->base . '/public/picture.txt', 'a public picture');
    file_put_contents($this->base . '/private/minutes.txt', 'private minutes');
    $spec = ['fields' => ['id' => ['type' => 'int', 'not null' => TRUE]], 'primary key' => ['id']];
    $this->container->get('database')->schema()->createTable('backuptest_content', $spec);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->base) && is_dir($this->base)) {
      (new Process(['rm', '-rf', $this->base]))->run();
    }
    parent::tearDown();
  }

  /**
   * The pause helper for this site's private folder.
   */
  private function pause(): BackupPause {
    return new BackupPause($this->base . '/private');
  }

  /**
   * The site's id.
   */
  private function uuid(): string {
    return (string) $this->config('system.site')->get('uuid');
  }

  /**
   * Runs the command with a given dumper and returns [exit code, printed].
   *
   * @param callable $dumper
   *   Gets the deadline, returns the dump callable.
   * @param array $options
   *   Options over the defaults (drain 0, margin 0, max-pause 60).
   */
  private function backup(callable $dumper, array $options = []): array {
    $command = new class($dumper) extends SiteBackupCommands {

      public function __construct(private $make) {
        parent::__construct();
      }

      protected function dumper(float $deadline): callable {
        return ($this->make)($deadline);
      }

      protected function margin(): int {
        return 0;
      }

    };
    $logger = new DrushLoggerManager();
    $sink = function (string $line): void {
      $this->logged[] = $line;
    };
    $logger->add('test', new class($sink) extends AbstractLogger {

      public function __construct(private \Closure $sink) {}

      public function log($level, string|\Stringable $message, array $context = []): void {
        ($this->sink)("$level: $message");
      }

    });
    $command->setLogger($logger);
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output = new BufferedOutput());
    $code = $command->backup($options + [
      'destination' => $this->base . '/out',
      'expect-site-uuid' => $this->uuid(),
      'with-secret-record' => FALSE,
      'drain-seconds' => 0,
      'max-pause' => 60,
    ]);
    return [$code, $output->fetch()];
  }

  /**
   * A dumper that writes SQL text and runs a check while the site is closed.
   */
  private function sql(?callable $during = NULL): callable {
    return function (float $deadline) use ($during): callable {
      return function (string $file, array $structure_only) use ($during): void {
        if ($during) {
          $during($file);
        }
        file_put_contents($file, "CREATE TABLE `backuptest_content` (`id` int);\n");
      };
    };
  }

  /**
   * Whether the destination is missing or empty.
   */
  private function assertNothingLeft(string $folder): void {
    if (is_dir($folder)) {
      $this->assertSame([], array_values(array_diff(scandir($folder), ['.', '..'])), 'The destination is empty.');
    }
    else {
      $this->assertFileDoesNotExist($folder);
    }
  }

  /**
   * Asserts the last log line is a refusal naming the reason.
   */
  private function assertRefused(string $reason): void {
    $last = end($this->logged);
    $this->assertStringContainsString('Backup refused: ' . $reason, (string) $last);
    $this->assertStringContainsString('Nothing was written.', (string) $last);
  }

  /**
   * Unpacks the archive and returns the export folder.
   */
  private function unpack(string $archive): string {
    $out = $this->base . '/unpacked-' . bin2hex(random_bytes(3));
    mkdir($out);
    (new Process(['tar', '-xf', $archive, '-C', $out]))->mustRun();
    $folders = glob($out . '/localnodes-export-*', GLOB_ONLYDIR);
    $this->assertCount(1, $folders);
    return $folders[0];
  }

  /**
   * A good run: closed while copying, reopened after, JSON line, manifest.
   */
  public function testGoodRun(): void {
    $seen = NULL;
    [$code, $printed] = $this->backup($this->sql(function () use (&$seen): void {
      $seen = $this->pause()->active();
    }));
    $this->assertSame(0, $code, implode("\n", $this->logged));
    $this->assertSame('backup', $seen['reason'] ?? NULL, 'The site was closed during the dump.');
    $this->assertNull($this->pause()->read(), 'The site is open again.');

    $line = json_decode(trim($printed), TRUE);
    $this->assertIsArray($line);
    $this->assertSame(['site_uuid', 'site_name', 'provision_id', 'capture_start', 'capture_end', 'archive', 'archive_sha256', 'archive_bytes', 'build_commit'], array_keys($line));
    $this->assertSame($this->uuid(), $line['site_uuid']);
    $this->assertSame('prov-test-1', $line['provision_id']);
    $archive = $this->base . '/out/' . $line['archive'];
    $this->assertSame(hash_file('sha256', $archive), $line['archive_sha256']);
    $this->assertSame(filesize($archive), $line['archive_bytes']);
    $this->assertStringNotContainsString(self::SALT, $printed);
    $this->assertStringNotContainsString(self::PROVISION_TOKEN, $printed);
    $this->assertFileDoesNotExist($this->base . '/out/' . SiteBackupCommands::SECRET_RECORD);

    $manifest = json_decode(file_get_contents($this->unpack($archive) . '/manifest.json'), TRUE);
    $this->assertSame($this->uuid(), $manifest['site_uuid']);
  }

  /**
   * The secret record: fixed name, 0600, the effective salt; not in the archive.
   */
  public function testSecretRecord(): void {
    [$code, $printed] = $this->backup($this->sql(), ['with-secret-record' => TRUE]);
    $this->assertSame(0, $code, implode("\n", $this->logged));
    $record = $this->base . '/out/' . SiteBackupCommands::SECRET_RECORD;
    $this->assertSame(0600, fileperms($record) & 0777);
    $this->assertSame([
      'hash_salt' => self::SALT,
      'provision_id' => 'prov-test-1',
      'provision_callback_token' => self::PROVISION_TOKEN,
    ], json_decode(file_get_contents($record), TRUE));
    $this->assertStringNotContainsString(self::SALT, $printed);

    $line = json_decode(trim($printed), TRUE);
    $folder = $this->unpack($this->base . '/out/' . $line['archive']);
    $text = gzdecode(file_get_contents($folder . '/database.sql.gz'));
    foreach (['public-files.tar.gz', 'private-files.tar.gz'] as $part) {
      $text .= (new Process(['tar', '-xzOf', $folder . '/' . $part]))->mustRun()->getOutput();
    }
    foreach (['manifest.json', 'RESTORE.md', 'SHA256SUMS'] as $file) {
      $text .= file_get_contents($folder . '/' . $file);
    }
    $this->assertStringContainsString('private minutes', $text, 'The archive was read.');
    $this->assertStringNotContainsString(self::SALT, $text);
    $this->assertStringNotContainsString(self::PROVISION_TOKEN, $text);
  }

  /**
   * A file or link already at the record's name fails the run, unwritten.
   */
  public function testSecretRecordRefusesTakenName(): void {
    $target = $this->base . '/elsewhere.txt';
    foreach (['link', 'file'] as $kind) {
      file_put_contents($target, 'untouched');
      [$code] = $this->backup($this->sql(function (string $sql) use ($kind, $target): void {
        // The dump runs in the staging folder inside the destination.
        $record = $this->base . '/out/' . SiteBackupCommands::SECRET_RECORD;
        $kind === 'link' ? symlink($target, $record) : file_put_contents($record, 'taken');
      }), ['with-secret-record' => TRUE]);
      $this->assertSame(1, $code, $kind);
      $this->assertStringContainsString('the name is taken', end($this->logged));
      $this->assertSame('untouched', file_get_contents($target), $kind);
      $this->assertNothingLeft($this->base . '/out');
      $this->assertNull($this->pause()->read(), "$kind: the site is open again.");
    }
  }

  /**
   * A wrong site id refuses before any pause or folder exists.
   */
  public function testWrongSiteRefused(): void {
    $dumped = FALSE;
    [$code] = $this->backup($this->sql(function () use (&$dumped): void {
      $dumped = TRUE;
    }), ['expect-site-uuid' => 'not-this-site']);
    $this->assertSame(1, $code);
    $this->assertRefused('this site is not the one --expect-site-uuid names');
    $this->assertFalse($dumped);
    $this->assertFileDoesNotExist($this->pause()->file());
    $this->assertFileDoesNotExist($this->base . '/out');
  }

  /**
   * A held backup lock refuses and leaves the other run's pause alone.
   */
  public function testHeldLockRefused(): void {
    $pause = $this->pause();
    $theirs = $pause->create('restore', NULL);
    $lock = $pause->openLock('backup.lock');
    $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
    try {
      [$code] = $this->backup($this->sql());
    }
    finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
    $this->assertSame(1, $code);
    $this->assertRefused('another backup or restore is running');
    $this->assertSame($theirs, $pause->read()['token'] ?? NULL);
    $this->assertFileDoesNotExist($this->base . '/out');
  }

  /**
   * A pause in force (lock free) refuses and stays; an expired one does not.
   */
  public function testExistingPauseRefused(): void {
    $pause = $this->pause();
    $theirs = $pause->create('backup', time() + 600);
    [$code] = $this->backup($this->sql());
    $this->assertSame(1, $code);
    $this->assertRefused('the site is already paused by another backup or restore');
    $this->assertSame($theirs, $pause->read()['token'] ?? NULL);
    $this->assertFileDoesNotExist($this->base . '/out');

    // An expired pause is a run that was killed: it no longer counts.
    file_put_contents($pause->file(), json_encode(['token' => 'old', 'until' => time() - 5, 'reason' => 'backup']));
    [$code] = $this->backup($this->sql());
    $this->assertSame(0, $code, implode("\n", $this->logged));
    $this->assertNull($pause->read());
  }

  /**
   * A failing dump reopens the site, frees the locks, leaves nothing.
   */
  public function testFailingDump(): void {
    [$code] = $this->backup(fn (float $deadline): callable => function (string $file): void {
      file_put_contents($file, 'partial');
      throw new \RuntimeException('dump failed');
    });
    $this->assertSame(1, $code);
    $this->assertStringContainsString('Backup failed: RuntimeException (code 0)', end($this->logged));
    $this->assertNothingLeft($this->base . '/out');
    $this->assertNull($this->pause()->read());
    foreach (['backup.lock', 'worker.lock'] as $name) {
      $handle = $this->pause()->openLock($name);
      $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB), "$name is free.");
      fclose($handle);
    }
  }

  /**
   * A failure whose message names a member's file logs class and code only.
   */
  public function testFailureMessageWithheld(): void {
    [$code, $out] = $this->backup(fn (float $deadline): callable => function (string $file): void {
      throw new \RuntimeException('Secret check failed: the mail key was found in private-files/SENTINEL-ana-rent-letter.pdf. The archive was deleted.', 7);
    });
    $this->assertSame(1, $code);
    $all = implode("\n", $this->logged) . $out;
    $this->assertStringNotContainsString('SENTINEL', $all);
    $this->assertStringContainsString('RuntimeException (code 7)', end($this->logged));
    $this->assertNull($this->pause()->read());
  }

  /**
   * A web request still running when the site closes is waited for.
   *
   * Another process holds the shared request lock, as a request does from
   * settings.php until it ends. The copy may not start while it runs.
   */
  public function testRunningWebRequestWaitedFor(): void {
    $lock = $this->pause()->lockFile(BackupPause::REQUEST_LOCK);
    // The site's .localnodes folder, as a running site has it.
    fclose($this->pause()->openLock(BackupPause::REQUEST_LOCK));
    $ready = $this->base . '/request-ready';
    $request = new Process(['php', '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_SH); touch($argv[2]); usleep(1500000);', $lock, $ready]);
    $request->start();
    $waited = 0;
    while (!file_exists($ready) && $waited++ < 100) {
      usleep(50000);
    }
    $this->assertFileExists($ready, 'The request holds its lock.');
    $running_at_copy = NULL;
    [$code] = $this->backup($this->sql(function () use ($request, &$running_at_copy): void {
      $running_at_copy = $request->isRunning();
    }));
    $this->assertSame(0, $code);
    $this->assertFalse($running_at_copy, 'The copy began only after the running request ended.');
  }

  /**
   * A dump still running at the deadline is killed and leaves nothing.
   */
  public function testSlowDumpKilled(): void {
    $started = microtime(TRUE);
    [$code] = $this->backup(fn (float $deadline): callable => function (string $file) use ($deadline): void {
      $process = new Process(['sleep', '30']);
      $process->setTimeout(SiteBackupCommands::timeLeft($deadline));
      $process->mustRun();
    }, ['max-pause' => 3]);
    $this->assertSame(1, $code);
    $this->assertLessThan(10, microtime(TRUE) - $started, 'The dump was stopped, not waited for.');
    $this->assertStringContainsString(SiteExporter::DEADLINE_MESSAGE, end($this->logged));
    $this->assertNothingLeft($this->base . '/out');
    $this->assertNull($this->pause()->read());
  }

  /**
   * A quick dump, then a later step past the deadline: stopped, nothing left.
   */
  public function testLateStepStopped(): void {
    [$code] = $this->backup(fn (float $deadline): callable => function (string $file) use ($deadline): void {
      file_put_contents($file, "CREATE TABLE `t` (`id` int);\n");
      // The dump returns on time-left zero; whatever comes next is late.
      while (microtime(TRUE) < $deadline + 0.05) {
        usleep(50000);
      }
    }, ['max-pause' => 2]);
    $this->assertSame(1, $code);
    $this->assertStringContainsString(SiteExporter::DEADLINE_MESSAGE, end($this->logged));
    $this->assertNothingLeft($this->base . '/out');
    $this->assertNull($this->pause()->read());
  }

  /**
   * The run removes only its own pause, never one with another token.
   */
  public function testOtherTokenKept(): void {
    $other = NULL;
    [$code] = $this->backup($this->sql(function () use (&$other): void {
      // Someone cleared this run's pause and another run paused the site.
      $pause = $this->pause();
      $pause->clear();
      $other = $pause->create('restore', NULL);
    }));
    $this->assertSame(0, $code, implode("\n", $this->logged));
    $this->assertNotNull($other);
    $this->assertSame($other, $this->pause()->read()['token'] ?? NULL);
  }

}
