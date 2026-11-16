<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Eval;

use Drupal\Tests\UnitTestCase;
use Drupal\social_ai_indexing\Eval\ForgedScopeCheck;
use Drupal\social_ai_indexing\Eval\IsolatedChild;

/**
 * One planned run per process: one record, a matching exit status, no retry.
 *
 * verify_cited_answers.php runs on include, so its child path is proved here
 * through the rules it calls, plus a check that the script calls them.
 *
 * @group social_ai_indexing
 */
final class IsolatedChildTest extends UnitTestCase {

  /**
   * Only the coordinating process compares runs.
   */
  public function testOnlyTheCoordinatorComparesRuns(): void {
    $this->assertTrue(IsolatedChild::crossChecksHere([]));
    $this->assertTrue(IsolatedChild::crossChecksHere(['in-process' => TRUE, 'space' => '20']));
    $this->assertFalse(IsolatedChild::crossChecksHere(['run-index' => '3']));
    $this->assertFalse(IsolatedChild::crossChecksHere(['run-index' => '0']));
  }

  /**
   * A child holding one fallback record would ask for a retry if it compared.
   *
   * This is why a child must not compare: the retry appends a second record
   * the coordinating process does not expect.
   */
  public function testAChildWithOneFallbackWouldRetry(): void {
    $one = [['answer' => 'Fallback text.', 'sources' => 0, 'outcome' => 'fallback']];
    $this->assertSame(ForgedScopeCheck::RETRY, ForgedScopeCheck::first($one, 'Fallback text.')['result']);
    $this->assertFalse(IsolatedChild::crossChecksHere(['run-index' => '7']), 'So a child never runs the comparison.');
  }

  /**
   * The child's record count and exit status must agree with each other.
   */
  public function testChildProblems(): void {
    $ok = ['status' => 'PASS', 'critical' => []];
    $failed = ['status' => 'FAIL', 'critical' => []];
    $critical = ['status' => 'CRITICAL', 'critical' => ['leak']];
    $quota = ['status' => 'INCOMPLETE', 'critical' => [], 'error' => 'daily_quota'];

    $this->assertNull(IsolatedChild::problem([$ok], 0));
    $this->assertNull(IsolatedChild::problem([$failed], 0));
    $this->assertNull(IsolatedChild::problem([$quota], 0));
    $this->assertNull(IsolatedChild::problem([$critical], 1));
    $this->assertNull(IsolatedChild::problem([], 0, FALSE), 'A site change writes no record.');

    $this->assertSame('the child process wrote 2 records (expected 1)', IsolatedChild::problem([$ok, $ok], 0), 'A retry record from a child is caught.');
    $this->assertSame('the child process wrote 0 records (expected 1)', IsolatedChild::problem([], 255));
    $this->assertSame('the child process wrote 1 records (expected 0)', IsolatedChild::problem([$ok], 0, FALSE));
    $this->assertSame('the child process exited with status 2 (expected 0)', IsolatedChild::problem([$ok], 2), 'An incomplete child is not a pass.');
    $this->assertSame('the child process exited with status 255 (expected 0)', IsolatedChild::problem([$ok], 255));
    $this->assertSame('the child process exited with status 0 (expected 1)', IsolatedChild::problem([$critical], 0));
    $this->assertSame('the child process exited with status 1 (expected 0)', IsolatedChild::problem([$ok], 1));
    $this->assertSame('the child process exited with status 1 (expected 0)', IsolatedChild::problem([], 1, FALSE));
  }

  /**
   * The script gates its comparison and judges every child through the rules.
   */
  public function testScriptUsesTheRules(): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4) . '/scripts/verify_cited_answers.php');
    $this->assertNotSame('', $source);
    // Two calls: the gated one in run() and the coordinator's in runIsolated().
    $this->assertSame(2, preg_match_all('/\$this->crossChecks\(/', $source));
    $this->assertSame(1, preg_match('/private function runIsolated\(.*?\n  }\n/s', $source, $isolated));
    $this->assertSame(1, substr_count($isolated[0], '$this->crossChecks($by_case);'), 'The coordinator compares.');
    $this->assertMatchesRegularExpression('/if \(IsolatedChild::crossChecksHere\(\$this->options\)\) \{\s*\$this->crossChecks\(\$by_case\);/', $source, 'The comparison is gated.');
    $this->assertSame(2, substr_count($source, 'IsolatedChild::problem('), 'Both the first run and its retry are judged by the rules.');
    $this->assertStringNotContainsString('shell_exec(', $source, 'The child is run in a way that keeps its exit status.');
    $this->assertMatchesRegularExpression('/exec\(\$command \. \' 2>&1\', \$lines, \$status\);/', $source);
  }

}
