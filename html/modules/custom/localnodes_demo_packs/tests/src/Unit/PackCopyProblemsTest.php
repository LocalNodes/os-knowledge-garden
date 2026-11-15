<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\PackCopyChecks;
use Drupal\Tests\UnitTestCase;

/**
 * The copy checks report where a problem is, never the text that is wrong.
 *
 * Source copies and expected answers are members-only text, so a failure must
 * name a row, a passage, a page or a phrase position and print none of the
 * words. Each case gives a phrase that is not where it should be and checks
 * that the problem is reported and that the made-up marker phrase is in no
 * message.
 *
 * @group localnodes_demo_packs
 */
final class PackCopyProblemsTest extends UnitTestCase {

  private const MARK = 'zqmark kettle ledger';

  private static function source(): array {
    return PackText::markdownBlocks("## Library\n\nThe lamp stays on until nine.\n\nThe door opens at noon.\n");
  }

  /**
   * Nothing in a list of messages holds the marker.
   */
  private function assertSilent(array $problems): void {
    $this->assertNotSame([], $problems);
    foreach ($problems as $message) {
      $this->assertFalse(str_contains(mb_strtolower((string) $message), 'zqmark'), 'A problem message prints the text that is wrong.');
    }
  }

  /**
   * A passage that is not in the source is named by its id.
   */
  public function testPassageProblemsNameThePassage(): void {
    $raw = "## Library\n\nThe lamp stays on until nine.\n";
    $good = ['id' => 'p-good', 'text' => 'The lamp stays on until nine.', 'source_line' => 3];
    $bad = ['id' => 'p-bad', 'text' => self::MARK, 'source_line' => 3];
    $this->assertSame([], PackCopyChecks::passageProblems('x', [$good], $raw));
    $problems = PackCopyChecks::passageProblems('x', [$good, $bad], $raw);
    $this->assertSilent($problems);
    $this->assertStringContainsString('p-bad', implode(' ', $problems));
  }

  /**
   * A prose block that is not in the source is named by file, row and block.
   */
  public function testProseProblemsNameTheFileRowAndBlock(): void {
    $uuid = 'a0000000-0000-4000-8000-000000000001';
    $good = '<p>The lamp stays on until nine.</p>';
    $files = ['topic.yml' => [$uuid => ['uuid' => $uuid, 'body' => ['value' => $good . '<p>' . self::MARK . '</p>', 'format' => 'basic_html']]]];
    $problems = PackChecks::proseProblems('x', $files, self::source())['problems'];
    $this->assertSilent($problems);
    $message = implode(' ', $problems);
    $this->assertStringContainsString('topic.yml', $message);
    $this->assertStringContainsString($uuid, $message);
    $this->assertStringContainsString('block 2', $message);
    $this->assertStringNotContainsString('block 1', $message);
    $this->assertStringContainsString('body', $message);
  }

  /**
   * A string with markup and no text is reported without printing it.
   */
  public function testProseWithNoTextIsReportedWithoutItsContent(): void {
    $uuid = 'a0000000-0000-4000-8000-000000000002';
    $files = ['topic.yml' => [$uuid => ['uuid' => $uuid, 'body' => '<p> </p><div class="zqmark"> </div>']]];
    $problems = PackChecks::proseProblems('x', $files, self::source())['problems'];
    $this->assertSilent($problems);
    $this->assertStringContainsString($uuid, implode(' ', $problems));
  }

  /**
   * A left-out sentence that is in the YAML is named by its row.
   */
  public function testLeftOutProblemsNameTheRow(): void {
    $raw = 'The pot holds ' . self::MARK . ".\n";
    $rows = [['id' => 'row-a', 'text' => 'The pot holds ' . self::MARK . '.']];
    $this->assertSame([], PackCopyChecks::leftOutProblems('x', $rows, $raw, 'nothing of it here'));
    $problems = PackCopyChecks::leftOutProblems('x', $rows, $raw, mb_strtolower('The pot holds ' . self::MARK . '.'));
    $this->assertSilent($problems);
    $this->assertStringContainsString('row-a', implode(' ', $problems));
    $problems = PackCopyChecks::leftOutProblems('x', [['id' => 'row-b', 'text' => self::MARK]], "Other text.\n", '');
    $this->assertSilent($problems);
    $this->assertStringContainsString('row-b', implode(' ', $problems));
  }

  /**
   * A must_say phrase that is not in the source is named by its position.
   */
  public function testMustSayProblemsNameTheRowAndPosition(): void {
    $rows = [['id' => 'row-c', 'must_say' => ['lamp stays on', self::MARK]]];
    $this->assertSame([], PackCopyChecks::mustSayProblems('x', [['id' => 'row-c', 'must_say' => ['lamp stays on']]], self::source()));
    $problems = PackCopyChecks::mustSayProblems('x', $rows, self::source());
    $this->assertSilent($problems);
    $message = implode(' ', $problems);
    $this->assertStringContainsString('row-c', $message);
    $this->assertStringContainsString('#2', $message);
    $this->assertStringNotContainsString('#1', $message);
  }

  /**
   * A how-to block that is not in Part 1 is named by its page and position.
   */
  public function testHowtoBlockProblemsNameThePageAndPosition(): void {
    $part_one = self::source();
    $this->assertSame([], PackCopyChecks::howtoBlockProblems('page-1', ['The lamp stays on until nine.'], $part_one));
    $problems = PackCopyChecks::howtoBlockProblems('page-1', ['The lamp stays on until nine.', self::MARK], $part_one);
    $this->assertSilent($problems);
    $message = implode(' ', $problems);
    $this->assertStringContainsString('page-1', $message);
    $this->assertStringContainsString('#2', $message);
  }

  /**
   * A withheld phrase that is in the how-to text is named by its position.
   */
  public function testWithheldProblemsNameThePosition(): void {
    $source = PackText::markdownBlocks("Respond to a decision\n\n" . self::MARK . "\n");
    $this->assertSame([], PackCopyChecks::withheldProblems(['Respond to a decision'], $source, 'a page about other things'));
    $problems = PackCopyChecks::withheldProblems(['Respond to a decision', self::MARK], $source, mb_strtolower('A page with ' . self::MARK . ' in it'));
    $this->assertSilent($problems);
    $message = implode(' ', $problems);
    $this->assertStringContainsString('#2', $message);
    $this->assertStringNotContainsString('#1', $message);
    $problems = PackCopyChecks::withheldProblems(['Not in the source at all ' . self::MARK], $source, '');
    $this->assertSilent($problems);
  }

}
