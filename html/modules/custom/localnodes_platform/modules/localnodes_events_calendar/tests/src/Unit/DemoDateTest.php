<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_events_calendar\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\localnodes_events_calendar\DemoContent\DemoDate;
use Drupal\Tests\UnitTestCase;

/**
 * Demo event dates land in the install month and the next, on any day.
 *
 * @coversDefaultClass \Drupal\localnodes_events_calendar\DemoContent\DemoDate
 * @group localnodes_events_calendar
 */
final class DemoDateTest extends UnitTestCase {

  /**
   * The timezone in force before the test.
   */
  private string $timezone;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->timezone = date_default_timezone_get();
    date_default_timezone_set('America/Denver');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    date_default_timezone_set($this->timezone);
    parent::tearDown();
  }

  /**
   * Install days that trip naive month arithmetic.
   */
  public static function installDays(): array {
    return [
      'first of the month' => ['2026-01-01 00:30', '2026-01', '2026-02'],
      'the 31st' => ['2026-01-31 23:30', '2026-01', '2026-02'],
      'end of February' => ['2027-02-28 12:00', '2027-02', '2027-03'],
      'New Year\'s Eve' => ['2026-12-31 23:59', '2026-12', '2027-01'],
    ];
  }

  /**
   * Month-anchored dates stay in the right month.
   *
   * @covers ::day
   * @covers ::localDateTime
   * @dataProvider installDays
   */
  public function testMonthAnchoredDays(string $now, string $this_month, string $next_month): void {
    $time = strtotime($now);
    $this->assertSame("$this_month-04", DemoDate::day('this month day 4', $time));
    $this->assertSame("$this_month-28", DemoDate::day('this month day 28', $time));
    $this->assertSame("$next_month-06", DemoDate::day('next month day 6', $time));
    $this->assertSame("$next_month-24T19:00:00", DemoDate::localDateTime('next month day 24|19:00', $time));
    // Plain strtotime() strings still work as social_demo reads them.
    $this->assertSame(date('Y-m-d', strtotime('+14 day', $time)), DemoDate::day('+14 day', $time));
    $this->assertSame($time, DemoDate::timestamp('now', $time));
  }

  /**
   * Install days around the 20th and the turn of a year.
   */
  public static function nextDayCases(): array {
    return [
      'the day before' => ['2026-11-19 23:30', '2026-11-20', '2026-12-20'],
      'the day itself' => ['2026-11-20 08:00', '2026-12-20', '2027-01-20'],
      'the day after' => ['2026-11-21 00:30', '2026-12-20', '2027-01-20'],
      'the 31st' => ['2026-01-31 12:00', '2026-02-20', '2026-03-20'],
      'New Year\'s Eve' => ['2026-12-31 23:59', '2027-01-20', '2027-02-20'],
    ];
  }

  /**
   * "next day N" is always after install day, and "+1 month" a month on.
   *
   * @covers ::day
   * @covers ::localDateTime
   * @dataProvider nextDayCases
   */
  public function testNextDayIsAlwaysAhead(string $now, string $first, string $second): void {
    $time = strtotime($now);
    $this->assertSame($first, DemoDate::day('next day 20', $time));
    $this->assertSame($second, DemoDate::day('next day 20 +1 month', $time));
    $this->assertSame("{$second}T19:00:00", DemoDate::localDateTime('next day 20 +1 month|19:00', $time));
    $this->assertGreaterThan(date('Y-m-d', $time), DemoDate::day('next day 20', $time));
  }

  /**
   * A clock time is the site's local time, stored as UTC.
   *
   * @covers ::dateTime
   * @covers ::timestamp
   * @dataProvider localTimes
   */
  public function testClockTimeIsLocalAndStoredAsUtc(string $now, string $value, string $zone, string $stored): void {
    $time = strtotime($now);
    $this->assertSame($stored, DemoDate::dateTime($value, $time, $zone));
    $this->assertSame(strtotime($stored . ' UTC'), DemoDate::timestamp($value, $time, $zone));
  }

  /**
   * Install days, clock times and zones, with the UTC start each stores.
   */
  public static function localTimes(): array {
    return [
      'Denver in winter' => ['2026-11-20 12:00', '+14 day|09:30', 'America/Denver', '2026-12-04T16:30:00'],
      'Denver in summer' => ['2026-06-01 12:00', '+3 day|09:30', 'America/Denver', '2026-06-04T15:30:00'],
      'an evening that is the next day in UTC' => ['2026-11-20 12:00', 'next month day 24|18:30', 'America/Denver', '2026-12-25T01:30:00'],
      'a UTC site stores the time as written' => ['2026-11-20 12:00', '+14 day|09:30', 'UTC', '2026-12-04T09:30:00'],
    ];
  }

  /**
   * With no zone given and no site to ask, PHP's default is the local zone.
   *
   * @covers ::dateTime
   * @covers ::siteTimezone
   */
  public function testDefaultZoneIsUsedWithoutASite(): void {
    $this->assertSame('America/Denver', DemoDate::siteTimezone());
    $this->assertSame('2026-12-04T16:30:00', DemoDate::dateTime('+14 day|09:30', strtotime('2026-11-20 12:00')));
  }

  /**
   * A day that some months lack is refused rather than rolled over.
   *
   * @covers ::day
   */
  public function testDayAfterTwentyEightIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    DemoDate::day('next month day 30', strtotime('2026-01-31'));
  }

  /**
   * The "next day N" form refuses the same days.
   *
   * @covers ::day
   */
  public function testNextDayAfterTwentyEightIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    DemoDate::day('next day 29', strtotime('2026-01-31'));
  }

  /**
   * Each demo module has events in the install month and the next.
   *
   * @dataProvider installDays
   */
  public function testDemoEventsFillTwoMonths(string $now, string $this_month, string $next_month): void {
    $custom = dirname(__DIR__, 6);
    foreach (['localnodes_demo', 'boulder_demo'] as $module) {
      $events = Yaml::decode(file_get_contents("$custom/$module/content/entity/event.yml"));
      $months = [];
      foreach ($events as $event) {
        $start = DemoDate::localDateTime($event['field_event_date'], strtotime($now));
        $end = DemoDate::localDateTime($event['field_event_date_end'] ?? $event['field_event_date'], strtotime($now));
        $this->assertLessThanOrEqual($end, $start, "$module: {$event['title']} ends before it starts.");
        $month = substr($start, 0, 7);
        $months[$month] = ($months[$month] ?? 0) + 1;
      }
      $this->assertGreaterThanOrEqual(3, $months[$this_month] ?? 0, "$module has too few events in the install month.");
      $this->assertGreaterThanOrEqual(3, $months[$next_month] ?? 0, "$module has too few events next month.");
    }
  }

}
