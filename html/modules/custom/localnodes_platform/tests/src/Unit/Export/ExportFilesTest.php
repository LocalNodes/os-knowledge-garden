<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\localnodes_platform\Export\ExportFiles;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class ExportFilesTest extends UnitTestCase {

  public function testPrivateConfinementAndCleanup(): void {
    $base = sys_get_temp_dir() . '/export-files-' . bin2hex(random_bytes(8));
    mkdir($base . '/private', 0700, TRUE);
    mkdir($base . '/web', 0700);
    $files = new ExportFiles($base . '/private', $base . '/web');
    $id = str_repeat('a', 32);
    try {
      $dir = $files->directory($id, TRUE);
      $this->assertSame(0700, fileperms($dir) & 0777);
      file_put_contents($dir . '/copy.tar', 'private');
      chmod($dir . '/copy.tar', 0600);
      $this->assertSame($dir . '/copy.tar', $files->archive(['id' => $id, 'archive' => 'copy.tar']));
      symlink($dir . '/copy.tar', $dir . '/link.tar');
      $this->assertNull($files->archive(['id' => $id, 'archive' => 'link.tar']));
      $this->assertNull($files->archive(['id' => $id, 'archive' => '../copy.tar']));
      $this->assertTrue($files->remove($id));
      $this->assertDirectoryDoesNotExist($dir);
      symlink($base . '/web', $base . '/private/site-exports/' . $id);
      $this->expectException(\RuntimeException::class);
      $files->directory($id);
    }
    finally {
      @unlink($base . '/private/site-exports/' . $id);
      @rmdir($base . '/private/site-exports');
      @rmdir($base . '/private');
      @rmdir($base . '/web');
      @rmdir($base);
    }
  }

  public function testPublicPrivateRootRefused(): void {
    $this->expectException(\RuntimeException::class);
    (new ExportFiles(DRUPAL_ROOT, DRUPAL_ROOT))->directory(str_repeat('a', 32), TRUE);
  }

  public function testMissingPrivateRootIsNeverTheWorkingDirectory(): void {
    $previous = getcwd();
    chdir(sys_get_temp_dir());
    try {
      $this->expectException(\RuntimeException::class);
      (new ExportFiles('', DRUPAL_ROOT))->root();
    }
    finally { chdir($previous); }
  }

}
