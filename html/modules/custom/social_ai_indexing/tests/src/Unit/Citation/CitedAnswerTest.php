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

  private const GROUP_NO_ANSWER = "I couldn't find anything about that in this group's pages. Try asking with different words, or look through the group's pages yourself.";

  private const SITE_NO_ANSWER = "I couldn't find anything about that on this site. Try asking with different words, or use search.";

  private const FALLBACK = "I couldn't find a source I can point you to for that, so I won't guess. Try asking with different words, or look through the group's pages yourself.";

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
   * The fallback is the exact UI-SPEC string and passes the invariants.
   */
  public function testFallbackCopy(): void {
    $this->assertSame(self::FALLBACK, CitedAnswer::fallbackMarkdown());
    $this->assertSame([], CitationInvariants::check(CitedAnswer::fallbackMarkdown(), new SourceRegistry()));
  }

}
