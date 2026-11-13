<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\KernelTests\KernelTestBase;

/**
 * The module's stylesheets load after socialblue's, in a fixed order.
 *
 * ln-tokens.css sets the --ln-* values, ln-overrides.css holds the contrast
 * fixes, and ln-app-look.css restyles the member theme on top of both, so it
 * loads last. Core files module CSS before every theme stylesheet, so the
 * order depends on localnodes_platform_css_alter().
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
   * Runs the shutdown callbacks while the container is still there.
   *
   * The bootstrap base theme saves its cache in a shutdown callback. Core
   * runs those after a passing test; after a failing one they ran only at
   * process exit, with no container, and that error replaced the failure.
   */
  protected function tearDown(): void {
    $callbacks = &drupal_register_shutdown_function();
    while ($callback = array_shift($callbacks)) {
      call_user_func_array($callback['callback'], $callback['arguments']);
    }
    parent::tearDown();
  }

  /**
   * Resolved order: brand.css, brand--sky.css, tokens, overrides, app look.
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
    $look = $find($order, 'localnodes_platform/css/ln-app-look.css');
    $listing = implode(', ', $order);

    foreach (['brand.css' => $brand, 'brand--sky.css' => $sky, 'ln-tokens.css' => $tokens, 'ln-overrides.css' => $overrides, 'ln-app-look.css' => $look] as $name => $i) {
      $this->assertGreaterThanOrEqual(0, $i, "$name is in the resolved CSS: $listing");
    }
    $this->assertGreaterThan($brand, $tokens, 'ln-tokens.css loads after brand.css');
    $this->assertGreaterThan($sky, $tokens, 'ln-tokens.css loads after brand--sky.css');
    $this->assertGreaterThan($tokens, $overrides, 'ln-overrides.css loads after ln-tokens.css');
    $this->assertGreaterThan($overrides, $look, "ln-app-look.css loads after ln-overrides.css: $listing");
    $this->assertSame(count($order) - 2, $overrides, "Only ln-app-look.css loads after ln-overrides.css: $listing");
    $this->assertSame(count($order) - 1, $look, "ln-app-look.css is the last stylesheet: $listing");
  }

}
