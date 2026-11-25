<?php

namespace Drupal\localnodes_platform\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/** Adds membership gates alongside the installed route requirements. */
final class HouseRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection) {
    foreach (['comment.reply', 'ajax_comments.add', 'ajax_comments.reply', 'ajax_comments.save_reply', 'like_and_dislike.vote'] as $name) {
      if ($route = $collection->get($name)) $route->setRequirement('_localnodes_house_participation', 'TRUE');
    }
    if ($route = $collection->get('system.403')) {
      $route->setDefault('_controller', 'localnodes_platform.departed_author_notice:on403');
    }
    if ($route = $collection->get('social_mentions.autocomplete')) {
      $route->setDefault('_controller', '\\Drupal\\localnodes_platform\\Mention\\ContextualMentionController::suggestions');
    }
  }

  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    // Finish after feature subscribers, preserving their existing checks.
    $events[\Drupal\Core\Routing\RoutingEvents::ALTER] = ['onAlterRoutes', -1000];
    return $events;
  }

}
