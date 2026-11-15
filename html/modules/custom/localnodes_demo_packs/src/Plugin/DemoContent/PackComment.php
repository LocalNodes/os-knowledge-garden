<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\DemoComment;

/**
 * Pack Comment: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_comment",
 *   label = @Translation("Pack Comment"),
 *   source = "content/entity/comment.yml",
 *   entity_type = "comment"
 * )
 */
class PackComment extends DemoComment {

  use ResolvesPackTokens;

}
