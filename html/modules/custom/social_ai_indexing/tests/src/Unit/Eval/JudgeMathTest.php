<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Eval;

use Drupal\Tests\UnitTestCase;
use Drupal\social_ai_indexing\Eval\JudgeMath;

/**
 * The judge's deterministic parts: sentences, quote check, metrics.
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class JudgeMathTest extends UnitTestCase {

  /**
   * A cited answer splits into sentences with their [n] numbers.
   */
  public function testSentences(): void {
    $answer = "CBPP differs from firm production:\n\n* **Firm Production:** Relies on a centralized decision process [1]. Intellectual property is housed in one firm [2].\n* No citation here.\n\nThe key is kept at the old mill [1][2].\n\n**Sources**\n- [1] [A](/node/1)\n- [2] [B](/node/2)";
    $s = JudgeMath::sentences($answer);
    $this->assertSame([
      ['text' => 'CBPP differs from firm production:', 'labels' => []],
      ['text' => 'Firm Production: Relies on a centralized decision process.', 'labels' => [1]],
      ['text' => 'Intellectual property is housed in one firm.', 'labels' => [2]],
      ['text' => 'No citation here.', 'labels' => []],
      ['text' => 'The key is kept at the old mill.', 'labels' => [1, 2]],
    ], $s);
  }

  /**
   * A quote the excerpt doesn't hold can't count as support.
   */
  public function testCrossCheckDowngradesInventedQuote(): void {
    $excerpts = ['[1]' => "The seed vault key is kept\n at the old mill."];
    $ok = JudgeMath::crossCheck(['verdict' => 'supported', 'per_source' => [['label' => '[1]', 'adds_support' => TRUE, 'quote' => 'kept at the old mill']]], $excerpts);
    $this->assertSame('supported', $ok['verdict']);
    $this->assertTrue($ok['per_source'][0]['quote_found']);

    $invented = JudgeMath::crossCheck(['verdict' => 'supported', 'per_source' => [['label' => '[1]', 'adds_support' => TRUE, 'quote' => 'kept in the green tin']]], $excerpts);
    $this->assertSame('not_supported', $invented['verdict'], 'An invented quote downgrades the pair.');
    $this->assertSame('supported', $invented['model_verdict']);
    $this->assertTrue($invented['downgraded']);
    $this->assertFalse($invented['per_source'][0]['adds_support']);

    $wrong_label = JudgeMath::crossCheck(['verdict' => 'partial', 'per_source' => [['label' => '[2]', 'adds_support' => TRUE, 'quote' => 'kept at the old mill']]], $excerpts);
    $this->assertSame('not_supported', $wrong_label['verdict'], 'A quote credited to a source it is not in counts for nothing.');

    $bare = JudgeMath::crossCheck(['verdict' => 'supported', 'per_source' => [['label' => '1', 'adds_support' => TRUE, 'quote' => 'kept at the old mill']]], $excerpts);
    $this->assertSame('supported', $bare['verdict'], 'A bare "1" label means source [1].');
    $this->assertSame('[1]', $bare['per_source'][0]['label']);
    $this->assertTrue($bare['per_source'][0]['quote_found']);

    $this->assertTrue(JudgeMath::quoteIn('the work of Jose Ramos', 'extracts more from the work of [Jose Ramos](https://example.org/jr) and'), 'Markdown link markup is not part of the quote.');
    $this->assertTrue(JudgeMath::quoteIn("the vault's key \"is\" kept", "the vault\u{2019}s key \u{201C}is\u{201D} kept"), 'Curly quotes fold to straight ones.');
    $this->assertFalse(JudgeMath::quoteIn('the work of Jose Rivera', 'the work of [Jose Ramos](https://example.org/jr)'), 'Folding does not loosen the match itself.');

    $short = JudgeMath::crossCheck(['verdict' => 'supported', 'per_source' => [['label' => '[1]', 'adds_support' => TRUE, 'quote' => 'mill']]], $excerpts);
    $this->assertSame('not_supported', $short['verdict'], 'A quote under 8 characters proves nothing.');

    $junk = JudgeMath::crossCheck(['verdict' => 'yes'], $excerpts);
    $this->assertSame('not_supported', $junk['verdict']);
  }

  /**
   * Recall counts partial as unsupported; precision is per citation.
   */
  public function testMetrics(): void {
    $m = JudgeMath::metrics([
      ['verdict' => 'supported', 'per_source' => [['adds_support' => TRUE], ['adds_support' => FALSE]]],
      ['verdict' => 'partial', 'per_source' => [['adds_support' => TRUE]]],
      ['verdict' => 'not_supported', 'per_source' => [['adds_support' => FALSE]]],
      ['verdict' => 'supported', 'per_source' => [['adds_support' => TRUE]]],
    ], 3);
    $this->assertSame(4, $m['sentences']);
    $this->assertSame(0.5, $m['recall']);
    $this->assertSame(5, $m['citations']);
    $this->assertSame(0.6, $m['precision']);
    $this->assertSame([2, 1, 1, 3], [$m['supported'], $m['partial'], $m['not_supported'], $m['uncited']]);
  }

  /**
   * Calibration counts agreement, false passes and partial passes.
   */
  public function testCalibrate(): void {
    $labels = ['P01' => 'supported', 'P02' => 'partly', 'P03' => 'not', 'P04' => 'partly', 'P05' => 'supported'];
    $judge = ['P01' => 'supported', 'P02' => 'supported', 'P03' => 'supported', 'P04' => 'partial', 'P05' => 'not_supported'];
    $c = JudgeMath::calibrate($judge, $labels);
    $this->assertSame(5, $c['total']);
    $this->assertSame(2, $c['agree']);
    $this->assertSame(1, $c['false_passes']);
    $this->assertSame(1, $c['partial_passes']);
    $this->assertCount(3, $c['disagreements']);
    $this->assertNull($c['kappa'], 'No kappa below 10 not-supported labels.');
    $this->assertFalse($c['trusted']);

    $all = JudgeMath::calibrate(['P01' => 'supported', 'P02' => 'partial'], ['P01' => 'supported', 'P02' => 'partly']);
    $this->assertTrue($all['trusted']);
  }

  /**
   * Kappa is 1 for perfect agreement and 0 for chance-level.
   */
  public function testKappa(): void {
    $this->assertSame(1.0, JudgeMath::kappa([['supported', 'supported'], ['not_supported', 'not_supported']]));
    $this->assertEqualsWithDelta(0.0, JudgeMath::kappa([['supported', 'supported'], ['supported', 'not_supported'], ['not_supported', 'supported'], ['not_supported', 'not_supported']]), 1e-9);
  }

}
