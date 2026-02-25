<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\social_demo\Plugin\DemoContent\UserTerms;

/**
 * LocalNodes User Terms Plugin for demo content.
 *
 * @DemoContent(
 *   id = "localnodes_user_terms",
 *   label = @Translation("LocalNodes User Terms"),
 *   source = "content/entity/user-terms.yml",
 *   entity_type = "taxonomy_term"
 * )
 */
class LocalnodesUserTerms extends UserTerms {

}
