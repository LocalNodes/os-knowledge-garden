<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Drush\Commands;

use Drupal\localnodes_web3\Readiness\Web3ReadinessCheck;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush command the entrypoint runs before a practice site reports ready.
 *
 * It runs Web3ReadinessCheck once; the entrypoint retries.
 * On success it prints the relayer's public address and nothing else on
 * stdout, so the entrypoint can read it as is. On failure it prints one fixed
 * line naming the failed check on stderr and exits 1. It never prints the
 * relayer key, the bearer token, an RPC URL or an exception message.
 *
 * The command exists only while localnodes_web3 is enabled, so on a site
 * whose enable failed, drush has no such command and exits non-zero: the
 * check fails, as it should.
 */
final class Web3CheckCommands extends DrushCommands {

  /**
   * Checks that group funds and decisions work; prints the relayer address.
   */
  #[CLI\Command(name: 'localnodes-web3:check')]
  #[CLI\Usage(name: 'localnodes-web3:check', description: 'Prints the relayer address, or exits 1 with a fixed reason')]
  public function check(): int {
    $check = new Web3ReadinessCheck(
      \Drupal::moduleHandler(),
      \Drupal::configFactory(),
      static fn () => \Drupal::service('gas_sponsor.sponsor'),
    );
    $result = $check->run();
    if ($result['code'] !== Web3ReadinessCheck::OK) {
      $this->stderr()->writeln('Web3 check failed: ' . $result['code']);
      return self::EXIT_FAILURE;
    }
    $this->output()->writeln((string) $result['address']);
    return self::EXIT_SUCCESS;
  }

}
