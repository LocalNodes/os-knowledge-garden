<?php

namespace Drupal\localnodes_platform\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/** Adds membership gates alongside the installed route requirements. */
final class HouseEnrollmentRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection) {
    foreach ([
      'view.event_enrollments.view_enrollments',
      'view.event_manage_enrollment_invites.page_manage_enrollment_invites',
      'view.event_manage_enrollments.page_manage_enrollments',
      'view.event_manage_enrollment_requests.page_manage_enrollment_requests',
      'social_event_managers.add_enrollees',
      'social_event_managers.vbo.execute_configurable',
      'social_event_managers.vbo.confirm',
      'social_event.update_enrollment_request',
      'social_event.enrollment_request_decline_form',
      'social_event.request_enroll_dialog',
      'entity.event_enrollment.canonical',
      'entity.event_enrollment.edit_form',
      'entity.event_enrollment.delete_form',
    ] as $name) {
      if ($route = $collection->get($name)) $route->setRequirement('_localnodes_house_enrollment', 'TRUE');
    }
  }

  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    // Finish after feature subscribers, preserving their existing checks.
    $events[\Drupal\Core\Routing\RoutingEvents::ALTER] = ['onAlterRoutes', -1000];
    return $events;
  }

}
