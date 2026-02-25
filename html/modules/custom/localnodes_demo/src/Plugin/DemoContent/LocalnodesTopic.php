<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\Topic;

/**
 * LocalNodes Topic Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_topic",
 *   label = @Translation("LocalNodes Topic"),
 *   source = "content/entity/topic.yml",
 *   entity_type = "node"
 * )
 */
class LocalnodesTopic extends Topic {

}
