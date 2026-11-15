<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\PackProvenanceChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Where a phrase a reply must not say comes from, and where it must not be.
 *
 * Made-up pages and rows: a forbidden phrase is held by a page the row
 * forbids (or by a sentence the pack leaves out), and by no page the row
 * allows or cites.
 *
 * @group localnodes_demo_packs
 */
final class PackProvenanceTest extends UnitTestCase {

  use PackTestTrait;

  private const FORBIDDEN = 'f0000000-0000-4000-8000-000000000001';

  private const CITED = 'c0000000-0000-4000-8000-000000000002';

  private const MAYBE = 'c0000000-0000-4000-8000-000000000003';

  private const OTHER = 'e0000000-0000-4000-8000-000000000004';

  /**
   * Passages of made-up pages.
   */
  private static function passages(array $extra = []): array {
    return array_merge([
      ['uuid' => self::FORBIDDEN, 'text' => 'The brass lantern hangs in the back room.'],
      ['uuid' => self::CITED, 'text' => 'The kettle is warm on Thursday.'],
      ['uuid' => self::MAYBE, 'text' => 'Doors open at noon.'],
      ['uuid' => self::OTHER, 'text' => 'The vase is blue.'],
    ], $extra);
  }

  private static function row(array $change = []): array {
    return $change + [
      'id' => 'row-1',
      'cites' => [self::CITED],
      'may_cite' => [self::MAYBE],
      'forbidden' => [self::FORBIDDEN],
      'must_not_say' => ['brass lantern'],
    ];
  }

  /**
   * Whole pages of the made-up passages: one page for each uuid.
   *
   * @return array<string, string[]>
   *   From PackProvenanceChecks::pageTexts().
   */
  private static function pagesOf(array $passages, array $extra_body = []): array {
    $bodies = [];
    foreach ($passages as $passage) {
      $bodies[(string) $passage['uuid']][] = '<p>' . $passage['text'] . '</p>';
    }
    $entries = [];
    foreach ($bodies as $uuid => $paragraphs) {
      $body = implode('', $paragraphs) . ($extra_body[$uuid] ?? '');
      $entries[$uuid] = ['uuid' => $uuid, 'title' => 'Page ' . substr($uuid, 0, 2), 'body' => ['value' => $body, 'format' => 'basic_html']];
    }
    return PackProvenanceChecks::pageTexts(['p.yml' => $entries]);
  }

  private function problems(array $rows, array $passages, array $left_out = [], ?array $pages = NULL): string {
    return implode(' | ', PackProvenanceChecks::mustNotSayProblems('x', $rows, $passages, $left_out, $pages ?? self::pagesOf($passages)));
  }

  /**
   * A forbidden phrase in the body of an allowed page is reported, though the
   * recorded passage of the page does not hold it.
   */
  public function testPhraseInTheWholeBodyOfAnAllowedPageIsReported(): void {
    $passages = self::passages();
    $pages = self::pagesOf($passages, [self::CITED => '<p>Also: the brass lantern is for sale.</p>']);
    $message = $this->problems([self::row()], $passages, [], $pages);
    $this->assertStringContainsString('row-1', $message);
    $this->assertStringContainsString('allowed', $message);
    $this->assertStringNotContainsString('lantern', $message);
    $this->assertSame('', $this->problems([self::row()], $passages), 'Without the extra sentence the row passes.');
  }

  /**
   * A forbidden phrase in the title of a may_cite page is reported.
   */
  public function testPhraseInTheTitleOfAnAllowedPageIsReported(): void {
    $pages = PackProvenanceChecks::pageTexts(['p.yml' => [
      self::MAYBE => ['uuid' => self::MAYBE, 'title' => 'The Brass Lantern', 'body' => '<p>Doors open at noon.</p>'],
      self::CITED => ['uuid' => self::CITED, 'title' => 'Kettle', 'body' => '<p>The kettle is warm on Thursday.</p>'],
      self::FORBIDDEN => ['uuid' => self::FORBIDDEN, 'title' => 'Back room', 'body' => '<p>The brass lantern hangs in the back room.</p>'],
    ]]);
    $this->assertStringContainsString('allowed', $this->problems([self::row()], self::passages(), [], $pages));
  }

  /**
   * An allowed or cited uuid that is no page is reported, with its position.
   */
  public function testAnAllowedUuidThatIsNoPageIsReported(): void {
    $row = self::row(['may_cite' => [self::MAYBE, 'd0000000-0000-4000-8000-000000000009']]);
    $message = $this->problems([$row], self::passages(), [], self::pagesOf(self::passages()));
    $this->assertStringContainsString('row-1', $message);
    $this->assertStringContainsString('no page', $message);
    $this->assertStringNotContainsString('d0000000', $message);
  }

  /**
   * The text of a page is its title and its body, without markup, folded.
   */
  public function testPageTextsHoldTitleAndBodyWithoutMarkup(): void {
    $pages = PackProvenanceChecks::pageTexts(['p.yml' => [
      'k' => ['uuid' => self::CITED, 'title' => 'Kettle  Notes', 'body' => ['value' => '<p>One <em>warm</em> day.</p><p>Two.</p>', 'format' => 'basic_html'], 'type' => 'topic'],
    ]]);
    $this->assertArrayHasKey(self::CITED, $pages);
    $joined = implode(' | ', $pages[self::CITED]);
    $this->assertStringContainsString('kettle notes', $joined);
    $this->assertStringContainsString('one warm day', $joined);
    $this->assertStringContainsString('two', $joined);
    $this->assertStringNotContainsString('<', $joined);
    $this->assertStringNotContainsString('topic', $joined, 'Only prose keys are page text.');
  }

  /**
   * On the smoke pack: with a forbidden phrase appended to an allowed page and
   * to the source, the copy checks still pass, and the exclusivity rule fails.
   */
  public function testCopyChecksPassWhenAForbiddenPhraseIsAppendedToAnAllowedPage(): void {
    [$files, $source, , $row_id] = self::appendedCopy();
    $result = PackChecks::proseProblems('smoke', $files, PackText::markdownBlocks($source));
    $this->assertSame([], $result['problems'], 'The copy checks see nothing wrong.');
    $this->assertNotSame('', $row_id);
  }

  /**
   * The exclusivity rule reads the whole allowed page and fails on the copy.
   */
  public function testExclusivityFailsOnTheAppendedCopy(): void {
    [$files, , $rows, $row_id] = self::appendedCopy();
    $left_out = self::expectation('smoke', 'expect.yml')['left_out'] ?? [];
    $passages = self::expectation('smoke', 'passages.yml');
    $run = static fn (array $files): array => PackProvenanceChecks::mustNotSayProblems('smoke', $rows, $passages, $left_out, PackProvenanceChecks::pageTexts($files + ['howto.yml' => self::sharedHowto()]));
    $problems = $run($files);
    $this->assertNotSame([], $problems);
    $this->assertStringContainsString($row_id, implode(' | ', $problems));
    $this->assertStringNotContainsString('PRACTICE', implode(' | ', $problems));
    $this->assertSame([], $run(self::packYaml('smoke')), 'The real pack passes.');
  }

  /**
   * The smoke pack with a forbidden phrase appended to the page a row cites.
   *
   * @return array{0: array, 1: string, 2: array, 3: string}
   *   The pack's YAML files, the source text, the assistant rows and the row.
   */
  private static function appendedCopy(): array {
    $rows = self::expectation('smoke', 'assistant.yml');
    $row = $rows[0];
    $phrase = (string) $row['must_not_say'][0];
    $target = (string) $row['cites'][0];
    $files = self::packYaml('smoke');
    foreach ($files as $file => $entries) {
      foreach ($entries as $key => $entry) {
        if (is_array($entry) && (string) ($entry['uuid'] ?? $key) === $target) {
          $files[$file][$key]['body'] = $entry['body'] . '<p>' . $phrase . '</p>';
        }
      }
    }
    return [$files, (string) self::expectation('smoke', 'source.md') . "\n\n" . $phrase . "\n", $rows, (string) $row['id']];
  }

  /**
   * A phrase held by a forbidden page and by no allowed page passes.
   */
  public function testForbiddenPhraseOnForbiddenPagePasses(): void {
    $this->assertSame('', $this->problems([self::row()], self::passages()));
  }

  /**
   * A forbidden phrase that also sits on a cited page is reported.
   */
  public function testPhraseOnACitedPageIsReported(): void {
    $passages = self::passages([['uuid' => self::CITED, 'text' => 'Note: a brass lantern is for sale.']]);
    $message = $this->problems([self::row()], $passages);
    $this->assertStringContainsString('row-1', $message);
    $this->assertStringContainsString('allowed', $message);
    $this->assertStringNotContainsString('lantern', $message);
  }

  /**
   * A forbidden phrase that also sits on a may_cite page is reported.
   */
  public function testPhraseOnAMayCitePageIsReported(): void {
    $passages = self::passages([['uuid' => self::MAYBE, 'text' => 'Brass Lantern hours: noon.']]);
    $this->assertStringContainsString('allowed', $this->problems([self::row()], $passages));
  }

  /**
   * A phrase held by no forbidden page and no left-out sentence is reported.
   */
  public function testPhraseWithNoSourceIsReported(): void {
    $message = $this->problems([self::row(['must_not_say' => ['golden goose']])], self::passages());
    $this->assertStringContainsString('row-1', $message);
    $this->assertStringContainsString('no forbidden page', $message);
  }

  /**
   * The left-out sentence rule is its own rule: it accepts a phrase of a
   * sentence the pack never loads, when the row forbids nothing.
   */
  public function testLeftOutSentenceAcceptsAPhraseOnItsOwn(): void {
    $row = self::row(['forbidden' => [], 'must_not_say' => ['480 PRACTICE']]);
    $left_out = [['id' => 'pot', 'text' => 'The pot balance is 480 PRACTICE.']];
    $this->assertSame('', $this->problems([$row], self::passages(), $left_out));
    $this->assertTrue(PackProvenanceChecks::heldByLeftOut('480 practice', $left_out));
    $this->assertFalse(PackProvenanceChecks::heldByLeftOut('golden goose', $left_out));
  }

  /**
   * A left-out phrase that a cited page also holds is reported.
   */
  public function testLeftOutPhraseOnACitedPageIsReported(): void {
    $row = self::row(['forbidden' => [], 'must_not_say' => ['480 PRACTICE']]);
    $left_out = [['id' => 'pot', 'text' => 'The pot balance is 480 PRACTICE.']];
    $passages = self::passages([['uuid' => self::CITED, 'text' => 'The pot holds 480 PRACTICE today.']]);
    $this->assertStringContainsString('allowed', $this->problems([$row], $passages, $left_out));
  }

  /**
   * An empty phrase is reported; so is a phrase in only an unrelated page.
   */
  public function testEmptyPhraseAndUnrelatedPageAreReported(): void {
    $this->assertStringContainsString('empty', $this->problems([self::row(['must_not_say' => ['  ']])], self::passages()));
    $passages = self::passages([['uuid' => self::OTHER, 'text' => 'The brass lantern is also in the vase.']]);
    $row = self::row(['forbidden' => [], 'must_not_say' => ['brass lantern']]);
    $this->assertStringContainsString('no forbidden page', $this->problems([$row], $passages));
  }

  /**
   * The packs as written keep both rules.
   *
   * @dataProvider packProvider
   */
  public function testPacksKeepTheRules(string $pack): void {
    $this->assertSame([], PackProvenanceChecks::mustNotSayProblems(
      $pack,
      self::expectation($pack, 'assistant.yml'),
      self::expectation($pack, 'passages.yml'),
      self::expectation($pack, 'expect.yml')['left_out'] ?? [],
      PackProvenanceChecks::pageTexts(self::packYaml($pack) + ['howto.yml' => self::sharedHowto()])
    ));
  }

}
