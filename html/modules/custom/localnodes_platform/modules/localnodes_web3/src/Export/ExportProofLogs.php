<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Export;

/** Only the synchronous export-proof call suppresses SIWE exception logging. */
final class ExportProofLogs {
  private static int $depth = 0;
  public static function active(): bool { return self::$depth > 0; }
  public static function quiet(callable $operation): mixed {
    self::$depth++;
    try { return $operation(); }
    finally { self::$depth--; }
  }
}
