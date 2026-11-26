<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * One saved response: its round, its revision id and its member.
 *
 * Ids only: a listener loads what it needs and reads it under its own
 * access rules.
 */
final class ResponseSavedEvent extends Event {

  public function __construct(
    protected readonly int $roundId,
    protected readonly int $responseRevisionId,
    protected readonly int $memberId,
  ) {}

  /**
   * The round's id.
   */
  public function getRoundId(): int {
    return $this->roundId;
  }

  /**
   * The saved response row's id, which is its revision id.
   */
  public function getResponseRevisionId(): int {
    return $this->responseRevisionId;
  }

  /**
   * The responding member's account id.
   */
  public function getMemberId(): int {
    return $this->memberId;
  }

}
