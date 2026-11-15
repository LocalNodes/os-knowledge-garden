<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\Plugin\DemoContent\EventEnrollment;

/**
 * Pack Event Enrollment: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_event_enrollment",
 *   label = @Translation("Pack Event Enrollment"),
 *   source = "content/entity/event-enrollment.yml",
 *   entity_type = "event_enrollment"
 * )
 */
class PackEventEnrollment extends EventEnrollment {

  use ResolvesPackTokens;

}
