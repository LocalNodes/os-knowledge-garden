<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\Plugin\DemoContent\EventType;

/**
 * Pack Event Type: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_event_type",
 *   label = @Translation("Pack Event Type"),
 *   source = "content/entity/event-type.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class PackEventType extends EventType {

  use ResolvesPackTokens;

}
