<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Like;

/**
 * LocalNodes Like Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_like",
 *   label = @Translation("LocalNodes Like"),
 *   source = "content/entity/like.yml",
 *   entity_type = "vote"
 * )
 */
class LocalnodesLike extends Like {

}
