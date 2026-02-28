<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Event;

/**
 * Boulder Event Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_event",
 *   label = @Translation("Boulder Event"),
 *   source = "content/entity/event.yml",
 *   entity_type = "node"
 * )
 */
class BoulderEvent extends Event {

}
