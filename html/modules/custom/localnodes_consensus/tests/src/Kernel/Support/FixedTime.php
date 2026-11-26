<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel\Support;

use Drupal\Component\Datetime\Time;

/**
 * A server clock the test sets: request and current time are both $now.
 */
class FixedTime extends Time {

  /**
   * The time every caller reads.
   */
  public static int $now = 1800000000;

  /**
   * Times the next current-time reads return first, in order (opt-in).
   *
   * @var int[]
   */
  public static array $next = [];

  /**
   * {@inheritdoc}
   */
  public function getRequestTime(): int {
    return static::$now;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequestMicroTime(): float {
    return (float) static::$now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentTime(): int {
    if (static::$next) {
      return array_shift(static::$next);
    }
    return static::$now;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentMicroTime(): float {
    return (float) static::$now;
  }

}
