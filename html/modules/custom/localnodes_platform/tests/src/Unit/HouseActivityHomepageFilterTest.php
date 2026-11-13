<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\activity_viewer\Plugin\views\filter\ActivityFilterPersonalisedHomepage;
use Drupal\localnodes_platform\Plugin\views\filter\HouseActivityHomepageFilter;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class HouseActivityHomepageFilterTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    require_once DRUPAL_ROOT . '/modules/custom/localnodes_platform/localnodes_platform.module';
    $container = new \Drupal\Core\DependencyInjection\ContainerBuilder();
    $container->set('cache_contexts_manager', new \Drupal\Core\Cache\Context\CacheContextsManager($container, ['user', 'user.permissions', 'user.roles']));
    \Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public function testInstalledPluginIsReplacedWithoutChangingItsOtherMetadata(): void {
    $original = [
      'activity_filter_personalised_homepage' => ['class' => ActivityFilterPersonalisedHomepage::class, 'provider' => 'activity_viewer', 'id' => 'activity_filter_personalised_homepage'],
      'other' => ['class' => 'OtherFilter', 'provider' => 'other'],
    ];
    $plugins = $original;
    localnodes_platform_views_plugins_filter_alter($plugins);
    $this->assertSame(HouseActivityHomepageFilter::class, $plugins['activity_filter_personalised_homepage']['class']);
    $plugins['activity_filter_personalised_homepage']['class'] = ActivityFilterPersonalisedHomepage::class;
    $this->assertSame($original, $plugins);
  }

  public function testOptionalActivityModuleDoesNotCreateAPhantomDefinition(): void {
    $plugins = [];
    localnodes_platform_views_plugins_filter_alter($plugins);
    $this->assertSame([], $plugins);
  }

  public function testMembershipContentAndPermissionChangesInvalidateCachedRows(): void {
    $filter = (new \ReflectionClass(HouseActivityHomepageFilter::class))->newInstanceWithoutConstructor();
    $filter->options = ['exposed' => FALSE];
    foreach (['group_content_list', 'group_list', 'node_list', 'post_list', 'comment_list'] as $tag) {
      $this->assertContains($tag, $filter->getCacheTags());
    }
    foreach (['user', 'user.permissions', 'user.roles'] as $context) {
      $this->assertContains($context, $filter->getCacheContexts());
    }
  }
  public function testStoredStringUserIdReachesTheIntegerTypedMembershipHelper(): void {
    $actor = $this->createMock(\Drupal\Core\Session\AccountInterface::class);
    $actor->method('id')->willReturn('7');
    $actor->method('getRoles')->willReturn(['authenticated', 'verified', 'sitemanager']);
    $view = $this->createMock(\Drupal\views\ViewExecutable::class);
    $view->method('getUser')->willReturn($actor);
    $groups = $this->createMock(\Drupal\social_group\SocialGroupHelperService::class);
    $groups->expects($this->once())->method('getAllGroupsForUser')->with(7)
      ->willThrowException(new \LogicException('Reached the typed membership boundary.'));
    $query = $this->createMock(\Drupal\views\Plugin\views\query\Sql::class);
    $query->method('ensureTable')->willReturn('activity_field_data');
    $reflection = new \ReflectionClass(HouseActivityHomepageFilter::class);
    $filter = $reflection->newInstanceWithoutConstructor();
    foreach (['view' => $view, 'query' => $query, 'table' => 'activity_field_data', 'groupHelper' => $groups] as $name => $value) {
      $reflection->getProperty($name)->setValue($filter, $value);
    }
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Reached the typed membership boundary.');
    $filter->query();
  }

}
