<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\Plugin\DemoContent\Topic;

/**
 * Neighbors Topic Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_topic",
 *   label = @Translation("Neighbors Topic"),
 *   source = "content/entity/topic.yml",
 *   entity_type = "node"
 * )
 */
class NeighborsTopic extends Topic {

  use FillsSiteName;

}
