<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\localnodes_events_calendar\DemoContent\DemoDate;
use Drupal\Tests\UnitTestCase;

/**
 * Lantern Row's public events look current on any install day.
 *
 * A signed-out visitor sees the public events. Whatever day the site is
 * installed, exactly one of them has already happened, at least three are
 * still to come, and the open evenings fall on the 20th, as the pack's posts
 * say they do.
 *
 * @group localnodes_demo_packs
 */
final class PackPublicDatesTest extends UnitTestCase {

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
   * Every install day of a year, at 10:00 and late in the evening.
   */
  public function testPublicEventsOnEveryInstallDay(): void {
    $events = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/packs/lanternrow/content/entity/event.yml'));
    $public = array_filter($events, static fn (array $event): bool => ($event['field_content_visibility'] ?? '') === 'public');
    $this->assertGreaterThanOrEqual(4, count($public));

    for ($day = strtotime('2026-01-01 10:00'); $day < strtotime('2027-01-01'); $day = strtotime('+1 day', $day)) {
      foreach ([$day, strtotime(date('Y-m-d 22:00', $day))] as $now) {
        $past = 0;
        $ahead = 0;
        foreach ($public as $event) {
          $start = DemoDate::timestamp($event['field_event_date'], $now);
          $start > $now ? $ahead++ : $past++;
          if ($event['title'] === 'Open evening') {
            $this->assertSame('20', date('d', $start), 'An open evening is not on the 20th for an install on ' . date('Y-m-d H:i', $now));
            $this->assertGreaterThan($now, $start, 'An open evening is past for an install on ' . date('Y-m-d H:i', $now));
          }
        }
        $label = date('Y-m-d H:i', $now);
        $this->assertSame(1, $past, "Install on $label: $past public events are past, not 1.");
        $this->assertGreaterThanOrEqual(3, $ahead, "Install on $label: only $ahead public events are still to come.");
      }
    }
  }

}
