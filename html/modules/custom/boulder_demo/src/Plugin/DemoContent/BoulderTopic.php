<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Topic;

/**
 * Boulder Topic Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_topic",
 *   label = @Translation("Boulder Topic"),
 *   source = "content/entity/topic.yml",
 *   entity_type = "node"
 * )
 */
class BoulderTopic extends Topic {

}
