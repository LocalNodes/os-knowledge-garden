<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing_test\EventSubscriber;

use Drupal\social_ai_indexing\EventSubscriber\EmbeddingThrottleSubscriber;

/**
 * The embeddings throttle with a controllable context and a clock (22.2-12).
 *
 * A test swaps this class in for the shipped subscriber. State decides
 * whether the run counts as a CLI run, and a wait does not pause: it is
 * recorded, and the subscriber's clock moves forward by the waited time, so
 * the window ages as if the time had passed.
 *
 * State keys:
 * - social_ai_indexing_test.throttle_cli: TRUE for a drush or cron run.
 * - social_ai_indexing_test.throttle_clock_offset: seconds added to now().
 * - social_ai_indexing_test.throttle_waits: every wait requested, in seconds.
 */
final class TestableEmbeddingThrottleSubscriber extends EmbeddingThrottleSubscriber {

  /**
   * {@inheritdoc}
   */
  protected function canWait(): bool {
    return (bool) \Drupal::state()->get('social_ai_indexing_test.throttle_cli', FALSE);
  }

  /**
   * {@inheritdoc}
   */
  protected function wait(int $seconds): void {
    $state = \Drupal::state();
    $waits = $state->get('social_ai_indexing_test.throttle_waits', []);
    $waits[] = $seconds;
    $state->set('social_ai_indexing_test.throttle_waits', $waits);
    $state->set('social_ai_indexing_test.throttle_clock_offset', (int) $state->get('social_ai_indexing_test.throttle_clock_offset', 0) + $seconds);
  }

  /**
   * {@inheritdoc}
   */
  protected function now(): float {
    return parent::now() + (int) \Drupal::state()->get('social_ai_indexing_test.throttle_clock_offset', 0);
  }

}
