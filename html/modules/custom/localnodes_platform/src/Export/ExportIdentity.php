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
    if (!$this->flood->isAllowed('localnodes_export_confirm', 5, 3600, $identifier)) return FALSE;
    $user = $this->access->account($uid);
    if (!$user || !$user->getPassword() || !$this->password->check($password, $user->getPassword())) {
      $this->flood->register('localnodes_export_confirm', 3600, $identifier);
      return FALSE;
    }
    $this->flood->clear('localnodes_export_confirm', $identifier);
    return TRUE;
  }

}
