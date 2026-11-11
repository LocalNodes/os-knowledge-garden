<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\KernelTests\KernelTestBase;

/**
 * ln-overrides.css loads after socialblue and ln-tokens.css (22.1-03, D-09).
 *
 * 22.1-03 must_have: "The AA overrides live in a hand-written
 * css/ln-overrides.css that uses only var(--ln-*) values and loads after
 * socialblue and ln-tokens.css". Core files module CSS in the default group,
 * before every theme stylesheet, so the order depends on
 * localnodes_platform_css_alter(). Before this test only a manual check of the
 * served login page covered it. Added by the 22.1 Nyquist audit.
 *
 * @group localnodes_platform
 * @group phase22_1
 */
final class OverridesLoadOrderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'jquery_ui', 'jquery_ui_draggable', 'jquery_ui_resizable',
    'localnodes_platform',
  ];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['socialblue']);
    $this->config('system.theme')->set('default', 'socialblue')->save();
    $theme = $this->container->get('theme.initialization')->initTheme('socialblue');
    $this->container->get('theme.manager')->setActiveTheme($theme);
  }

  /**
   * Resolved CSS order: brand.css, brand--sky.css, ln-tokens, ln-overrides.
   */
  public function testOverridesLoadLast(): void {
    $assets = AttachedAssets::createFromRenderArray([
      '#attached' => ['library' => ['socialblue/brand', 'localnodes_platform/tokens']],
    ]);
    $order = array_keys($this->container->get('asset.resolver')->getCssAssets($assets, FALSE));
    unset($assets);
    $find = static function (array $order, string $suffix): int {
      foreach ($order as $i => $path) {
        if (str_ends_with($path, $suffix)) {
          return $i;
        }
      }
      return -1;
    };
    $brand = $find($order, 'socialblue/assets/css/brand.css');
    $sky = $find($order, 'socialblue/assets/css/brand--sky.css');
    $tokens = $find($order, 'localnodes_platform/css/ln-tokens.css');
    $overrides = $find($order, 'localnodes_platform/css/ln-overrides.css');
    $listing = implode(', ', $order);

    foreach (['brand.css' => $brand, 'brand--sky.css' => $sky, 'ln-tokens.css' => $tokens, 'ln-overrides.css' => $overrides] as $name => $i) {
      $this->assertGreaterThanOrEqual(0, $i, "$name is in the resolved CSS: $listing");
    }
    $this->assertGreaterThan($brand, $tokens, 'ln-tokens.css loads after brand.css');
    $this->assertGreaterThan($sky, $tokens, 'ln-tokens.css loads after brand--sky.css');
    $this->assertGreaterThan($tokens, $overrides, 'ln-overrides.css loads after ln-tokens.css');
    $this->assertSame(count($order) - 1, $overrides, 'ln-overrides.css is the last stylesheet');
  }

}
