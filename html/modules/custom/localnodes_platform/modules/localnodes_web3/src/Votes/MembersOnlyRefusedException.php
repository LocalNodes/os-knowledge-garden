<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Votes;

/**
 * The group save check's refusal: the group stays as it is.
 *
 * Its message is the line for drush and the log (MembersOnlyWait::LINE, or
 * ::NOT_LOCKED for a save that skipped validation). The storage wraps it in
 * its own exception; on a web request MembersOnlyRefusal answers it with
 * the plain refusal page, which shows plainLine() (MembersOnlyWait::LINE
 * or ::FROM_HERE), never the developer line.
 */
final class MembersOnlyRefusedException extends \RuntimeException {

  /**
   * Constructs the refusal.
   *
   * @param string $message
   *   The line for drush and the log.
   * @param string $groupLabel
   *   The group's name, the refusal page's title.
   * @param string $plainLine
   *   The line the refusal page shows.
   */
  public function __construct(
    string $message,
    private readonly string $groupLabel = '',
    private readonly string $plainLine = MembersOnlyWait::LINE,
  ) {
    parent::__construct($message);
  }

  /**
   * The line the refusal page shows.
   */
  public function plainLine(): string {
    return $this->plainLine;
  }

  /**
   * The group's name.
   */
  public function groupLabel(): string {
    return $this->groupLabel;
  }

}
