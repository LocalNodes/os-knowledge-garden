<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Tests\safe_smart_accounts\Kernel\SafeKernelTestBase;

/**
 * LocalNodes styles reach the Phase 21 components from localnodes_web3 (D-04).
 *
 * The generic drupal.org modules (safe_smart_accounts, group_treasury) carry
 * no LocalNodes coupling: their components use stock socialbase classes and
 * structural CSS only. The LocalNodes-only touches (the Test network
 * banner's left rule and padding, the receipt/approval panel rules, a
 * neutral Short ID) ship in the localnodes_web3/treasury library, which
 * localnodes_web3 hangs off safe_smart_accounts/components with
 * hook_library_info_alter(). So every page that attaches the components
 * gets them, and the generic modules never name the library.
 *
 * localnodes_web3 is added with enableModules(), which loads its .module
 * without resolving its .info.yml dependencies (group_treasury and
 * social_group_treasury pull in Open Social, which a Kernel test can't
 * boot). The required platform parent is enabled alongside it; the base
 * fixture already enables siwe_login. Nothing here installs its config.
 *
 * @group localnodes_web3
 */
final class TreasuryStylesTest extends SafeKernelTestBase {

  /**
   * The library localnodes_web3 attaches.
   */
  private const TREASURY = 'localnodes_web3/treasury';

  /**
   * Without localnodes_web3 the generic library stays as it ships.
   */
  public function testNoTreasuryDependencyWithoutLocalnodesWeb3(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('localnodes_web3'));

    $components = $this->componentsLibrary();
    $this->assertNotContains(self::TREASURY, $components['dependencies'] ?? []);
  }

  /**
   * With localnodes_web3 enabled, the components library depends on it.
   */
  public function testLocalnodesWeb3AttachesTreasuryToComponents(): void {
    $this->enableLocalnodesWeb3();

    $components = $this->componentsLibrary();
    $this->assertContains(self::TREASURY, $components['dependencies'] ?? [], 'safe_smart_accounts/components depends on localnodes_web3/treasury.');
    // The generic library keeps its own dependencies.
    foreach (['core/drupal', 'core/once', 'core/drupal.announce'] as $dependency) {
      $this->assertContains($dependency, $components['dependencies']);
    }
  }

  /**
   * The treasury library carries treasury.css and the token library.
   */
  public function testTreasuryLibraryDefinition(): void {
    $this->enableLocalnodesWeb3();

    $treasury = $this->container->get('library.discovery')->getLibraryByName('localnodes_web3', 'treasury');
    $this->assertIsArray($treasury, 'localnodes_web3 defines a treasury library.');
    $css = array_column($treasury['css'] ?? [], 'data');
    $this->assertCount(1, $css);
    $this->assertStringEndsWith('localnodes_web3/css/treasury.css', $css[0]);
    $this->assertContains('localnodes_platform/tokens', $treasury['dependencies'] ?? [], 'The --ln-* tokens load wherever the treasury styles do.');
  }

  /**
   * Treasury.css adds no colour: every colour is an existing --ln-* token.
   *
   * 21-UI-SPEC Color: "Phase 21 adds no colour." No hex, rgb(), hsl() or
   * colour name; each var(--ln-…) it uses is defined in ln-tokens.css; the
   * banner rule uses the warning text token (22-UI-SPEC Part 1).
   */
  public function testTreasuryCssUsesOnlyExistingTokens(): void {
    $module_dir = dirname(__DIR__, 3);
    $file = $module_dir . '/css/treasury.css';
    $this->assertFileExists($file);

    $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file));
    $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b|\b(rgba?|hsla?|color-mix)\s*\(/', $css, 'treasury.css has no raw colour.');
    $this->assertDoesNotMatchRegularExpression('/(^|[\s:,])(white|black|red|green|blue|yellow|orange|gray|grey|pink|teal|amber)\b/i', $css, 'treasury.css has no colour name.');
    $this->assertStringContainsString('var(--ln-color-warning-text)', $css);

    $tokens_css = (string) file_get_contents(dirname($module_dir, 2) . '/css/ln-tokens.css');
    preg_match_all('/(--ln-[a-z0-9-]+)\s*:/', $tokens_css, $defined);
    preg_match_all('/var\(\s*(--[a-z0-9-]+)/', $css, $used);
    $this->assertNotEmpty($used[1]);
    foreach (array_unique($used[1]) as $token) {
      $this->assertStringStartsWith('--ln-', $token);
      $this->assertContains($token, $defined[1], "$token is defined in ln-tokens.css.");
    }
  }

  /**
   * Enables localnodes_web3 and drops the cached library definitions.
   */
  private function enableLocalnodesWeb3(): void {
    $this->enableModules(['localnodes_platform', 'localnodes_web3']);
    $this->container->get('library.discovery')->clearCachedDefinitions();
  }

  /**
   * The safe_smart_accounts/components library definition.
   */
  private function componentsLibrary(): array {
    $library = $this->container->get('library.discovery')->getLibraryByName('safe_smart_accounts', 'components');
    $this->assertIsArray($library, 'safe_smart_accounts defines the components library.');
    return $library;
  }

}
