<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Socialblue's radius comes from the token file (22.1-03, D-08, D-10).
 *
 * 22.1-03 must_have: "The socialblue radius settings come from the token
 * source through ln-tokens.flat.json, not from literals in PHP". Before this
 * test only the generator side was covered (build_tokens.test.mjs); nothing
 * failed if _localnodes_platform_configure_theme_shape() stopped writing the
 * values or wrote the wrong keys. Added by the 22.1 Nyquist audit.
 *
 * @group localnodes_platform
 * @group phase22_1
 */
final class ThemeShapeFromTokensTest extends KernelTestBase {

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
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
  }

  /**
   * Reads radius.control and radius.card from the flat token file.
   *
   * @return array{control: string, card: string}
   *   Unitless numbers, as socialblue.settings stores them.
   */
  private function flatRadius(): array {
    $path = $this->container->get('extension.list.module')->getPath('localnodes_platform') . '/tokens/ln-tokens.flat.json';
    $tokens = json_decode((string) file_get_contents($path), TRUE);
    return [
      'control' => (string) (float) $tokens['radius']['control'],
      'card' => (string) (float) $tokens['radius']['card'],
    ];
  }

  /**
   * The helper writes the flat file's radius over socialblue's stock values.
   */
  public function testRadiusEqualsFlatTokenFile(): void {
    $stock = $this->config('socialblue.settings');
    // Precondition: stock socialblue ships 10 / 5 / 10, so a no-op helper
    // cannot pass the assertions below by accident.
    $this->assertSame('10', (string) $stock->get('button_radius'));
    $this->assertSame('5', (string) $stock->get('form_control_radius'));
    $this->assertSame('10', (string) $stock->get('card_radius'));

    _localnodes_platform_configure_theme_shape();

    $want = $this->flatRadius();
    $this->assertNotSame('10', $want['control'], 'Token control radius differs from stock, so the test discriminates.');
    $settings = $this->config('socialblue.settings');
    $this->assertSame($want['control'], (string) $settings->get('button_radius'));
    $this->assertSame($want['control'], (string) $settings->get('form_control_radius'));
    $this->assertSame($want['card'], (string) $settings->get('card_radius'));

    // Idempotent: a second call changes nothing.
    _localnodes_platform_configure_theme_shape();
    $this->assertSame($want['card'], (string) $this->config('socialblue.settings')->get('card_radius'));
  }

}
