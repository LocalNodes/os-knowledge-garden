<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackAnswerCheck;
use Drupal\localnodes_demo_packs\PackCheckRows;
use Drupal\Tests\UnitTestCase;

/**
 * The row rules the tier 2 script uses: who a row runs as, what it prints and
 * how a search row is judged.
 *
 * The script needs a running site; these rules do not, so they are proved
 * here.
 *
 * @group localnodes_demo_packs
 */
final class PackCheckRowsTest extends UnitTestCase {

  private const MEMBER = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d02';

  private const OUTSIDER = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d03';

  /**
   * A row runs as the user its `as` key names, and as nobody else.
   *
   * A search row once read `persona`, so a row written with `as` ran as a
   * visitor and passed for the wrong reason.
   */
  public function testPersonaIsTheAsKey(): void {
    $this->assertSame(self::MEMBER, PackCheckRows::persona(['as' => self::MEMBER, 'persona' => self::OUTSIDER]));
    $this->assertSame('anonymous', PackCheckRows::persona(['as' => 'anonymous']));
    $this->assertNull(PackCheckRows::persona(['persona' => self::OUTSIDER]), 'The persona key is not read.');
    $this->assertNull(PackCheckRows::persona([]), 'A row with no persona does not default to a visitor.');
    $this->assertNull(PackCheckRows::persona(['as' => '']));
    $this->assertNull(PackCheckRows::persona(['as' => ['x']]));
  }

  /**
   * A printed result is the row id and fixed reason codes, never a phrase.
   *
   * The row's phrases (the ones it needs and the ones it forbids) stand for
   * text that must not reach a log, so the line a failing row prints holds
   * none of them, nor any part of the answer.
   */
  public function testLineHoldsTheIdAndReasonsOnly(): void {
    $secret = 'the answer holds sk-LEAK and https://example.test/one-time/abc';
    $row = [
      'id' => 'row-1',
      'cites' => [self::MEMBER],
      'must_say' => ['We meet again', 'room key is under the mat'],
      'must_not_say' => ['sk-LEAK', 'one-time/abc', '480 PRACTICE'],
    ];
    $result = PackAnswerCheck::judge($secret, [], $row);
    $this->assertFalse($result['pass']);
    $line = PackCheckRows::line('row-1', $result + ['answer' => $secret]);
    $this->assertSame('FAIL row-1: must_not_say[0]; must_not_say[1]; cite missing: ' . self::MEMBER . '; must_say[0] missing; must_say[1] missing', $line);
    foreach (array_merge($row['must_say'], $row['must_not_say'], [$secret]) as $text) {
      $this->assertStringNotContainsString($text, $line);
    }
    $this->assertStringNotContainsString('LEAK', $line);

    $this->assertSame('FAIL row-1: infrastructure: RuntimeException 503', PackCheckRows::line('row-1', ['pass' => FALSE, 'reasons' => ['infrastructure: RuntimeException 503'], 'answer' => $secret]));
    $this->assertSame('PASS row-1', PackCheckRows::line('row-1', ['pass' => TRUE, 'reasons' => [], 'answer' => $secret]));
    $this->assertSame('FAIL row-2: no reason given', PackCheckRows::line('row-2', ['pass' => FALSE, 'reasons' => []]));
  }

  /**
   * The script neither keeps the answer in a result nor prints it.
   */
  public function testScriptNeverKeepsOrPrintsTheAnswer(): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/check_demo_pack.php');
    $this->assertNotSame('', $source);
    $this->assertDoesNotMatchRegularExpression('/[\'"]answer[\'"]\s*=>/', $source, 'A result must not carry an answer key.');
    $this->assertDoesNotMatchRegularExpression('/\[[\'"]answer[\'"]\]/', $source, 'The script must not read an answer key.');
    $this->assertStringContainsString('PackCheckRows::line(', $source, 'Results print through the one formatter.');
    $this->assertStringContainsString('PackCheckRows::persona(', $source, 'Rows read their persona through the one rule.');
  }

  /**
   * A search row needs a query, a title, a persona and a control member.
   */
  public function testSearchRowNeedsEveryKey(): void {
    $row = ['id' => 'r', 'as' => self::OUTSIDER, 'keys' => 'garden', 'title' => 'Hidden plan', 'control_as' => self::MEMBER];
    $this->assertSame([], PackCheckRows::searchRowProblems($row));
    foreach (['as' => 'no member to search as', 'keys' => 'no query', 'title' => 'no title', 'control_as' => 'no control member'] as $key => $problem) {
      $broken = $row;
      unset($broken[$key]);
      $this->assertSame([$problem], PackCheckRows::searchRowProblems($broken), "Without $key.");
    }
    $same = ['control_as' => self::OUTSIDER] + $row;
    $this->assertSame(['the control member is the one searching'], PackCheckRows::searchRowProblems($same));
  }

  /**
   * The pager reads every page until one is short.
   */
  public function testAllHitsReadsEveryPage(): void {
    $items = array_map(static fn (int $i): string => "Page $i", range(1, 120));
    $calls = [];
    $read = PackCheckRows::allHits(static function (int $offset, int $size) use ($items, &$calls): array {
      $calls[] = [$offset, $size];
      return array_slice($items, $offset, $size);
    });
    $this->assertSame($items, $read['hits']);
    $this->assertTrue($read['complete']);
    $this->assertSame([[0, 50], [50, 50], [100, 50]], $calls);
  }

  /**
   * A result set that fills the last page exactly needs one more, empty, read.
   */
  public function testAllHitsStopsOnAnEmptyPage(): void {
    $items = array_map(static fn (int $i): string => "Page $i", range(1, 100));
    $read = PackCheckRows::allHits(static fn (int $offset, int $size): array => array_slice($items, $offset, $size));
    $this->assertCount(100, $read['hits']);
    $this->assertTrue($read['complete']);
  }

  /**
   * A result set beyond the cap is reported as not fully read.
   */
  public function testAllHitsReportsWhenTheCapStopsIt(): void {
    $items = array_map(static fn (int $i): string => "Page $i", range(1, 250));
    $read = PackCheckRows::allHits(static fn (int $offset, int $size): array => array_slice($items, $offset, $size), 50, 100);
    $this->assertCount(100, $read['hits']);
    $this->assertFalse($read['complete']);
  }

  /**
   * A row passes when the persona's hits lack the title and the control's hold it.
   */
  public function testSearchPassesOnlyWithTheControlFindingTheTitle(): void {
    $title = 'Hidden plan';
    $absent = ['hits' => ['Other page'], 'complete' => TRUE];
    $found = ['hits' => ['Other page', $title], 'complete' => TRUE];

    $this->assertSame(['pass' => TRUE, 'reasons' => []], PackCheckRows::searchVerdict($title, $absent, $found));

    $leaks = PackCheckRows::searchVerdict($title, $found, $found);
    $this->assertFalse($leaks['pass']);
    $this->assertContains('a hit carries the hidden title', $leaks['reasons']);
    $this->assertStringNotContainsString($title, implode(' ', $leaks['reasons']), 'A reason does not print the title.');

    $blind = PackCheckRows::searchVerdict($title, $absent, $absent);
    $this->assertFalse($blind['pass'], 'A search that finds nothing for anyone proves nothing.');
    $this->assertContains('the control member does not find the title', $blind['reasons']);
    $this->assertStringNotContainsString($title, implode(' ', $blind['reasons']), 'A reason does not print the title.');
  }

  /**
   * A row can forbid more than its own title; a leak of any listed title fails.
   *
   * With only the first page forbidden, a second page that leaks still passes:
   * that is the gap a longer hidden_titles list closes.
   */
  public function testASecondForbiddenTitleThatLeaksFailsTheRow(): void {
    $first = 'Join a group';
    $second = 'Write a post';
    $absent = ['hits' => ['Other page'], 'complete' => TRUE];
    $leaks_second = ['hits' => ['Other page', $second], 'complete' => TRUE];
    $control = ['hits' => [$first], 'complete' => TRUE];

    $narrow = PackCheckRows::searchVerdict($first, $leaks_second, $control, [$first]);
    $this->assertTrue($narrow['pass'], 'A list holding only the first title does not see the second page leak.');

    $wide = PackCheckRows::searchVerdict($first, $leaks_second, $control, [$first, $second]);
    $this->assertFalse($wide['pass'], 'A list holding both titles sees it.');
    $this->assertSame(['hidden_titles[1]'], $wide['reasons'], 'The reason names the list position and prints no title.');

    $clean = PackCheckRows::searchVerdict($first, $absent, $control, [$first, $second]);
    $this->assertSame(['pass' => TRUE, 'reasons' => []], $clean);
  }

  /**
   * The hidden titles of a row are a list of titles, or the row is incomplete.
   */
  public function testHiddenTitlesMustBeAListOfTitles(): void {
    $row = ['as' => 'anonymous', 'keys' => 'a', 'title' => 'T', 'control_as' => 'u'];
    $this->assertSame([], PackCheckRows::searchRowProblems($row), 'No hidden_titles key is allowed.');
    $this->assertSame([], PackCheckRows::hiddenTitles($row));
    $this->assertSame(['A', 'B'], PackCheckRows::hiddenTitles($row + ['hidden_titles' => ['A', 'B']]));
    foreach (['A', [], [''], ['A', 5], ['A', ['B']], [' ']] as $index => $bad) {
      $this->assertContains(PackCheckRows::BAD_HIDDEN, PackCheckRows::searchRowProblems($row + ['hidden_titles' => $bad]), "Bad hidden_titles #$index fails the row.");
    }
  }

  /**
   * The script hands a row's hidden titles to the verdict.
   */
  public function testScriptPassesTheHiddenTitlesToTheVerdict(): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/check_demo_pack.php');
    $this->assertStringContainsString('PackCheckRows::hiddenTitles(', $source);
  }

  /**
   * A hit past the first 50 still fails the row.
   */
  public function testALeakBeyondTheFirstFiftyHitsIsSeen(): void {
    $title = 'Hidden plan';
    $items = array_map(static fn (int $i): string => "Page $i", range(1, 120));
    $items[99] = $title;
    $read = PackCheckRows::allHits(static fn (int $offset, int $size): array => array_slice($items, $offset, $size));
    $control = ['hits' => [$title], 'complete' => TRUE];
    $this->assertFalse(PackCheckRows::searchVerdict($title, $read, $control)['pass']);
  }

  /**
   * Hits that were not all read cannot pass a row.
   */
  public function testUnreadHitsFailTheRow(): void {
    $title = 'Hidden plan';
    $partial = ['hits' => ['Other page'], 'complete' => FALSE];
    $control = ['hits' => [$title], 'complete' => TRUE];
    $verdict = PackCheckRows::searchVerdict($title, $partial, $control);
    $this->assertFalse($verdict['pass']);
    $this->assertContains('the hits were not all read', $verdict['reasons']);
  }

  /**
   * A row's group is its uuid, or none when the key is empty or absent.
   *
   * An empty group is a site-wide question: the script must not look a group
   * up for it, and must not pick one by default.
   */
  public function testGroupIsReadOnlyWhenTheRowNamesOne(): void {
    $uuid = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d10';
    $this->assertSame($uuid, PackCheckRows::groupUuid(['group' => $uuid]));
    $this->assertSame($uuid, PackCheckRows::groupUuid(['group' => " $uuid "]));
    $this->assertNull(PackCheckRows::groupUuid(['group' => '']));
    $this->assertNull(PackCheckRows::groupUuid([]));
    $this->assertNull(PackCheckRows::groupUuid(['group' => NULL]));
    foreach ([[], ['group' => NULL], ['group' => ''], ['group' => $uuid], ['group' => " $uuid "]] as $row) {
      $this->assertSame([], PackCheckRows::groupProblems($row), 'A site-wide or named group is not a problem.');
    }
  }

  /**
   * A group value that is not a uuid string never becomes a site-wide question.
   *
   * Only a missing key, NULL or an empty string is site-wide. A list, a
   * number, a boolean or a blank string is a row that was written wrong, so it
   * fails with one fixed reason and reads no group at all.
   */
  public function testAMalformedGroupIsNotSiteWide(): void {
    $uuid = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d10';
    $malformed = [['x'], [$uuid], [], 0, 5, 1.5, TRUE, FALSE, '  ', "\t", new \stdClass()];
    foreach ($malformed as $index => $value) {
      $row = ['group' => $value];
      $this->assertSame([PackCheckRows::BAD_GROUP], PackCheckRows::groupProblems($row), "Malformed group #$index fails the row.");
      try {
        PackCheckRows::groupUuid($row);
        $this->fail("Malformed group #$index was read as a group.");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringNotContainsString($uuid, $e->getMessage(), 'The message does not repeat the value.');
      }
    }
    $this->assertSame('FAIL row-1: group is not a uuid string', PackCheckRows::line('row-1', ['pass' => FALSE, 'reasons' => [PackCheckRows::BAD_GROUP]]));
  }

  /**
   * The script reads a row's group problems before it runs or lists the row.
   */
  public function testScriptChecksTheGroupBeforeRunningARow(): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/check_demo_pack.php');
    $this->assertStringContainsString('PackCheckRows::groupProblems(', $source);
  }

  /**
   * The dry-run line names the member and the group, or says site-wide.
   */
  public function testDryLineSaysSiteWideForNoGroup(): void {
    $this->assertSame('DRY row-1 as smoke_two in site-wide', PackCheckRows::dryLine('row-1', 'smoke_two', NULL));
    $this->assertSame('DRY row-1 as visitor in Common room', PackCheckRows::dryLine('row-1', 'visitor', 'Common room'));
  }

  /**
   * A row whose member or group is not on the site fails with one fixed code.
   */
  public function testMissingAccountOrGroupHasOneFixedLine(): void {
    $result = ['pass' => FALSE, 'reasons' => [PackCheckRows::MISSING]];
    $this->assertSame('FAIL row-1: account or group missing', PackCheckRows::line('row-1', $result));
  }

}
