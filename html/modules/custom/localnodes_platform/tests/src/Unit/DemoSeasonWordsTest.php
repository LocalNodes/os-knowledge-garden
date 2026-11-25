<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * A demo item whose date floats with install day names no season.
 *
 * Demo dates are offsets from install day ("+60 day", "this month day 4"),
 * so an event called a solstice gathering lands in whatever month the site
 * was installed. Text on such an item must not name a season, a solstice or
 * the longest day.
 *
 * @group localnodes_platform
 */
final class DemoSeasonWordsTest extends UnitTestCase {

  /**
   * Words that tie a text to a time of year.
   */
  private const SEASON = '/\b(spring|springtime|summer|summertime|autumn|winter|wintertime|solstice|equinox|longest day|shortest day|this season|(last|this|next|early|late|in the) fall)\b/i';

  /**
   * Keys that hold an item's date.
   */
  private const DATE_KEYS = ['created', 'field_event_date', 'field_event_date_end'];

  /**
   * The demo modules held to this rule.
   */
  public static function demoModules(): array {
    return [
      'boulder_demo' => ['boulder_demo'],
      'neighbors_demo' => ['neighbors_demo'],
    ];
  }

  /**
   * No item with a floating date says a season.
   *
   * @dataProvider demoModules
   */
  public function testNoSeasonOnAFloatingDate(string $module): void {
    $files = glob(dirname(__DIR__, 4) . "/$module/content/entity/*.yml");
    $this->assertNotEmpty($files);
    $found = [];
    $floating = 0;
    foreach ($files as $file) {
      $data = Yaml::decode((string) file_get_contents($file));
      foreach (is_array($data) ? $data : [] as $uuid => $item) {
        if (!is_array($item) || !$this->floats($item)) {
          continue;
        }
        $floating++;
        array_walk_recursive($item, function ($value, $key) use (&$found, $file, $uuid): void {
          if (is_string($value) && preg_match(self::SEASON, strip_tags($value), $match)) {
            $found[] = basename($file) . " $uuid $key: {$match[0]}";
          }
        });
      }
    }
    $this->assertGreaterThan(0, $floating, "$module has items dated from install day.");
    $this->assertSame([], $found, "$module names a season on an item whose date floats with install day.");
  }

  /**
   * Whether the item's date is counted from install day.
   *
   * An offset ("+60 day", "this month day 4") floats, and so does an item
   * with no date at all: it gets the time it was loaded. Only a written
   * calendar date (2026-06-21) is fixed.
   */
  private function floats(array $item): bool {
    foreach (self::DATE_KEYS as $key) {
      if (is_string($item[$key] ?? NULL) && preg_match('/^\d{4}-\d{2}-\d{2}/', $item[$key])) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
