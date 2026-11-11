<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * The big_pipe decision (D-25) is shipped where the notes say it is.
 *
 * Phase 22.2 plan 16 decides whether localnodes_platform turns core's big_pipe
 * module on. The decision is one line in the phase notes ("Decision: on" or
 * "Decision: off" under "## big_pipe (D-25)"). This test reads that line and
 * checks the source agrees:
 * - on: localnodes_platform.info.yml lists drupal:big_pipe, so every fresh
 *   install and every `drush en localnodes_platform` enables it.
 * - off: it is listed nowhere.
 * Either way big_pipe is never enabled from docker/entrypoint.sh or from a
 * custom install or update hook (CLAUDE.md: app configuration lives in module
 * install config, and there are no existing installs to update).
 *
 * @group phase222
 * @group localnodes_platform
 */
final class BigPipeDecisionTest extends UnitTestCase {

  private const NOTES = '.planning/phases/22.2-open-social-13-1-upgrade/22.2-NOTES.md';

  private const INFO = 'html/modules/custom/localnodes_platform/localnodes_platform.info.yml';

  /**
   * The repository root (the Drupal root is html/).
   */
  private function repoRoot(): string {
    return dirname($this->root);
  }

  /**
   * Reads the one `Decision:` line under the big_pipe heading of the notes.
   *
   * The section runs from "## big_pipe (D-25)" to the next "## " heading, so
   * its "###" subheadings belong to it.
   *
   * @return string
   *   'on' or 'off'.
   */
  private function decision(): string {
    $path = $this->repoRoot() . '/' . self::NOTES;
    $this->assertFileExists($path, 'The phase notes are missing.');
    $in_section = FALSE;
    $found = [];
    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
      $line = rtrim($line, "\r");
      if (str_starts_with($line, '## ')) {
        $in_section = ($line === '## big_pipe (D-25)');
        continue;
      }
      if ($in_section && preg_match('/^Decision: (on|off)$/', $line, $m)) {
        $found[] = $m[1];
      }
    }
    $this->assertCount(1, $found, 'The notes need exactly one "Decision: on" or "Decision: off" line under "## big_pipe (D-25)".');
    return $found[0];
  }

  /**
   * The dependencies of localnodes_platform.
   *
   * @return string[]
   *   The dependency strings as written in the info file.
   */
  private function dependencies(): array {
    $path = $this->repoRoot() . '/' . self::INFO;
    $this->assertFileExists($path);
    $info = Yaml::decode((string) file_get_contents($path));
    return array_map('strval', (array) ($info['dependencies'] ?? []));
  }

  /**
   * The info file matches the recorded decision.
   */
  public function testInfoFileMatchesDecision(): void {
    $decision = $this->decision();
    $has = in_array('drupal:big_pipe', $this->dependencies(), TRUE);
    if ($decision === 'on') {
      $this->assertTrue($has, 'The decision is on, so localnodes_platform.info.yml must list drupal:big_pipe in its dependencies.');
    }
    else {
      $this->assertFalse($has, 'The decision is off, so localnodes_platform.info.yml must not list big_pipe.');
    }
  }

  /**
   * No other custom module's info file lists big_pipe under either decision.
   *
   * The install config belongs to localnodes_platform, so one place decides.
   */
  public function testNoOtherCustomModuleDependsOnIt(): void {
    $root = $this->root . '/modules/custom';
    $this->assertDirectoryExists($root);
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    );
    $read = 0;
    $violations = [];
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if (!str_ends_with($path, '.info.yml') || str_ends_with($path, '/localnodes_platform/localnodes_platform.info.yml')) {
        continue;
      }
      $read++;
      $info = Yaml::decode((string) file_get_contents($path));
      foreach ((array) ($info['dependencies'] ?? []) as $dependency) {
        if (preg_match('/(^|:)big_pipe$/', (string) $dependency)) {
          $violations[] = substr($path, strlen($this->repoRoot()) + 1) . ' lists ' . $dependency;
        }
      }
    }
    $this->assertGreaterThanOrEqual(4, $read, 'The scan read too few .info.yml files to count.');
    $this->assertSame([], $violations, implode("\n", $violations));
  }

  /**
   * docker/entrypoint.sh never enables big_pipe, whatever the decision.
   */
  public function testEntrypointDoesNotEnableIt(): void {
    $path = $this->repoRoot() . '/docker/entrypoint.sh';
    $this->assertFileExists($path);
    $code = [];
    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
      if (preg_match('/^\s*#/', $line) !== 1) {
        $code[] = $line;
      }
    }
    $this->assertStringNotContainsString('big_pipe', implode("\n", $code), 'docker/entrypoint.sh must not mention big_pipe outside a comment.');
  }

  /**
   * No custom install, update or module file enables it either.
   */
  public function testNoInstallOrUpdateHookEnablesIt(): void {
    $root = $this->root . '/modules/custom';
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    );
    $read = 0;
    $violations = [];
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if (!preg_match('/\.(install|module)$/', $path) || str_contains($path, '/tests/')) {
        continue;
      }
      $read++;
      if (str_contains((string) file_get_contents($path), 'big_pipe')) {
        $violations[] = substr($path, strlen($this->repoRoot()) + 1);
      }
    }
    $this->assertGreaterThanOrEqual(4, $read, 'The scan read too few .install and .module files to count.');
    $this->assertSame([], $violations, "These files mention big_pipe:\n" . implode("\n", $violations));
  }

}
