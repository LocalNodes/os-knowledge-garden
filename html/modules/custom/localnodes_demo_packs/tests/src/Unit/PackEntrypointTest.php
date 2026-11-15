<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_platform\Demo\DemoPlugins;
use Drupal\Tests\UnitTestCase;

/**
 * The entrypoint loads the pack named by DEMO_PACK through this module.
 *
 * Reads docker/entrypoint.sh as text: the plugin ids and profile option it
 * passes to social-demo:add, the guard that vets DEMO_PACK before any module
 * is enabled, and the step that uninstalls the loader once the content is in.
 *
 * @group localnodes_demo_packs
 */
final class PackEntrypointTest extends UnitTestCase {

  private string $script;

  protected function setUp(): void {
    parent::setUp();
    $script = file_get_contents($this->repoRoot() . '/docker/entrypoint.sh');
    $this->assertNotFalse($script);
    $this->script = $script;
  }

  /**
   * The platform's plugin list for this module is exactly its plugins.
   *
   * The entrypoint loads what drush localnodes-demo:plugins prints, which is
   * DemoPlugins::ids().
   */
  public function testListIsExactlyThePluginsTheModuleDefines(): void {
    $loaded = DemoPlugins::ids('localnodes_demo_packs');
    $defined = [];
    foreach (glob(dirname(__DIR__, 3) . '/src/Plugin/DemoContent/*.php') as $file) {
      if (preg_match('/^\s*\*\s*id = "(pack_\w+)"/m', (string) file_get_contents($file), $id)) {
        $defined[] = $id[1];
      }
    }
    sort($loaded);
    sort($defined);
    $this->assertCount(13, $defined);
    $this->assertSame($defined, $loaded);
  }

  /**
   * For this module only, the load passes the pack directory as profile.
   */
  public function testLoadPassesThePackProfile(): void {
    $lines = explode("\n", $this->script);
    $set = $this->firstLine($lines, '/^\s*if \[ "\$DEMO_MODULE" = "localnodes_demo_packs" \]; then DEMO_PROFILE_OPT="--profile=packs\/\$\{DEMO_PACK\}\/"; fi\s*$/');
    $load = $this->firstLine($lines, '/^\s*LOCALNODES_DEFER_INDEXING=1 \$DRUSH social-demo:add \$DEMO_PLUGINS \$DEMO_PROFILE_OPT\s*$/');
    $this->assertNotNull($set, 'The profile option is set for the packs module in a live statement.');
    $this->assertNotNull($load, 'The load passes the profile option.');
    $this->assertLessThan($load, $set);
    $this->assertSame(1, preg_match_all('/^\s*DEMO_PROFILE_OPT=/m', $this->script), 'Nothing else sets the option.');
  }

  /**
   * DEMO_PACK is vetted before the demo module is enabled.
   */
  public function testGuardRunsBeforeTheModuleIsEnabled(): void {
    $lines = explode("\n", $this->script);
    $guard = $this->firstLine($lines, '/^\s*if \[ "\$DEMO_MODULE" = "localnodes_demo_packs" \]; then check_demo_pack \|\| exit 1; fi\s*$/');
    $enable = $this->firstLine($lines, '/^\s*\$DRUSH en "\$DEMO_MODULE" -y\s*$/');
    $this->assertNotNull($guard, 'The guard is a live statement.');
    $this->assertNotNull($enable, 'The demo module is enabled.');
    $this->assertLessThan($enable, $guard, 'The guard runs before the module is enabled.');
    $this->assertMatchesRegularExpression('/if \[ "\$DEMO_MODULE" = "localnodes_demo_packs" \]/', $lines[$guard], 'The guard is for the packs module only.');
  }

  /**
   * Once the content is in, the loader and social_demo are uninstalled.
   *
   * social_demo's messenger mutes every status message while it is enabled.
   */
  public function testLoaderIsUninstalledAfterTheLoad(): void {
    $lines = explode("\n", $this->script);
    $enable = $this->firstLine($lines, '/^\s*\$DRUSH en "\$DEMO_MODULE" -y\s*$/');
    $load = NULL;
    foreach ($lines as $i => $line) {
      if ($i > $enable && preg_match('/^\s*load_demo_content\s*$/', $line)) {
        $load = $i;
      }
    }
    $uninstall = $this->firstLine($lines, '/^\s*\$DRUSH pm:uninstall localnodes_demo_packs social_demo -y\s*$/');
    $this->assertNotNull($load);
    $this->assertNotNull($uninstall, 'The entrypoint uninstalls the loader in a live statement.');
    $this->assertGreaterThan($load, $uninstall, 'The uninstall follows the last load call.');
    $this->assertMatchesRegularExpression('/^\s*if \[ "\$DEMO_MODULE" = "localnodes_demo_packs" \]; then\s*$/', $lines[$uninstall - 1], 'The uninstall is for the packs module only.');
    $this->assertMatchesRegularExpression('/^\s*fi\s*$/', $lines[$uninstall + 1] ?? '', 'The packs-only block closes after the uninstall.');
    $this->assertSame(1, substr_count($this->script, 'pm:uninstall localnodes_demo_packs'));
  }

  /**
   * A redeploy does not enable the loader again.
   */
  public function testRedeployDoesNotReenableTheLoader(): void {
    $this->assertSame(1, preg_match('/^\s*if (.*)\n\s*echo "Ensuring demo module enabled/m', $this->script, $m), 'The redeploy enable block is found.');
    $this->assertStringContainsString('"$DEMO_MODULE" != "localnodes_demo_packs"', $m[1]);
  }

  /**
   * The index of the first line matching a pattern, or NULL.
   */
  private function firstLine(array $lines, string $pattern): ?int {
    foreach ($lines as $i => $line) {
      if (preg_match($pattern, $line)) {
        return $i;
      }
    }
    return NULL;
  }

  private function repoRoot(): string {
    return dirname(__DIR__, 7);
  }

}
