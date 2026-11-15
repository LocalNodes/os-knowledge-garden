<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\DemoGroup;

/**
 * Pack Group: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_group",
 *   label = @Translation("Pack Group"),
 *   source = "content/entity/group.yml",
 *   entity_type = "group"
 * )
 */
class PackGroup extends DemoGroup {

  use ResolvesPackTokens;

}
