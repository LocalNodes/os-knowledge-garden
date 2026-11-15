<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Tests\localnodes_demo_packs\PackWalkChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * The walk rules, run against copies of the smoke walk that break each one.
 *
 * Every case changes a parsed copy in memory; the walk file itself is not
 * touched. A rule that cannot fail proves nothing, so each is shown a walk
 * that breaks it and must report it.
 *
 * @group localnodes_demo_packs
 */
final class PackWalkRulesTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * The smoke walk, parsed, as a fresh copy.
   */
  private static function walkCopy(): array {
    return self::walk('smoke');
  }

  /**
   * The smoke assistant rows by id.
   */
  private static function rows(): array {
    $rows = [];
    foreach (self::expectation('smoke', 'assistant.yml') as $row) {
      $rows[(string) ($row['id'] ?? '')] = $row;
    }
    return $rows;
  }

  /**
   * The index of the step with an id.
   */
  private static function stepIndex(array $walk, int $id): int {
    foreach ($walk['steps'] as $index => $step) {
      if ((int) $step['id'] === $id) {
        return $index;
      }
    }
    throw new \LogicException('No such step.');
  }

  private function problems(array $walk): string {
    return implode(' | ', PackWalkChecks::walkProblems('smoke', $walk, self::rows()));
  }

  /**
   * The walk as written has no problems.
   */
  public function testTheSmokeWalkIsComplete(): void {
    $this->assertSame([], PackWalkChecks::walkProblems('smoke', self::walkCopy(), self::rows()));
  }

  /**
   * A step marked checked with no checks and no assistant row is reported.
   */
  public function testCheckedStepWithNoChecksIsReported(): void {
    $walk = self::walkCopy();
    $walk['steps'][self::stepIndex($walk, 6)] = ['id' => 6, 'status' => 'checked'];
    $this->assertStringContainsString('step 6', $this->problems($walk));
    $walk['steps'][self::stepIndex($walk, 6)] = ['id' => 6, 'status' => 'checked', 'checks' => [], 'assistant' => []];
    $this->assertStringContainsString('step 6', $this->problems($walk));
    // Step 7 the same way: pending flipped to checked.
    $walk = self::walkCopy();
    $walk['steps'][self::stepIndex($walk, 7)]['status'] = 'checked';
    $this->assertStringContainsString('step 7', $this->problems($walk));
  }

  /**
   * Checks the walk cannot run do not make a checked step.
   */
  public function testChecksTheWalkDoesNotRunAreReported(): void {
    $walk = self::walkCopy();
    $walk['steps'][self::stepIndex($walk, 6)] = ['id' => 6, 'status' => 'checked', 'checks' => [['kind' => 'telepathy']]];
    $this->assertStringContainsString('step 6', $this->problems($walk));
    $walk['steps'][self::stepIndex($walk, 6)] = ['id' => 6, 'status' => 'checked', 'checks' => [['uuid' => 'x']]];
    $this->assertStringContainsString('step 6', $this->problems($walk));
    // One real check among them is enough, but the unknown kind is still named.
    $walk['steps'][self::stepIndex($walk, 6)] = ['id' => 6, 'status' => 'checked', 'checks' => [['kind' => 'export'], ['kind' => 'telepathy']]];
    $this->assertStringContainsString('step 6', $this->problems($walk));
  }

  /**
   * A checked step may hold only assistant rows, but they must exist.
   */
  public function testAssistantOnlyStepNeedsRealRows(): void {
    $walk = self::walkCopy();
    $walk['steps'][self::stepIndex($walk, 6)] = ['id' => 6, 'status' => 'checked', 'assistant' => ['smoke-next-meeting']];
    $this->assertSame('', $this->problems($walk));
    $walk['steps'][self::stepIndex($walk, 6)] = ['id' => 6, 'status' => 'checked', 'assistant' => ['no-such-row']];
    $this->assertStringContainsString('no-such-row', $this->problems($walk));
  }

  /**
   * A walk has exactly eight steps with eight distinct ids.
   */
  public function testEightDistinctStepIds(): void {
    $walk = self::walkCopy();
    array_pop($walk['steps']);
    $this->assertStringContainsString('eight', $this->problems($walk));
    $walk = self::walkCopy();
    $walk['steps'][self::stepIndex($walk, 8)]['id'] = 7;
    $this->assertStringContainsString('distinct', $this->problems($walk));
    $walk = self::walkCopy();
    $walk['steps'][] = ['id' => 9, 'status' => 'held', 'owner' => 'Someone'];
    $this->assertStringContainsString('eight', $this->problems($walk));
    $walk = self::walkCopy();
    unset($walk['steps'][self::stepIndex($walk, 3)]['id']);
    $this->assertNotSame('', $this->problems($walk));
  }

  /**
   * Held and pending steps still name their owner and what they need.
   */
  public function testHeldAndPendingStepsNameTheirOwner(): void {
    $walk = self::walkCopy();
    unset($walk['steps'][self::stepIndex($walk, 6)]['owner']);
    $this->assertStringContainsString('step 6', $this->problems($walk));
    $walk = self::walkCopy();
    unset($walk['steps'][self::stepIndex($walk, 7)]['needs']);
    $this->assertStringContainsString('step 7', $this->problems($walk));
  }

  /**
   * An assistant row beside a shared page must forbid the group's own page.
   */
  public function testSharedPageRowMustForbid(): void {
    $rows = self::rows();
    $rows['smoke-cross-group']['forbidden'] = [];
    $problems = implode(' | ', PackWalkChecks::walkProblems('smoke', self::walkCopy(), $rows));
    $this->assertStringContainsString('smoke-cross-group', $problems);
  }

  /**
   * The check kinds the walk test runs are the ones the rule accepts.
   */
  public function testKindsAreTheOnesTheWalkTestRuns(): void {
    $source = (string) file_get_contents(self::moduleDir() . '/tests/src/Functional/PackWalkTest.php');
    foreach (PackWalkChecks::KINDS as $kind) {
      $this->assertStringContainsString("'$kind' => ", $source, 'The walk test does not run a kind the rule accepts.');
    }
    preg_match_all("/^      '([a-z_]+)' => \\\$this->check/m", $source, $found);
    $this->assertEqualsCanonicalizing(PackWalkChecks::KINDS, $found[1], 'The kinds the walk test runs are the accepted kinds.');
  }

}
