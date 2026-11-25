<?php

declare(strict_types=1);

namespace Drupal\Tests\boulder_demo\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The Boulder demo names its site and sets its clock, never over a choice.
 *
 * @group boulder_demo
 */
final class BoulderDemoSiteNameTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    require_once dirname(__DIR__, 3) . '/boulder_demo.install';
    // The entrypoint asks for a fresh Boulder demo this way and passes no
    // site name unless the operator gave one.
    putenv('DEMO_MODULE=boulder_demo');
    putenv('DRUPAL_SITE_NAME');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('DEMO_MODULE');
    putenv('DRUPAL_SITE_NAME');
    parent::tearDown();
  }

  /**
   * Sets the site name and slogan, then runs the module's install hook.
   */
  private function install(string $name, string $slogan): array {
    $this->config('system.site')->set('name', $name)->set('slogan', $slogan)->save();
    boulder_demo_install();
    $site = $this->config('system.site');
    return [$site->get('name'), $site->get('slogan')];
  }

  /**
   * Sets the site timezone, runs the install hook, returns the timezone.
   */
  private function installWithTimezone(string $timezone): string {
    $this->config('system.date')->set('timezone.default', $timezone)->save();
    boulder_demo_install();
    return (string) $this->config('system.date')->get('timezone.default');
  }

  /**
   * Each install default becomes the demo's name, with its slogan.
   *
   * @dataProvider defaultNames
   */
  public function testInstallDefaultIsReplaced(string $name): void {
    $this->assertSame(
      [BOULDER_DEMO_SITE_NAME, BOULDER_DEMO_SITE_SLOGAN],
      $this->install($name, ''),
    );
  }

  /**
   * The names an install leaves when nobody named the site.
   */
  public static function defaultNames(): array {
    return [
      'drush' => ['Drush Site-Install'],
      'entrypoint' => ['LocalNodes'],
      'empty' => [''],
    ];
  }

  /**
   * A chosen name and slogan stay as they are.
   */
  public function testChosenNameAndSloganAreKept(): void {
    $this->assertSame(['Maple Street', 'Our street'], $this->install('Maple Street', 'Our street'));
  }

  /**
   * A chosen name gets no demo slogan either.
   */
  public function testChosenNameGetsNoSlogan(): void {
    $this->assertSame(['Maple Street', ''], $this->install('Maple Street', ''));
  }

  /**
   * A default name with a slogan already set keeps that slogan.
   */
  public function testExistingSloganIsKept(): void {
    $this->assertSame([BOULDER_DEMO_SITE_NAME, 'Our street'], $this->install('LocalNodes', 'Our street'));
  }

  /**
   * A fresh Boulder demo on the container's UTC moves to Boulder's clock.
   */
  public function testFreshDemoGetsBoulderClock(): void {
    $this->assertSame('America/Denver', $this->installWithTimezone('UTC'));
  }

  /**
   * A chosen timezone stays.
   */
  public function testChosenTimezoneIsKept(): void {
    $this->assertSame('Europe/Lisbon', $this->installWithTimezone('Europe/Lisbon'));
  }

  /**
   * Enabled without a request for a fresh Boulder demo, nothing is changed.
   *
   * Someone who enables the module by hand on a site set to UTC and named
   * "LocalNodes" may have chosen both; only the entrypoint's DEMO_MODULE
   * request says the values are still the install's own.
   */
  public function testNoDemoRequestKeepsUtcAndName(): void {
    putenv('DEMO_MODULE');
    $this->assertSame(['LocalNodes', ''], $this->install('LocalNodes', ''));
    $this->assertSame('UTC', $this->installWithTimezone('UTC'));
  }

  /**
   * A site name the operator passed in DRUPAL_SITE_NAME is kept.
   *
   * DRUPAL_SITE_NAME=LocalNodes is a choice, not the entrypoint's fallback.
   */
  public function testNamePassedByOperatorIsKept(): void {
    putenv('DRUPAL_SITE_NAME=LocalNodes');
    $this->assertSame(['LocalNodes', ''], $this->install('LocalNodes', ''));
  }

}
