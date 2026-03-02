<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\DemoFile;

/**
 * Portland File Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_file",
 *   label = @Translation("Portland File"),
 *   source = "content/entity/file.yml",
 *   entity_type = "file"
 * )
 */
class PortlandFile extends DemoFile {

}
