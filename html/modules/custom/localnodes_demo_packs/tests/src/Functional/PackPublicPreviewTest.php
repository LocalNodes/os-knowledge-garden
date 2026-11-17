<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;

/**
 * A signed-out visitor to a loaded Lantern Row pack finds a living site.
 *
 * The pack is loaded with its dates counted from the moment the test runs,
 * as a fresh install counts them, because the event lists compare against
 * the database's own clock. The front page shows upcoming events and public
 * posts, the event list shows upcoming and past events, and the topic list
 * shows public topics. A month after install the event list still has
 * something coming. Records for members stay out of every list.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackPublicPreviewTest extends BrowserTestBase {

  use PackLoadTrait;
  use PackTestTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  /**
   * The pack this test loads.
   */
  private const PACK = 'lanternrow';

  /**
   * The past public event, in the source's "Open to visitors" section.
   */
  private const PAST_EVENT = 'Autumn bulk-buy pickup';

  /**
   * A members-only event in the Willow House group, one day after install.
   */
  private const MEMBERS_EVENT = 'Fridge clear-out';

  /**
   * Posts a visitor must not see: Willow House members only, then community.
   */
  private const HIDDEN_POSTS = [
    'b94df212-2c8a-48d7-9817-372243e76969',
    '85bb39aa-cd18-4e24-ac7e-c5b9dca55c86',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpPackSite();
  }

  /**
   * On install day and a month later, a visitor sees public records only.
   */
  public function testVisitorSeesPublicRecords(): void {
    $now = (int) $this->container->get('datetime.time')->getCurrentTime();
    $this->loadPack(self::PACK, $now);
    $this->drupalResetSession();

    // The front page: upcoming events in the sidebar, public posts in the feed.
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertGreaterThanOrEqual(1, $this->elementsMatching('#block-socialblue-views-block-upcoming-events-block-community-events .teaser--small__title'), 'The front page lists an upcoming event.');
    $this->assertGreaterThanOrEqual(3, $this->elementsMatching('.card--stream'), 'The front page feed shows at least three posts.');
    $text = $this->getSession()->getPage()->getText();
    foreach (self::HIDDEN_POSTS as $uuid) {
      $sentence = $this->postSentence($uuid);
      $this->assertStringNotContainsString($sentence, $text, "The front page must not show post $uuid.");
    }

    // The event list: upcoming by default, the past on its filter.
    $this->drupalGet('/community-events');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertGreaterThanOrEqual(3, $this->elementsMatching('.teaser-event'), 'The event list shows at least three upcoming events.');
    $this->assertSession()->pageTextNotContains(self::MEMBERS_EVENT);
    $this->drupalGet('/community-events', ['query' => ['field_event_date_value' => 2]]);
    $this->assertGreaterThanOrEqual(1, $this->elementsMatching('.teaser-event'), 'The past event list is not empty.');
    $this->assertSession()->pageTextContains(self::PAST_EVENT);

    // The topic list.
    $this->drupalGet('/all-topics');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertGreaterThanOrEqual(3, $this->elementsMatching('.teaser'), 'The topic list shows at least three topics.');

    // A month on, before the monthly reinstall, something is still coming.
    $this->removePack(self::PACK);
    $this->loadPack(self::PACK, $now - 30 * 86400);
    $this->drupalResetSession();
    $this->drupalGet('/community-events');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertGreaterThanOrEqual(1, $this->elementsMatching('.teaser-event'), 'A month after install the event list still shows an upcoming event.');
  }

  /**
   * How many elements on the current page match a CSS selector.
   */
  private function elementsMatching(string $selector): int {
    return count($this->getSession()->getPage()->findAll('css', $selector));
  }

  /**
   * The first sentence of a pack post, as plain text.
   */
  private function postSentence(string $uuid): string {
    $post = $this->entityByUuid($uuid);
    $this->assertNotNull($post, "Post $uuid loaded.");
    $text = trim(html_entity_decode(strip_tags((string) $post->get('field_post')->value), ENT_QUOTES));
    $end = strpos($text, '. ');
    $sentence = $end === FALSE ? $text : substr($text, 0, $end + 1);
    $this->assertNotSame('', $sentence, "Post $uuid has text.");
    return $sentence;
  }

}
