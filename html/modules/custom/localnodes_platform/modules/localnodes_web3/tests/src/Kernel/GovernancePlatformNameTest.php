<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Tests\safe_smart_accounts\Kernel\SafeKernelTestBase;

/**
 * The distribution gives safe_governance its own platform name.
 *
 * The safe_governance module is generic contrib: its outside-payment note reads
 * the platform name from safe_governance.settings:platform_name and says
 * "this site" while that is empty. localnodes_web3_install() sets it to
 * LocalNodes, at install, never in hook_update_N() or the entrypoint
 * (CLAUDE.md). As in SponsoredExecutionDefaultTest, localnodes_web3 itself
 * can't be enabled here, so the .install file is loaded and its helper is
 * called directly. safe_governance is really enabled in the cases that need
 * it.
 *
 * @group localnodes_web3
 */
final class GovernancePlatformNameTest extends SafeKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/localnodes_web3.install';
  }

  /**
   * With safe_governance enabled an empty name becomes LocalNodes.
   */
  public function testEmptyNameBecomesLocalNodes(): void {
    $this->enableModules(['safe_governance']);
    $this->installConfig(['safe_governance']);
    $this->assertSame('', $this->config('safe_governance.settings')->get('platform_name'), 'Empty as safe_governance ships it.');

    _localnodes_web3_set_governance_platform_name();
    _localnodes_web3_set_governance_platform_name();

    $this->assertSame('LocalNodes', $this->config('safe_governance.settings')->get('platform_name'));
  }

  /**
   * A name the site already set is kept.
   */
  public function testExistingNameIsKept(): void {
    $this->enableModules(['safe_governance']);
    $this->installConfig(['safe_governance']);
    $this->config('safe_governance.settings')->set('platform_name', 'Boulder Commons')->save();

    _localnodes_web3_set_governance_platform_name();

    $this->assertSame('Boulder Commons', $this->config('safe_governance.settings')->get('platform_name'));
  }

  /**
   * Without safe_governance nothing is written and nothing fails.
   */
  public function testNothingWrittenWithoutSafeGovernance(): void {
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('safe_governance'));

    _localnodes_web3_set_governance_platform_name();

    $this->assertTrue($this->config('safe_governance.settings')->isNew(), 'No safe_governance.settings was created.');
  }

}
