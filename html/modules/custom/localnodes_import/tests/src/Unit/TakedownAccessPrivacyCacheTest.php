<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Access\TakedownAccessCheck;
use Drupal\localnodes_import\Service\ReportAudience;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/** @group localnodes_import */
final class TakedownAccessPrivacyCacheTest extends UnitTestCase {
  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public function testChangingHousePrivacyInvalidatesPublicStewardship(): void {
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['user', 'user.permissions', 'user.roles']));
    \Drupal::setContainer($container);
    $public = TRUE;
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('3');
    $group->method('bundle')->willReturn('flexible_group');
    $group->method('access')->willReturnCallback(function () use (&$public) { return $public; });
    $group->method('getCacheTags')->willReturn(['group:3']);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $relationship = new class($group) {
      public function __construct(private GroupInterface $group) {}
      public function getGroup(): GroupInterface { return $this->group; }
    };
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$relationship]);
    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('getListCacheTags')->willReturn(['group_content_list']);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('group_content')->willReturn($storage);
    $entities->method('getDefinition')->with('group_content')->willReturn($definition);
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn('12');
    $node->method('access')->willReturnCallback(fn ($operation) => $operation === 'view');
    $node->method('getCacheTags')->willReturn(['node:12']);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(Cache::PERMANENT);
    $account = $this->createMock(UserInterface::class);
    $account->method('isAnonymous')->willReturn(FALSE);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $account->method('getRoles')->willReturn(['sitemanager']);
    $check = new TakedownAccessCheck(new ReportAudience($entities), $entities);
    $allowed = $check->check($node, $account);
    $this->assertTrue($allowed->isAllowed());
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturn(1000);
    $clock->method('getRequestTime')->willReturn(1000);
    $cache = new MemoryBackend($clock);
    $cache->set('takedown-access', $allowed, Cache::PERMANENT, $allowed->getCacheTags());
    $this->assertNotFalse($cache->get('takedown-access'));
    $public = FALSE;
    $cache->invalidateTags(['group:3']);
    $this->assertFalse($cache->get('takedown-access'), 'Changing group privacy must expire previously allowed route access.');
    $this->assertContains('group:3', $allowed->getCacheTags());
    foreach (['user', 'user.permissions', 'user.roles'] as $context) {
      $this->assertContains($context, $allowed->getCacheContexts());
    }
    $this->assertFalse($check->check($node, $account)->isAllowed());
  }
}
