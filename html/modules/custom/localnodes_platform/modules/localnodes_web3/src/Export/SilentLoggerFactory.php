<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Export;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/** Export-only verifier channels have no logging backends or member context. */
final class SilentLoggerFactory implements LoggerChannelFactoryInterface {
  public function get($channel): LoggerChannel { return new LoggerChannel('export_confirmation'); }
  public function addLogger(LoggerInterface $logger, $priority = 0): void {}
}
