<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\Plugin\DemoContent\PackEvent;
use Drupal\localnodes_demo_packs\Plugin\DemoContent\PackHowto;
use Drupal\localnodes_demo_packs\Plugin\DemoContent\PackTopic;
use Drupal\localnodes_demo_packs\StampsChangedTime;
use Drupal\Tests\UnitTestCase;

/**
 * A loaded page, event or guide page has its changed time set to its created time.
 *
 * The home stream orders by the changed time of each item. Posts already load
 * with changed equal to created; a page, event or guide page that kept the
 * save time would sit above every post, whatever date the pack gave it.
 *
 * @group localnodes_demo_packs
 */
final class PackStampsChangedTest extends UnitTestCase {

  /**
   * The entry gets a changed time equal to its created time.
   */
  public function testEntryChangedEqualsCreated(): void {
    $this->assertTrue(trait_exists(StampsChangedTime::class), 'The trait that stamps the changed time exists.');
    $loader = new class() extends StampsChangedBase {

      use StampsChangedTime;

    };
    $entry = $loader->entryFor(['created' => 1700000000]);
    $this->assertSame(1700000000, $entry['created']);
    $this->assertSame(1700000000, $entry['changed'], 'The changed time is the created time.');
  }

  /**
   * Every node loader of the pack stamps it.
   *
   * @dataProvider loaderProvider
   */
  public function testEveryNodeLoaderStampsIt(string $class): void {
    $this->assertTrue(trait_exists(StampsChangedTime::class), 'The trait that stamps the changed time exists.');
    $found = FALSE;
    for ($c = $class; $c; $c = get_parent_class($c)) {
      if (in_array(StampsChangedTime::class, class_uses($c), TRUE)) {
        $found = TRUE;
      }
    }
    $this->assertTrue($found, "$class stamps the changed time from the created time.");
  }

  /**
   * The loaders: pages, events and guide pages.
   */
  public static function loaderProvider(): array {
    return [
      'topic' => [PackTopic::class],
      'event' => [PackEvent::class],
      'guide page' => [PackHowto::class],
    ];
  }

}

/**
 * Stands in for the loader above the trait: it builds an entry.
 */
abstract class StampsChangedBase {

  /**
   * The entry social_demo builds before the pack adds to it.
   */
  protected function getEntry(array $item) {
    return ['created' => $item['created']];
  }

  /**
   * Opens the protected entry for the test.
   */
  public function entryFor(array $item): array {
    return $this->getEntry($item);
  }

}
