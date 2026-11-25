<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\Plugin\DemoContent\Like;

/**
 * Neighbors Like Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_like",
 *   label = @Translation("Neighbors Like"),
 *   source = "content/entity/like.yml",
 *   entity_type = "vote"
 * )
 */
class NeighborsLike extends Like {

  use FillsSiteName;

}
