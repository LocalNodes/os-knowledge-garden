<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\localnodes_platform\Logger\ExportNoticeLoggerFactory;
use Drupal\localnodes_platform\Logger\ExportNoticeLogs;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class ExportNoticeLogsTest extends UnitTestCase {

  public function testTransportDetailsAreSuppressedAndScopeAlwaysEnds(): void {
    $channel = $this->createMock(LoggerChannelInterface::class);
    $channel->expects($this->once())->method('log')->with('error', 'Site export notice processing reported an outcome.', []);
    $inner = $this->createMock(LoggerChannelFactoryInterface::class);
    $inner->method('get')->willReturn($channel);
    $factory = new ExportNoticeLoggerFactory($inner);
    try {
      ExportNoticeLogs::run(function () use ($factory): void {
        $factory->get('symfony_mailer')->error('Raw %message', ['%message' => 'private@example.org /private/file.pdf']);
        throw new \RuntimeException('fixture');
      });
    }
    catch (\RuntimeException) {}
    $this->assertFalse(ExportNoticeLogs::active());
    $this->assertSame($channel, $factory->get('unrelated'));
  }

}
