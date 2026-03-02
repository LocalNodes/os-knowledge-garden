<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Post;

/**
 * Portland Post Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_post",
 *   label = @Translation("Portland Post"),
 *   source = "content/entity/post.yml",
 *   entity_type = "post"
 * )
 */
class PortlandPost extends Post {

}
