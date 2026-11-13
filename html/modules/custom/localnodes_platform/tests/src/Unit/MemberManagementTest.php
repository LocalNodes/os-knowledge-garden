<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\social_user\Plugin\Action\SocialBlockUser;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Drupal\user\Plugin\Action\UnblockUser;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** @group localnodes_platform */
final class MemberManagementTest extends UnitTestCase {

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  private function actor(int $uid, bool $can_manage, bool $admin = FALSE): AccountInterface {
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('id')->willReturn((string) $uid);
    $actor->method('getRoles')->willReturn([$admin ? 'actor_admin' : 'actor_staff']);
    $actor->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $can_manage && $permission === 'block users');
    return $actor;
  }

  private function fixture(int $uid, bool $admin, AccountInterface $actor, array $roles = [], int $owner = 0): User {
    $target = $this->createMock(User::class);
    $target->method('id')->willReturn((string) $uid);
    $target->method('getRoles')->willReturn($roles ?: ['fixture_role']);
    $target->method('getCacheContexts')->willReturn([]);
    $target->method('getCacheTags')->willReturn(['user:' . $uid]);
    $target->method('getCacheMaxAge')->willReturn(-1);
    $target->method('isActive')->willReturn(TRUE);
    $target->method('isBlocked')->willReturn(TRUE);
    $target->method('access')->willReturn(AccessResult::neutral());
    $status = $this->createMock(FieldItemListInterface::class);
    $status->method('access')->willReturn(AccessResult::neutral());
    $target->method('__get')->willReturn($status);
    $role = $this->createMock(RoleInterface::class);
    $role->method('isAdmin')->willReturn($admin);
    $role->method('getCacheContexts')->willReturn([]);
    $role->method('getCacheTags')->willReturn(['config:user.role.fixture_role']);
    $role->method('getCacheMaxAge')->willReturn(-1);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturnCallback(function (array $ids) use ($role): array {
      $loaded = [];
      foreach ($ids as $id) {
        if ($id === 'actor_admin') {
          $actor_role = $this->createMock(RoleInterface::class);
          $actor_role->method('isAdmin')->willReturn(TRUE);
          $actor_role->method('getCacheContexts')->willReturn([]);
          $actor_role->method('getCacheTags')->willReturn(['config:user.role.actor_admin']);
          $actor_role->method('getCacheMaxAge')->willReturn(-1);
          $loaded[$id] = $actor_role;
        }
        else {
          $loaded[$id] = $role;
        }
      }
      return $loaded;
    });
    $types = $this->createMock(EntityTypeManagerInterface::class);
    $types->method('getStorage')->with('user_role')->willReturn($storage);
    $container = new ContainerBuilder();
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static fn (string $key, $default = NULL) => $key === 'localnodes.organizer_uid' ? $owner : $default);
    $container->set('state', $state);
    $container->set('current_user', $actor);
    $container->set('entity_type.manager', $types);
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['user', 'user.permissions']));
    \Drupal::setContainer($container);
    return $target;
  }

  private function action(string $id): object {
    // These are the real shipped Social/Core handlers before LocalNodes' alter.
    // On main the behavioral assertions fail, without referring to absent code.
    $definitions = [
      'user_block_user_action' => ['class' => SocialBlockUser::class],
      'user_unblock_user_action' => ['class' => UnblockUser::class],
    ];
    require_once dirname(__DIR__, 3) . '/localnodes_platform.module';
    if (function_exists('localnodes_platform_action_info_alter')) {
      localnodes_platform_action_info_alter($definitions);
    }
    $class = $definitions[$id]['class'];
    return new $class([], $id, []);
  }

  public static function actionIds(): array {
    return [['user_block_user_action'], ['user_unblock_user_action']];
  }

  /** @dataProvider actionIds */
  public function testScopedManagementHasConsistentBooleanAndObjectAccess(string $id): void {
    $actor = $this->actor(8, TRUE);
    $target = $this->fixture(20, FALSE, $actor);
    $action = $this->action($id);
    $this->assertTrue((bool) $action->access($target, $actor));
    $this->assertTrue($action->access($target, $actor, TRUE)->isAllowed());
  }

  public static function protectedTargets(): array {
    $cases = [];
    foreach (['user_block_user_action', 'user_unblock_user_action'] as $id) {
      foreach (['uid1' => [1, FALSE, [], 0], 'custom admin' => [20, TRUE, [], 0], 'self' => [8, FALSE, [], 0], 'co-organizer' => [20, FALSE, ['sitemanager'], 0], 'recorded owner without site role' => [20, FALSE, [], 20]] as $label => [$uid, $admin, $roles, $owner]) {
        $cases[$id . ':' . $label] = [$id, $uid, $admin, $roles, $owner];
      }
    }
    return $cases;
  }

  /** @dataProvider protectedTargets */
  public function testScopedActionsCannotTargetHostAdministratorOrSelf(string $id, int $uid, bool $admin, array $roles = [], int $owner = 0): void {
    $actor = $this->actor(8, TRUE);
    $target = $this->fixture($uid, $admin, $actor, $roles, $owner);
    $action = $this->action($id);
    $this->assertFalse((bool) $action->access($target, $actor));
    $this->assertFalse($action->access($target, $actor, TRUE)->isAllowed());
  }

  public static function organizerTargets(): array {
    return [['sitemanager'], ['recorded-owner']];
  }

  /** @dataProvider organizerTargets */
  public function testRealAdministratorCanManageAnOrganizer(string $kind): void {
    $actor = $this->actor(9, TRUE, TRUE);
    $target = $this->fixture(20, FALSE, $actor, $kind === 'sitemanager' ? ['sitemanager'] : [], $kind === 'recorded-owner' ? 20 : 0);
    foreach (['user_block_user_action', 'user_unblock_user_action'] as $id) {
      $this->assertTrue($this->action($id)->access($target, $actor, TRUE)->isAllowed());
    }
  }

  /** @dataProvider actionIds */
  public function testOrdinaryMemberCannotExecuteManagementAction(string $id): void {
    $actor = $this->actor(8, FALSE);
    $target = $this->fixture(20, FALSE, $actor);
    $target->expects($this->never())->method('block');
    $target->expects($this->never())->method('activate');
    $target->expects($this->never())->method('save');
    $this->expectException(AccessDeniedHttpException::class);
    $this->action($id)->execute($target);
  }

  /** @dataProvider protectedTargets */
  public function testForgedExecutionIsCheckedBeforeAnyMutation(string $id, int $uid, bool $admin, array $roles = [], int $owner = 0): void {
    $actor = $this->actor(8, TRUE);
    $target = $this->fixture($uid, $admin, $actor, $roles, $owner);
    $target->expects($this->never())->method('block');
    $target->expects($this->never())->method('activate');
    $target->expects($this->never())->method('save');
    $this->expectException(AccessDeniedHttpException::class);
    $this->action($id)->execute($target);
  }

}
