<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\EventType;

/**
 * Boulder Event Type Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_event_type",
 *   label = @Translation("Boulder Event Type"),
 *   source = "content/entity/event-type.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class BoulderEventType extends EventType {

}
