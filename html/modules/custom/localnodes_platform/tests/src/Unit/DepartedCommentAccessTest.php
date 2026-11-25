<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Comment\DepartedCommentAccessControlHandler;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class DepartedCommentAccessTest extends UnitTestCase {

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


  public static function createTargets(): array {
    return ['read-only former member' => [TRUE, FALSE], 'current member' => [FALSE, TRUE]];
  }

  /** @dataProvider createTargets */
  public function testGlobalCommentGrantDoesNotOverrideTheConcreteHouse(bool $denied, bool $allowed): void {
    $root = $this->createMock(EntityInterface::class);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn('9');
    $account->method('hasPermission')->with('post comments')->willReturn(TRUE);
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->method('deniesParticipation')->with($root, $account)->willReturn($denied);
    $policy->method('accessMetadata')->willReturn((new CacheableMetadata())->setCacheContexts(['user'])->setCacheTags(['group_content_list']));
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $this->createMock(EntityTypeManagerInterface::class));
    $container->set('localnodes_platform.departed_author_access', $policy);
    $type = $this->createMock(EntityTypeInterface::class);
    $type->method('id')->willReturn('comment');
    $handler = DepartedCommentAccessControlHandler::createInstance($container, $type);
    $hooks = $this->createMock(ModuleHandlerInterface::class);
    $hooks->method('invokeAll')->willReturn([]);
    $handler->setModuleHandler($hooks);
    $result = $handler->createAccess('comment', $account, ['entity' => $root], TRUE);
    $this->assertSame($allowed, $result->isAllowed());
  }

}
