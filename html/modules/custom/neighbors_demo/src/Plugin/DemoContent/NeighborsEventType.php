<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\Plugin\DemoContent\EventType;

/**
 * Neighbors Event Type Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_event_type",
 *   label = @Translation("Neighbors Event Type"),
 *   source = "content/entity/event-type.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class NeighborsEventType extends EventType {

  use FillsSiteName;

}
