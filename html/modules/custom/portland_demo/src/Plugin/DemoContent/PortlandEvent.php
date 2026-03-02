<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Event;

/**
 * Portland Event Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_event",
 *   label = @Translation("Portland Event"),
 *   source = "content/entity/event.yml",
 *   entity_type = "node"
 * )
 */
class PortlandEvent extends Event {

}
