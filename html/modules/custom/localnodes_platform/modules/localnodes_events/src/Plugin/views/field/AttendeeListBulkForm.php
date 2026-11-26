<?php

declare(strict_types=1);

namespace Drupal\localnodes_events\Plugin\views\field;

use Drupal\node\NodeInterface;
use Drupal\social_event_managers\Plugin\views\field\SocialEventManagersViewsBulkOperationsBulkForm;

/**
 * The attendee list's bulk column: every action for the rule's people.
 *
 * Open Social offers the actions only to the event's organizers (or to an
 * enrollee); a site manager admitted by the attendee-list rule gets them too.
 * Each action still checks the rule for every selected enrollment.
 */
final class AttendeeListBulkForm extends SocialEventManagersViewsBulkOperationsBulkForm {

  protected function bulkOptionAccess(array $bulkOptions) {
    $event = social_event_get_current_event();
    if ($event instanceof NodeInterface && \Drupal::service('localnodes_events.attendee_list_access')->allowed($event, $this->currentUser)) {
      return $bulkOptions;
    }
    return parent::bulkOptionAccess($bulkOptions);
  }

}
