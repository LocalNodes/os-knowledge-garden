<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Tests\safe_smart_accounts\Kernel\SafeKernelTestBase;

/**
 * The community covers sending group payments when gas_sponsor is on (R5).
 *
 * The safe_smart_accounts module ships sponsored_execution FALSE and has no
 * dependency on gas_sponsor (D-03). localnodes_web3_install() turns the
 * setting on when gas_sponsor is enabled, at install, never in
 * hook_update_N() or the entrypoint (CLAUDE.md). As in
 * NetworkFeePermissionTest, localnodes_web3 itself can't be enabled here, so
 * the .install file is loaded and its helper is called directly.
 *
 * @group localnodes_web3
 */
final class SponsoredExecutionDefaultTest extends SafeKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/localnodes_web3.install';
  }

  /**
   * With gas_sponsor enabled the setting is turned on, idempotently.
   */
  public function testTurnedOnWhenGasSponsorIsEnabled(): void {
    $this->enableModules(['gas_sponsor']);
    $this->assertFalse($this->config('safe_smart_accounts.settings')->get('sponsored_execution'), 'Off as safe_smart_accounts ships it.');

    _localnodes_web3_enable_sponsored_execution();
    _localnodes_web3_enable_sponsored_execution();

    $this->assertTrue($this->config('safe_smart_accounts.settings')->get('sponsored_execution'));
  }

  /**
   * Without gas_sponsor the setting stays off.
   */
  public function testStaysOffWithoutGasSponsor(): void {
    _localnodes_web3_enable_sponsored_execution();
    $this->assertFalse($this->config('safe_smart_accounts.settings')->get('sponsored_execution'));
  }

}
