<?php

declare(strict_types=1);

namespace Drupal\localnodes_events\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\social_event\EventEnrollmentInterface;
use Drupal\social_event_managers\Plugin\Action\SocialEventManagersSendEmail;

/**
 * "Email selected enrollees" for everyone who may open the attendee list.
 *
 * Who may use it is the attendee-list rule's answer for the enrollment's
 * event, instead of Open Social's organizer check.
 */
final class AttendeeListSendEmail extends SocialEventManagersSendEmail {

  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    // The attendee-list rule decides alone: Open Social's organizer grant does
    // not know the group membership rule, and the site manager's scoped
    // permission is not Open Social's.
    $access = AccessResult::forbidden();
    if ($object instanceof EventEnrollmentInterface) {
      $rule = \Drupal::service('localnodes_events.attendee_list_access');
      $event = $rule->eventOf($object);
      $account ??= \Drupal::currentUser();
      if ($event instanceof NodeInterface) {
        $access = $rule->access($event, $account)->addCacheableDependency($object);
      }
    }
    return $return_as_object ? $access : $access->isAllowed();
  }

}
