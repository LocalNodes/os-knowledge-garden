<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Access\HouseParticipationAccess;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Routing\Route;

/** @group localnodes_platform */
final class HouseParticipationAccessTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    $container = new \Drupal\Core\DependencyInjection\ContainerBuilder();
    $container->set('cache_contexts_manager', new \Drupal\Core\Cache\Context\CacheContextsManager($container, ['user', 'user.permissions', 'user.roles', 'languages']));
    \Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }


  public function testReadPermissionDoesNotAuthorizeReplyOrReaction(): void {
    $account = $this->createMock(AccountInterface::class);
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('access')->willReturn(AccessResult::allowed());
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->with($entity, $account)->willReturn(TRUE);
    $policy->method('isPrivate')->willReturn(TRUE);
    $policy->method('accessMetadata')->willReturn((new CacheableMetadata())->setCacheContexts(['user'])->setCacheTags(['group_content_list']));
    $gate = new HouseParticipationAccess($policy, $this->createMock(EntityTypeManagerInterface::class));
    $result = $gate->access(new RouteMatch('comment.reply', new Route('/{entity}'), ['entity' => $entity]), $account);
    $this->assertTrue($result->isForbidden());
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains('group_content_list', $result->getCacheTags());
  }

  public function testCurrentMemberWithViewCanStillParticipate(): void {
    $account = $this->createMock(AccountInterface::class);
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('access')->willReturn(AccessResult::allowed());
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->willReturn(FALSE);
    $policy->method('isPrivate')->willReturn(TRUE);
    $policy->method('accessMetadata')->willReturn(new CacheableMetadata());
    $gate = new HouseParticipationAccess($policy, $this->createMock(EntityTypeManagerInterface::class));
    $this->assertTrue($gate->access(new RouteMatch('comment.reply', new Route('/{entity}'), ['entity' => $entity]), $account)->isAllowed());
  }

  public function testAHiddenPrivateTargetCannotBeReactedToByItsMember(): void {
    $account = $this->createMock(AccountInterface::class);
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('access')->willReturn(AccessResult::forbidden());
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->willReturn(FALSE);
    $policy->method('isPrivate')->willReturn(TRUE);
    $policy->method('accessMetadata')->willReturn(new CacheableMetadata());
    $gate = new HouseParticipationAccess($policy, $this->createMock(EntityTypeManagerInterface::class));
    $this->assertFalse($gate->access(new RouteMatch('vote', new Route('/{entity}'), ['entity' => $entity]), $account)->isAllowed());
  }

  public function testReactionRouteLoadsItsActualTarget(): void {
    $account = $this->createMock(AccountInterface::class);
    $entity = $this->createMock(EntityInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('load')->with('23')->willReturn($entity);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->with('comment')->willReturn(TRUE);
    $entities->method('getStorage')->with('comment')->willReturn($storage);
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->expects($this->once())->method('deniesParticipation')->with($entity, $account)->willReturn(TRUE);
    $policy->method('accessMetadata')->willReturn(new CacheableMetadata());
    $gate = new HouseParticipationAccess($policy, $entities);
    $this->assertTrue($gate->access(new RouteMatch('vote', new Route('/{entity_type_id}/{entity_id}'), ['entity_type_id' => 'comment', 'entity_id' => '23']), $account)->isForbidden());
  }

  public function testMissingTargetFailsClosed(): void {
    $gate = new HouseParticipationAccess($this->createMock(DepartedAuthorAccess::class), $this->createMock(EntityTypeManagerInterface::class));
    $this->assertTrue($gate->access(new RouteMatch('vote', new Route('/')), $this->createMock(AccountInterface::class))->isForbidden());
  }

}
