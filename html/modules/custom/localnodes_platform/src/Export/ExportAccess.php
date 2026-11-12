<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\user\UserInterface;

/** Ownership is a recorded account, never an administrative bypass. */
final class ExportAccess {

  public function __construct(private readonly StateInterface $state, private readonly EntityTypeManagerInterface $entities, private readonly ?Connection $database = NULL) {}

  /**
   * Whether this install has the export table.
   *
   * A site installed before the export existed and redeployed in place
   * (updatedb, no update hook: fresh installs only) lacks it.
   */
  public function installed(): bool {
    return $this->database === NULL || $this->database->schema()->tableExists('localnodes_site_export');
  }

  public function account(int $uid): ?UserInterface {
    if ($uid <= 0) return NULL;
    $storage = $this->entities->getStorage('user');
    $storage->resetCache([$uid]);
    $user = $storage->load($uid);
    return $user instanceof UserInterface ? $user : NULL;
  }

  public function allows(int $uid): bool {
    $this->state->resetCache();
    $owner = (int) $this->state->get('localnodes.organizer_uid', 0);
    if ($owner <= 0 || $uid !== $owner) return FALSE;
    $user = $this->account($uid);
    if (!$user || !$user->isActive() || !in_array('sitemanager', $user->getRoles(), TRUE)) return FALSE;
    // Core's uid-1/admin policy can return TRUE without an actual grant.
    // Re-read role configuration so a long capture sees external revocation.
    $roles = $this->entities->getStorage('user_role');
    $roles->resetCache($user->getRoles());
    foreach ($roles->loadMultiple($user->getRoles()) as $role) {
      if (in_array('export localnodes site', $role->getPermissions(), TRUE)) return $user->hasPermission('export localnodes site');
    }
    return FALSE;
  }

  public function hasOwner(): bool {
    $this->state->resetCache();
    return $this->allows((int) $this->state->get('localnodes.organizer_uid', 0));
  }

  public function access(AccountInterface $account): AccessResult {
    // Refuse before any query touches the missing table.
    if (!$this->installed()) return AccessResult::forbidden()->setCacheMaxAge(0);
    $user = $this->account((int) $account->id());
    $explanation = !$this->hasOwner() && $user && $user->isActive() && in_array('sitemanager', $user->getRoles(), TRUE);
    return AccessResult::allowedIf($explanation || $this->allows((int) $account->id()))->setCacheMaxAge(0);
  }

}
