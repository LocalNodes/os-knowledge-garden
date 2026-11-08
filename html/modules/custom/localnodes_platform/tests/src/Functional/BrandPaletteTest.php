<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\BrowserTestBase;

/**
 * The brand palette hook generates socialblue's CSS on color 2.0.0-alpha1.
 *
 * Phase 22.2 criterion 4. _localnodes_platform_apply_brand_palette() in
 * localnodes_platform.install recolours socialblue by calling drupal/color's
 * color_scheme_form_submit() with the palette shipped in
 * config/optional/color.theme.socialblue.yml. Phase 22 (22-05) checked that
 * by hand with drush php:eval; nothing tested it. Open Social 13.1 brings
 * drupal/color 2.0.0-alpha1 (from 1.0.3), so this test proves the hook on the
 * colour release we now run: the stylesheets exist, the generated brand.css
 * carries our brand-primary and not socialblue's stock blue, and a second
 * call rewrites nothing.
 *
 * Why Functional, not Kernel: socialblue is a theme inside the social
 * profile. A Kernel test would need a custom theme extension list to discover
 * profile themes; installing the social profile boots socialblue for real
 * (as OverviewFunctionalTestBase does).
 *
 * localnodes_platform itself is not installed: it pulls in the AI, Gemini and
 * Qdrant stack. Its .install file is loaded by path and the helper is called
 * directly, as hook_install calls it.
 *
 * Runs in the DDEV web container (scripts/run-phpunit.sh, PHPUNIT_MODE=web),
 * in lane fresh3-p222 only (D-08).
 *
 * @group localnodes_platform
 * @group phase222
 * @group phase22_functional
 */
final class BrandPaletteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   *
   * Open Social's shipped config carries third-party settings from modules
   * this test doesn't enable (as in OverviewFunctionalTestBase).
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   *
   * The social profile already depends on color; named here because the
   * helper returns early without it.
   */
  protected static $modules = ['color'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to the DDEV Solr prints its response; PHPUnit would fail the
    // test for unexpected output (as OverviewFunctionalTestBase does).
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }

    $path = $this->container->get('extension.list.module')->getPath('localnodes_platform');
    require_once DRUPAL_ROOT . '/' . $path . '/localnodes_platform.install';
  }

  /**
   * The hook writes brand CSS, and a second call leaves the files alone.
   */
  public function testPaletteGeneratesBrandCss(): void {
    _localnodes_platform_apply_brand_palette();

    // The expected colour comes from the palette we ship, not a literal.
    $shipped_path = DRUPAL_ROOT . '/'
      . $this->container->get('extension.list.module')->getPath('localnodes_platform')
      . '/config/optional/color.theme.socialblue.yml';
    $shipped = Yaml::decode((string) file_get_contents($shipped_path));
    $brand_primary = $shipped['palette']['brand-primary'] ?? '';
    $this->assertNotSame('', $brand_primary, 'color.theme.socialblue.yml ships a brand-primary value.');

    // socialblue's stock primary, read from its color.inc default scheme.
    $this->container->get('module_handler')->loadInclude('color', 'module');
    $info = color_get_info('socialblue');
    $stock_primary = $info['schemes']['default']['colors']['brand-primary'] ?? '';
    $this->assertNotSame('', $stock_primary, 'socialblue color.inc has a default brand-primary.');
    $this->assertNotSame(strtolower($stock_primary), strtolower($brand_primary), 'Our brand-primary differs from the stock one.');

    $config = $this->config('color.theme.socialblue');
    $this->assertSame($shipped['palette'], $config->get('palette'), 'The shipped palette is saved on color.theme.socialblue.');

    $stylesheets = $config->get('stylesheets') ?: [];
    $this->assertNotEmpty($stylesheets, 'color.theme.socialblue:stylesheets lists generated files.');

    $realpaths = [];
    foreach ($stylesheets as $uri) {
      $realpath = $this->realpath($uri);
      $this->assertFileExists($realpath, sprintf('Generated stylesheet %s exists.', $uri));
      $realpaths[$uri] = $realpath;
    }

    // The file that replaces socialblue's assets/css/brand.css.
    $brand_css = NULL;
    foreach ($realpaths as $uri => $realpath) {
      if (basename($uri) === 'brand.css') {
        $brand_css = $realpath;
      }
    }
    $this->assertNotNull($brand_css, 'A generated brand.css is listed in the stylesheets.');
    $css = (string) file_get_contents($brand_css);
    $this->assertNotFalse(stripos($css, $brand_primary), sprintf('Generated brand.css contains brand-primary %s.', $brand_primary));
    $this->assertFalse(stripos($css, $stock_primary), sprintf('Generated brand.css no longer contains the stock primary %s.', $stock_primary));

    // Idempotence. Back-date every file by an hour so any rewrite shows,
    // whatever the file system's timestamp resolution, and record its hash.
    $backdated = time() - 3600;
    $hashes = [];
    foreach ($realpaths as $uri => $realpath) {
      $this->assertTrue(touch($realpath, $backdated), sprintf('Back-dated %s.', $uri));
      $hashes[$uri] = hash_file('sha256', $realpath);
    }
    clearstatcache();

    _localnodes_platform_apply_brand_palette();
    clearstatcache();

    // A regeneration goes to a new directory, so the path list catches it
    // first; then each file must still exist, untouched.
    $this->assertSame($stylesheets, $this->config('color.theme.socialblue')->get('stylesheets'), 'A second call keeps the same stylesheet paths.');
    foreach ($realpaths as $uri => $realpath) {
      $this->assertFileExists($realpath, sprintf('%s still exists after a second call.', $uri));
      $this->assertSame($backdated, filemtime($realpath), sprintf('%s was not rewritten by a second call (mtime unchanged).', $uri));
      $this->assertSame($hashes[$uri], hash_file('sha256', $realpath), sprintf('%s content is unchanged after a second call.', $uri));
    }
  }

  /**
   * The local path of a stream wrapper URI such as public://color/...
   */
  private function realpath(string $uri): string {
    $realpath = $this->container->get('file_system')->realpath($uri);
    $this->assertIsString($realpath, sprintf('%s resolves to a local path.', $uri));
    return $realpath;
  }

}
