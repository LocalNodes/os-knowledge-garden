<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks newly typed or pasted house mentions.
 *
 * @Constraint(
 *   id = "HouseMentionEligibility",
 *   label = @Translation("House mention eligibility", context = "Validation"),
 *   type = "entity"
 * )
 */
final class HouseMentionEligibilityConstraint extends Constraint {}
