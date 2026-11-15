<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\PackCopyChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * Pack copy is the source's copy, word for word.
 *
 * Each pack keeps a byte copy of its source file under
 * tests/fixtures/expect/<pack>/source.md. Every prose string the pack loads
 * must be in that source, and so must every passage its assistant rows
 * quote, so nothing is paraphrased. The check folds markup and typography
 * only (PackText), never words.
 *
 * @group localnodes_demo_packs
 */
final class PackCopyTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * A pack without its expectation files cannot land.
   *
   * @dataProvider packProvider
   */
  public function testEveryPackHasExpectations(string $pack): void {
    $directory = self::moduleDir() . "/tests/fixtures/expect/$pack";
    foreach (['source.md', 'passages.yml', 'expect.yml', 'assistant.yml'] as $file) {
      $this->assertFileExists("$directory/$file", "Pack $pack has its $file.");
    }
    $this->assertNotSame('', trim(self::expectation($pack, 'source.md')), "Pack $pack's source.md is not empty.");
    $this->assertNotEmpty(self::expectation($pack, 'passages.yml'), "Pack $pack states at least one passage.");
    $this->assertNotEmpty(self::expectation($pack, 'expect.yml'), "Pack $pack's expect.yml is not empty.");
    $this->assertNotEmpty(self::expectation($pack, 'assistant.yml'), "Pack $pack states at least one assistant row.");
  }

  /**
   * Every prose string in the pack's YAML is in the source word for word.
   *
   * @dataProvider packProvider
   */
  public function testPackProseIsCopiedFromTheSource(string $pack): void {
    $source = PackText::markdownBlocks((string) self::expectation($pack, 'source.md'));
    $result = PackChecks::proseProblems($pack, self::packYaml($pack), $source);
    $this->assertSame([], $result['problems']);
    $this->assertGreaterThan(0, $result['checked'], "Pack $pack has prose to check.");
  }

  /**
   * An enrollment's title is its member's name and its event's title.
   *
   * @dataProvider packProvider
   */
  public function testEnrollmentTitlesAreComposed(string $pack): void {
    $this->assertSame([], PackChecks::enrollmentProblems(self::packYaml($pack)));
  }

  /**
   * Every entry's key is its uuid, and no uuid is used twice in a pack.
   *
   * The how-to pages that every pack site loads are part of the pack.
   *
   * @dataProvider packProvider
   */
  public function testEntryKeysAreTheirUuids(string $pack): void {
    $this->assertSame([], PackChecks::entryProblems(self::packYaml($pack) + ['howto.yml' => self::sharedHowto()]));
  }

  /**
   * Every passage is in the source word for word, near its stated line.
   *
   * @dataProvider packProvider
   */
  public function testPassagesAreInTheSource(string $pack): void {
    $passages = self::expectation($pack, 'passages.yml');
    $this->assertNotEmpty($passages, "Pack $pack states passages.");
    $this->assertSame([], PackCopyChecks::passageProblems($pack, $passages, (string) self::expectation($pack, 'source.md')));
  }

  /**
   * What a pack leaves out of its YAML is in its source, and not in the YAML.
   *
   * expect.yml's left_out rows name sentences the source states that the pack
   * must not load. Each is in the source word for word, and none is in the
   * pack's content.
   *
   * @dataProvider packProvider
   */
  public function testLeftOutPhrasesAreInTheSource(string $pack): void {
    $rows = self::expectation($pack, 'expect.yml')['left_out'] ?? [];
    $this->assertNotEmpty($rows, "Pack $pack names what it leaves out of the source.");
    $content = '';
    foreach (self::packYaml($pack) as $entries) {
      foreach (self::entries($entries) as [, $entry]) {
        $content .= ' ' . PackChecks::textOfEntry($entry);
      }
    }
    $content = mb_strtolower(PackText::normalize($content));
    $this->assertSame([], PackCopyChecks::leftOutProblems($pack, $rows, (string) self::expectation($pack, 'source.md'), $content));
  }

  /**
   * Every assistant row asks as a pack member and points at pack content.
   *
   * The member, the group, and every page a row cites, allows or forbids are
   * uuids of the pack or of the shared guide pages, and every phrase a reply
   * must say is in the source, so a row cannot pass on words the pack does not
   * hold.
   *
   * @dataProvider packProvider
   */
  public function testAssistantRowsAreGrounded(string $pack): void {
    $rows = self::expectation($pack, 'assistant.yml');
    $this->assertNotEmpty($rows, "Pack $pack states assistant rows.");
    $uuids = [];
    foreach (self::packYaml($pack) + ['howto.yml' => self::sharedHowto()] as $file => $entries) {
      foreach (self::entries($entries) as [$uuid]) {
        $uuids[$file][] = $uuid;
      }
    }
    $source = PackText::markdownBlocks((string) self::expectation($pack, 'source.md'));
    $seen = [];
    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '');
      $this->assertNotSame('', $id, "Pack $pack: an assistant row has an id.");
      $this->assertNotContains($id, $seen, "Pack $pack: assistant row id $id is used once.");
      $seen[] = $id;
      $this->assertNotSame('', trim((string) ($row['question'] ?? '')), "Pack $pack, row $id asks a question.");
      $this->assertContains((string) ($row['as'] ?? ''), $uuids['user.yml'] ?? [], "Pack $pack, row $id asks as a pack user.");
      if (isset($row['group'])) {
        $this->assertContains((string) $row['group'], $uuids['group.yml'] ?? [], "Pack $pack, row $id asks in a pack group.");
      }
      $everything = array_merge(...array_values($uuids));
      foreach (['cites', 'may_cite', 'forbidden'] as $key) {
        foreach ($row[$key] ?? [] as $uuid) {
          $this->assertContains((string) $uuid, $everything, "Pack $pack, row $id: $key names a pack uuid ($uuid).");
        }
      }
      if (!empty($row['refusal'])) {
        $this->assertNotSame('', trim((string) ($row['expected_words'] ?? '')), "Pack $pack, refusal row $id states its expected words.");
        $this->assertEmpty($row['cites'] ?? [], "Pack $pack, refusal row $id cites nothing.");
      }
      else {
        $this->assertNotEmpty($row['cites'] ?? [], "Pack $pack, row $id cites a page.");
        $this->assertNotEmpty($row['must_say'] ?? [], "Pack $pack, row $id says a phrase.");
      }
    }
    $this->assertSame([], PackCopyChecks::mustSayProblems($pack, $rows, $source));
  }

  /**
   * Part 1 of the how-to source, as pages: heading id and title, then text.
   *
   * @return array<string, array{title: string, text: string}>
   *   Keyed by P01 to P10, in source order; the text is the normalized plain
   *   text of the section under the heading.
   */
  private static function howtoSections(): array {
    $sections = [];
    $current = NULL;
    foreach (preg_split('/\R/', self::howtoSource()) ?: [] as $line) {
      if (preg_match('/^### (P\d\d) \x{2014} (.+)$/u', $line, $match)) {
        $current = $match[1];
        $sections[$current] = ['title' => trim($match[2]), 'lines' => []];
        continue;
      }
      if ($current !== NULL) {
        $sections[$current]['lines'][] = $line;
      }
    }
    foreach ($sections as $id => $section) {
      $sections[$id] = [
        'title' => $section['title'],
        'text' => PackText::normalizeMarkdown(implode("\n", $section['lines'])),
      ];
    }
    return $sections;
  }

  /**
   * The ten how-to pages are Part 1 of the source, word for word.
   *
   * Each page's title is a Part 1 heading, and its body, read as plain text,
   * is the text under that heading. The check reads only Part 1 (up to the
   * P11 heading), so a sentence that sits elsewhere in the source does not
   * count. The page count comes from expect/howto/expect.yml.
   */
  public function testHowtoPagesAreCopiedFromPartOne(): void {
    $sections = self::howtoSections();
    $this->assertCount(10, $sections, 'Part 1 of the source holds P01 to P10.');
    $by_title = [];
    foreach ($sections as $id => $section) {
      $by_title[$section['title']] = $id;
    }
    $pages = self::entries(self::sharedHowto());
    $expected = (int) (self::expectation('howto', 'expect.yml')['counts']['howto'] ?? -1);
    $this->assertSame($expected, count($pages), 'The shared how-to file holds the expected number of pages.');
    $part_one = PackText::markdownBlocks(self::howtoSource());
    foreach ($pages as [$uuid, $page]) {
      $title = PackText::normalize((string) ($page['title'] ?? ''));
      $this->assertTrue(isset($by_title[$title]), "How-to page $uuid: its title is not a Part 1 heading.");
      $blocks = PackText::blocks(PackText::untoken((string) ($page['body'] ?? '')));
      $this->assertNotSame([], $blocks, "How-to page $uuid has a body.");
      $this->assertSame([], PackCopyChecks::howtoBlockProblems($uuid, $blocks, $part_one));
      $this->assertTrue(
        $sections[$by_title[$title]]['text'] === PackText::normalize(implode(' ', $blocks)),
        "How-to page $uuid is not the whole of its Part 1 section and nothing else."
      );
    }
  }

  /**
   * The titles are those of P01 to P10, once each, in order.
   */
  public function testHowtoTitlesAreP01ToP10(): void {
    $sections = self::howtoSections();
    $expected = array_column($sections, 'title');
    $this->assertSame(
      ['P01', 'P02', 'P03', 'P04', 'P05', 'P06', 'P07', 'P08', 'P09', 'P10'],
      array_keys($sections),
      'The source holds P01 to P10 in Part 1, in order.'
    );
    $titles = [];
    foreach (self::entries(self::sharedHowto()) as [, $page]) {
      $titles[] = PackText::normalize((string) ($page['title'] ?? ''));
    }
    $this->assertSame($expected, $titles, 'The how-to titles are the P01 to P10 headings, in order.');
    foreach (['Respond to a decision', 'See what the fund holds'] as $withheld) {
      $this->assertNotContains($withheld, $titles, "$withheld stays withheld.");
    }
  }

  /**
   * Text the source holds beyond P01 to P10 is not in the how-to file.
   *
   * Each phrase is first found in the full source, so the check cannot pass
   * because the source changed, then searched for in every string of the
   * how-to file. The left_out rows of expect/howto/expect.yml are held to
   * the same rule.
   */
  public function testWithheldTextIsAbsent(): void {
    $source = PackText::markdownBlocks((string) self::expectation('howto', 'source.md'));
    $phrases = [
      'Respond to a decision',
      'See what the fund holds',
      'First-visit tour words',
      'Required cited page',
      "Builder's label table",
    ];
    $rows = self::expectation('howto', 'expect.yml')['left_out'] ?? [];
    $this->assertNotEmpty($rows, 'The how-to expectations name what they leave out.');
    foreach ($rows as $row) {
      $phrases[] = (string) ($row['text'] ?? '');
    }
    $content = '';
    foreach (self::entries(self::sharedHowto()) as [, $page]) {
      $content .= ' ' . PackChecks::textOfEntry($page);
    }
    $content = mb_strtolower(PackText::normalize($content));
    $this->assertNotSame('', trim($content), 'The how-to file has text to search.');
    $this->assertSame([], PackCopyChecks::withheldProblems($phrases, $source, $content));
  }

  /**
   * Every how-to passage is in Part 1, not only somewhere in the source.
   */
  public function testHowtoPassagesAreInPartOne(): void {
    $part_one = PackText::markdownBlocks(self::howtoSource());
    $passages = self::expectation('howto', 'passages.yml');
    $this->assertNotEmpty($passages, 'The how-to expectations state passages.');
    foreach ($passages as $passage) {
      $id = (string) ($passage['id'] ?? '?');
      $text = PackText::normalize(PackText::untoken((string) ($passage['text'] ?? '')));
      $this->assertNotSame('', $text);
      $this->assertTrue(PackText::inBlocks($text, $part_one), "How-to passage $id is not in Part 1 word for word, inside one block.");
    }
    $this->assertCount((int) (self::expectation('howto', 'expect.yml')['counts']['howto'] ?? -1), $passages, 'There is one passage for each page.');
  }

}
