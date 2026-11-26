<?php

declare(strict_types=1);

namespace Drupal\localnodes_events\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Puts the attendee-list rule on every route of an event's attendee list.
 *
 * Runs after Open Social's subscribers and the platform's house subscriber
 * (-1000), whose membership requirement stays on each route (AND).
 */
final class AttendeeListRouteSubscriber extends RouteSubscriberBase {

  /** The management list: our rule replaces Open Social's organizer checks. */
  public const MANAGE = [
    'view.event_manage_enrollments.page_manage_enrollments',
    'social_event_managers.add_enrollees',
    'view.event_manage_enrollment_requests.page_manage_enrollment_requests',
    'view.event_manage_enrollment_invites.page_manage_enrollment_invites',
  ];

  /** The bulk routes: our rule is added; the bulk tool's own check stays. */
  public const BULK = [
    'social_event_managers.vbo.execute_configurable',
    'social_event_managers.vbo.confirm',
  ];

  /** Organizer actions on one enrollment: our rule replaces Open Social's organizer check (CSRF stays). */
  public const ENROLLMENT_ACTIONS = [
    'social_event.update_enrollment_request',
    'social_event.enrollment_request_decline_form',
    'social_event_invite.cancel_enrollment_invite',
  ];

  /** One enrollment's own pages: our rule or the enrollee, added. */
  public const ENROLLMENT_PAGES = [
    'entity.event_enrollment.canonical',
    'entity.event_enrollment.edit_form',
    'entity.event_enrollment.delete_form',
  ];

  /** The bulk tool's own routes: the rule for an attendee view's event. */
  public const BULK_TOOL = [
    'views_bulk_operations.execute_batch',
    'views_bulk_operations.update_selection',
    'views_bulk_operations.execute_configurable',
    'views_bulk_operations.confirm',
  ];

  /** Inviting to the event: added to Open Social's check. */
  public const INVITE = [
    'social_event_invite.invite_email',
    'social_event_invite.invite_user',
  ];

  /** Open Social's organizer checks that the rule replaces. */
  private const REPLACED = ['_custom_access', '_enrollee_permission', '_social_event_enrollments_access'];

  protected function alterRoutes(RouteCollection $collection) {
    foreach (self::MANAGE as $name) {
      if ($route = $collection->get($name)) {
        $requirements = array_diff_key($route->getRequirements(), array_flip(self::REPLACED));
        $requirements['_localnodes_attendee_list'] = 'manage';
        $route->setRequirements($requirements);
      }
    }
    foreach (self::BULK as $name) {
      $collection->get($name)?->setRequirement('_localnodes_attendee_list', 'manage');
    }
    foreach (self::ENROLLMENT_ACTIONS as $name) {
      if ($route = $collection->get($name)) {
        $requirements = array_diff_key($route->getRequirements(), array_flip(self::REPLACED));
        $requirements['_localnodes_attendee_list'] = 'manage';
        $route->setRequirements($requirements);
      }
    }
    foreach (self::BULK_TOOL as $name) {
      $collection->get($name)?->setRequirement('_localnodes_attendee_list', 'bulk');
    }
    foreach (self::INVITE as $name) {
      $collection->get($name)?->setRequirement('_localnodes_attendee_list', 'invite');
    }
    // Answering an invitation: only the person it is for.
    $collection->get('social_event_invite.update_enrollment_invite')?->setRequirement('_localnodes_attendee_list', 'invitee');
    foreach (self::ENROLLMENT_PAGES as $name) {
      $collection->get($name)?->setRequirement('_localnodes_attendee_list', 'enrollment');
    }
    // The attendees' own tab: the "Hide enrollments" check of Open Social is
    // replaced by ours, which also lets the organizers in when it is ticked.
    if ($route = $collection->get('view.event_enrollments.view_enrollments')) {
      $requirements = $route->getRequirements();
      unset($requirements['_social_event_enrollments_access']);
      $requirements['_localnodes_attendee_list'] = 'names';
      $route->setRequirements($requirements);
    }
  }

  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -1100];
    return $events;
  }

}
