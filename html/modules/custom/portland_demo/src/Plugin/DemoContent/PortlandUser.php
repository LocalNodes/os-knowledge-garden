<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoUser;

/**
 * Portland User Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_user",
 *   label = @Translation("Portland User"),
 *   source = "content/entity/user.yml",
 *   entity_type = "user"
 * )
 */
class PortlandUser extends DemoUser {

}
