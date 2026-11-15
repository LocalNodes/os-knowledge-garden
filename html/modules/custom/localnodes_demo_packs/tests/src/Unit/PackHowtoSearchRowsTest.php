<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * The anonymous search rows cover all ten how-to pages.
 *
 * tests/fixtures/expect/howto/expect.yml holds the search rows that
 * scripts/check_demo_pack.php runs on a site. A visitor must not find any of
 * the ten pages, whichever page's words they type, so each page is searched by
 * words from its own title and every row forbids all ten titles.
 *
 * @group localnodes_demo_packs
 */
final class PackHowtoSearchRowsTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * The ten page titles, in the order of the how-to file.
   *
   * @return string[]
   *   The titles.
   */
  private static function titles(): array {
    $titles = [];
    foreach (self::entries(self::sharedHowto()) as [, $data]) {
      $titles[] = (string) $data['title'];
    }
    return $titles;
  }

  /**
   * The search rows.
   *
   * @return array<int, array>
   *   The rows.
   */
  private static function rows(): array {
    $expect = self::expectation('howto', 'expect.yml');
    $rows = is_array($expect) ? ($expect['search_hidden'] ?? []) : [];
    return is_array($rows) ? array_values($rows) : [];
  }

  /**
   * Lower-case words of a text.
   *
   * @return string[]
   *   The words.
   */
  private static function words(string $text): array {
    return preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  }

  /**
   * Every row forbids all ten titles, each as a visitor.
   */
  public function testEveryRowForbidsAllTenTitles(): void {
    $titles = self::titles();
    $this->assertCount(10, $titles, 'The how-to file holds P01 to P10.');
    $sorted = $titles;
    sort($sorted);
    $this->assertNotEmpty(self::rows());
    foreach (self::rows() as $row) {
      $id = (string) ($row['id'] ?? '');
      $this->assertSame('anonymous', $row['as'] ?? NULL, "Row $id searches as a visitor.");
      $forbidden = array_map('strval', $row['hidden_titles'] ?? []);
      sort($forbidden);
      $this->assertSame($sorted, $forbidden, "Row $id forbids every how-to page.");
      $this->assertContains((string) ($row['title'] ?? ''), $titles, "Row $id's own title is a how-to page.");
    }
  }

  /**
   * Each page is searched by words from its own title.
   */
  public function testEachPageIsSearchedByItsOwnTitleWords(): void {
    $by_title = [];
    foreach (self::rows() as $row) {
      $by_title[(string) $row['title']][] = $row;
    }
    foreach (self::titles() as $title) {
      $this->assertArrayHasKey($title, $by_title, "A row searches for the page \"$title\".");
      $found = FALSE;
      foreach ($by_title[$title] as $row) {
        $keys = self::words((string) $row['keys']);
        if ($keys !== [] && array_diff($keys, self::words($title)) === []) {
          $found = TRUE;
        }
      }
      $this->assertTrue($found, "A row for \"$title\" searches with words of that title.");
    }
  }

  /**
   * A row's control member is a member of the smoke pack, not the visitor.
   */
  public function testTheControlIsAMember(): void {
    foreach (self::rows() as $row) {
      $this->assertSame('5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d02', $row['control_as'] ?? NULL, 'The control is the smoke pack member.');
    }
  }

}
