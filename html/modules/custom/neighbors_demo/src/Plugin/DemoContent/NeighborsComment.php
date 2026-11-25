<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\DemoComment;

/**
 * Neighbors Comment Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_comment",
 *   label = @Translation("Neighbors Comment"),
 *   source = "content/entity/comment.yml",
 *   entity_type = "comment"
 * )
 */
class NeighborsComment extends DemoComment {

  use FillsSiteName;

}
