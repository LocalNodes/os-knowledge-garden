<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\localnodes_demo_packs\StampsChangedTime;
use Drupal\social_demo\Plugin\DemoContent\Topic;

/**
 * Pack Topic: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_topic",
 *   label = @Translation("Pack Topic"),
 *   source = "content/entity/topic.yml",
 *   entity_type = "node"
 * )
 */
class PackTopic extends Topic {

  use ResolvesPackTokens;
  use StampsChangedTime;

}
