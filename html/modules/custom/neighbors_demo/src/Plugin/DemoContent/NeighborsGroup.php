<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\DemoGroup;

/**
 * Neighbors Group Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_group",
 *   label = @Translation("Neighbors Group"),
 *   source = "content/entity/group.yml",
 *   entity_type = "group"
 * )
 */
class NeighborsGroup extends DemoGroup {

  use FillsSiteName;

}
