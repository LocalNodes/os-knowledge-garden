<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Access;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\ChainedFastBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\social_ai_indexing\Access\RetrievalAccessCache;
use Drupal\Tests\UnitTestCase;

/**
 * The fresh access context forgets chained fast bins' last-write memo.
 *
 * Codex round 4 (P1-1): with a per-process fast backend (APCu) in front of
 * a shared database, cache.config's ChainedFastBackend trusts its fast
 * copy until it re-reads the last write time, so a role permission
 * revoked by another process stayed granted. Kernel tests have no APCu,
 * so this checks that fresh() resets every ChainedFastBackend bin, and
 * only those.
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class RetrievalAccessCacheTest extends UnitTestCase {

  /**
   * Every chained fast bin is reset; other bins are left alone.
   */
  public function testChainedFastBinsAreReset(): void {
    $container = new ContainerBuilder();
    $container->setParameter('memory_cache_bins', []);
    $container->setParameter('cache_bins', ['cache.config' => 'config', 'cache.discovery' => 'discovery', 'cache.default' => 'default']);
    foreach (['cache.config', 'cache.discovery'] as $id) {
      $chained = $this->createMock(ChainedFastBackend::class);
      $chained->expects($this->once())->method('reset');
      $container->set($id, $chained);
    }
    $plain = $this->createMock(CacheBackendInterface::class);
    $plain->expects($this->never())->method($this->anything());
    $container->set('cache.default', $plain);
    $config = $this->createMock(ConfigFactoryInterface::class);
    $config->expects($this->once())->method('reset');
    $container->set('config.factory', $config);
    \Drupal::setContainer($container);

    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);
    $entity_type_manager->method('getDefinitions')->willReturn([]);
    $anonymous = new AnonymousUserSession();
    $this->assertSame($anonymous, RetrievalAccessCache::fresh($entity_type_manager, $anonymous));
  }

}
