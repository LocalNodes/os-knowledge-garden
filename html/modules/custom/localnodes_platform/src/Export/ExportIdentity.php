<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Password\PasswordInterface;

/** One password check per submitted request or download; no reusable proof. */
final class ExportIdentity {

  public function __construct(private readonly ExportAccess $access, private readonly PasswordInterface $password, private readonly FloodInterface $flood) {}

  public function confirm(int $uid, string $password, bool $acknowledged): bool {
    if (!$acknowledged || !$this->access->allows($uid)) return FALSE;
    $identifier = (string) $uid;
    // Reserve this attempt before checking it, so concurrent tries each count
    // against the limit before any of them reaches the password. Counting
    // this one, at most five attempts in the hour get checked.
    $this->flood->register('localnodes_export_confirm', 3600, $identifier);
    if (!$this->flood->isAllowed('localnodes_export_confirm', 6, 3600, $identifier)) return FALSE;
    $user = $this->access->account($uid);
    if (!$user || !$user->getPassword() || !$this->password->check($password, $user->getPassword())) return FALSE;
    $this->flood->clear('localnodes_export_confirm', $identifier);
    return TRUE;
  }

}
