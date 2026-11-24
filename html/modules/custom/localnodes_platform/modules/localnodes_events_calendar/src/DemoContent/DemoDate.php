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
 *
 * "next day N" is the first day N after install day, and "next day N +1
 * month" the one a month later: dates for something held on a fixed day of
 * each month that must stay upcoming whatever day the site was installed.
 */
final class DemoDate {

  /**
   * Returns the date part ("Y-m-d") of a demo date string.
   *
   * @param string $spec
   *   The part before "|": "this month day N", "next month day N", "next day
   *   N", "next day N +M month", or any strtotime() string such as "+14 day"
   *   or "now".
   * @param int|null $now
   *   The time to count from; the current time when NULL.
   * @param string|null $timezone
   *   The timezone whose calendar the days are counted on; the site's
   *   default when NULL (see siteTimezone()), the same one dateTime() uses.
   */
  public static function day(string $spec, ?int $now = NULL, ?string $timezone = NULL): string {
    $previous = date_default_timezone_get();
    date_default_timezone_set($timezone ?? self::siteTimezone());
    try {
      return self::dayInDefaultTimezone(trim($spec), $now ?? time());
    }
    finally {
      date_default_timezone_set($previous);
    }
  }

  /**
   * Counts day() in PHP's current default timezone.
   */
  private static function dayInDefaultTimezone(string $spec, int $now): string {
    if (preg_match('/^(this|next) month day (\d{1,2})$/', $spec, $m)) {
      $day = (int) $m[2];
      if ($day < 1 || $day > 28) {
        throw new \InvalidArgumentException("A month-anchored demo date needs a day from 1 to 28, not $day.");
      }
      $first = strtotime('first day of ' . $m[1] . ' month', $now);
      return date('Y-m', $first) . sprintf('-%02d', $day);
    }
    if (preg_match('/^next day (\d{1,2})(?: \+(\d{1,2}) month)?$/', $spec, $m)) {
      $day = (int) $m[1];
      if ($day < 1 || $day > 28) {
        throw new \InvalidArgumentException("A month-anchored demo date needs a day from 1 to 28, not $day.");
      }
      // Strictly after install day, so the date is never today and already
      // over by the time a visitor looks.
      $months = (int) ($m[2] ?? 0) + ((int) date('j', $now) < $day ? 0 : 1);
      $first = strtotime("first day of +$months month", strtotime(date('Y-m-01 12:00', $now)));
      return date('Y-m', $first) . sprintf('-%02d', $day);
    }
    $time = strtotime($spec, $now);
    if ($time === FALSE) {
      throw new \InvalidArgumentException("Demo date \"$spec\" is not a date.");
    }
    return date('Y-m-d', $time);
  }

  /**
   * Returns the stored start ("Y-m-dTH:i:00", UTC) of a "date|HH:MM" string.
   *
   * Demo YAML writes the clock time people see on the site: "09:30" is half
   * past nine in the site's own timezone (system.date timezone.default), the
   * one a signed-out visitor sees. Datetime fields store UTC, so the time is
   * converted before it is stored. Stored as written, a 09:30 event showed as
   * 02:30 to anyone in Denver.
   *
   * @param string $value
   *   "date|HH:MM", the date part as day() reads it.
   * @param int|null $now
   *   The time to count from; the current time when NULL.
   * @param string|null $timezone
   *   The timezone the clock time is written in; the site's default when
   *   NULL (see siteTimezone()).
   */
  public static function dateTime(string $value, ?int $now = NULL, ?string $timezone = NULL): string {
    $zone = new \DateTimeZone($timezone ?? self::siteTimezone());
    $local = new \DateTimeImmutable(self::localDateTime($value, $now, $zone->getName()), $zone);
    return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s');
  }

  /**
   * Returns "Y-m-dTH:i:00" for a "date|HH:MM" string as the site shows it.
   *
   * The day is counted in $timezone, so "+14 day" and "next month day 6"
   * mean the calendar day a visitor to the site sees.
   *
   * @param string $value
   *   "date|HH:MM".
   * @param int|null $now
   *   The time to count from; the current time when NULL.
   * @param string|null $timezone
   *   The timezone to count days in; the site's default when NULL.
   */
  public static function localDateTime(string $value, ?int $now = NULL, ?string $timezone = NULL): string {
    [$spec, $clock] = array_pad(explode('|', $value, 2), 2, '00:00');
    return self::day($spec, $now, $timezone) . 'T' . $clock . ':00';
  }

  /**
   * Returns the timestamp for a "date|HH:MM" string in the site's timezone.
   */
  public static function timestamp(string $value, ?int $now = NULL, ?string $timezone = NULL): int {
    if (trim($value) === 'now') {
      return $now ?? time();
    }
    return (new \DateTimeImmutable(self::dateTime($value, $now, $timezone), new \DateTimeZone('UTC')))->getTimestamp();
  }

  /**
   * The timezone demo clock times are written in: the site's default.
   *
   * That is system.date timezone.default, which a fresh install takes from
   * PHP (UTC in the production container unless something sets it). With no
   * Drupal site to ask, as in a unit test, PHP's default timezone.
   */
  public static function siteTimezone(): string {
    if (\Drupal::hasContainer() && \Drupal::getContainer()->has('config.factory')) {
      $zone = (string) \Drupal::config('system.date')->get('timezone.default');
      if ($zone !== '') {
        return $zone;
      }
    }
    return date_default_timezone_get();
  }

}
