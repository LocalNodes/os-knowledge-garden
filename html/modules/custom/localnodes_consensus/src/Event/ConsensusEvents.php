<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Event;

/**
 * Events this module dispatches about its own decision records.
 *
 * These are not the proposal feed's events. They go out from
 * RoundLifecycle only, after the transaction that wrote the record ends.
 */
final class ConsensusEvents {

  /**
   * A response row was saved on an open round.
   *
   * Dispatched once per saved row, a changed answer included, with a
   * ResponseSavedEvent. A repeat that saves nothing and a refused response
   * send nothing.
   */
  public const RESPONSE_SAVED = 'localnodes_consensus.response_saved';

}
