<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel\Support;

use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_consensus\Service\RoundLifecycle;

/**
 * RoundLifecycle whose terminal save fails before commit when told to.
 *
 * close() runs inside the lifecycle's transaction, so throwing after the
 * parent's save rolls the save back: the simulated failure lands after the
 * write and before the commit. Set $failures to the number of closes that
 * fail; it is zero unless a test sets it.
 */
class FailingRoundLifecycle extends RoundLifecycle {

  /**
   * How many coming closes fail.
   */
  public static int $failures = 0;

  /**
   * {@inheritdoc}
   */
  protected function close(ConsensusRound $round, string $state, string $reason, ?string $note, string $key_suffix = ''): void {
    parent::close($round, $state, $reason, $note, $key_suffix);
    if (static::$failures > 0) {
      static::$failures--;
      throw new \RuntimeException('Simulated failure before commit.');
    }
  }

}
