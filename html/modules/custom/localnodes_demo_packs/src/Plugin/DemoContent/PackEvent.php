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
  // The trait's getEntry() stamps changed with created; this class's own
  // getEntry() calls it, so the stamp is kept.
  use StampsChangedTime {
    getEntry as stampedEntry;
  }

  /**
   * {@inheritdoc}
   *
   * Dates resolve against the pack's own anchor, as every other pack date does,
   * so a pack loaded again on the same site gets the same days.
   */
  protected function createEventDate($date_string) {
    return DemoDate::dateTime((string) $date_string, $this->packAnchor());
  }

  /**
   * {@inheritdoc}
   *
   * A pack event may say whether people outside its group may sign up
   * (field_event_enroll_outside_group). The event form sets it; a loaded event
   * skips the form, so the pack states it. An event that does not state it
   * keeps the field's default.
   */
  protected function getEntry(array $item) {
    $entry = $this->stampedEntry($item);
    if (array_key_exists('field_event_enroll_outside_group', $item)) {
      $entry['field_event_enroll_outside_group'] = (bool) $item['field_event_enroll_outside_group'];
    }
    return $entry;
  }

}
