<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\localnodes_demo_packs\StampsChangedTime;
use Drupal\localnodes_events_calendar\DemoContent\DemoDate;
use Drupal\social_demo\Plugin\DemoContent\Event;

/**
 * Pack Event: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_event",
 *   label = @Translation("Pack Event"),
 *   source = "content/entity/event.yml",
 *   entity_type = "node"
 * )
 */
class PackEvent extends Event {

  use ResolvesPackTokens;
  use StampsChangedTime;

  /**
   * {@inheritdoc}
   *
   * Dates resolve against the pack's own anchor, as every other pack date does,
   * so a pack loaded again on the same site gets the same days.
   */
  protected function createEventDate($date_string) {
    return DemoDate::dateTime((string) $date_string, $this->packAnchor());
  }

}
