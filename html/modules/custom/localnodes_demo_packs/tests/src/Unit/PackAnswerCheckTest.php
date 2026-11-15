<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackAnswerCheck;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\Tests\UnitTestCase;

/**
 * The rules that judge one assistant answer against its expectation row.
 *
 * @group localnodes_demo_packs
 */
final class PackAnswerCheckTest extends UnitTestCase {

  private const TOPIC = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d20';

  private const OTHER = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d21';

  /**
   * The cited pages, as the script passes them.
   */
  private static function sources(string ...$uuids): array {
    return array_map(static fn (string $uuid): array => ['uuid' => $uuid, 'title' => "Page $uuid"], $uuids);
  }

  /**
   * A row that wants one page cited and one phrase said.
   */
  private static function row(array $more = []): array {
    return $more + [
      'id' => 'row',
      'cites' => [self::TOPIC],
      'must_say' => ['We meet again'],
    ];
  }

  /**
   * An answer that cites every expected page and says every phrase passes.
   */
  public function testCitedAnswerWithItsPhrasesPasses(): void {
    $verdict = PackAnswerCheck::judge('The group says we meet again soon. [1]', self::sources(self::TOPIC), self::row());
    $this->assertSame([], $verdict['reasons']);
    $this->assertTrue($verdict['pass']);
  }

  /**
   * Case and typography do not count as a difference.
   */
  public function testPhraseMatchFoldsCaseAndQuotes(): void {
    $row = self::row(['must_say' => ["the group's room"]]);
    $verdict = PackAnswerCheck::judge("We meet in  THE GROUP\u{2019}s\u{00A0}room. [1]", self::sources(self::TOPIC), $row);
    $this->assertTrue($verdict['pass'], implode('; ', $verdict['reasons']));
  }

  /**
   * An answer that leaves out a page the row expects fails.
   */
  public function testMissingCitedPageFails(): void {
    $verdict = PackAnswerCheck::judge('We meet again soon. [1]', self::sources(self::OTHER), self::row());
    $this->assertFalse($verdict['pass']);
    $this->assertContains('cite missing: ' . self::TOPIC, $verdict['reasons']);
  }

  /**
   * An answer without a phrase the row expects fails.
   */
  public function testMissingPhraseFails(): void {
    $verdict = PackAnswerCheck::judge('Soon. [1]', self::sources(self::TOPIC), self::row());
    $this->assertFalse($verdict['pass']);
    $this->assertContains('must_say[0] missing', $verdict['reasons']);
  }

  /**
   * A reason names the phrase by its place in the row, never by its text.
   *
   * The reasons end up in a log, and a phrase can be a key, a password or a
   * line the assistant must never print.
   */
  public function testReasonsNameAPhraseByIndexNotByText(): void {
    $row = self::row([
      'must_say' => ['first needed phrase', 'second needed phrase'],
      'must_not_say' => ['harmless one', 'sk-SECRET-KEY', 'one-time link'],
    ]);
    $verdict = PackAnswerCheck::judge('The answer holds sk-secret-key and a one-time link. [1]', self::sources(self::TOPIC), $row);
    $this->assertSame(['must_not_say[1]', 'must_not_say[2]', 'must_say[0] missing', 'must_say[1] missing'], $verdict['reasons']);
    $printed = implode(' ', $verdict['reasons']);
    foreach (array_merge($row['must_say'], $row['must_not_say']) as $phrase) {
      $this->assertStringNotContainsString($phrase, $printed);
    }
  }

  /**
   * Citing a forbidden page fails, even when every expected page is cited.
   */
  public function testForbiddenPageFails(): void {
    $row = self::row(['forbidden' => [self::OTHER]]);
    $verdict = PackAnswerCheck::judge('We meet again. [1] [2]', self::sources(self::TOPIC, self::OTHER), $row);
    $this->assertFalse($verdict['pass']);
    $this->assertContains('cites a forbidden page: ' . self::OTHER, $verdict['reasons']);
  }

  /**
   * A phrase the row forbids fails, whether or not the rest is right.
   */
  public function testMustNotSayFails(): void {
    $row = self::row(['must_not_say' => ['480 PRACTICE']]);
    $verdict = PackAnswerCheck::judge('We meet again. The pot holds 480 practice. [1]', self::sources(self::TOPIC), $row);
    $this->assertFalse($verdict['pass']);
    $this->assertContains('must_not_say[0]', $verdict['reasons']);
  }

  /**
   * A refusal row passes on each fixed no-answer reply that cites nothing.
   *
   * @dataProvider noAnswerProvider
   */
  public function testRefusalPassesOnANoAnswerReply(string $reply): void {
    $verdict = PackAnswerCheck::judge($reply, [], ['id' => 'refuse', 'refusal' => TRUE]);
    $this->assertTrue($verdict['pass'], implode('; ', $verdict['reasons']));
  }

  /**
   * The four replies that say nothing was found.
   */
  public static function noAnswerProvider(): array {
    return [
      'group' => [CitedAnswer::NO_ANSWER_GROUP],
      'site' => [CitedAnswer::NO_ANSWER_SITE],
      'sourced group' => [CitedAnswer::NO_SOURCED_ANSWER_GROUP],
      'sourced site' => [CitedAnswer::NO_SOURCED_ANSWER_SITE],
    ];
  }

  /**
   * A refusal row fails on a confident answer.
   */
  public function testRefusalFailsOnAnAnswer(): void {
    $verdict = PackAnswerCheck::judge('The spare key is in the blue box. [1]', self::sources(self::TOPIC), ['id' => 'refuse', 'refusal' => TRUE]);
    $this->assertFalse($verdict['pass']);
    $this->assertContains('is not a no-answer reply', $verdict['reasons']);
  }

  /**
   * A refusal row may cite only the pages it lists in may_cite.
   */
  public function testRefusalCitesOnlyAllowedPages(): void {
    $reply = CitedAnswer::NO_SOURCED_ANSWER_GROUP;
    $row = ['id' => 'refuse', 'refusal' => TRUE, 'may_cite' => [self::TOPIC]];
    $this->assertTrue(PackAnswerCheck::judge($reply, self::sources(self::TOPIC), $row)['pass']);
    $verdict = PackAnswerCheck::judge($reply, self::sources(self::OTHER), $row);
    $this->assertFalse($verdict['pass']);
    $this->assertContains('cites a page a refusal may not cite: ' . self::OTHER, $verdict['reasons']);
  }

  /**
   * A reply that is only the assistant's trouble message is not an answer.
   *
   * It fails any row, a refusal row included, with the reason infrastructure.
   *
   * @dataProvider troubleProvider
   */
  public function testTroubleReplyIsInfrastructure(string $reply): void {
    foreach ([self::row(), ['id' => 'refuse', 'refusal' => TRUE]] as $row) {
      $verdict = PackAnswerCheck::judge($reply, [], $row);
      $this->assertFalse($verdict['pass']);
      $this->assertSame(['infrastructure'], $verdict['reasons']);
    }
  }

  /**
   * The three replies that mean the assistant did not run.
   */
  public static function troubleProvider(): array {
    return [
      'search trouble' => [CitedAnswer::SEARCH_TROUBLE],
      'unavailable' => [CitedAnswer::UNAVAILABLE],
      'fallback' => [CitedAnswer::FALLBACK],
    ];
  }

  /**
   * A row that expects an answer fails when the assistant refuses.
   */
  public function testRefusingAnAnswerableQuestionFails(): void {
    $verdict = PackAnswerCheck::judge(CitedAnswer::NO_ANSWER_GROUP, [], self::row());
    $this->assertFalse($verdict['pass']);
    $this->assertContains('refuses a question it should answer', $verdict['reasons']);
  }

  /**
   * An empty reply fails.
   */
  public function testEmptyReplyFails(): void {
    $this->assertFalse(PackAnswerCheck::judge('  ', [], self::row())['pass']);
  }

  /**
   * A phrase counts only when the answer body says it, not its Sources list.
   *
   * The Sources lines hold page titles. A title that happens to hold the
   * phrase must not make an answer that never says it pass.
   */
  public function testMustSayIsCheckedInTheBodyNotTheSourcesList(): void {
    $reply = "Nothing here.\n\n" . CitedAnswer::SOURCES_HEADING . "\n- [1] [We meet again](https://example.test/a)";
    $verdict = PackAnswerCheck::judge($reply, self::sources(self::TOPIC), self::row());
    $this->assertFalse($verdict['pass']);
    $this->assertContains('must_say[0] missing', $verdict['reasons']);
  }

  /**
   * The body above the Sources list still satisfies a phrase.
   */
  public function testMustSayIsFoundInTheBodyOfACitedReply(): void {
    $reply = "We meet again on Thursday. [1]\n\n" . CitedAnswer::SOURCES_HEADING . "\n- [1] [Page](https://example.test/a)";
    $verdict = PackAnswerCheck::judge($reply, self::sources(self::TOPIC), self::row());
    $this->assertSame([], $verdict['reasons']);
  }

  /**
   * The body is cut at the last Sources heading, the one the assistant adds.
   */
  public function testBodyIsCutAtTheLastSourcesHeading(): void {
    $heading = CitedAnswer::SOURCES_HEADING;
    $reply = "A note quoting\n$heading\nwe meet again.\n\n$heading\n- [1] [Page](https://example.test/a)";
    $this->assertStringContainsString('we meet again', PackAnswerCheck::body($reply));
    $this->assertStringNotContainsString('Page', PackAnswerCheck::body($reply));
    $this->assertSame('No sources here.', PackAnswerCheck::body('No sources here.'));
  }

  /**
   * The ten how-to page uuids a boundary row forbids, as the how-to rows hold.
   *
   * @return string[]
   *   The uuids.
   */
  private static function howtoPages(): array {
    return [
      '082c2aa7-75a3-4ee1-af7d-2b4fe8e7af08',
      'b8d8e848-e2a3-4caa-bbca-247fa7e07759',
      '41177da8-52b7-47f4-83c3-bff8d4fb3433',
      'f4d742fe-20f6-40ac-b989-6b361efdbf10',
      '83737855-1f79-4c2e-965f-7f8f8e7d350c',
      'ef2613d4-33e2-4712-bdf0-e7c58afbb42c',
      'bd9829b7-0e22-423b-ac3c-f4b754f70a63',
      '80647fee-c455-45bb-a191-32bfdf55fca2',
      '9e55d601-cbef-4c2e-946c-39503b37941b',
      'f42f8def-e88f-48e5-b194-737527cdeecc',
    ];
  }

  /**
   * A boundary row: no answer expected, none of the ten how-to pages cited.
   */
  private static function boundaryRow(): array {
    return [
      'id' => 'howto-boundary',
      'refusal' => TRUE,
      'may_cite' => [],
      'forbidden' => self::howtoPages(),
    ];
  }

  /**
   * A trouble reply is never taken for the refusal a boundary row wants.
   *
   * The product's own fallback, search-trouble and unavailable replies mean the
   * assistant did not run, so a boundary row answered with one fails as
   * infrastructure and cannot pass for the wrong reason.
   */
  public function testBoundaryRowRefusesATroubleReply(): void {
    foreach ([CitedAnswer::FALLBACK, CitedAnswer::SEARCH_TROUBLE, CitedAnswer::UNAVAILABLE] as $reply) {
      $verdict = PackAnswerCheck::judge($reply, [], self::boundaryRow());
      $this->assertFalse($verdict['pass']);
      $this->assertSame(['infrastructure'], $verdict['reasons']);
    }
  }

  /**
   * The site's no-answer reply with no source passes a boundary row.
   */
  public function testBoundaryRowPassesTheSiteNoAnswerReply(): void {
    $verdict = PackAnswerCheck::judge(CitedAnswer::NO_ANSWER_SITE, [], self::boundaryRow());
    $this->assertSame([], $verdict['reasons']);
    $this->assertTrue($verdict['pass']);
  }

  /**
   * A boundary row fails when the reply cites any of the ten how-to pages.
   */
  public function testBoundaryRowFailsOnAHowtoCitation(): void {
    foreach (self::howtoPages() as $uuid) {
      $verdict = PackAnswerCheck::judge(CitedAnswer::NO_ANSWER_SITE, self::sources($uuid), self::boundaryRow());
      $this->assertFalse($verdict['pass'], "A citation of $uuid fails the row.");
      $this->assertContains("cites a forbidden page: $uuid", $verdict['reasons']);
    }
  }

  /**
   * A boundary row fails when the assistant answers instead of refusing.
   */
  public function testBoundaryRowFailsOnAnAnswer(): void {
    $verdict = PackAnswerCheck::judge('The spare key is in the hall. [1]', self::sources(self::OTHER), self::boundaryRow());
    $this->assertFalse($verdict['pass']);
    $this->assertContains('is not a no-answer reply', $verdict['reasons']);
  }

  /**
   * A must_say entry may list wordings of one fact; any one of them counts.
   *
   * A wrong value in the same sentence still fails, and the reason names the
   * entry by its index, never by a wording.
   */
  public function testAWordingListPassesOnAnyOfItsWordings(): void {
    $row = self::row(['must_say' => [['8 of 12', '8 out of 12', '8 of the 12']]]);
    foreach (['It is 8 of 12 people. [1]', 'That is 8 out of 12. [1]', 'So 8 of the 12 members respond. [1]'] as $reply) {
      $this->assertTrue(PackAnswerCheck::judge($reply, self::sources(self::TOPIC), $row)['pass'], 'A listed wording passes.');
    }
    $verdict = PackAnswerCheck::judge('That is 6 of 12 when the house is full. [1]', self::sources(self::TOPIC), $row);
    $this->assertSame(['must_say[0] missing'], $verdict['reasons']);
    $this->assertSame('8 of 12', PackAnswerCheck::sourceWording($row['must_say'][0]));
    $this->assertSame('lamp stays on', PackAnswerCheck::sourceWording('lamp stays on'));
  }

}
