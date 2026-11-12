<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Logger;

/** Bounds raw transport diagnostics to the export notice operation. */
final class ExportNoticeLogs {

  private static int $depth = 0;

  public static function active(): bool { return self::$depth > 0; }

  public static function run(callable $send): mixed {
    self::$depth++;
    try { return $send(); }
    finally { self::$depth--; }
  }

}
