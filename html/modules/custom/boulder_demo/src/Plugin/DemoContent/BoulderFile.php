<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoFile;

/**
 * Boulder File Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_file",
 *   label = @Translation("Boulder File"),
 *   source = "content/entity/file.yml",
 *   entity_type = "file"
 * )
 */
class BoulderFile extends DemoFile {

}
