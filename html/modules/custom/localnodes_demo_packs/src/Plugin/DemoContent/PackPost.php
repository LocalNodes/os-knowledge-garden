<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\Plugin\DemoContent\Post;

/**
 * Pack Post: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_post",
 *   label = @Translation("Pack Post"),
 *   source = "content/entity/post.yml",
 *   entity_type = "post"
 * )
 */
class PackPost extends Post {

  use ResolvesPackTokens;

}
