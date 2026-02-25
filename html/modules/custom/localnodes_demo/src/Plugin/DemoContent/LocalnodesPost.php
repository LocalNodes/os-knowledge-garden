<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Post;

/**
 * LocalNodes Post Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_post",
 *   label = @Translation("LocalNodes Post"),
 *   source = "content/entity/post.yml",
 *   entity_type = "post"
 * )
 */
class LocalnodesPost extends Post {

}
