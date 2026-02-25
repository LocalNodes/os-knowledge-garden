<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoGroup;

/**
 * LocalNodes Group Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_group",
 *   label = @Translation("LocalNodes Group"),
 *   source = "content/entity/group.yml",
 *   entity_type = "group"
 * )
 */
class LocalnodesGroup extends DemoGroup {

}
