<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\OverviewTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The AI overview is for signed-in members only (D-27a, T-22-92).
 *
 * - The overview route needs 'use ai overview'; an anonymous request is
 *   refused with 403 before any model request is made.
 * - social_ai_indexing_install() grants it to the authenticated role, once,
 *   and not during a config sync. The authenticated role is core config, so
 *   the grant is a hook_install, not a shipped user.role file.
 * - The AI overview block isn't shown to anonymous visitors, so their search
 *   pages make no overview request at all.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class OverviewAccessTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use OverviewTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'ai_test',
  ];

  /**
   * The permission this plan adds.
   */
  private const PERMISSION = 'use ai overview';

  /**
   * The overview route.
   */
  private const ROUTE = 'social_ai_indexing.ai_overview';

  /**
   * Loads social_ai_indexing.install and checks its functions exist.
   */
  private function loadInstallInclude(): void {
    $this->container->get('module_handler')->loadInclude('social_ai_indexing', 'install');
    $this->assertTrue(function_exists('_social_ai_indexing_grant_overview_permission'), 'social_ai_indexing.install defines _social_ai_indexing_grant_overview_permission().');
    $this->assertTrue(function_exists('social_ai_indexing_install'), 'social_ai_indexing.install defines social_ai_indexing_install().');
  }

  /**
   * Whether an account may use the overview route.
   */
  private function routeAllowed(AccountInterface $account): bool {
    $this->resetAccessCaches();
    return $this->container->get('access_manager')->checkNamedRoute(self::ROUTE, [], $account);
  }

  /**
   * The role's permissions, fresh from storage.
   *
   * @return string[]
   *   Permission names.
   */
  private function rolePermissions(string $rid): array {
    $this->container->get('entity_type.manager')->getStorage('user_role')->resetCache();
    return Role::load($rid)->getPermissions();
  }

  /**
   * Anonymous: the route is denied, and HTTP gets 403 with no model call.
   */
  public function testAnonymousIsDenied(): void {
    $this->setUpOverviewSearch();
    $this->armCiteAll();
    $events = 0;
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      static function () use (&$events): void {
        $events++;
      },
      1000,
    );

    $this->assertFalse($this->routeAllowed($this->anonymous()), 'The access manager denies the overview route to anonymous visitors.');

    $this->setCurrentUser($this->anonymous());
    $this->resetAccessCaches();
    $response = $this->container->get('http_kernel')->handle(Request::create('/api/ai/overview', 'GET', ['q' => 'garden']));
    $this->assertSame(403, $response->getStatusCode(), 'An anonymous overview request gets 403.');
    $this->assertSame(0, $events, 'No PreGenerateResponseEvent was dispatched for the anonymous request.');
    $this->assertSame([], $this->allCalls(), 'No model request was made.');
  }

  /**
   * The grant helper lets members in, once, and never anonymous visitors.
   */
  public function testGrantHelperIsIdempotent(): void {
    $this->loadInstallInclude();
    $member = $this->createPersona('fictional_overview_member');

    _social_ai_indexing_grant_overview_permission();
    $this->assertTrue($this->routeAllowed($member), 'After the grant, a signed-in member may use the overview.');

    _social_ai_indexing_grant_overview_permission();
    $granted = array_filter($this->rolePermissions(RoleInterface::AUTHENTICATED_ID), static fn (string $p): bool => $p === self::PERMISSION);
    $this->assertCount(1, $granted, 'Granting twice leaves the permission on the authenticated role once.');

    $this->assertNotContains(self::PERMISSION, $this->rolePermissions(RoleInterface::ANONYMOUS_ID), 'The anonymous role never gets it.');
    $this->assertFalse($this->routeAllowed($this->anonymous()), 'Anonymous visitors are still denied.');
  }

  /**
   * hook_install grants on a normal install and does nothing during sync.
   */
  public function testInstallHookSkipsConfigSync(): void {
    $this->loadInstallInclude();
    $before = $this->rolePermissions(RoleInterface::AUTHENTICATED_ID);
    $this->assertNotContains(self::PERMISSION, $before, 'Baseline: the Kernel base does not grant it.');

    social_ai_indexing_install(TRUE);
    $this->assertSame($before, $this->rolePermissions(RoleInterface::AUTHENTICATED_ID), 'During a config sync the install hook changes nothing.');

    social_ai_indexing_install(FALSE);
    $this->assertContains(self::PERMISSION, $this->rolePermissions(RoleInterface::AUTHENTICATED_ID), 'A normal install grants it to the authenticated role.');
    $this->assertNotContains(self::PERMISSION, $this->rolePermissions(RoleInterface::ANONYMOUS_ID));
  }

  /**
   * The block is forbidden for anonymous visitors, allowed for members.
   */
  public function testBlockAccess(): void {
    user_role_grant_permissions(RoleInterface::AUTHENTICATED_ID, [self::PERMISSION]);
    $block = $this->container->get('plugin.manager.block')->createInstance('social_ai_overview', []);
    $member = $this->createPersona('fictional_block_member');

    $this->resetAccessCaches();
    $this->assertFalse($block->access($this->anonymous()), 'The AI overview block is not shown to anonymous visitors.');
    $this->assertTrue($block->access($member), 'The AI overview block is shown to signed-in members.');
  }

}
