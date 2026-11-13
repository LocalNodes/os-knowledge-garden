<?php

declare(strict_types=1);

namespace Drupal\localnodes_events_calendar\DemoContent;

/**
 * Turns a demo content date like "next month day 10|18:00" into a time.
 *
 * Demo content is loaded on install day, so its dates are relative to that
 * day. social_demo reads "+14 day|09:00" with strtotime(). This adds month
 * anchored dates, "this month day N" and "next month day N" (N up to 28, so
 * the day exists in every month), so the month calendar has events in the
 * month a visitor opens and the next, whatever day the site was installed.
 * strtotime() cannot say this itself: "first day of next month +9 day" drops
 * the "+9 day".
 */
final class DemoDate {

  /**
   * Returns the date part ("Y-m-d") of a demo date string.
   *
   * @param string $spec
   *   The part before "|": "this month day N", "next month day N", or any
   *   strtotime() string such as "+14 day" or "now".
   * @param int|null $now
   *   The time to count from; the current time when NULL.
   */
  public static function day(string $spec, ?int $now = NULL): string {
    $now ??= time();
    $spec = trim($spec);
    if (preg_match('/^(this|next) month day (\d{1,2})$/', $spec, $m)) {
      $day = (int) $m[2];
      if ($day < 1 || $day > 28) {
        throw new \InvalidArgumentException("A month-anchored demo date needs a day from 1 to 28, not $day.");
      }
      $first = strtotime('first day of ' . $m[1] . ' month', $now);
      return date('Y-m', $first) . sprintf('-%02d', $day);
    }
    $time = strtotime($spec, $now);
    if ($time === FALSE) {
      throw new \InvalidArgumentException("Demo date \"$spec\" is not a date.");
    }
    return date('Y-m-d', $time);
  }

  /**
   * Returns "Y-m-dTH:i:00" for a "date|HH:MM" string, as social_demo does.
   */
  public static function dateTime(string $value, ?int $now = NULL): string {
    [$spec, $clock] = array_pad(explode('|', $value, 2), 2, '00:00');
    return self::day($spec, $now) . 'T' . $clock . ':00';
  }

  /**
   * Returns the timestamp for a "date|HH:MM" string in the site's timezone.
   */
  public static function timestamp(string $value, ?int $now = NULL): int {
    if (trim($value) === 'now') {
      return $now ?? time();
    }
    return (int) strtotime(self::dateTime($value, $now));
  }

}
