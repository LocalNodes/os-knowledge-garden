<?php

declare(strict_types=1);

namespace Drupal\localnodes_group_setup\Exception;

/**
 * A Group set-up step was refused; nothing was applied by the refused call.
 *
 * The reason is a fixed machine code a screen can turn into words:
 * group_type_not_listed, not_manager, already_applied, in_progress, busy,
 * not_resumable, not_applied or rules_refused.
 */
class SetupRefusedException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param string $reason
   *   The fixed reason code.
   * @param string $detail
   *   Optional detail for logs (never shown to members as is).
   */
  public function __construct(public readonly string $reason, string $detail = '') {
    parent::__construct($detail === '' ? "Group set-up refused: $reason" : "Group set-up refused: $reason ($detail)");
  }

}
