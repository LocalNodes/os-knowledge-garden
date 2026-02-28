<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\UserTerms;

/**
 * Boulder User Terms Plugin for demo content.
 *
 * @DemoContent(
 *   id = "boulder_user_terms",
 *   label = @Translation("Boulder User Terms"),
 *   source = "content/entity/user-terms.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class BoulderUserTerms extends UserTerms {

}
