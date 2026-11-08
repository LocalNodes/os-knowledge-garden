<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Our code never switches on the Open Social 13.1 features that stay off.
 *
 * Phase 22.2 criterion 3. Open Social 13.1 ships five things we keep off on
 * purpose: BigBlueButton meetings, user segments, the event stream /
 * webhooks, the GraphQL 5 API and Open Social's own AI ("Gaia"). The reasons
 * are in the phase notes (22.2-NOTES.md, "Stays off (criterion 3)"). None is
 * on after a fresh install today; plan 22.2-05 checks that on the lane with
 * drush pm:list. This test stops a later change in our code from turning
 * one on: no custom module may depend on them, and docker/entrypoint.sh may
 * not enable them. GraphQL with graphql_oauth would also open an
 * unauthenticated API surface (threat T-22.2-07).
 *
 * No custom .install or .module file calls the module installer today, so
 * install hooks are covered by the dependency scan plus that fact (recorded
 * in the 22.2-03 summary).
 *
 * @group phase222
 * @group localnodes_platform
 */
final class StaysOffTest extends UnitTestCase {

  /**
   * Machine names that stay off.
   */
  private const OFF_MODULES = [
    'meeting_api_bbb',
    'meeting_api_bbb_recordings',
    'meeting_api_scheduler',
    'social_eda',
    'social_graphql',
    'graphql',
    'graphql_oauth',
    'user_segments',
    'gaia_vector_index',
  ];

  /**
   * The fewest custom .info.yml files the scan must read to count.
   */
  private const MIN_INFO_FILES = 5;

  /**
   * No custom module (or test module) depends on a stays-off module.
   */
  public function testNoCustomModuleDependsOnThem(): void {
    $root = $this->root . '/modules/custom';
    $this->assertDirectoryExists($root);

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    );
    $read = 0;
    $violations = [];
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if (!str_ends_with($path, '.info.yml')) {
        continue;
      }
      $info = Yaml::decode((string) file_get_contents($path));
      $read++;
      foreach ((array) ($info['dependencies'] ?? []) as $dependency) {
        $name = $this->machineName((string) $dependency);
        if (in_array($name, self::OFF_MODULES, TRUE)) {
          $violations[] = sprintf('%s depends on %s', $this->relative($path), $dependency);
        }
      }
    }

    $this->assertGreaterThanOrEqual(self::MIN_INFO_FILES, $read, sprintf('Only %d .info.yml files were read under %s; the scan found too little to count.', $read, $root));
    $this->assertSame([], $violations, implode("\n", $violations));
  }

  /**
   * docker/entrypoint.sh never enables a stays-off module.
   */
  public function testEntrypointNeverEnablesThem(): void {
    $path = dirname($this->root) . '/docker/entrypoint.sh';
    $this->assertFileExists($path, sprintf('The entrypoint was not found at %s.', $path));

    $enable_lines = 0;
    $violations = [];
    foreach (file($path) ?: [] as $number => $line) {
      // Comments can't enable anything.
      if (preg_match('/^\s*#/', $line)) {
        continue;
      }
      // A drush call ($DRUSH, ${DRUSH} or drush) that enables modules.
      if (!preg_match('/(?:\$\{?DRUSH\}?|\bdrush)\b.*?\s(?:en|pm:install|pm-install|pm:enable|pm-enable)\s+(.*)$/', $line, $matches)) {
        continue;
      }
      $enable_lines++;
      foreach (self::OFF_MODULES as $module) {
        if (preg_match('/(?<![\w-])' . preg_quote($module, '/') . '(?![\w-])/', $matches[1])) {
          $violations[] = sprintf('docker/entrypoint.sh line %d enables %s: %s', $number + 1, $module, trim($line));
        }
      }
    }

    $this->assertGreaterThan(0, $enable_lines, 'No drush en / pm:install line was found in docker/entrypoint.sh; the scan found nothing to check.');
    $this->assertSame([], $violations, implode("\n", $violations));
  }

  /**
   * The module machine name in a dependencies entry.
   *
   * "drupal:graphql", "graphql:graphql_oauth" and "social_graphql (>=1.0)"
   * all reduce to the part after the last colon, without a version
   * constraint. The comparison is exact, so social_graphql is not graphql.
   */
  private function machineName(string $dependency): string {
    $name = trim(preg_replace('/\(.*\)/', '', $dependency) ?? $dependency);
    $colon = strrpos($name, ':');
    return $colon === FALSE ? $name : substr($name, $colon + 1);
  }

  /**
   * A path relative to the Drupal root, for failure messages.
   */
  private function relative(string $path): string {
    return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
  }

}
