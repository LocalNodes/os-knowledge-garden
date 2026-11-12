<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\Dependency;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Fresh-site community features and the local calendar default.
 *
 * @group localnodes_platform
 */
final class OpenSocialFeaturesTest extends UnitTestCase {

  public function testFeatureDependenciesWithoutTagFollowing(): void {
    $info = Yaml::decode((string) file_get_contents($this->root . '/modules/custom/localnodes_platform/localnodes_platform.info.yml'));
    foreach (['social_profile_privacy', 'social_tagging', 'social_group_default_route', 'social_event_addtocal'] as $module) {
      $this->assertContains('social:' . $module, $info['dependencies']);
    }
    $names = array_map(static fn(string $entry): string => Dependency::createFromString($entry)->getName(), $info['dependencies']);
    foreach (['social_follow_tag', 'social_follow_taxonomy'] as $module) {
      $this->assertNotContains($module, $names);
    }
  }

  /**
   * @dataProvider calendarSettings
   */
  public function testCalendarInstallDefault(array $before): void {
    require_once $this->root . '/modules/custom/localnodes_platform/localnodes_platform.install';
    $storage = new MemoryStorage();
    if ($before) {
      $storage->write('social_event_addtocal.settings', $before);
    }
    $events = new EventDispatcher();
    $factory = new ConfigFactory($storage, $events, $this->createMock(TypedConfigManagerInterface::class));
    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    \Drupal::setContainer($container);
    $saves = 0;
    $events->addListener('config.save', static function () use (&$saves): void {
      $saves++;
    });

    _localnodes_platform_configure_event_calendar();

    $saved = $storage->read('social_event_addtocal.settings');
    $this->assertTrue($saved['enable_add_to_calendar']);
    $this->assertSame(['ical'], $saved['allowed_calendars'], 'Only the same-site calendar download is enabled.');
    $this->assertSame(['social_event_addtocal.settings'], $storage->listAll());
    $this->assertSame(1, $saves);
    _localnodes_platform_configure_event_calendar();
    $this->assertSame(1, $saves, 'Running the install helper again needs no second write.');
  }

  public function calendarSettings(): array {
    return [
      'new settings' => [[]],
      'outside calendars replaced' => [[
        'enable_add_to_calendar' => FALSE,
        'allowed_calendars' => ['google', 'outlook', 'ical'],
      ]],
    ];
  }

}
