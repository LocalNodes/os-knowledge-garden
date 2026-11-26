<?php

declare(strict_types=1);

namespace Drupal\localnodes_proposal_feed\Event;

/**
 * Names of the events a proposal's own module dispatches for the feed.
 *
 * A module that owns a kind of proposal (for example a consensus decision
 * round) dispatches these with a ProposalFeedEvent carrying the proposal,
 * and registers one source adapter for that kind (ProposalSourceInterface).
 * This module keeps the one feed card per proposal; the proposal's module
 * adds no feed or stream entries of its own.
 *
 * These names are a contract with other modules: they never change.
 */
final class ProposalFeedEvents {

  /**
   * A proposal was created and is open.
   *
   * Dispatched once, after the proposal is saved.
   */
  public const OPENED = 'localnodes_proposal_feed.opened';

  /**
   * An open or closed proposal changed in a way its card shows.
   *
   * For example its title, text, visibility or state changed, or the count
   * of votes or responses its card shows ("6 of 12 have voted"). Dispatched
   * after the proposal is saved. A change of state moves the card back to
   * the top of the feed; any other change only refreshes it in place.
   */
  public const CHANGED = 'localnodes_proposal_feed.changed';

  /**
   * A proposal closed: voting or responding ended, or it was withdrawn.
   *
   * Dispatched once, after the proposal is saved in its closed state.
   */
  public const CLOSED = 'localnodes_proposal_feed.closed';

  /**
   * This class only holds constants.
   */
  private function __construct() {}

}
