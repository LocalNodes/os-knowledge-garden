<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\Plugin\DemoContent\EventEnrollment;

/**
 * Neighbors Event Enrollment Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_event_enrollment",
 *   label = @Translation("Neighbors Event Enrollment"),
 *   source = "content/entity/event-enrollment.yml",
 *   entity_type = "event_enrollment"
 * )
 */
class NeighborsEventEnrollment extends EventEnrollment {

  use FillsSiteName;

}
