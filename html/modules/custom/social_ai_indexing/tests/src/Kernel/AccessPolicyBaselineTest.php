<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;

/**
 * Grounds the AI-SPEC §5 leak matrix in the shipped access rules (Wave 0).
 *
 * Records $entity->access('view', $persona) for every persona x fixture cell
 * on the shipped Open Social config. Three layers of assertion:
 * - MUST_HIDE: cells the AI-SPEC calls hidden and access must deny, whatever
 *   the config. Hard assertions; POLICY may never mark them visible.
 * - MUST_SHOW: the member positive control. Hard assertions.
 * - POLICY: the full observed table, mirrored in 22-TEST-HARNESS.md
 *   "Access policy (shipped config)". A policy change is a one-line diff.
 *
 * Cells the AI-SPEC calls "hidden (scope)" (H-MO for P-MEMH and P-NON, both
 * members of H) are visible by access; the Group assistant's scope hint (G)
 * must hide them. They are listed in SCOPE_HIDDEN so the leak tests (F-1 to
 * F-3) assert them at the query/scope layer, not here.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class AccessPolicyBaselineTest extends GroupAccessKernelTestBase {

  use LeakFixtureTrait;

  /**
   * Observed access('view') per persona x fixture on the shipped config.
   *
   * TRUE = visible, FALSE = hidden. Mirrors 22-TEST-HARNESS.md.
   */
  public const POLICY = [
    'P-MEM' => [
      'G-PUB' => TRUE,
      'G-COM' => TRUE,
      'G-MO' => TRUE,
      'G-MO-COMMENT' => TRUE,
      'G-PUB-COMMENT' => TRUE,
      'H-MO' => FALSE,
      'H-PUB' => TRUE,
      'S-MO' => FALSE,
    ],
    'P-MEMH' => [
      'G-PUB' => TRUE,
      'G-COM' => TRUE,
      'G-MO' => TRUE,
      'G-MO-COMMENT' => TRUE,
      'G-PUB-COMMENT' => TRUE,
      // Member of H: visible by access, hidden by scope (SCOPE_HIDDEN).
      'H-MO' => TRUE,
      'H-PUB' => TRUE,
      'S-MO' => FALSE,
    ],
    'P-NON' => [
      'G-PUB' => TRUE,
      'G-COM' => TRUE,
      'G-MO' => FALSE,
      'G-MO-COMMENT' => FALSE,
      'G-PUB-COMMENT' => TRUE,
      // Member of H: visible by access, hidden by scope (SCOPE_HIDDEN).
      'H-MO' => TRUE,
      'H-PUB' => TRUE,
      'S-MO' => FALSE,
    ],
    'P-ANON' => [
      'G-PUB' => TRUE,
      'G-COM' => FALSE,
      'G-MO' => FALSE,
      'G-MO-COMMENT' => FALSE,
      // Policy, not a leak: the flexible_group-anonymous group role has no
      // "access comments", so comments inside a group are member-and-
      // logged-in only, even on a public Topic.
      'G-PUB-COMMENT' => FALSE,
      'H-MO' => FALSE,
      'H-PUB' => TRUE,
      'S-MO' => FALSE,
    ],
  ];

  /**
   * Hidden cells access must deny, whatever the config (AI-SPEC §5).
   */
  public const MUST_HIDE = [
    'P-MEM' => ['H-MO', 'S-MO'],
    'P-MEMH' => ['S-MO'],
    'P-NON' => ['G-MO', 'G-MO-COMMENT', 'S-MO'],
    'P-ANON' => ['G-MO', 'G-MO-COMMENT', 'H-MO', 'S-MO'],
  ];

  /**
   * The member positive control: must be visible by policy.
   */
  public const MUST_SHOW = [
    'P-MEM' => ['G-MO', 'G-MO-COMMENT'],
    'P-MEMH' => ['G-MO', 'G-MO-COMMENT'],
  ];

  /**
   * Cells hidden by the scope hint (G), not by access.
   *
   * The leak tests must hide these at the query/scope layer.
   */
  public const SCOPE_HIDDEN = [
    'P-MEMH' => ['H-MO'],
    'P-NON' => ['H-MO'],
  ];

  /**
   * One row per persona; each row checks all eight fixture cells.
   *
   * A row per persona (not per cell) keeps the suite to four Kernel boots on
   * an 8 GB machine; every assertion message names its cell.
   */
  public static function personaProvider(): array {
    $rows = [];
    foreach (array_keys(self::POLICY) as $persona) {
      $rows[$persona] = [$persona];
    }
    return $rows;
  }

  /**
   * Every cell of the persona's row matches the shipped policy.
   *
   * @dataProvider personaProvider
   */
  public function testAccessMatrixRow(string $persona): void {
    $this->setUpLeakFixtures();
    $account = $this->personas[$persona];

    // The fixture set is exactly the AI-SPEC set.
    $this->assertSame(array_keys(self::POLICY[$persona]), array_keys($this->fixtures), 'Fixture ids match the POLICY columns.');

    // The policy table can never mark a must-hide cell visible, or a
    // positive-control cell hidden.
    foreach (self::MUST_HIDE[$persona] ?? [] as $fixture) {
      $this->assertFalse(self::POLICY[$persona][$fixture], "POLICY keeps $persona x $fixture hidden.");
    }
    foreach (self::MUST_SHOW[$persona] ?? [] as $fixture) {
      $this->assertTrue(self::POLICY[$persona][$fixture], "POLICY keeps $persona x $fixture visible.");
    }

    foreach ($this->fixtures as $fixture => $entity) {
      $observed = $entity->access('view', $account);

      if (in_array($fixture, self::MUST_HIDE[$persona] ?? [], TRUE)) {
        $this->assertFalse($observed, "LEAK: $persona can view $fixture, which must be hidden.");
      }
      if (in_array($fixture, self::MUST_SHOW[$persona] ?? [], TRUE)) {
        $this->assertTrue($observed, "Positive control: $persona must view $fixture.");
      }
      $this->assertSame(
        self::POLICY[$persona][$fixture],
        $observed,
        "$persona x $fixture: shipped access('view') differs from POLICY. Update POLICY and 22-TEST-HARNESS.md together, after deciding whether the change is policy or a leak.",
      );
    }

    // The secret group itself is invisible to every persona (none is in S).
    $this->assertFalse($this->groups['S']->access('view', $account), "$persona cannot view secret group S.");
  }

}
