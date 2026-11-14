<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * The search pill in the top bar.
 *
 * The module's block--system-menu-block--main--localnodes--sky.html.twig is
 * socialbase's main menu block with the round search icon replaced by a pill
 * that names what the search does. If an Open Social update changes the stock
 * menu block, the first test fails: copy the new file into the module and put
 * the pill in again.
 *
 * socialblue cannot boot in a kernel test, so the template is read as a file
 * here; tests/a11y/search-pill.spec.ts checks it on the real theme.
 *
 * @group localnodes_platform
 */
final class SearchPillTest extends KernelTestBase {

  /**
   * The stock main menu block (relative to the app root).
   */
  private const STOCK = 'profiles/contrib/social/themes/socialbase/templates/block/block--system-menu-block--main.html.twig';

  /**
   * The module's main menu block.
   */
  private const MODULE = 'modules/custom/localnodes_platform/templates/block/block--system-menu-block--main--localnodes--sky.html.twig';

  /**
   * The stock search control: a round icon link with a tooltip.
   */
  private const STOCK_CONTROL = <<<'TWIG'
    <div class="navbar__open-search-control">
      <a href="{{ '/search/all' }}" class="navbar__open-search-block" rel="search" title="{{ 'Click to open search box'|t }}">
        <svg class="navbar-nav__icon navbar-nav__icon--search" aria-hidden="true">
          <title>{{ 'Search'|t }}</title>
          <use xlink:href="#icon-search"></use>
        </svg>
      </a>
    </div>

TWIG;

  /**
   * The pill: the same link, now with its label as visible text.
   */
  private const PILL_CONTROL = <<<'TWIG'
    <div class="navbar__open-search-control ln-search-pill-control">
      <a href="{{ '/search/all' }}" class="navbar__open-search-block ln-search-pill" rel="search">
        <svg class="navbar-nav__icon navbar-nav__icon--search" aria-hidden="true">
          <use xlink:href="#icon-search"></use>
        </svg>
        <span class="ln-search-pill__label">{{ ln_search_label }}</span>
      </a>
    </div>

TWIG;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'localnodes_platform'];

  /**
   * The module's copy is the stock block with only the search control changed.
   */
  public function testModuleBlockIsTheStockBlockWithThePill(): void {
    $stock = file_get_contents($this->root . '/' . self::STOCK);
    $this->assertSame(1, substr_count($stock, self::STOCK_CONTROL), 'socialbase\'s main menu block still has its round search control.');
    $this->assertSame(str_replace(self::STOCK_CONTROL, self::PILL_CONTROL, $stock), file_get_contents($this->root . '/' . self::MODULE));
  }

  /**
   * The main menu block, and only it, takes the module's suggestion.
   */
  public function testOnlyTheMainMenuTakesTheSuggestion(): void {
    $suggestions = ['block__system_menu_block', 'block__system_menu_block__main'];
    localnodes_platform_theme_suggestions_block_alter($suggestions, ['elements' => ['#plugin_id' => 'system_menu_block:main']]);
    $this->assertSame(['block__system_menu_block', 'block__system_menu_block__main', 'block__system_menu_block__main__localnodes'], $suggestions);

    foreach (['system_menu_block:account', 'system_menu_block:footer', 'search_content_block'] as $plugin_id) {
      $suggestions = ['block__x'];
      localnodes_platform_theme_suggestions_block_alter($suggestions, ['elements' => ['#plugin_id' => $plugin_id]]);
      $this->assertSame(['block__x'], $suggestions, $plugin_id);
    }
  }

  /**
   * The sky template is registered from the module, on the block hook.
   */
  public function testSkyTemplateIsRegistered(): void {
    $hooks = localnodes_platform_theme([], 'module', 'localnodes_platform', 'modules/custom/localnodes_platform');
    $hook = $hooks['block__system_menu_block__main__localnodes__sky'];
    $this->assertSame('block', $hook['base hook']);
    $this->assertFileExists($this->root . '/' . $hook['path'] . '/' . $hook['template'] . '.html.twig');
  }

  /**
   * The label promises an answer only to someone who gets one.
   *
   * The search page's AI answer needs "use ai overview"; anonymous visitors
   * do not have it, so their pill says only "Search".
   */
  public function testLabelFollowsTheAnswerPermission(): void {
    foreach ([TRUE => 'Search or ask a question', FALSE => 'Search'] as $may_ask => $label) {
      $variables = $this->preprocess('system_menu_block:main', (bool) $may_ask);
      $this->assertSame($label, (string) $variables['ln_search_label']['#markup']);
      $this->assertContains('user.permissions', $variables['ln_search_label']['#cache']['contexts']);
    }
  }

  /**
   * Other blocks get no label.
   */
  public function testOtherBlocksGetNoLabel(): void {
    $this->assertArrayNotHasKey('ln_search_label', $this->preprocess('system_menu_block:account', TRUE));
  }

  /**
   * Runs the block preprocess for a viewer who may or may not get an answer.
   */
  private function preprocess(string $plugin_id, bool $may_ask): array {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(fn (string $permission) => $may_ask && $permission === 'use ai overview');
    $this->container->get('current_user')->setAccount($account);
    $variables = ['elements' => ['#plugin_id' => $plugin_id]];
    localnodes_platform_preprocess_block($variables);
    return $variables;
  }

}
