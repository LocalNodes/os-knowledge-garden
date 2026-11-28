<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Routing;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * The mention settings stay with the first account.
 *
 * Every page under the mention settings (the mention types, their add, edit
 * and delete forms, the type form and the settings form) gets a check that
 * only account 1 passes, beside the permission the page already asks for.
 * Other pages that ask for the same permission are left as they are.
 */
final class MentionSettingsRouteSubscriber extends RouteSubscriberBase {

  /** The mention settings pages, by route name. */
  public const ROUTES = [
    'entity.mentions_type.list',
    'entity.mentions_type.add_form',
    'entity.mentions_type.edit_form',
    'entity.mentions_type.delete_form',
    'mentions.mentions_type_form',
    'mentions.settings',
  ];

  /** Pages under these paths are mention settings too. */
  public const PATHS = ['/admin/structure/mentions', '/admin/config/people/mentions'];

  protected function alterRoutes(RouteCollection $collection) {
    foreach ($collection->all() as $name => $route) {
      if (in_array($name, self::ROUTES, TRUE) || self::underSettings($route->getPath())) {
        $route->setRequirement('_custom_access', self::class . '::access');
      }
    }
  }

  /** Only account 1 opens the mention settings. */
  public static function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf((int) $account->id() === 1)->cachePerUser();
  }

  private static function underSettings(string $path): bool {
    $path = '/' . ltrim($path, '/');
    foreach (self::PATHS as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    // After the feature modules' own route changes.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -1000];
    return $events;
  }

}
