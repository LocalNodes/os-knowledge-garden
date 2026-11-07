<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Access;

use Drupal\Core\Cache\ChainedFastBackend;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\UserInterface;

/**
 * Gives a retrieval a fresh access context before anything is checked.
 *
 * A web request starts with empty request-local caches, but a long-lived
 * process (a queue worker, a drush job, the eval runner) keeps what it
 * computed earlier, and nothing outside it can invalidate that. Each kind
 * found so far let a revoked grant through: entity access results (the
 * 22-27 live run, RQ-13), entities in the storage memory cache, Group's
 * calculated permissions and role memo, core's access policies, role
 * config, the asker's own account object (its roles), and an event
 * invitation loaded through entity storage (Codex reviews of 36636b8,
 * f5cb94d and 05468b5). So rather than chase caches one at a time, every
 * retrieval access check starts from a clean request-local state:
 * - every in-process memory cache bin (entity.memory_cache and the
 *   cache.bin.memory services; never a chained or persistent bin);
 * - every entity access-control handler's static cache, and every config
 *   entity storage's own memo (GroupRoleStorage's role ids, for one);
 * - the chained fast bins' last-write memo (cache.config and the like)
 *   and the config factory's static cache;
 * - the cache-tag checksums already read, so a persistent entry another
 *   process invalidated is not used;
 * - the asker, reloaded from storage and returned (a deleted or blocked
 *   asker as anonymous). Callers check access with it, and point the
 *   current-user proxy at it only for their own call.
 * drupal_static() is left alone: it also holds mutable request state that
 * a page render depends on, and the post-filter runs mid-render (Codex
 * round 4, P2-3). Persistent caches are never touched: they are shared
 * across processes and tag-invalidated, and clearing them would cost
 * every page.
 *
 * Callers: the RAG tool at the start of each execute(), and the hybrid
 * search post-filter that feeds the overview.
 */
final class RetrievalAccessCache {

  /**
   * Resets request-local state and returns a freshly loaded asker.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The asker.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The asker reloaded from storage; anonymous as given; an anonymous
   *   session when the account no longer exists.
   */
  public static function fresh(EntityTypeManagerInterface $entity_type_manager, AccountInterface $account): AccountInterface {
    $container = \Drupal::getContainer();

    $bins = array_keys($container->hasParameter('memory_cache_bins') ? $container->getParameter('memory_cache_bins') : []);
    $bins[] = 'entity.memory_cache';
    foreach (array_unique($bins) as $id) {
      if ($container->has($id)) {
        $bin = $container->get($id);
        // Only in-process memory: a chained bin (Group's
        // cache.group_memberships_chained) also writes to a persistent one.
        // MemoryBackend counts: it is what these bins are when the cache
        // factory is swapped (Kernel tests); on a site they are MemoryCache.
        if ($bin instanceof MemoryCacheInterface || $bin instanceof MemoryBackend) {
          $bin->deleteAll();
        }
      }
    }

    foreach ($entity_type_manager->getDefinitions() as $entity_type_id => $definition) {
      if ($definition->hasHandlerClass('access')) {
        $entity_type_manager->getAccessControlHandler($entity_type_id)->resetCache();
      }
      if ($definition->entityClassImplements(ConfigEntityInterface::class)) {
        $storage = $entity_type_manager->getStorage($entity_type_id);
        if ($storage instanceof ConfigEntityStorageInterface) {
          // Config entity storage keeps no persistent entity cache.
          $storage->resetCache();
        }
      }
    }

    // Chained fast bins (cache.config, cache.bootstrap, cache.discovery)
    // remember when they last checked for writes elsewhere; with a per-
    // process fast backend (APCu) a role's config changed by another
    // process is read stale until that is forgotten (Codex round 4, P1-1).
    foreach (array_keys($container->hasParameter('cache_bins') ? $container->getParameter('cache_bins') : []) as $id) {
      if ($container->has($id)) {
        $bin = $container->get($id);
        if ($bin instanceof ChainedFastBackend) {
          $bin->reset();
        }
      }
    }
    $container->get('config.factory')->reset();
    if ($container->has('cache_tags.invalidator.checksum')) {
      $checksum = $container->get('cache_tags.invalidator.checksum');
      if (method_exists($checksum, 'reset')) {
        $checksum->reset();
      }
    }

    if ($account->isAnonymous()) {
      return $account;
    }
    $user = $entity_type_manager->getStorage('user')->load($account->id());
    if (!$user instanceof UserInterface || $user->isBlocked()) {
      // A deleted or blocked account gets nothing beyond anonymous access
      // (Codex round 4, P1-2).
      return new AnonymousUserSession();
    }
    return $user;
  }

}
