<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\social_ai_indexing\Citation\CitationInvariants;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Citation\RetrievedSource;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\social_ai_indexing\Citation\UncitableAnswerException;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the validated cited answer and its fixed copy (D-04, D-19).
 *
 * @coversDefaultClass \Drupal\social_ai_indexing\Citation\CitedAnswer
 * @group phase22
 * @group phase22_unit
 */
final class CitedAnswerTest extends UnitTestCase {

  use CitationTestEntities;

  private const GROUP_NO_ANSWER = "I couldn't find anything about that in this group's documents. Try asking with different words, or open the group's documents yourself.";

  private const SITE_NO_ANSWER = "I couldn't find anything about that on this site. Try asking with different words, or use search.";

  private const FALLBACK = "I couldn't finish an answer with sources this time, so I won't guess. Ask again, or look through the documents yourself.";

  /**
   * The cited() factory refuses every invalid answer with its reason code.
   *
   * @dataProvider invalidCitedAnswers
   */
  public function testCitedRejectsInvalidAnswers(string $body, bool $with_citation, string $reason): void {
    $citations = $with_citation ? [new RetrievedSource(1, 'node', 1, 'One', 'Topic', '/node/1')] : [];
    try {
      CitedAnswer::cited($body, $citations);
      $this->fail('Expected UncitableAnswerException ' . $reason);
    }
    catch (UncitableAnswerException $e) {
      $this->assertSame($reason, $e->getMessage());
    }
  }

  /**
   * Invalid answers: body, has a citation, expected reason.
   */
  public static function invalidCitedAnswers(): array {
    return [
      'blank body' => [" \n ", TRUE, 'empty_body'],
      'no citations' => ['A claim [1].', FALSE, 'no_valid_citations'],
      'raw label left in body' => ['A claim [R1].', TRUE, 'unrewritten_label'],
      'raw lowercase label left in body' => ['A claim [r1].', TRUE, 'unrewritten_label'],
    ];
  }

  /**
   * A citation that is not a RetrievedSource is refused.
   */
  public function testCitedRejectsForeignCitationType(): void {
    $this->expectException(UncitableAnswerException::class);
    $this->expectExceptionMessage('bad_citation_type');
    CitedAnswer::cited('A claim [1].', [['title' => 'Fake', 'url' => '/node/1']]);
  }

  /**
   * Markdown is the body, a blank line, "**Sources**" and the list.
   */
  public function testToMarkdownShape(): void {
    $answer = CitedAnswer::cited("Rivers set borders [1]. Commons [2].  \n", [
      new RetrievedSource(3, 'node', 30, 'Watershed [draft] notes', 'Topic', '/node/30'),
      new RetrievedSource(1, 'comment', 7, 'Comment on Commons', 'comment', '/node/5#comment-7'),
    ]);

    $this->assertFalse($answer->noAnswer);
    $this->assertSame(
      "Rivers set borders [1]. Commons [2].\n\n**Sources**\n"
      . "- [1] [Watershed (draft) notes](/node/30)\n"
      . "- [2] [Comment on Commons](/node/5#comment-7)",
      $answer->toMarkdown()
    );
  }

  /**
   * Two different pages with the same title get distinct link texts.
   */
  public function testDuplicateTitlesGetDistinctLinkTexts(): void {
    $registry = new SourceRegistry();
    $a = $registry->register($this->mockNode(1, 'Meeting notes'), 'Topic');
    $b = $registry->register($this->mockNode(2, 'Meeting notes'), 'Topic');
    $answer = CitedAnswer::cited('First [1]. Second [2].', [$a, $b]);

    $markdown = $answer->toMarkdown();
    $this->assertStringContainsString('- [1] [Meeting notes](/node/1)', $markdown);
    $this->assertStringContainsString('- [2] [Meeting notes (2)](/node/2)', $markdown);
    $this->assertSame([], CitationInvariants::check($markdown, $registry));
  }

  /**
   * The no-answer copy is the exact UI-SPEC string per scope, with no Sources.
   */
  public function testNoRelevantContentCopyPerScope(): void {
    $group = CitedAnswer::noRelevantContent(TRUE);
    $site = CitedAnswer::noRelevantContent(FALSE);

    $this->assertTrue($group->noAnswer);
    $this->assertSame([], $group->citations);
    $this->assertSame(self::GROUP_NO_ANSWER, $group->toMarkdown());
    $this->assertSame(self::SITE_NO_ANSWER, $site->toMarkdown());
    $this->assertStringNotContainsString('Sources', $group->toMarkdown());
    // Byte-identical every time, for every person (T-22-20).
    $this->assertSame($group->toMarkdown(), CitedAnswer::noRelevantContent(TRUE)->toMarkdown());
  }

  /**
   * The could-not replies are these exact words, with no Sources list.
   */
  public function testCouldNotAnswerCopy(): void {
    $copies = [
      CitedAnswer::NO_SOURCED_ANSWER_GROUP => "I couldn't give you an answer with a source this time. Try asking with different words, or open the group's documents yourself.",
      CitedAnswer::NO_SOURCED_ANSWER_SITE => "I couldn't give you an answer with a source this time. Try asking with different words, or use search.",
      CitedAnswer::SEARCH_TROUBLE => "I couldn't look through the documents just now. Wait a moment, then ask again. If it keeps happening, use Get help at the bottom of the page.",
      CitedAnswer::UNAVAILABLE => "The assistant isn't available right now. You can still open and read the documents. If this keeps happening, use Get help at the bottom of the page.",
    ];
    foreach ($copies as $constant => $words) {
      $this->assertSame($words, $constant);
      $this->assertSame([], CitationInvariants::check($constant, new SourceRegistry()));
    }
  }

  /**
   * The fallback is the exact UI-SPEC string and passes the invariants.
   */
  public function testFallbackCopy(): void {
    $this->assertSame(self::FALLBACK, CitedAnswer::fallbackMarkdown());
    $this->assertSame([], CitationInvariants::check(CitedAnswer::fallbackMarkdown(), new SourceRegistry()));
  }

}
