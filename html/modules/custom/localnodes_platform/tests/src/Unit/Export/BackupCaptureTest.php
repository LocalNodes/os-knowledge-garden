<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\localnodes_platform\Backup\BackupCapture;
use Drupal\localnodes_platform\Backup\BackupPause;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class BackupCaptureTest extends UnitTestCase {

  public function testFailureReleasesOnlyItsPauseAndLocks(): void {
    $base = sys_get_temp_dir() . '/export-capture-' . bin2hex(random_bytes(8));
    $pause = new BackupPause($base);
    try {
      try {
        (new BackupCapture())->run($pause, 'export', 60, 0, 0, function (float $deadline) use ($pause): void {
          $this->assertSame('export', $pause->active()['reason']);
          $this->assertGreaterThan(microtime(TRUE), $deadline);
          throw new \RuntimeException('fixture');
        });
        $this->fail('The copy failure must propagate.');
      }
      catch (\RuntimeException $e) {
        $this->assertSame('fixture', $e->getMessage());
      }
      $this->assertNull($pause->active());
      $theirs = $pause->create('restore', NULL);
      try {
        (new BackupCapture())->run($pause, 'export', 60, 0, 0, fn () => $this->fail('An existing pause refuses the copy.'));
      }
      catch (\RuntimeException) {}
      $this->assertSame($theirs, $pause->active()['token']);
      $pause->release($theirs);
    }
    finally {
      foreach (glob($base . '/.localnodes/*') ?: [] as $file) @unlink($file);
      @rmdir($base . '/.localnodes');
      @rmdir($base);
    }
  }

  /**
   * A pause the capture cannot lift is reported, never passed as done: the
   * site stays closed, and a copy that failed keeps its own error.
   */
  public function testUnliftedPauseIsReported(): void {
    $base = sys_get_temp_dir() . '/export-capture-' . bin2hex(random_bytes(8));
    $pause = new BackupPause($base, 0.2);
    $held = NULL;
    // The pause lock stays taken, so the capture cannot lift its pause.
    $hold = function () use ($pause, &$held): void {
      $held = $pause->openLock('pause.lock');
      $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));
    };
    $let_go = function () use (&$held): void {
      flock($held, LOCK_UN);
      fclose($held);
    };
    try {
      try {
        (new BackupCapture())->run($pause, 'export', 60, 0, 0, function () use ($hold): string {
          $hold();
          return 'copied';
        });
        $this->fail('A pause left in place must not count as a finished capture.');
      }
      catch (\RuntimeException $e) {
        $this->assertSame('The pause could not be lifted; the site stays closed until the pause ends or an operator clears it.', $e->getMessage());
        $this->assertNull($e->getPrevious());
      }
      $this->assertSame('export', $pause->active()['reason'], 'The pause is still in force.');
      $let_go();
      $pause->clear();

      try {
        (new BackupCapture())->run($pause, 'export', 60, 0, 0, function () use ($hold): void {
          $hold();
          throw new \RuntimeException('fixture');
        });
        $this->fail('The failure must propagate.');
      }
      catch (\RuntimeException $e) {
        $this->assertSame('The pause could not be lifted; the site stays closed until the pause ends or an operator clears it.', $e->getMessage());
        $this->assertSame('fixture', $e->getPrevious()?->getMessage(), 'The copy\'s own error is kept.');
      }
      $let_go();
      $pause->clear();

      // An unreadable pause file left behind keeps the site closed with no
      // time limit, so it is reported as not lifted, with the copy's error.
      try {
        (new BackupCapture())->run($pause, 'export', 60, 0, 0, function () use ($hold, $pause): void {
          $hold();
          file_put_contents($pause->file(), 'not a pause');
          throw new \RuntimeException('fixture');
        });
        $this->fail('An unreadable pause must not count as lifted.');
      }
      catch (\RuntimeException $e) {
        $this->assertSame('The pause could not be lifted; the site stays closed until the pause ends or an operator clears it.', $e->getMessage());
        $this->assertSame('fixture', $e->getPrevious()?->getMessage(), 'The copy\'s own error is kept.');
      }
      $this->assertSame('unreadable', $pause->active()['reason'], 'The site is still closed.');
      $let_go();
      $pause->clear();
      // Both locks were let go.
      $this->assertSame('again', (new BackupCapture())->run($pause, 'export', 60, 0, 0, fn () => 'again'));
      $this->assertNull($pause->active());
    }
    finally {
      foreach (glob($base . '/.localnodes/*') ?: [] as $file) @unlink($file);
      @rmdir($base . '/.localnodes');
      @rmdir($base);
    }
  }

}
