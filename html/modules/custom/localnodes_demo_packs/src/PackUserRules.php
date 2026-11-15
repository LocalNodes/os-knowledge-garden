<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

use Drupal\localnodes_platform\Mail\ReservedRecipientFilter;

/**
 * Checks a pack's parsed user file before any account is created.
 *
 * Every address must be in a reserved test domain, so no seeded account can
 * mail a real person, and at most one user may be the export owner. The
 * messages name the uuid, never the address, which could be real.
 */
final class PackUserRules {

  /**
   * Throws when the user file breaks a rule.
   *
   * @param array $data
   *   The parsed user file, keyed by uuid.
   *
   * @throws \RuntimeException
   */
  public static function check(array $data): void {
    $owners = 0;
    foreach ($data as $key => $item) {
      $item = is_array($item) ? $item : [];
      $uuid = (string) ($item['uuid'] ?? $key);
      if (!ReservedRecipientFilter::isReserved((string) ($item['mail'] ?? ''))) {
        throw new \RuntimeException("Pack user $uuid has an address outside the reserved test domains; demo accounts must use .example addresses.");
      }
      if (!empty($item['export_owner'])) {
        $owners++;
      }
    }
    if ($owners > 1) {
      throw new \RuntimeException("A pack may name one export owner; found $owners.");
    }
  }

}
