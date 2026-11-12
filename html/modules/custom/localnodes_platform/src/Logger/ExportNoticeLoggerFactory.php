<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Logger;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Psr\Log\LoggerInterface;

/** The mail library's caught transport errors must not log private details. */
final class ExportNoticeLoggerFactory implements LoggerChannelFactoryInterface {

  public function __construct(private readonly LoggerChannelFactoryInterface $inner) {}

  public function get($channel) {
    $inner = $this->inner->get($channel);
    if (!ExportNoticeLogs::active()) return $inner;
    return new class($inner) extends LoggerChannel {
      public function __construct(private readonly LoggerChannelInterface $inner) { parent::__construct('localnodes_export_notice'); }
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (ExportNoticeLogs::active()) {
          $this->inner->log($level, 'Site export notice processing reported an outcome.', []);
        }
        else $this->inner->log($level, $message, $context);
      }
    };
  }

  public function addLogger(LoggerInterface $logger, $priority = 0) {
    $this->inner->addLogger($logger, $priority);
  }

}
