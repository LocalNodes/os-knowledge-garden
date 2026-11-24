<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;

/**
 * The pack check runs on a site where the pack module is not installed.
 *
 * A pack install ends by uninstalling localnodes_demo_packs, so the module's
 * classes are not on the site's autoloader when scripts/check_demo_pack.php
 * runs there. This test runs the script through drush, as an operator does,
 * on a site that never had the module, and expects it to reach its summary
 * line. The smoke pack's members are not on this site, so every row is
 * reported missing and the script exits 1; what matters is that it runs at
 * all instead of dying on a class it cannot find.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackCheckUninstalledTest extends BrowserTestBase {

  use DrushTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['user'];

  /**
   * A dry run of the smoke pack reaches its summary with the module absent.
   */
  public function testDryRunWithoutModule(): void {
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('localnodes_demo_packs'), 'The pack module is not installed.');
    $script = DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('localnodes_demo_packs') . '/scripts/check_demo_pack.php';

    $this->drush('php:script', [$script, '--', '--pack=smoke', '--dry-run'], ['uri' => $this->baseUrl], NULL, NULL, 1, '2>&1');
    $output = $this->getOutput();

    $this->assertStringNotContainsString('not found', $output);
    $this->assertMatchesRegularExpression('/^rows: (\d+) pass: 0 fail: \1 skip: 0$/m', $output);
    $this->assertMatchesRegularExpression('/^FAIL smoke-next-meeting\b/m', $output);
  }

}
