<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoComment;

/**
 * Boulder Comment Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_comment",
 *   label = @Translation("Boulder Comment"),
 *   source = "content/entity/comment.yml",
 *   entity_type = "comment"
 * )
 */
class BoulderComment extends DemoComment {

}
