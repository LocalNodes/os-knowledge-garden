<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Export;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/** Covers SIWE exceptions that fetch the global factory instead of DI. */
final class ExportProofLoggerFactory implements LoggerChannelFactoryInterface {
  public function __construct(private readonly LoggerChannelFactoryInterface $inner) {}
  public function get($channel) {
    if ($channel === 'siwe_login' && ExportProofLogs::active()) return (new SilentLoggerFactory())->get($channel);
    return $this->inner->get($channel);
  }
  public function addLogger(LoggerInterface $logger, $priority = 0): void { $this->inner->addLogger($logger, $priority); }
}
