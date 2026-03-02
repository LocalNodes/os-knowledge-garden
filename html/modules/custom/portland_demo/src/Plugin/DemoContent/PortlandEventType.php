<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\EventType;

/**
 * Portland Event Type Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_event_type",
 *   label = @Translation("Portland Event Type"),
 *   source = "content/entity/event-type.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class PortlandEventType extends EventType {

}
