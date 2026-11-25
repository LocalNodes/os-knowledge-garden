<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Drupal\localnodes_web3\Votes\MembersOnlyWait;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A group turns members-only only when no change to who may vote waits.
 *
 * On a flexible group's visibility field (localnodes_web3's
 * hook_entity_bundle_field_info_alter()).
 */
#[Constraint(
  id: 'LocalnodesMembersOnlyWait',
  label: new TranslatableMarkup('Members-only waits for changes to who may vote', [], ['context' => 'Validation'])
)]
final class MembersOnlyWaitConstraint extends SymfonyConstraint {

  /**
   * What an organizer is told.
   *
   * @var string
   */
  public $message = MembersOnlyWait::LINE;

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return MembersOnlyWaitConstraintValidator::class;
  }

}
