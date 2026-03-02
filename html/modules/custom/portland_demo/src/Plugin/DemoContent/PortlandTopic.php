<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Topic;

/**
 * Portland Topic Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_topic",
 *   label = @Translation("Portland Topic"),
 *   source = "content/entity/topic.yml",
 *   entity_type = "node"
 * )
 */
class PortlandTopic extends Topic {

}
