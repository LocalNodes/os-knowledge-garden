<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\Plugin\DemoContent\UserTerms;

/**
 * Pack User Terms: the same loader as social_demo's, reading the chosen pack.
 *
 * @DemoContent(
 *   id = "pack_user_terms",
 *   label = @Translation("Pack User Terms"),
 *   source = "content/entity/user-terms.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class PackUserTerms extends UserTerms {

  use ResolvesPackTokens;

}
