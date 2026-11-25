<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Access\DepartedAuthorPostAccessControlHandler;
use Drupal\social_post\Entity\PostInterface;
use Drupal\Tests\UnitTestCase;

/**
 * A retained-reading exception never substitutes for a mutation decision.
 *
 * @group localnodes_platform
 */
final class DepartedAuthorHandlersTest extends UnitTestCase {

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


  public function testPostReadExceptionCarriesPolicyMetadata(): void {
    $post = $this->createMock(PostInterface::class);
    $account = $this->createMock(AccountInterface::class);
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->expects($this->once())->method('canRetainView')->with($post, $account)->willReturn(TRUE);
    $policy->method('accessMetadata')->willReturn((new CacheableMetadata())->setCacheContexts(['user'])->setCacheTags(['post:9']));
    $handler = new DepartedAuthorPostAccessControlHandler(
      $this->createMock(EntityTypeInterface::class), $this->createMock(EntityTypeManagerInterface::class), $policy
    );
    $method = new \ReflectionMethod($handler, 'checkAccess');
    $method->setAccessible(TRUE);
    $result = $method->invoke($handler, $post, 'view', $account);
    $this->assertTrue($result->isAllowed());
    $this->assertContains('user', $result->getCacheContexts());
    $this->assertContains('post:9', $result->getCacheTags());
  }

  /** @dataProvider postMutations */
  public function testPostMutationKeepsTheRealUpstreamDecision(string $operation, string $permission): void {
    $post = $this->createMock(PostInterface::class);
    $post->method('getOwnerId')->willReturn(7);
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn(7);
    $account->method('hasPermission')->willReturnCallback(static fn(string $asked): bool => $asked === $permission);
    $policy = $this->createMock(DepartedAuthorAccess::class);
    $policy->expects($this->never())->method('canRetainView');
    $handler = new DepartedAuthorPostAccessControlHandler(
      $this->createMock(EntityTypeInterface::class), $this->createMock(EntityTypeManagerInterface::class), $policy
    );
    $method = new \ReflectionMethod($handler, 'checkAccess');
    $method->setAccessible(TRUE);
    $this->assertTrue($method->invoke($handler, $post, $operation, $account)->isAllowed(), 'The wrapper preserves upstream own-content mutation decisions; the platform privacy guard remains separate.');
  }

  public static function postMutations(): array {
    return [
      'update' => ['update', 'edit own post entities'],
      'delete' => ['delete', 'delete own post entities'],
    ];
  }

}
