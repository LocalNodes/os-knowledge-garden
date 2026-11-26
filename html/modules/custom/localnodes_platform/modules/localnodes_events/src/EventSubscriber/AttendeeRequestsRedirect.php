<?php

declare(strict_types=1);

namespace Drupal\localnodes_events\EventSubscriber;

use Drupal\node\NodeInterface;
use Drupal\social_event\Routing\EnrollRequestsOverviewSubscriber;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * The enrollment requests page: the rule's people stay on it.
 *
 * Open Social sends everyone but the event's organizers back to the event;
 * the route's own access (the attendee-list rule) has already decided.
 */
final class AttendeeRequestsRedirect extends EnrollRequestsOverviewSubscriber {

  public function checkAccessToEnrollRequestsOverview(RequestEvent $event) {
    if (\Drupal::routeMatch()->getRouteName() === 'view.event_manage_enrollment_requests.page_manage_enrollment_requests') {
      $current = social_event_get_current_event();
      if ($current instanceof NodeInterface && \Drupal::service('localnodes_events.attendee_list_access')->allowed($current, \Drupal::currentUser())) {
        return;
      }
    }
    parent::checkAccessToEnrollRequestsOverview($event);
  }

}
