<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Every shipped index on an AI search server indexes at most 8 items a pass.
 *
 * Why (22-26 live check, 2026-11-05): a free Gemini key allows 100 embedding
 * requests a minute. drupal/ai's AiVdbProviderClientBase::indexItems()
 * deletes a batch's old vectors, then embeds item by item and catches only
 * AiUnsafePromptException, so the first 429 aborts the whole batch and none
 * of its items is marked indexed. At cron_limit 50 (about 130 embedding
 * requests with contextual_chunks) every worker pass stalled; batches of 8,
 * 65 s apart, finished.
 *
 * The test discovers the indexes instead of naming them: it reads every
 * search_api server YAML shipped in module and profile config, keeps those
 * on the search_api_ai_search backend, then checks every index YAML whose
 * server is one of them.
 *
 * @group phase22
 * @group localnodes_platform
 */
final class AiIndexCronLimitTest extends UnitTestCase {

  /**
   * The highest cron_limit a free-tier key can index in one pass.
   */
  private const MAX_CRON_LIMIT = 8;

  /**
   * Shipped index YAMLs on an AI search server have cron_limit 1 to 8.
   */
  public function testAiIndexesCapCronLimit(): void {
    $files = $this->shippedConfigFiles();

    $ai_servers = [];
    foreach ($files as $path) {
      if (str_starts_with(basename($path), 'search_api.server.')) {
        $server = Yaml::decode((string) file_get_contents($path));
        if (($server['backend'] ?? NULL) === 'search_api_ai_search') {
          $ai_servers[$server['id']] = $path;
        }
      }
    }
    $this->assertNotEmpty($ai_servers, 'No shipped search_api server uses the search_api_ai_search backend; the scan found nothing to check.');

    $checked = [];
    $violations = [];
    foreach ($files as $path) {
      if (!str_starts_with(basename($path), 'search_api.index.')) {
        continue;
      }
      $index = Yaml::decode((string) file_get_contents($path));
      if (!isset($ai_servers[$index['server'] ?? ''])) {
        continue;
      }
      $checked[$index['id']] = $path;
      $limit = $index['options']['cron_limit'] ?? NULL;
      if (!is_int($limit) || $limit < 1 || $limit > self::MAX_CRON_LIMIT) {
        $violations[] = sprintf('%s: options.cron_limit is %s, expected 1 to %d', $this->relative($path), var_export($limit, TRUE), self::MAX_CRON_LIMIT);
      }
    }

    // A floor on discovery, not the check itself: the library's Topics (and
    // their files' text, via file_content_extractor) live in social_posts.
    $this->assertArrayHasKey('social_posts', $checked, 'social_posts was not found on an AI search server.');
    $this->assertArrayHasKey('social_comments', $checked, 'social_comments was not found on an AI search server.');
    $this->assertSame([], $violations, implode("\n", $violations));
  }

  /**
   * Every config/install and config/optional YAML in custom modules and profiles.
   *
   * @return string[]
   *   Absolute paths, sorted.
   */
  private function shippedConfigFiles(): array {
    $paths = [];
    foreach (['modules/custom', 'profiles'] as $dir) {
      $root = $this->root . '/' . $dir;
      if (!is_dir($root)) {
        continue;
      }
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
      );
      foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (!str_ends_with($path, '.yml') || str_contains($path, '/tests/')) {
          continue;
        }
        if (!preg_match('#/config/(install|optional)/search_api\.(server|index)\.[^/]+\.yml$#', $path)) {
          continue;
        }
        $paths[] = $path;
      }
    }
    sort($paths);
    return $paths;
  }

  /**
   * A path relative to the Drupal root, for failure messages.
   */
  private function relative(string $path): string {
    return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
  }

}
