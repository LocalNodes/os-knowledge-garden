<?php

declare(strict_types=1);

namespace Drupal\Tests\neighbors_demo\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\localnodes_platform\CommonsName;
use Drupal\neighbors_demo\SiteName;
use Drupal\Tests\UnitTestCase;

/**
 * The neighbors demo names no real town, speaks plainly, has one name.
 *
 * @group neighbors_demo
 */
final class NeighborsContentTest extends UnitTestCase {

  /**
   * Words a visitor must not read here: the chain and wallet vocabulary.
   *
   * Matched as whole words, so a bicycle chain is fine.
   */
  private const JARGON = [
    'wallet', 'wallets', 'crypto', 'cryptocurrency', 'blockchain', 'on-chain',
    'onchain', 'ethereum', 'eth', 'web3', 'dao', 'daos', 'multisig', 'sepolia',
    'siwe', 'nft', 'nfts', 'token', 'tokens', 'gas', 'signer', 'signers',
    'smart account', 'safe account', 'treasury', 'quadratic',
  ];

  /**
   * The module's directory.
   */
  private function moduleDir(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Every content file, parsed and with the site name filled in.
   *
   * @return array<string, array>
   *   Parsed content keyed by file name.
   */
  private function content(): array {
    $content = [];
    foreach (glob($this->moduleDir() . '/content/entity/*.yml') as $file) {
      $data = Yaml::decode((string) file_get_contents($file));
      $content[basename($file)] = SiteName::fill(is_array($data) ? $data : []);
    }
    $this->assertNotEmpty($content);
    return $content;
  }

  /**
   * Every string in the content, with where it is.
   *
   * @return array<string, string>
   *   Text keyed by file and path.
   */
  private function strings(): array {
    $strings = [];
    $walk = function (array $data, string $at) use (&$walk, &$strings): void {
      foreach ($data as $key => $value) {
        if (is_array($value)) {
          $walk($value, "$at/$key");
        }
        elseif (is_string($value)) {
          $strings["$at/$key"] = $value;
        }
      }
    };
    foreach ($this->content() as $file => $data) {
      $walk($data, $file);
    }
    return $strings;
  }

  /**
   * No file of the module says Boulder, a town near it, Colorado or its postcode.
   *
   * The tests are left out: this one names what it looks for.
   */
  public function testNoBoulder(): void {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->moduleDir(), \FilesystemIterator::SKIP_DOTS));
    $found = [];
    foreach ($iterator as $file) {
      if (!in_array($file->getExtension(), ['yml', 'php', 'install', 'module'], TRUE) || str_contains($file->getPathname(), '/tests/')) {
        continue;
      }
      $text = (string) file_get_contents($file->getPathname());
      // The profiles' timezone is America/Denver; that is a clock, not text
      // a visitor reads as a place.
      $text = str_replace('America/Denver', '', $text);
      if (preg_match('/boulder|colorado|denver|front range|longmont|louisville|nederland|lafayette|\b803\d\d\b/i', $text) || preg_match('/\bCO\b/', $text)) {
        $found[] = substr($file->getPathname(), strlen($this->moduleDir()) + 1);
      }
    }
    $this->assertSame([], $found, 'These files still name Boulder.');
    $this->assertDoesNotMatchRegularExpression('/boulder/i', SiteName::NAME . ' ' . SiteName::SLOGAN);
  }

  /**
   * No text a visitor reads uses chain names or wallet words.
   */
  public function testNoJargon(): void {
    $pattern = '/\b(' . implode('|', array_map(fn ($word) => preg_quote($word, '/'), self::JARGON)) . ')\b/i';
    $found = [];
    foreach ($this->strings() as $at => $text) {
      if (preg_match($pattern, strip_tags($text), $match)) {
        $found[] = "$at: {$match[0]}";
      }
    }
    $this->assertSame([], $found);
  }

  /**
   * The site name is written once, in SiteName, and the content uses it.
   */
  public function testSiteNameInOnePlace(): void {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->moduleDir(), \FilesystemIterator::SKIP_DOTS));
    $places = 0;
    foreach ($iterator as $file) {
      if (in_array($file->getExtension(), ['yml', 'php', 'install', 'module'], TRUE)) {
        $places += substr_count((string) file_get_contents($file->getPathname()), SiteName::NAME);
      }
    }
    $this->assertSame(1, $places, 'The site name is written in SiteName only.');

    $groups = $this->content()['group.yml'];
    $this->assertContains(CommonsName::of(SiteName::NAME), array_column($groups, 'label'), 'The main group is the site\'s Commons.');
    foreach ($this->strings() as $at => $text) {
      $this->assertStringNotContainsString(SiteName::PLACEHOLDER, $text, "$at keeps an unfilled placeholder.");
      $this->assertStringNotContainsString(SiteName::COMMONS_PLACEHOLDER, $text, "$at keeps an unfilled placeholder.");
      $this->assertStringNotContainsString('{{', $text, "$at has a placeholder SiteName does not fill.");
    }
  }

  /**
   * Fill puts the Commons name in place of its placeholder, from the rule.
   */
  public function testFillCommons(): void {
    $this->assertSame(
      ['a' => 'Open ' . CommonsName::of(SiteName::NAME) . ' on ' . SiteName::NAME],
      SiteName::fill(['a' => 'Open ' . SiteName::COMMONS_PLACEHOLDER . ' on ' . SiteName::PLACEHOLDER]),
    );
  }

  /**
   * Fill puts the name in place of each placeholder, nested or not.
   */
  public function testFill(): void {
    $this->assertSame(
      ['a' => 'Hi ' . SiteName::NAME, 'b' => ['c' => SiteName::NAME], 'n' => 3],
      SiteName::fill(['a' => 'Hi ' . SiteName::PLACEHOLDER, 'b' => ['c' => SiteName::PLACEHOLDER], 'n' => 3]),
    );
  }

}
