<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoGroup;

/**
 * Boulder Group Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_group",
 *   label = @Translation("Boulder Group"),
 *   source = "content/entity/group.yml",
 *   entity_type = "group"
 * )
 */
class BoulderGroup extends DemoGroup {

}
