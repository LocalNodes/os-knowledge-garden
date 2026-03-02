<?php

namespace Drupal\portland_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\UserTerms;

/**
 * Portland User Terms Plugin for demo content.
 *
 * @DemoContent(
 *   id = "portland_user_terms",
 *   label = @Translation("Portland User Terms"),
 *   source = "content/entity/user-terms.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class PortlandUserTerms extends UserTerms {

}
