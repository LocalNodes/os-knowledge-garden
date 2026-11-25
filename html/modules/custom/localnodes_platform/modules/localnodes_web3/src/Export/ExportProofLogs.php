<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Export;

/**
 * Only the synchronous export-proof call suppresses SIWE exception logging.
 */
final class ExportProofLogs {

  /**
   * How many export-proof calls are running now.
   */
  private static int $depth = 0;

  /**
   * Whether an export-proof call is running.
   */
  public static function active(): bool {
    return self::$depth > 0;
  }

  /**
   * Runs one export-proof operation with SIWE exception logging quiet.
   *
   * @param callable $operation
   *   The operation.
   *
   * @return mixed
   *   What the operation returns.
   */
  public static function quiet(callable $operation): mixed {
    self::$depth++;
    try {
      return $operation();
    }
    finally {
      self::$depth--;
    }
  }

}
