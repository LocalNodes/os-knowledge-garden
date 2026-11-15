<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\Plugin\DemoContent\Like;

/**
 * Pack Like: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_like",
 *   label = @Translation("Pack Like"),
 *   source = "content/entity/like.yml",
 *   entity_type = "vote"
 * )
 */
class PackLike extends Like {

  use ResolvesPackTokens;

}
