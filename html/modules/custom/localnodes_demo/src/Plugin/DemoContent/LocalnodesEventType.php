<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\EventType;

/**
 * LocalNodes Event Type Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_event_type",
 *   label = @Translation("LocalNodes Event Type"),
 *   source = "content/entity/event-type.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class LocalnodesEventType extends EventType {

}
