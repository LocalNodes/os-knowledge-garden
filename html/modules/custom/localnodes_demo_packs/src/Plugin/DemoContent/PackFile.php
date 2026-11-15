<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\DemoFile;

/**
 * Pack File: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_file",
 *   label = @Translation("Pack File"),
 *   source = "content/entity/file.yml",
 *   entity_type = "file"
 * )
 */
class PackFile extends DemoFile {

  use ResolvesPackTokens;

}
