<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\localnodes_platform\Logger\DbLogWithoutQuery;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/** @group localnodes_platform */
final class ExportLogTest extends UnitTestCase {

  public function testDownloadAndPrivateLinksNeverReachWatchdog(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->exactly(3))->method('log')->with(4, 'Safe text.', ['request_uri' => '[private-export]', 'referer' => '[private-export]', '@uri' => '[private-export]']);
    $decorator = new DbLogWithoutQuery($logger);
    foreach (['https://example.org/admin/config/system/site-export/confirm/download?secret=CANARY', '/system/files/site-exports/CANARY/file.pdf', '/admin/config/system/%73ite-export/confirm/download'] as $url) {
      $decorator->warning('Safe text.', ['request_uri' => $url, 'referer' => $url, '@uri' => $url]);
    }
  }

}
