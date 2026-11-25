<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\DemoFile;

/**
 * Neighbors File Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_file",
 *   label = @Translation("Neighbors File"),
 *   source = "content/entity/file.yml",
 *   entity_type = "file"
 * )
 */
class NeighborsFile extends DemoFile {

  use FillsSiteName;

}
