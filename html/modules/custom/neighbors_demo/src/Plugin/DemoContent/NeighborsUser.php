<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\DemoUser;

/**
 * Neighbors User Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_user",
 *   label = @Translation("Neighbors User"),
 *   source = "content/entity/user.yml",
 *   entity_type = "user"
 * )
 */
class NeighborsUser extends DemoUser {

  use FillsSiteName;

}
