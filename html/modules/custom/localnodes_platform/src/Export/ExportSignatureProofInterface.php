<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

/** Optional sign-in proof; the platform also works without the sign-in module. */
interface ExportSignatureProofInterface {
  /** When an attachment wait ends, or NULL when this signer has no wait. */
  public function waitingUntil(int $uid): ?int;
  public function available(int $uid): bool;
  public function attach(array &$form): bool;
  public function issue(int $uid, string $action, string $exportId): array;
  public function confirm(int $uid, string $action, string $exportId, string $message, string $signature): bool;
}
