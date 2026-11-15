<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackAnswerCheck;
use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * The assistant rows for the how-to pages ask what the pages answer.
 *
 * tests/fixtures/expect/howto/assistant.yml is run on a site by
 * scripts/check_demo_pack.php. Here only the rows themselves are proved: each
 * question row cites a real how-to page and asks for labels that page holds in
 * its body, and each boundary row forbids all ten pages.
 *
 * @group localnodes_demo_packs
 */
final class PackHowtoAssistantRowsTest extends UnitTestCase {

  use PackTestTrait;

  private const MEMBER = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d02';

  /**
   * The how-to pages by uuid: [uuid => normalized body text].
   *
   * @return array<string, string>
   *   The bodies in the order of the how-to file.
   */
  private static function pages(): array {
    $pages = [];
    foreach (self::entries(self::sharedHowto()) as [$uuid, $data]) {
      $pages[$uuid] = PackText::normalize(implode(' ', PackText::blocks((string) $data['body'])));
    }
    return $pages;
  }

  /**
   * The rows of the how-to assistant file.
   *
   * @return array<int, array>
   *   The rows.
   */
  private static function rows(): array {
    $rows = self::expectation('howto', 'assistant.yml');
    return is_array($rows) ? array_values($rows) : [];
  }

  /**
   * Six question rows and two boundary rows, each run as the member site-wide.
   */
  public function testRowsHaveTheirShape(): void {
    $rows = self::rows();
    $this->assertCount(8, $rows, 'Six question rows and two boundary rows.');
    $ids = [];
    $refusals = 0;
    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '');
      $this->assertNotSame('', $id, 'A row has an id.');
      $this->assertNotContains($id, $ids, "Row id $id is used once.");
      $ids[] = $id;
      $this->assertSame(self::MEMBER, $row['as'] ?? NULL, "Row $id runs as the member of the smoke pack.");
      $this->assertSame('', $row['group'] ?? NULL, "Row $id asks site-wide: its group is empty.");
      $this->assertNotSame('', trim((string) ($row['question'] ?? '')), "Row $id has a question.");
      $this->assertSame([], $row['needs'] ?? NULL, "Row $id needs nothing beyond the pages.");
      if (!empty($row['refusal'])) {
        $refusals++;
      }
    }
    $this->assertSame(2, $refusals, 'Two boundary rows.');
  }

  /**
   * Each question row cites a how-to page and names labels that page holds.
   */
  public function testQuestionRowsCiteAPageThatHoldsTheirLabels(): void {
    $pages = self::pages();
    $this->assertCount(10, $pages, 'The how-to file holds P01 to P10.');
    $checked = 0;
    foreach (self::rows() as $row) {
      if (!empty($row['refusal'])) {
        continue;
      }
      $id = (string) $row['id'];
      $this->assertCount(1, $row['cites'] ?? [], "Row $id cites one page.");
      $uuid = (string) $row['cites'][0];
      $this->assertArrayHasKey($uuid, $pages, "Row $id cites one of the ten how-to pages.");
      $this->assertNotEmpty($row['must_say'] ?? [], "Row $id says at least one label.");
      foreach ($row['must_say'] as $label) {
        $this->assertStringContainsString(PackText::normalize((string) $label), $pages[$uuid], "Row $id: the page it cites holds $label.");
      }
      $checked++;
    }
    $this->assertSame(6, $checked, 'Six question rows.');
  }

  /**
   * Each boundary row forbids all ten how-to pages and may cite none.
   */
  public function testBoundaryRowsForbidAllTenPages(): void {
    $all = array_keys(self::pages());
    sort($all);
    $seen = 0;
    foreach (self::rows() as $row) {
      if (empty($row['refusal'])) {
        continue;
      }
      $id = (string) $row['id'];
      $forbidden = array_map('strval', $row['forbidden'] ?? []);
      sort($forbidden);
      $this->assertSame($all, $forbidden, "Row $id forbids every how-to page.");
      $this->assertSame([], $row['may_cite'] ?? NULL, "Row $id may cite no page.");
      $this->assertNotSame('', trim((string) ($row['expected_words'] ?? '')), "Row $id records the sentence the page asks for.");
      $seen++;
    }
    $this->assertSame(2, $seen, 'Two boundary rows.');
  }

  /**
   * The question rows, keyed by id.
   *
   * @return array<string, array>
   *   The rows that are not boundary rows.
   */
  private static function questionRows(): array {
    $rows = [];
    foreach (self::rows() as $row) {
      if (empty($row['refusal'])) {
        $rows[(string) $row['id']] = $row;
      }
    }
    return $rows;
  }

  /**
   * Every question row pins more than control names.
   *
   * At least one must_say phrase of each row is not a control name from the
   * label rows: it is the page's substance (an approval step, who sees a
   * post, a confirmation), so an answer that only lists buttons fails.
   */
  public function testEveryQuestionRowSaysMoreThanControlNames(): void {
    $labels = [];
    foreach ((array) self::expectation('howto', 'labels.yml') as $label_row) {
      $labels[] = PackText::normalize((string) $label_row['label']);
    }
    foreach (self::questionRows() as $id => $row) {
      $other = array_filter(
        array_map('strval', $row['must_say'] ?? []),
        static fn (string $phrase): bool => !in_array(PackText::normalize($phrase), $labels, TRUE)
      );
      $this->assertNotEmpty($other, "Row $id says something besides control names.");
      $this->assertLessThanOrEqual(5, count($row['must_say']), "Row $id keeps its phrase list short.");
    }
  }

  /**
   * A reply with every phrase passes; one forbidden phrase added fails it.
   */
  public function testTheJudgeFailsAReplyThatAlsoMakesAForbiddenPromise(): void {
    $checked = 0;
    foreach (self::questionRows() as $id => $row) {
      $cited = [['uuid' => (string) $row['cites'][0], 'title' => 'Page']];
      $reply = 'Here is how. ' . implode('. ', $row['must_say']) . '. [1]';
      $clean = PackAnswerCheck::judge($reply, $cited, $row);
      $this->assertTrue($clean['pass'], "Row $id: a reply with every phrase passes: " . implode('; ', $clean['reasons']));
      foreach (array_values($row['must_not_say'] ?? []) as $index => $phrase) {
        $verdict = PackAnswerCheck::judge($reply . ' It is ' . $phrase . '.', $cited, $row);
        $this->assertFalse($verdict['pass'], "Row $id: a reply with forbidden phrase #$index fails.");
        $this->assertContains("must_not_say[$index]", $verdict['reasons']);
        $this->assertStringNotContainsString((string) $phrase, implode(' ', $verdict['reasons']), 'A reason does not print the phrase.');
        $checked++;
      }
    }
    $this->assertGreaterThan(0, $checked, 'At least one row forbids a promise.');
  }

  /**
   * The page rules out what a row forbids, so its own page never says it.
   */
  public function testNoForbiddenPhraseIsInThePageItsRowCites(): void {
    $pages = self::pages();
    foreach (self::questionRows() as $id => $row) {
      foreach ($row['must_not_say'] ?? [] as $phrase) {
        $this->assertNotSame('', trim((string) $phrase), "Row $id: a forbidden phrase is not blank.");
        $this->assertStringNotContainsStringIgnoringCase((string) $phrase, $pages[(string) $row['cites'][0]], "Row $id: the page it cites does not say a forbidden phrase.");
      }
    }
  }

  /**
   * Every question row rules out a claim its page denies and states a rule.
   */
  public function testEveryQuestionRowForbidsAClaimAndStatesARule(): void {
    foreach (self::questionRows() as $id => $row) {
      $this->assertNotEmpty($row['must_not_say'] ?? [], "Row $id rules out a claim its page denies.");
      $this->assertGreaterThanOrEqual(3, count($row['must_say'] ?? []), "Row $id says its control names and one rule.");
    }
  }

  /**
   * An answer that makes joining automatic fails the join row.
   *
   * The reply is made up: it has every must_say phrase and the right cite, so
   * only the claim of automatic approval can fail it.
   */
  public function testAnAnswerThatCallsApprovalAutomaticFailsTheJoinRow(): void {
    $row = self::questionRows()['howto-p01-join'] ?? [];
    $this->assertContains('organizer', array_map('strval', $row['must_say'] ?? []), 'The join row says an organizer approves.');
    $cited = [['uuid' => (string) $row['cites'][0], 'title' => 'Join a group']];
    $reply = 'Choose ' . implode(', ', $row['must_say']) . ' and you are in. Approval is automatic, so you can post at once. [1]';
    $verdict = PackAnswerCheck::judge($reply, $cited, $row);
    $this->assertFalse($verdict['pass'], 'A reply that calls approval automatic fails.');
    $this->assertNotEmpty(array_filter($verdict['reasons'], static fn (string $reason): bool => str_starts_with($reason, 'must_not_say[')), 'It fails on a must_not_say rule.');
    $honest = 'Choose ' . implode(', ', $row['must_say']) . '. [1]';
    $this->assertTrue(PackAnswerCheck::judge($honest, $cited, $row)['pass'], 'The same reply without the claim passes.');
  }

  /**
   * The assistant row for the sources page rules out a claim of certainty.
   */
  public function testTheSourcesRowForbidsAClaimOfCertainty(): void {
    $row = self::questionRows()['howto-p04-check-sources'] ?? [];
    $forbidden = array_map('strval', $row['must_not_say'] ?? []);
    foreach (['guarantee', 'always right', 'always correct'] as $phrase) {
      $this->assertContains($phrase, $forbidden, 'The sources row forbids a claim of certainty.');
    }
  }

}
