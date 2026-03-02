<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\EventEnrollment;

/**
 * Portland Event Enrollment Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_event_enrollment",
 *   label = @Translation("Portland Event Enrollment"),
 *   source = "content/entity/event-enrollment.yml",
 *   entity_type = "event_enrollment"
 * )
 */
class PortlandEventEnrollment extends EventEnrollment {

}
