<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoGroup;

/**
 * Portland Group Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_group",
 *   label = @Translation("Portland Group"),
 *   source = "content/entity/group.yml",
 *   entity_type = "group"
 * )
 */
class PortlandGroup extends DemoGroup {

}
