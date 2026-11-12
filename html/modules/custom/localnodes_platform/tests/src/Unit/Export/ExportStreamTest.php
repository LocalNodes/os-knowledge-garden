<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\localnodes_platform\Export\ExportStream;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class ExportStreamTest extends UnitTestCase {

  public function testAnAuthorizedOpenDownloadSurvivesExpiryCleanup(): void {
    $path = tempnam(sys_get_temp_dir(), 'export-stream-');
    file_put_contents($path, 'private archive bytes');
    $response = ExportStream::response($path);
    unlink($path);
    $this->assertNotNull($response);
    $this->assertSame('21', $response->headers->get('Content-Length'));
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    $this->assertSame('attachment; filename="site-export.tar"', $response->headers->get('Content-Disposition'));
    ob_start();
    try { $response->sendContent(); $bytes = ob_get_contents(); }
    finally { ob_end_clean(); }
    $this->assertSame('private archive bytes', $bytes);
  }

  public function testAFileThatIsAlreadyGoneHasNoResponse(): void {
    $this->assertNull(ExportStream::response(sys_get_temp_dir() . '/missing-export-' . bin2hex(random_bytes(8))));
  }

}
