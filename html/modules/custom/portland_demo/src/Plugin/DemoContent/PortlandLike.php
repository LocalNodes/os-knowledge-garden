<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Like;

/**
 * Portland Like Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_like",
 *   label = @Translation("Portland Like"),
 *   source = "content/entity/like.yml",
 *   entity_type = "vote"
 * )
 */
class PortlandLike extends Like {

}
