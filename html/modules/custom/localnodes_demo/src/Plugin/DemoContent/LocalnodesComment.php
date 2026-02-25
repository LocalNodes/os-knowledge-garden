<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoComment;

/**
 * LocalNodes Comment Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_comment",
 *   label = @Translation("LocalNodes Comment"),
 *   source = "content/entity/comment.yml",
 *   entity_type = "comment"
 * )
 */
class LocalnodesComment extends DemoComment {

}
