<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Rag;

use Drupal\Tests\UnitTestCase;
use Drupal\social_ai_indexing\Rag\PermissionAwareRagTool;

/**
 * A page's excerpt holds its top chunks, inside the input cap (22.2-15).
 *
 * RQ-01 ("What does bioregionalism organize life around?") got no answer on
 * gemini-3.8-flash because the 1,500-character excerpt held only the top
 * chunk of the "Bioregionalism" page. The chunk that answers the question is
 * short (295 or 370 characters) and ranks 2nd or 3rd among the page's chunks
 * when the search uses the question's words. On the lane's index the third
 * chunk ends at 3,069 characters of excerpt; any two whole chunks fit in
 * 2 x 1,467 + 3 = 2,937 (1,467 is the longest cleaned chunk in the index).
 * The orchestrator's ruling (2026-11-07, option A) raised the cap to 3,200.
 *
 * The cap is bounded from above by the live runner's per-question input cap
 * (verify_cited_answers.php MAX_INPUT_TOKENS, 20,000, which this plan keeps).
 * That cap is a sum over the turn's chat calls, so the worst case below is a
 * three-call turn that fills the whole source budget at full excerpt length.
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class ExcerptBudgetTest extends UnitTestCase {

  /**
   * The live runner's per-question input cap (E11), kept at 20,000.
   *
   * @see \CitedAnswersLiveRunner::MAX_INPUT_TOKENS
   */
  private const MAX_INPUT_TOKENS = 20000;

  /**
   * Characters per token, on the safe side.
   *
   * The lane measured about 4.85 (22.2-15 jsonl line 6: about 3,230
   * characters of search results added 666 input tokens).
   */
  private const CHARS_PER_TOKEN = 4;

  /**
   * Input tokens of a call before any search result arrives.
   *
   * System prompt, tool definition and question: 569 tokens measured on
   * the lane (22.2-15 jsonl lines 4-8, first call), rounded up.
   */
  private const BASE_CALL_TOKENS = 600;

  /**
   * Characters around one fenced block: label, a 40-character title,
   * the two fences and the separators.
   */
  private const BLOCK_FRAME_CHARS = 60;

  /**
   * The "Search results. Cite with..." header of one tool output.
   */
  private const RESULT_HEADER_CHARS = 150;

  /**
   * The cap is 3,200 characters (orchestrator ruling, option A).
   */
  public function testExcerptCapIs3200(): void {
    $this->assertSame(3200, PermissionAwareRagTool::EXCERPT_CHARS);
  }

  /**
   * Two long chunks and a short third one come back whole.
   *
   * The lengths are node 29's top three chunks for the search
   * "bioregionalism organize life around" (1,336, 1,357 and 370 cleaned
   * characters); the third holds the answer.
   */
  public function testTopThreeChunksOfAPageStayWhole(): void {
    $first = self::prose('Economy and polity are described here', 1336);
    $second = self::prose('A new vision for the ordering of society follows', 1357);
    $third = self::prose('It is the tradition of organizing human life around watersheds and living landscapes', 370);

    $excerpt = PermissionAwareRagTool::excerpt([$first, $second, $third]);

    $this->assertStringContainsString('organizing human life around watersheds', $excerpt);
    $this->assertStringEndsNotWith(' …', $excerpt, 'the excerpt was not cut');
    $this->assertSame(mb_strlen($first) + mb_strlen($second) + mb_strlen($third) + 6, mb_strlen($excerpt));
  }

  /**
   * Two chunks at the index's longest length come back whole.
   */
  public function testTwoLongestChunksStayWhole(): void {
    $first = self::prose('The first long passage begins', 1467);
    $second = self::prose('The second long passage ends with its marker phrase', 1467);

    $excerpt = PermissionAwareRagTool::excerpt([$first, $second]);

    $this->assertStringContainsString('The second long passage ends with its marker phrase', $excerpt);
    $this->assertStringEndsNotWith(' …', $excerpt, 'the excerpt was not cut');
  }

  /**
   * The worst three-call turn stays under the 20,000 input-token cap.
   *
   * Call 1 asks for the searches. Round 1 brings 10 sources at the full
   * excerpt; call 2 sees them and searches again. Round 2 brings the last
   * 2 sources of the 12-source budget; call 3 sees all 12 and answers. Every
   * call resends the history, so round 1's text is paid twice.
   */
  public function testWorstCaseTurnStaysUnderTheInputCap(): void {
    $block = PermissionAwareRagTool::EXCERPT_CHARS + self::BLOCK_FRAME_CHARS;
    $round1 = (int) ceil((10 * $block + self::RESULT_HEADER_CHARS) / self::CHARS_PER_TOKEN);
    $round2 = (int) ceil(((PermissionAwareRagTool::MAX_SOURCES - 10) * $block + self::RESULT_HEADER_CHARS) / self::CHARS_PER_TOKEN);

    $call1 = self::BASE_CALL_TOKENS;
    $call2 = self::BASE_CALL_TOKENS + $round1;
    $call3 = self::BASE_CALL_TOKENS + $round1 + $round2;

    $this->assertSame(12, PermissionAwareRagTool::MAX_SOURCES, 'the source budget this sum assumes');
    $this->assertLessThanOrEqual(self::MAX_INPUT_TOKENS, $call1 + $call2 + $call3);
  }

  /**
   * Plain sentences of exactly $length characters, starting with $lead.
   */
  private static function prose(string $lead, int $length): string {
    $text = $lead . '.';
    $filler = ' Members share notes about the place they live in.';
    while (mb_strlen($text) < $length) {
      $text .= $filler;
    }
    // End on a full stop at exactly $length characters.
    return mb_substr($text, 0, $length - 1) . '.';
  }

}
