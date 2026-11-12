<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\user\UserInterface;

/** Ownership is a recorded account, never an administrative bypass. */
final class ExportAccess {

  public function __construct(private readonly StateInterface $state, private readonly EntityTypeManagerInterface $entities) {}

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
    $user = $this->account((int) $account->id());
    $explanation = !$this->hasOwner() && $user && $user->isActive() && in_array('sitemanager', $user->getRoles(), TRUE);
    return AccessResult::allowedIf($explanation || $this->allows((int) $account->id()))->setCacheMaxAge(0);
  }

}
