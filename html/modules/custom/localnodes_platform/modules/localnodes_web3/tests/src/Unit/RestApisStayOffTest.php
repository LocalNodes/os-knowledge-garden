<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Neither localnodes_platform nor localnodes_web3 turns on REST or JSON:API.
 *
 * The signature guard covers the fund and vote modules' own routes and
 * Group's relationship pages. The write routes that core's REST and JSON:API
 * modules generate for entity types, safe_account among them (PATCH and
 * DELETE on one account, POST to the collection), are not covered: a
 * session that was never proven could change a Safe account through them.
 * Until the guard recognises those generated routes, the distribution must
 * not enable either module, through a dependency or through install config.
 *
 * @group localnodes_web3
 */
final class RestApisStayOffTest extends TestCase {

  /**
   * The modules that generate entity write routes the guard does not cover.
   */
  private const OFF = ['rest', 'jsonapi'];

  /**
   * Module name => module directory.
   *
   * @return array<string, string>
   */
  private static function modules(): array {
    return [
      'localnodes_web3' => dirname(__DIR__, 3),
      'localnodes_platform' => dirname(__DIR__, 5),
    ];
  }

  /**
   * No .info.yml dependency on either module.
   */
  public function testNoDependency(): void {
    foreach (self::modules() as $name => $directory) {
      $info = Yaml::decode((string) file_get_contents($directory . '/' . $name . '.info.yml'));
      $this->assertIsArray($info, $name);
      $this->assertNotEmpty($info['dependencies'] ?? [], $name . ' declares dependencies; the file was read.');
      foreach ($info['dependencies'] as $dependency) {
        $module = preg_replace('/\s.*$/s', '', substr(strrchr(':' . $dependency, ':'), 1));
        $this->assertNotContains($module, self::OFF, "$name depends on $dependency.");
      }
    }
  }

  /**
   * No install or optional config that enables, belongs to or needs either.
   */
  public function testNoInstallConfig(): void {
    foreach (self::modules() as $name => $directory) {
      $files = array_merge(
        glob($directory . '/config/install/*.yml') ?: [],
        glob($directory . '/config/optional/*.yml') ?: [],
      );
      $this->assertNotEmpty($files, $name . ' ships install config; the directory was read.');
      foreach ($files as $file) {
        $label = $name . ': ' . basename($file);
        $owner = explode('.', basename($file))[0];
        $this->assertNotContains($owner, self::OFF, $label . ' is config of that module.');
        $config = Yaml::decode((string) file_get_contents($file));
        $this->assertIsArray($config, $label);
        if (basename($file) === 'core.extension.yml') {
          foreach (self::OFF as $module) {
            $this->assertArrayNotHasKey($module, $config['module'] ?? [], $label . ' enables ' . $module . '.');
          }
        }
        $needs = array_merge($config['dependencies']['module'] ?? [], $config['dependencies']['enforced']['module'] ?? []);
        foreach (self::OFF as $module) {
          $this->assertNotContains($module, $needs, $label . ' needs ' . $module . '.');
        }
      }
    }
  }

}
