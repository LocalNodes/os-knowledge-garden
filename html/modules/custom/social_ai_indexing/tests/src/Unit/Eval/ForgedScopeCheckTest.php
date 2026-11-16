<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Eval;

use Drupal\Tests\UnitTestCase;
use Drupal\social_ai_indexing\Eval\ForgedScopeCheck;

/**
 * E2's sameness rule for the replies to forged scopes.
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class ForgedScopeCheckTest extends UnitTestCase {

  private const FALLBACK = 'Fallback text.';

  /**
   * The no-answer reply.
   */
  private static function noAnswer(): array {
    return ['answer' => 'No answer text.', 'sources' => 0, 'outcome' => 'no_answer'];
  }

  /**
   * The fallback reply.
   */
  private static function fallback(): array {
    return ['answer' => self::FALLBACK, 'sources' => 0, 'outcome' => 'fallback'];
  }

  /**
   * Identical replies pass without a retry.
   */
  public function testSameRepliesPass(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => self::noAnswer()];
    $this->assertSame(['result' => ForgedScopeCheck::SAME, 'retry' => []], ForgedScopeCheck::first($replies, self::FALLBACK));
  }

  /**
   * One fallback is retried; a matching retry passes.
   */
  public function testFallbackRetriedThenMatches(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => self::fallback()];
    $this->assertSame(['result' => ForgedScopeCheck::RETRY, 'retry' => ['abc']], ForgedScopeCheck::first($replies, self::FALLBACK));
    $replies['abc'] = self::noAnswer();
    $this->assertSame(ForgedScopeCheck::PASS_AFTER_RETRY, ForgedScopeCheck::afterRetry($replies, ['abc'], self::FALLBACK));
  }

  /**
   * A second fallback makes the run incomplete.
   */
  public function testSecondFallbackIsIncomplete(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => self::fallback()];
    $this->assertSame(ForgedScopeCheck::INCOMPLETE, ForgedScopeCheck::afterRetry($replies, ['abc'], self::FALLBACK));
  }

  /**
   * A retry that returns some other reply is critical.
   */
  public function testRetryWithOtherReplyIsCritical(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => ['answer' => 'Something else.', 'sources' => 0, 'outcome' => 'uncited']];
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::afterRetry($replies, ['abc'], self::FALLBACK));
  }

  /**
   * A retry that matches in text but came with sources is critical.
   */
  public function testRetryWithSourcesIsCritical(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => ['sources' => 2] + self::noAnswer()];
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::afterRetry($replies, ['abc'], self::FALLBACK));
  }

  /**
   * A different reply with no sources is not exempt: critical, no retry.
   *
   * Only the exact fallback text is exempt; a widening to any reply with no
   * sources would hide a scope that changed the answer.
   */
  public function testOtherNoSourceReplyIsCritical(): void {
    $other = ['answer' => 'A different reply.', 'sources' => 0, 'outcome' => 'fallback'];
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => $other];
    $this->assertSame(['result' => ForgedScopeCheck::CRITICAL, 'retry' => []], ForgedScopeCheck::first($replies, self::FALLBACK));
    $other = ['answer' => 'A different reply.', 'sources' => 0, 'outcome' => 'no_answer'];
    $replies['abc'] = $other;
    $this->assertSame(['result' => ForgedScopeCheck::CRITICAL, 'retry' => []], ForgedScopeCheck::first($replies, self::FALLBACK));
  }

  /**
   * The fallback text with sources or another outcome is not exempt.
   */
  public function testFallbackTextWithSourcesIsCritical(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => ['answer' => self::FALLBACK, 'sources' => 1, 'outcome' => 'fallback']];
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::first($replies, self::FALLBACK)['result']);
    $replies['abc']['sources'] = 0;
    $replies['abc']['outcome'] = 'cited';
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::first($replies, self::FALLBACK)['result']);
  }

  /**
   * Two different non-fallback replies stay critical even beside a fallback.
   */
  public function testTwoDifferentOthersAreCritical(): void {
    $replies = ['S' => self::noAnswer(), '999999' => ['answer' => 'Other.', 'sources' => 0, 'outcome' => 'no_answer'], 'abc' => self::fallback()];
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::first($replies, self::FALLBACK)['result']);
  }

  /**
   * Replies that are all the fallback are retried, never accepted as the same.
   */
  public function testAllFallbacksAreRetried(): void {
    $replies = ['S' => self::fallback(), '999999' => self::fallback(), 'abc' => self::fallback()];
    $this->assertSame(['result' => ForgedScopeCheck::RETRY, 'retry' => ['S', '999999', 'abc']], ForgedScopeCheck::first($replies, self::FALLBACK));
  }

  /**
   * Through the whole rule: all fallbacks, retries that stay fallbacks, incomplete.
   */
  public function testAllFallbacksStayingFallbackIsIncomplete(): void {
    $replies = ['S' => self::fallback(), '999999' => self::fallback(), 'abc' => self::fallback()];
    $asked = [];
    $retry = function ($key) use (&$asked): array {
      $asked[] = $key;
      return self::fallback();
    };
    $this->assertSame(ForgedScopeCheck::INCOMPLETE, ForgedScopeCheck::resolve($replies, self::FALLBACK, $retry));
    $this->assertSame(['S', '999999', 'abc'], $asked, 'Every fallback is asked again once.');
  }

  /**
   * Through the whole rule: all fallbacks, retries that agree, pass.
   */
  public function testAllFallbacksThenSameRepliesPass(): void {
    $replies = ['S' => self::fallback(), '999999' => self::fallback(), 'abc' => self::fallback()];
    $retry = fn ($key): array => self::noAnswer();
    $this->assertSame(ForgedScopeCheck::PASS_AFTER_RETRY, ForgedScopeCheck::resolve($replies, self::FALLBACK, $retry));
  }

  /**
   * A retry that does not complete makes the run incomplete.
   */
  public function testRetryThatDoesNotCompleteIsIncomplete(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => self::fallback()];
    $this->assertSame(ForgedScopeCheck::INCOMPLETE, ForgedScopeCheck::resolve($replies, self::FALLBACK, fn ($key): ?array => NULL));
  }

  /**
   * A retry that does not complete does not hide another retry's different reply.
   */
  public function testIncompleteRetryDoesNotHideADifferentReply(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::fallback(), 'abc' => self::fallback()];
    $other = ['answer' => 'Something else.', 'sources' => 0, 'outcome' => 'uncited'];
    $asked = [];
    $retry = function (string $key) use (&$asked, $other): ?array {
      $asked[] = $key;
      return $key === '999999' ? NULL : $other;
    };
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::resolve($replies, self::FALLBACK, $retry));
    $this->assertSame(['999999', 'abc'], $asked, 'Every fallback is asked again, even after one does not complete.');
  }

  /**
   * Identical replies need no retry through the whole rule.
   */
  public function testSameRepliesNeedNoRetry(): void {
    $replies = ['S' => self::noAnswer(), '999999' => self::noAnswer(), 'abc' => self::noAnswer()];
    $retry = function ($key): array {
      $this->fail('No retry is asked for identical replies.');
    };
    $this->assertSame(ForgedScopeCheck::SAME, ForgedScopeCheck::resolve($replies, self::FALLBACK, $retry));
  }

  /**
   * A different reply wins over a fallback that is left, in either order.
   */
  public function testDifferentReplyBeatsLeftFallback(): void {
    $other = ['answer' => 'Something else.', 'sources' => 0, 'outcome' => 'uncited'];
    $first = ['S' => self::noAnswer(), '999999' => self::fallback(), 'abc' => self::fallback()];
    $this->assertSame(['result' => ForgedScopeCheck::RETRY, 'retry' => ['999999', 'abc']], ForgedScopeCheck::first($first, self::FALLBACK));
    $after = ['S' => self::noAnswer(), '999999' => self::fallback(), 'abc' => $other];
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::afterRetry($after, ['999999', 'abc'], self::FALLBACK));
    $after = ['S' => self::noAnswer(), '999999' => $other, 'abc' => self::fallback()];
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::afterRetry($after, ['999999', 'abc'], self::FALLBACK));
    // Through the whole rule, both orders.
    $answers = ['999999' => self::fallback(), 'abc' => $other];
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::resolve($first, self::FALLBACK, fn ($key): array => $answers[$key]));
    $answers = ['999999' => $other, 'abc' => self::fallback()];
    $this->assertSame(ForgedScopeCheck::CRITICAL, ForgedScopeCheck::resolve($first, self::FALLBACK, fn ($key): array => $answers[$key]));
  }

}
