<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Like;

/**
 * Boulder Like Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_like",
 *   label = @Translation("Boulder Like"),
 *   source = "content/entity/like.yml",
 *   entity_type = "vote"
 * )
 */
class BoulderLike extends Like {

}
