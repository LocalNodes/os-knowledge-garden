<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackCaptureFile;
use Drupal\Tests\UnitTestCase;

/**
 * Captured answers go to a private ordinary file and never to output.
 *
 * @group localnodes_demo_packs
 */
final class PackCaptureFileTest extends UnitTestCase {

  /**
   * A folder of its own for each test.
   */
  private string $dir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->dir = sys_get_temp_dir() . '/packcapture_' . bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @chmod($this->dir, 0700);
    foreach (scandir($this->dir) ?: [] as $name) {
      if ($name !== '.' && $name !== '..') {
        $path = "$this->dir/$name";
        is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
      }
    }
    rmdir($this->dir);
    parent::tearDown();
  }

  /**
   * Streams, relative paths, links, folders and devices are refused.
   */
  public function testRefusedDestinations(): void {
    // The control: an ordinary new file in an existing folder is accepted.
    $this->assertNull(PackCaptureFile::problem("$this->dir/capture.jsonl"));

    mkdir("$this->dir/sub");
    touch("$this->dir/target.jsonl");
    symlink("$this->dir/target.jsonl", "$this->dir/link.jsonl");
    symlink("$this->dir/sub", "$this->dir/linkdir");
    $refused = [
      '',
      'php://stdout',
      'php://output',
      'php://stderr',
      'php://memory',
      'php://filter/resource=' . "$this->dir/capture.jsonl",
      'file://' . "$this->dir/capture.jsonl",
      'data:text/plain,x',
      'capture.jsonl',
      "$this->dir/missing/capture.jsonl",
      "$this->dir/sub",
      "$this->dir/link.jsonl",
      "$this->dir/linkdir/capture.jsonl",
      "$this->dir/nul\0.jsonl",
    ];
    if (file_exists('/dev/null')) {
      $refused[] = '/dev/null';
    }
    foreach ($refused as $path) {
      $this->assertNotNull(PackCaptureFile::problem($path), 'Refused: ' . str_replace("\0", '\\0', $path));
    }
  }

  /**
   * A record reaches the file, nothing reaches output, and only the owner reads it.
   */
  public function testSentinelGoesToTheFileOnly(): void {
    $sentinel = 'SENTINEL-' . bin2hex(random_bytes(8));
    $path = "$this->dir/capture.jsonl";

    ob_start();
    PackCaptureFile::open($path);
    PackCaptureFile::append($path, "$sentinel\n");
    PackCaptureFile::append($path, "second\n");
    $printed = ob_get_clean();

    $this->assertSame('', $printed, 'Nothing is printed.');
    $this->assertSame("$sentinel\nsecond\n", file_get_contents($path), 'Records are appended whole, in order.');
    clearstatcache();
    $this->assertSame(0600, fileperms($path) & 0777, 'Only the owner can read the file.');
  }

  /**
   * A file that already exists with wider permissions is narrowed.
   */
  public function testExistingFileIsMadePrivate(): void {
    $path = "$this->dir/capture.jsonl";
    file_put_contents($path, "earlier\n");
    chmod($path, 0644);
    PackCaptureFile::open($path);
    clearstatcache();
    $this->assertSame(0600, fileperms($path) & 0777);
    PackCaptureFile::append($path, "later\n");
    $this->assertSame("earlier\nlater\n", file_get_contents($path), 'Earlier records are kept.');
  }

  /**
   * Refused destinations throw without writing the line or naming it.
   */
  public function testRefusedWritesThrowWithoutTheLine(): void {
    $sentinel = 'SENTINEL-' . bin2hex(random_bytes(8));
    foreach (['php://stdout', 'php://output'] as $path) {
      ob_start();
      try {
        PackCaptureFile::append($path, $sentinel);
        $this->fail("append() accepted $path.");
      }
      catch (\RuntimeException $e) {
        $this->assertStringNotContainsString($sentinel, $e->getMessage());
      }
      finally {
        $printed = ob_get_clean();
      }
      $this->assertSame('', $printed, "Nothing reaches output through $path.");
    }

    // A file swapped for a link after open() is refused, and its target is
    // left as it was.
    $path = "$this->dir/capture.jsonl";
    PackCaptureFile::open($path);
    unlink($path);
    file_put_contents("$this->dir/other.jsonl", "other\n");
    symlink("$this->dir/other.jsonl", $path);
    try {
      PackCaptureFile::append($path, $sentinel);
      $this->fail('append() wrote through a link.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringNotContainsString($sentinel, $e->getMessage());
    }
    $this->assertSame("other\n", file_get_contents("$this->dir/other.jsonl"));

    // A file never opened is refused rather than created by append().
    $this->expectException(\RuntimeException::class);
    PackCaptureFile::append("$this->dir/never-opened.jsonl", $sentinel);
  }

  /**
   * A file that cannot be created is an error, not a silent skip.
   */
  public function testUncreatableFileThrows(): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
      $this->markTestSkipped('The superuser can write to a read-only folder.');
    }
    mkdir("$this->dir/locked", 0500);
    try {
      PackCaptureFile::open("$this->dir/locked/capture.jsonl");
      $this->fail('open() did not report the failure.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('capture file could not be opened', $e->getMessage());
    }
    finally {
      chmod("$this->dir/locked", 0700);
    }
  }

}
