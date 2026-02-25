<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoUser;

/**
 * LocalNodes User Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_user",
 *   label = @Translation("LocalNodes User"),
 *   source = "content/entity/user.yml",
 *   entity_type = "user"
 * )
 */
class LocalnodesUser extends DemoUser {

}
