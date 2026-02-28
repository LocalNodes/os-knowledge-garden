<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoUser;

/**
 * Boulder User Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_user",
 *   label = @Translation("Boulder User"),
 *   source = "content/entity/user.yml",
 *   entity_type = "user"
 * )
 */
class BoulderUser extends DemoUser {

}
