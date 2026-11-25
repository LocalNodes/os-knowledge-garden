<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_web3\Votes\MembersOnlyWait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Takes the fund's lock and refuses the switch while a change waits.
 *
 * Validation runs before the save's transaction opens, so the lock is
 * taken here. On a pass it is kept for the save that follows in this
 * request; the save checks again and has it released afterwards. A request
 * that validates and does not save gives it back when it ends. When the
 * switch is refused, no save follows, so it is given back at once.
 */
final class MembersOnlyWaitConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   */
  public function __construct(
    private readonly MembersOnlyWait $wait,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('localnodes_web3.members_only_wait'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof FieldItemListInterface || !$constraint instanceof MembersOnlyWaitConstraint) {
      return;
    }
    $group = $value->getEntity();
    if (!$group instanceof GroupInterface || !$this->wait->turnsMembersOnly($group)) {
      return;
    }
    $fund_id = $this->wait->fundId($group);
    if ($fund_id === NULL) {
      return;
    }
    // Busy: another request is storing or running a payment step for the
    // fund, so the switch can't be checked now.
    if (!$this->wait->reserveFund($fund_id)) {
      $this->context->addViolation($constraint->message);
      return;
    }
    if ($this->wait->refuses($group)) {
      $this->wait->releaseReservation($fund_id);
      $this->context->addViolation($constraint->message);
    }
  }

}
