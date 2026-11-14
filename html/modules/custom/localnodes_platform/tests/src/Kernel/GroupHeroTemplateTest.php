<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\group\Entity\GroupInterface;

/**
 * The group header template is socialblue's, with two lines added.
 *
 * The module's group--hero--localnodes--sky.html.twig is socialblue's sky
 * header plus the one-line description under the group's name and the
 * buttons row under the header. If an Open Social update changes socialblue's header, this fails:
 * copy the new file into the module and add the two lines again.
 *
 * socialblue cannot boot in a kernel test, so the template is read as a file
 * here; tests/a11y/group-header.spec.ts checks it on the real theme.
 *
 * @group localnodes_platform
 */
final class GroupHeroTemplateTest extends KernelTestBase {

  /**
   * The stock socialblue sky header (relative to the app root).
   */
  private const STOCK = 'profiles/contrib/social/themes/socialblue/templates/group/group--hero--sky.html.twig';

  /**
   * The module's header.
   */
  private const MODULE = 'modules/custom/localnodes_platform/templates/group/group--hero--localnodes--sky.html.twig';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'localnodes_platform', 'localnodes_course_hero_test'];

  /**
   * The module's copy is the stock header with only the two lines added.
   */
  public function testModuleHeaderIsTheStockHeaderPlusTwoLines(): void {
    $stock = file_get_contents($this->root . '/' . self::STOCK);
    $expected = str_replace(
      ["      <h1>{{ content.label }}</h1>\n", "    </header>\n"],
      [
        "      <h1>{{ content.label }}</h1>\n      {{ content.ln_group_summary }}\n",
        "    </header>\n    {{ content.ln_group_actions }}\n",
      ],
      $stock,
    );
    $this->assertSame(2, substr_count($expected, 'content.ln_group_'), 'socialblue\'s header still has both places the lines go.');
    $this->assertSame($expected, file_get_contents($this->root . '/' . self::MODULE));
  }

  /**
   * The header view mode, and only it, takes the module's suggestion.
   */
  public function testOnlyTheHeaderTakesTheSuggestion(): void {
    $suggestions = ['group__hero'];
    localnodes_platform_theme_suggestions_group_alter($suggestions, self::elements('hero', $this->group('flexible_group')));
    $this->assertSame(['group__hero', 'group__hero__localnodes'], $suggestions);

    $suggestions = ['group__teaser'];
    localnodes_platform_theme_suggestions_group_alter($suggestions, self::elements('teaser', $this->group('flexible_group')));
    $this->assertSame(['group__teaser'], $suggestions);
  }

  /**
   * A group type with its own registered header keeps it.
   */
  public function testGroupTypeWithItsOwnHeaderKeepsIt(): void {
    $this->assertTrue($this->container->get('theme.registry')->getRuntime()->has('group__course_basic__hero'), 'Precondition: the test module registers a course header.');
    $suggestions = ['group__hero', 'group__course_basic__hero'];
    localnodes_platform_theme_suggestions_group_alter($suggestions, self::elements('hero', $this->group('course_basic')));
    $this->assertSame(['group__hero', 'group__course_basic__hero'], $suggestions);
  }

  /**
   * The hook's variables for a group in a view mode.
   */
  private static function elements(string $view_mode, GroupInterface $group): array {
    return ['elements' => ['#view_mode' => $view_mode, '#group' => $group]];
  }

  /**
   * A stand-in group of a type, for the suggestion hook (never saved).
   */
  private function group(string $bundle): GroupInterface {
    $group = $this->createMock(GroupInterface::class);
    $group->method('bundle')->willReturn($bundle);
    return $group;
  }

  /**
   * The sky template is registered from the module, on the group hook.
   */
  public function testSkyTemplateIsRegistered(): void {
    $hooks = localnodes_platform_theme([], 'module', 'localnodes_platform', 'modules/custom/localnodes_platform');
    $this->assertSame('group', $hooks['group__hero__localnodes__sky']['base hook']);
    $this->assertFileExists($this->root . '/' . $hooks['group__hero__localnodes__sky']['path'] . '/' . $hooks['group__hero__localnodes__sky']['template'] . '.html.twig');
  }

}
