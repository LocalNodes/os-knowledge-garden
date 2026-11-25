<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\localnodes_events_calendar\DemoContent\MonthAnchoredEventDates;
use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\Plugin\DemoContent\Event;

/**
 * Neighbors Event Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_event",
 *   label = @Translation("Neighbors Event"),
 *   source = "content/entity/event.yml",
 *   entity_type = "node"
 * )
 */
class NeighborsEvent extends Event {

  use FillsSiteName;
  use MonthAnchoredEventDates;

}
