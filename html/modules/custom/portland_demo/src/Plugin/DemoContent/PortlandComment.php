<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoComment;

/**
 * Portland Comment Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_comment",
 *   label = @Translation("Portland Comment"),
 *   source = "content/entity/comment.yml",
 *   entity_type = "comment"
 * )
 */
class PortlandComment extends DemoComment {

}
