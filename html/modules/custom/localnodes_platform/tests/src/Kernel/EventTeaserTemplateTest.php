<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\NodeInterface;

/**
 * The event card template is socialblue's, with the date tile line added.
 *
 * The module's node--event--teaser--localnodes--sky.html.twig is socialblue's
 * sky event teaser plus one line at the top of its body block. If an Open
 * Social update changes socialblue's event teaser, this fails: copy the new
 * file into the module and add the line again.
 *
 * socialblue cannot boot in a kernel test, so the template is read as a file
 * here; tests/a11y/event-date-tile.spec.ts checks it on the real theme.
 *
 * @group localnodes_platform
 */
final class EventTeaserTemplateTest extends KernelTestBase {

  /**
   * The stock socialblue sky event teaser (relative to the app root).
   */
  private const STOCK = 'profiles/contrib/social/themes/socialblue/templates/node/event/node--event--teaser--sky.html.twig';

  /**
   * The module's event teaser.
   */
  private const MODULE = 'modules/custom/localnodes_platform/templates/node/node--event--teaser--localnodes--sky.html.twig';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'localnodes_platform'];

  /**
   * The module's copy is the stock teaser with only the tile line added.
   */
  public function testModuleTeaserIsTheStockTeaserPlusOneLine(): void {
    $stock = file_get_contents($this->root . '/' . self::STOCK);
    $expected = str_replace("{% block card_body %}\n", "{% block card_body %}\n  {{ content.ln_event_date }}\n", $stock);
    $this->assertSame(1, substr_count($expected, 'content.ln_event_date'), 'socialblue\'s event teaser still has its body block.');
    $this->assertSame($expected, file_get_contents($this->root . '/' . self::MODULE));
  }

  /**
   * An event's teaser, and only it, takes the module's suggestion.
   */
  public function testOnlyTheEventTeaserTakesTheSuggestion(): void {
    $suggestions = ['node__teaser', 'node__event', 'node__event__teaser'];
    localnodes_platform_theme_suggestions_node_alter($suggestions, $this->elements('teaser', 'event'));
    $this->assertSame(['node__teaser', 'node__event', 'node__event__teaser', 'node__event__teaser__localnodes'], $suggestions);

    $others = [
      ['activity', 'event'],
      ['full', 'event'],
      ['small_teaser', 'event'],
      ['teaser', 'topic'],
    ];
    foreach ($others as [$view_mode, $bundle]) {
      $suggestions = ['node__' . $bundle];
      localnodes_platform_theme_suggestions_node_alter($suggestions, $this->elements($view_mode, $bundle));
      $this->assertSame(['node__' . $bundle], $suggestions, "$bundle $view_mode");
    }
  }

  /**
   * The sky template is registered from the module, on the node hook.
   */
  public function testSkyTemplateIsRegistered(): void {
    $hooks = localnodes_platform_theme([], 'module', 'localnodes_platform', 'modules/custom/localnodes_platform');
    $hook = $hooks['node__event__teaser__localnodes__sky'];
    $this->assertSame('node', $hook['base hook']);
    $this->assertFileExists($this->root . '/' . $hook['path'] . '/' . $hook['template'] . '.html.twig');
  }

  /**
   * The hook's variables for a node of a type in a view mode.
   */
  private function elements(string $view_mode, string $bundle): array {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    return ['elements' => ['#view_mode' => $view_mode, '#node' => $node]];
  }

}
