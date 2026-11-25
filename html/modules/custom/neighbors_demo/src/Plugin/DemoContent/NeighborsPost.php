<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\Plugin\DemoContent\Post;

/**
 * Neighbors Post Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_post",
 *   label = @Translation("Neighbors Post"),
 *   source = "content/entity/post.yml",
 *   entity_type = "post"
 * )
 */
class NeighborsPost extends Post {

  use FillsSiteName;

}
