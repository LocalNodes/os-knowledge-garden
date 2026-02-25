<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\EventEnrollment;

/**
 * LocalNodes Event Enrollment Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_event_enrollment",
 *   label = @Translation("LocalNodes Event Enrollment"),
 *   source = "content/entity/event-enrollment.yml",
 *   entity_type = "event_enrollment"
 * )
 */
class LocalnodesEventEnrollment extends EventEnrollment {

}
