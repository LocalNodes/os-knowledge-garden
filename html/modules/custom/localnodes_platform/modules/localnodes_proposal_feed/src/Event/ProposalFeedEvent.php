<?php

declare(strict_types=1);

namespace Drupal\localnodes_proposal_feed\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Carries one proposal to the feed.
 *
 * Dispatch it under one of the ProposalFeedEvents names. Everything the
 * card shows is read from the proposal through its kind's source adapter
 * (ProposalSourceInterface), so the event carries only the proposal.
 */
final class ProposalFeedEvent extends Event {

  /**
   * Constructs a ProposalFeedEvent.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source
   *   The proposal, already saved.
   */
  public function __construct(
    private readonly ContentEntityInterface $source,
  ) {}

  /**
   * Returns the proposal this event is about.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The proposal.
   */
  public function getSource(): ContentEntityInterface {
    return $this->source;
  }

}
