<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\neighbors_demo\FillsSiteName;
use Drupal\social_demo\Plugin\DemoContent\UserTerms;

/**
 * Neighbors User Terms Plugin for demo content.
 *
 * @DemoContent(
 *   id = "neighbors_user_terms",
 *   label = @Translation("Neighbors User Terms"),
 *   source = "content/entity/user-terms.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class NeighborsUserTerms extends UserTerms {

  use FillsSiteName;

}
