<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\Validation\Constraint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\localnodes_platform\Mention\MentionEligibility;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates mention targets while keeping saved historical text intact.
 */
final class HouseMentionEligibilityConstraintValidator extends ConstraintValidator {

  public function validate($value, Constraint $constraint): void {
    if ($value instanceof FieldableEntityInterface) {
      foreach ((new MentionEligibility())->violations($value) as $path) {
        $this->context->buildViolation(MentionEligibility::MESSAGE)->atPath($path)->addViolation();
      }
    }
  }

}
