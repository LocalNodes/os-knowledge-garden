<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoFile;

/**
 * LocalNodes File Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_file",
 *   label = @Translation("LocalNodes File"),
 *   source = "content/entity/file.yml",
 *   entity_type = "file"
 * )
 */
class LocalnodesFile extends DemoFile {

}
