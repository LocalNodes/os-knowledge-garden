<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Post;

/**
 * Boulder Post Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_post",
 *   label = @Translation("Boulder Post"),
 *   source = "content/entity/post.yml",
 *   entity_type = "post"
 * )
 */
class BoulderPost extends Post {

}
