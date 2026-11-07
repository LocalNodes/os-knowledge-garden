<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smoke test proving the PHPUnit harness boots for the web3 wrapper.
 *
 * Real per-module Unit/Kernel coverage (TEST-01) lands upstream on
 * drupal.org in Phases 19/22/23; this only proves the harness runs.
 *
 * @group localnodes_web3
 */
final class SmokeTest extends TestCase {

  /**
   * The harness can execute a trivial assertion.
   */
  public function testHarnessBoots(): void {
    $this->assertTrue(TRUE, 'PHPUnit harness executes web3 wrapper Unit tests.');
  }

}
