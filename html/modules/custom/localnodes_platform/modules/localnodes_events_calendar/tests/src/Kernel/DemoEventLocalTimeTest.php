<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_events_calendar\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_events_calendar\DemoContent\DemoDate;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * A demo event written for 09:30 shows 09:30 to the people on the site.
 *
 * Demo YAML writes the clock time in the site's own timezone. The stored
 * start must be UTC, or a member whose profile is in Denver (every Boulder
 * demo account) saw a 09:30 event as 02:30.
 *
 * @coversDefaultClass \Drupal\localnodes_events_calendar\DemoContent\DemoDate
 * @group localnodes_events_calendar
 */
final class DemoEventLocalTimeTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * PHP's default timezone before the test, put back afterwards.
   */
  private string $phpTimezone;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->phpTimezone = date_default_timezone_get();
    $this->installConfig(['system', 'user']);
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    // Uid 1 first, so the member below is an ordinary account.
    $this->createUser();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    date_default_timezone_set($this->phpTimezone);
    parent::tearDown();
  }

  /**
   * The event's start as the given viewer's screen shows it.
   */
  private function shownTo(string $stored, $account): string {
    $this->setCurrentUser($account);
    $timestamp = (new \DateTimeImmutable($stored, new \DateTimeZone('UTC')))->getTimestamp();
    return $this->container->get('date.formatter')->format($timestamp, 'custom', 'H:i');
  }

  /**
   * A Denver site: a Denver member and a signed-out visitor both see 09:30.
   *
   * @covers ::dateTime
   */
  public function testDenverSiteShowsTheWrittenTime(): void {
    $this->config('system.date')->set('timezone.default', 'America/Denver')->save();
    $stored = DemoDate::dateTime('+14 day|09:30', strtotime('2026-11-20 12:00 America/Denver'));

    $member = $this->createUser([], 'denver_member', FALSE, ['timezone' => 'America/Denver']);
    $this->assertSame('09:30', $this->shownTo($stored, $member), 'A member in Denver sees the time the event was written for.');
    $this->assertSame('09:30', $this->shownTo($stored, new AnonymousUserSession()), 'A signed-out visitor sees the same time.');
  }

  /**
   * A UTC site, as a production container installs: visitors see 09:30.
   *
   * @covers ::dateTime
   * @covers ::siteTimezone
   */
  public function testUtcSiteTreatsTheTimeAsUtc(): void {
    $this->config('system.date')->set('timezone.default', 'UTC')->save();
    $this->assertSame('UTC', DemoDate::siteTimezone());
    $stored = DemoDate::dateTime('+14 day|09:30', strtotime('2026-11-20 12:00 UTC'));

    $this->assertSame('09:30', $this->shownTo($stored, new AnonymousUserSession()));
  }

  /**
   * Days count on the site's calendar, not PHP's, when they differ.
   *
   * Pack tokens call day() directly while pack events go through dateTime().
   * At 23:30 on 30 November in Denver it is already 1 December in UTC, so a
   * day() that counted in PHP's UTC put "this month day 1" in December while
   * the site, and dateTime(), are still in November.
   *
   * @covers ::day
   */
  public function testDayCountsOnTheSiteCalendar(): void {
    $this->config('system.date')->set('timezone.default', 'America/Denver')->save();
    date_default_timezone_set('UTC');
    $now = strtotime('2026-11-30 23:30 America/Denver');

    $this->assertSame('2026-11-01', DemoDate::day('this month day 1', $now));
    $this->assertSame('2026-12-01', DemoDate::day('+1 day', $now));
    $this->assertSame('2026-11-01', substr(DemoDate::localDateTime('this month day 1|09:30', $now), 0, 10));
    $this->assertSame('UTC', date_default_timezone_get(), 'PHP\'s own timezone is left as it was.');
  }

}
