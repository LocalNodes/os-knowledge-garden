<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackAnswerCheck;
use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\PackProvenanceChecks;
use Drupal\Tests\localnodes_demo_packs\PackWalkChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * The rules a pack's data keeps: what its source says is accounted for.
 *
 * Reads the pack's YAML and its expectation files; no Drupal container.
 *
 * @group localnodes_demo_packs
 */
final class PackDataTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * The `## ` headings of a source, by exact text.
   *
   * @return string[]
   */
  private static function sectionHeadings(string $source): array {
    $headings = [];
    foreach (preg_split('/\R/', $source) ?: [] as $line) {
      if (str_starts_with($line, '## ')) {
        $headings[] = trim(substr($line, 3));
      }
    }
    return $headings;
  }

  /**
   * What a pack's expect.yml says of each section, or NULL for none or bad.
   *
   * @return string|null
   *   loaded, test_only, left_out or pending.
   */
  private static function sectionState(mixed $state): ?string {
    if (is_string($state)) {
      return in_array($state, ['loaded', 'test_only'], TRUE) ? $state : NULL;
    }
    if (is_array($state) && count($state) === 1) {
      $kind = (string) array_key_first($state);
      $owner = $state[$kind];
      if (in_array($kind, ['left_out', 'pending'], TRUE) && is_string($owner) && trim($owner) !== '') {
        return $kind;
      }
    }
    return NULL;
  }

  /**
   * Every section of the source is classified, and every classified one exists.
   *
   * @dataProvider packProvider
   */
  public function testEverySectionIsClassified(string $pack): void {
    $headings = self::sectionHeadings((string) self::expectation($pack, 'source.md'));
    $this->assertNotEmpty($headings, "Pack $pack's source has sections.");
    $sections = self::expectation($pack, 'expect.yml')['sections'] ?? [];
    $this->assertIsArray($sections);
    foreach ($headings as $heading) {
      $this->assertArrayHasKey($heading, $sections, "Pack $pack: the source heading '$heading' is not classified; classify it in expect.yml sections: loaded, test_only, left_out or pending.");
      $this->assertNotNull(self::sectionState($sections[$heading]), "Pack $pack: the section '$heading' is classified as something else; use loaded, test_only, left_out: <owner> or pending: <dependency>.");
    }
    foreach (array_keys($sections) as $name) {
      $this->assertContains((string) $name, $headings, "Pack $pack: expect.yml sections names '$name', which is not a ## heading of source.md.");
    }
  }

  /**
   * The sections of a source, by heading: the text under each `## ` heading.
   *
   * Text before the first heading is under the empty name and counts as
   * loaded.
   *
   * @return array<string, string>
   */
  private static function sectionTexts(string $source): array {
    $sections = ['' => ''];
    $name = '';
    foreach (preg_split('/\R/', $source) ?: [] as $line) {
      if (str_starts_with($line, '## ')) {
        $name = trim(substr($line, 3));
        $sections[$name] = '';
        continue;
      }
      $sections[$name] .= $line . "\n";
    }
    return $sections;
  }

  /**
   * The normalized text of a passage, day tokens read as "day +N".
   */
  private static function passageText(array $passage): string {
    return mb_strtolower(PackText::normalize(PackText::untoken((string) ($passage['text'] ?? ''))));
  }

  /**
   * What a pack leaves out is stated only in sections it leaves out or defers.
   *
   * @dataProvider packProvider
   */
  public function testLeftOutPhrasesComeOnlyFromLeftOutSections(string $pack): void {
    $expect = self::expectation($pack, 'expect.yml');
    $sections = $expect['sections'] ?? [];
    $texts = self::sectionTexts((string) self::expectation($pack, 'source.md'));
    foreach ($expect['left_out'] ?? [] as $row) {
      $id = (string) ($row['id'] ?? '?');
      $phrase = mb_strtolower(PackText::normalize((string) ($row['text'] ?? '')));
      $this->assertNotSame('', $phrase, "Pack $pack, left-out row $id has text.");
      $found = [];
      foreach ($texts as $name => $text) {
        if (PackText::inBlocks($phrase, PackText::markdownBlocks($text), TRUE)) {
          $found[] = $name;
        }
      }
      $this->assertNotSame([], $found, "Pack $pack, left-out row $id is in no section of the source.");
      foreach ($found as $name) {
        $state = $name === '' ? 'loaded' : self::sectionState($sections[$name] ?? NULL);
        $this->assertContains($state, ['left_out', 'pending'], "Pack $pack, left-out row $id is stated in section '$name', which the pack does not leave out; classify the section left_out or pending.");
      }
    }
  }

  /**
   * A phrase a reply must not say comes from a forbidden page or a left-out
   * sentence, and is on no page the row allows or cites.
   *
   * The two rules (and the left-out sentence rule, which the first one
   * accepts) are PackProvenanceChecks'; PackProvenanceTest shows each fail.
   *
   * @dataProvider packProvider
   */
  public function testMustNotSayComesOnlyFromForbiddenPages(string $pack): void {
    $this->assertSame([], PackProvenanceChecks::mustNotSayProblems(
      $pack,
      self::expectation($pack, 'assistant.yml'),
      self::expectation($pack, 'passages.yml'),
      self::expectation($pack, 'expect.yml')['left_out'] ?? [],
      PackProvenanceChecks::pageTexts(self::packYaml($pack) + self::siteGuideFiles())
    ));
  }

  /**
   * A phrase a reply must say is in a passage of a page the row cites.
   *
   * @dataProvider packProvider
   */
  public function testMustSayIsInTheCitedPassage(string $pack): void {
    // A pack site also shows the Site guide pages, so a row that cites one is
    // grounded by the how-to passages too; those name guide pages only.
    $passages = [];
    foreach (array_merge(self::expectation($pack, 'passages.yml'), self::expectation('howto', 'passages.yml')) as $passage) {
      $passages[(string) ($passage['uuid'] ?? '')][] = self::passageText($passage);
    }
    foreach (self::expectation($pack, 'assistant.yml') as $row) {
      $id = (string) ($row['id'] ?? '?');
      foreach ($row['must_say'] ?? [] as $phrase) {
        $phrase = mb_strtolower(PackText::normalize(PackText::untoken(PackAnswerCheck::sourceWording($phrase))));
        $held = FALSE;
        foreach ($row['cites'] ?? [] as $uuid) {
          foreach ($passages[(string) $uuid] ?? [] as $text) {
            $held = $held || str_contains($text, $phrase);
          }
        }
        $this->assertTrue($held, "Pack $pack, row $id: a must_say phrase is in no passage of a cited page.");
      }
    }
  }

  /**
   * Likes are counted as stated; nobody likes their own post; likers belong.
   *
   * The rule itself is PackChecks::likeProblems, shown each fault in
   * PackWalkChecksTest.
   *
   * @dataProvider packProvider
   */
  public function testLikesFollowTheRules(string $pack): void {
    $stated = self::expectation($pack, 'expect.yml')['likes'] ?? [];
    $this->assertSame([], PackChecks::likeProblems($pack, self::packYaml($pack), $stated));
  }

  /**
   * Each poll's answers add up to the stated votes of each choice.
   *
   * @dataProvider packProvider
   */
  public function testPollVotesMatch(string $pack): void {
    $stated = self::expectation($pack, 'expect.yml')['poll_votes'] ?? [];
    $polls = PackChecks::entries(self::packYaml($pack)['quick-poll.yml'] ?? []);
    foreach ($polls as [$uuid, $poll]) {
      $votes = array_fill(0, count($poll['choices'] ?? []), 0);
      foreach ($poll['answers'] ?? [] as $answer) {
        $choice = (int) ($answer['choice'] ?? -1);
        $this->assertArrayHasKey($choice, $votes, "Pack $pack, poll $uuid: an answer names a choice the poll lacks.");
        $votes[$choice]++;
      }
      $this->assertArrayHasKey($uuid, $stated, "Pack $pack, poll $uuid: expect.yml poll_votes states its votes.");
      $this->assertSame($votes, array_map('intval', $stated[$uuid]), "Pack $pack, poll $uuid: the answers are not the stated votes of each choice.");
    }
    $this->assertCount(count($polls), $stated, "Pack $pack: expect.yml poll_votes names only the pack's polls.");
  }

  /**
   * Confirmed enrolments are counted as stated; each enrolled member is in the group.
   *
   * @dataProvider packProvider
   */
  public function testEnrolmentsMatch(string $pack): void {
    $stated = self::expectation($pack, 'expect.yml')['going'] ?? [];
    $this->assertSame([], PackWalkChecks::enrolmentProblems($pack, self::packYaml($pack), $stated));
  }

  /**
   * A source copy that was written with lines replaced is intact.
   *
   * A pack whose walk.yml has `redactions` keeps a source.md that differs from
   * its working source in those lines only. PHPUnit never reads the working
   * files (scripts/check_pack_source.php does, on a development site), so this test
   * checks what is inside the repository: the recorded hashes are sha256
   * values, the copy has the recorded number of lines, each redacted line is
   * its recorded replacement, and no line of the copy carries a year, a local
   * path, a planning name, a web address, a phase number or an encyclopedia.
   * A message names a line number, never the text.
   *
   * @dataProvider packProvider
   */
  public function testRedactedSourceCopyIsIntact(string $pack): void {
    $walk = self::walk($pack);
    if (!array_key_exists('redactions', $walk)) {
      $this->addToAssertionCount(1);
      return;
    }
    foreach (['source_sha256', 'walk_section_sha256'] as $key) {
      $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($walk[$key] ?? ''), "Pack $pack: walk.yml $key is a sha256.");
    }
    $this->assertIsArray($walk['redactions'], "Pack $pack: redactions is a map of line number to replacement.");
    $lines = explode("\n", (string) self::expectation($pack, 'source.md'));
    if (end($lines) === '') {
      array_pop($lines);
    }
    $this->assertSame((int) ($walk['source_lines'] ?? 0), count($lines), "Pack $pack: source.md has the recorded number of lines.");
    $wrong = [];
    foreach ($walk['redactions'] as $number => $replacement) {
      if (($lines[(int) $number - 1] ?? NULL) !== (string) $replacement) {
        $wrong[] = (int) $number;
      }
    }
    $this->assertSame([], $wrong, "Pack $pack: these lines of source.md are not their recorded replacement.");
    $banned = [];
    foreach ($lines as $index => $line) {
      if (preg_match('/(?<![$\d,.])(?:19|20)\d{2}(?![\d,])|\/home\/|\.planning|http|Phase |Wikipedia/u', $line) === 1) {
        $banned[] = $index + 1;
      }
    }
    $this->assertSame([], $banned, "Pack $pack: these lines of source.md carry a year, a local path, a planning name, a web address, a phase number or an encyclopedia.");
  }

  /**
   * A walk names its witness and owner, and states each step completely.
   *
   * @dataProvider packProvider
   */
  public function testWalkIsComplete(string $pack): void {
    $walk = self::walk($pack);
    $this->assertNotEmpty($walk['steps'] ?? [], "Pack $pack: the walk has steps.");
    $rows = [];
    foreach (self::expectation($pack, 'assistant.yml') as $row) {
      $rows[(string) ($row['id'] ?? '')] = $row;
    }
    $this->assertSame([], PackWalkChecks::walkProblems($pack, $walk, $rows));
  }

  /**
   * No pack file holds a chain address: a demo fund's comes from the site.
   */
  public function testNoChainAddressInPacks(): void {
    $found = [];
    foreach (['packs', 'tests/fixtures'] as $directory) {
      $root = self::moduleDir() . '/' . $directory;
      if (!is_dir($root)) {
        continue;
      }
      $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
      foreach ($files as $file) {
        if ($file->isFile() && preg_match('/0x[0-9a-fA-F]{40}/', (string) file_get_contents($file->getPathname())) === 1) {
          $found[] = substr($file->getPathname(), strlen(self::moduleDir()) + 1);
        }
      }
    }
    $this->assertSame([], $found, 'A chain address is in these files.');
  }

  /**
   * The image leaves out the source copies, which hold members-only text.
   */
  public function testSourceCopiesStayOutOfTheImage(): void {
    $path = dirname(self::moduleDir(), 4) . '/.dockerignore';
    $this->assertFileExists($path);
    $lines = array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []);
    $this->assertContains('html/modules/custom/localnodes_demo_packs/tests/fixtures/expect/*/source.md', $lines, 'The .dockerignore leaves out the pack source copies.');
  }

}
