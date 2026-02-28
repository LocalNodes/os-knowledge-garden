<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\EventEnrollment;

/**
 * Boulder Event Enrollment Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_event_enrollment",
 *   label = @Translation("Boulder Event Enrollment"),
 *   source = "content/entity/event-enrollment.yml",
 *   entity_type = "event_enrollment"
 * )
 */
class BoulderEventEnrollment extends EventEnrollment {

}
