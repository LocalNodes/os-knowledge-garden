<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\localnodes_events_calendar\DemoContent\MonthAnchoredEventDates;
use Drupal\social_demo\Plugin\DemoContent\Event;

/**
 * LocalNodes Event Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_event",
 *   label = @Translation("LocalNodes Event"),
 *   source = "content/entity/event.yml",
 *   entity_type = "node"
 * )
 */
class LocalnodesEvent extends Event {

  use MonthAnchoredEventDates;

}
