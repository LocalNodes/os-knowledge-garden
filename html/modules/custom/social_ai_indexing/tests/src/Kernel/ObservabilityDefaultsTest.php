<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

/**
 * Prompt logging stays off by default (AI-SPEC E10, T-22-57).
 *
 * ai_observability is a DDEV-only tracer, never a platform dependency
 * (22-19 planner decision). Where it is enabled, log_input and log_output
 * must be FALSE after install: the contrib default already is, so this
 * guards that none of our install code, shipped config or settings.php
 * overrides switch them on.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class ObservabilityDefaultsTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai_observability',
    // localnodes_platform and the dependencies its container needs.
    'language',
    'link',
    'color',
    'modeler_api',
    'ai_agents',
    'gemini_provider',
    'ai_file_to_text',
    'ai_vdb_provider_qdrant',
    'search_api_solr',
    'localnodes_import',
    'localnodes_platform',
  ];

  /**
   * Our code: the custom modules (minus tests), settings.php, entrypoint.
   *
   * @return string[]
   *   Absolute file paths.
   */
  private function ourFiles(): array {
    $files = [
      $this->root . '/sites/default/settings.php',
      dirname($this->root) . '/docker/entrypoint.sh',
    ];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/modules/custom', \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if (str_contains($path, '/tests/') || !preg_match('/\.(php|module|install|inc|yml|sh)$/', $path)) {
        continue;
      }
      $files[] = $path;
    }
    return $files;
  }

  /**
   * After install, log_input and log_output are FALSE.
   */
  public function testPromptLoggingIsOffAfterInstall(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('localnodes_platform'));
    $this->assertTrue($this->container->get('module_handler')->moduleExists('ai_observability'));
    $this->installConfig(['ai_observability']);

    $settings = $this->config('ai_observability.settings');
    $this->assertFalse($settings->get('log_input'), 'log_input is FALSE.');
    $this->assertFalse($settings->get('log_output'), 'log_output is FALSE.');
    $this->assertFalse($settings->get('otel_spans_store_input'), 'OpenTelemetry spans store no input.');
    $this->assertFalse($settings->get('otel_spans_store_output'), 'OpenTelemetry spans store no output.');
  }

  /**
   * Nothing we ship touches ai_observability's settings.
   */
  public function testNothingWeShipTurnsLoggingOn(): void {
    $files = $this->ourFiles();
    $this->assertGreaterThan(20, count($files), 'The scan sees our code.');
    $this->assertFileExists($this->root . '/sites/default/settings.php');
    $this->assertFileExists(dirname($this->root) . '/docker/entrypoint.sh');
    foreach ($files as $path) {
      $this->assertStringNotContainsString('ai_observability', basename($path), "$path ships no ai_observability config.");
      $this->assertStringNotContainsString('ai_observability', (string) file_get_contents($path), "$path does not touch ai_observability.");
    }
  }

}
