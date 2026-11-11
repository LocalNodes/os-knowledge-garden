<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;

/**
 * The member teaser keeps no keyboard stop inside aria-hidden (22.1 quick).
 *
 * Open Social's socialbase profile--profile--teaser.html.twig has two links
 * to the member's profile that assistive technology is told to ignore: the
 * avatar link inside `<div class="teaser__image" aria-hidden="true">`, and
 * the "Profile: <name>" icon link that carries aria-hidden itself. Both stay
 * in the Tab order, so a keyboard user lands on stops a screen reader does
 * not announce (axe `aria-hidden-focus`; tests/a11y/keyboard.spec.ts S8 on
 * /search/all/bioregionalism, found by 22.2).
 *
 * localnodes_platform renders the member teaser from its own templates:
 * localnodes_platform_theme_suggestions_profile_alter() appends the
 * suggestion profile__profile__teaser__localnodes, which hook_theme()
 * registers from the module (a copy of the stock teaser with tabindex="-1"
 * on the two hidden links), plus a __sky copy of socialblue's sky teaser that
 * extends it. A theme registry alter cannot do this: the bootstrap base
 * theme's own registry alter runs after every module's and points each
 * template its themes ship back at the theme.
 *
 * These tests check, in rendered HTML, that no element at or under an
 * aria-hidden="true" element is keyboard-focusable: no a[href], button,
 * input, select, textarea or [tabindex], unless it has tabindex="-1". The
 * member name link in the title stays the teaser's one link stop.
 *
 * Kernel limits: socialblue cannot boot here (see
 * localnodes_stock_teaser_test.info.yml), so the stub theme stands in and
 * socialblue's own suggestion alter (which adds the __sky variants after the
 * module's) is not run. The suggestion order on the real theme is proven on
 * the lane by keyboard.spec.ts S8 and a rendered member teaser.
 *
 * @group localnodes_platform
 * @group phase22_1
 */
final class ProfileTeaserAriaHiddenTest extends KernelTestBase {

  /**
   * The stock teaser, as Open Social ships it (relative to the app root).
   */
  private const STOCK_TEASER = 'profiles/contrib/social/themes/socialbase/templates/profile/profile--profile--teaser.html.twig';

  /**
   * socialblue's sky teaser, which extends the stock teaser by name.
   */
  private const STOCK_SKY_TEASER = 'profiles/contrib/social/themes/socialblue/templates/profile/profile--profile--teaser--sky.html.twig';

  /**
   * The stub theme's copy of the stock teaser.
   */
  private const STUB_TEASER = 'modules/custom/localnodes_platform/tests/themes/localnodes_stock_teaser_test/templates/profile--profile--teaser.html.twig';

  /**
   * The module's teaser templates.
   */
  private const MODULE_TEASER = 'modules/custom/localnodes_platform/templates/profile/profile--profile--teaser--localnodes.html.twig';

  private const MODULE_SKY_TEASER = 'modules/custom/localnodes_platform/templates/profile/profile--profile--teaser--localnodes--sky.html.twig';

  private const SUGGESTION = 'profile__profile__teaser__localnodes';

  private const PROFILE_HOME = '/user/7/home';

  private const MEMBER_NAME = 'Ada Lovelace';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'views', 'entity', 'profile', 'localnodes_platform',
  ];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['localnodes_stock_teaser_test', 'stark']);
    $this->config('system.theme')->set('default', 'localnodes_stock_teaser_test')->save();
    $this->activateTheme('localnodes_stock_teaser_test');
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 3, 'roles' => ['authenticated']]));
  }

  /**
   * The stub theme's teaser is still the one Open Social ships.
   *
   * If an Open Social update changes the stock teaser, this fails: copy the
   * new file into the stub theme, then re-check the module copy against it
   * (and whether upstream fixed aria-hidden, so the override can go).
   */
  public function testStubThemeTeaserIsTheStockTeaser(): void {
    $this->assertFileExists($this->root . '/' . self::STOCK_TEASER);
    $this->assertFileEquals($this->root . '/' . self::STOCK_TEASER, $this->root . '/' . self::STUB_TEASER);
  }

  /**
   * The module's sky copy is socialblue's, with only its parent changed.
   */
  public function testModuleSkyTeaserIsTheStockSkyTeaser(): void {
    $this->assertFileExists($this->root . '/' . self::MODULE_SKY_TEASER);
    $stock = file_get_contents($this->root . '/' . self::STOCK_SKY_TEASER);
    $expected = str_replace(
      '{% extends "profile--profile--teaser.html.twig" %}',
      '{% extends "profile--profile--teaser--localnodes.html.twig" %}',
      $stock,
    );
    $this->assertNotSame($stock, $expected, 'socialblue\'s sky teaser still extends the stock teaser by name.');
    $this->assertSame($expected, file_get_contents($this->root . '/' . self::MODULE_SKY_TEASER));
  }

  /**
   * The checker finds both stock problems, so a pass below means something.
   */
  public function testStockTeaserHasFocusableLinksInsideAriaHidden(): void {
    $html = $this->renderTemplate(self::STOCK_TEASER);

    $found = $this->focusableInsideAriaHidden($html);
    $this->assertCount(2, $found, 'The stock teaser has two focusable links inside aria-hidden: ' . implode('; ', $found));
    $this->assertTitleLinkIsTheLinkStop($html);
  }

  /**
   * A member's teaser, seen by a signed-in member, gets the module's template.
   */
  public function testSuggestionIsAddedLastForTheMemberTeaser(): void {
    $suggestions = $this->alterSuggestions('profile', 'teaser');
    $this->assertSame(self::SUGGESTION, end($suggestions));
  }

  /**
   * Other profile renders, anonymous visitors and other themes are untouched.
   *
   * socialbase gives anonymous visitors its own __anonymous teaser, which has
   * no aria-hidden link; the module's suggestion would outrank it.
   */
  public function testSuggestionIsLeftOutElsewhere(): void {
    $this->assertNotContains(self::SUGGESTION, $this->alterSuggestions('profile', 'full'));
    $this->assertNotContains(self::SUGGESTION, $this->alterSuggestions('other', 'teaser'));

    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 0, 'roles' => ['anonymous']]));
    $this->assertNotContains(self::SUGGESTION, $this->alterSuggestions('profile', 'teaser'));
  }

  /**
   * A theme without the stock member teaser keeps its own profile output.
   */
  public function testSuggestionIsLeftOutWithoutTheStockTeaser(): void {
    $this->activateTheme('stark');
    $this->assertNotContains(self::SUGGESTION, $this->alterSuggestions('profile', 'teaser'));
  }

  /**
   * Both module templates are registered from the module.
   */
  public function testModuleTeaserHooksAreRegistered(): void {
    $registry = $this->container->get('theme.registry')->get();
    $module_path = $this->container->get('extension.list.module')->getPath('localnodes_platform');
    foreach ([self::SUGGESTION, self::SUGGESTION . '__sky'] as $hook) {
      $this->assertArrayHasKey($hook, $registry);
      $this->assertSame($module_path . '/templates/profile', $registry[$hook]['path'], $hook);
      $this->assertSame('profile', $registry[$hook]['base hook'], $hook);
    }
  }

  /**
   * The module teaser has no stop inside aria-hidden.
   */
  public function testModuleTeaserHasNoFocusableInsideAriaHidden(): void {
    $html = $this->renderTemplate(self::MODULE_TEASER);

    $this->assertSame([], $this->focusableInsideAriaHidden($html));
    $this->assertTitleLinkIsTheLinkStop($html);
    // The avatar and the icon still take a mouse click to the profile.
    $xpath = $this->domOf($html);
    $this->assertSame(1, $xpath->query('//*[contains(@class, "teaser__image")]//a[@href="' . self::PROFILE_HOME . '"][@tabindex="-1"]')->length);
    $this->assertSame(1, $xpath->query('//a[@aria-hidden="true"][@href="' . self::PROFILE_HOME . '"][@tabindex="-1"][.//*[contains(@class, "teaser__teaser-type")]]')->length);
  }

  /**
   * The module's sky teaser, which sky sites render, inherits the fix.
   */
  public function testModuleSkyTeaserHasNoFocusableInsideAriaHidden(): void {
    $html = $this->renderTemplate(self::MODULE_SKY_TEASER);

    $this->assertStringContainsString('teaser__tag', $html, 'The sky teaser rendered its own title block.');
    $this->assertSame([], $this->focusableInsideAriaHidden($html));
    $this->assertTitleLinkIsTheLinkStop($html);
  }

  /**
   * Makes a theme the active one.
   */
  private function activateTheme(string $name): void {
    $theme = $this->container->get('theme.initialization')->initTheme($name);
    $this->container->get('theme.manager')->setActiveTheme($theme);
    $this->container->get('theme.registry')->reset();
  }

  /**
   * Runs every module's profile suggestion alter for one profile render.
   *
   * @return string[]
   *   The suggestions after the alter.
   */
  private function alterSuggestions(string $bundle, string $view_mode): array {
    $profile = Profile::create(['type' => $bundle, 'uid' => 7]);
    $variables = [
      'elements' => ['#profile' => $profile, '#view_mode' => $view_mode],
      'theme_hook_original' => 'profile',
    ];
    $suggestions = [
      'profile__' . $view_mode,
      'profile__' . $bundle,
      'profile__' . $bundle . '__' . $view_mode,
    ];
    $hook = 'profile';
    $this->container->get('module_handler')->alter(['theme_suggestions', 'theme_suggestions_profile'], $suggestions, $variables, $hook);
    return $suggestions;
  }

  /**
   * Renders a teaser template file with a member's variables.
   */
  private function renderTemplate(string $file): string {
    $variables = [
      'profile_home' => self::PROFILE_HOME,
      'profile_name' => self::MEMBER_NAME,
      'profile_name_extra' => '',
      'profile_contact_url' => '/user/7/information',
      'profile_contact_label' => 'profile',
      'profile_stream_url' => '/user/7/stream',
      'content' => [
        'field_profile_image' => ['#markup' => '<img src="/avatar.png" alt="">'],
      ],
      'attributes' => new Attribute(),
      'badges' => [],
      'following_enabled' => FALSE,
    ];
    $twig = $this->container->get('twig');
    return (string) $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      static fn () => $twig->load($file)->render($variables),
    );
  }

  /**
   * Keyboard-focusable elements at or under an aria-hidden="true" element.
   *
   * @return string[]
   *   One line per element: its tag and its href, title or tabindex.
   */
  private function focusableInsideAriaHidden(string $html): array {
    $query = '//*[@aria-hidden="true"]/descendant-or-self::*'
      . '[(self::a and @href) or self::button or self::input or self::select or self::textarea or @tabindex]'
      . '[not(@tabindex="-1")]';
    $found = [];
    foreach ($this->domOf($html)->query($query) as $node) {
      assert($node instanceof \DOMElement);
      $found[$node->getNodePath()] = sprintf('<%s href="%s" title="%s" tabindex="%s">',
        $node->tagName, $node->getAttribute('href'), $node->getAttribute('title'), $node->getAttribute('tabindex'));
    }
    return array_values($found);
  }

  /**
   * The member name link in the title is a real, named, unhidden link.
   */
  private function assertTitleLinkIsTheLinkStop(string $html): void {
    $links = $this->domOf($html)->query('//h4[contains(@class, "teaser__title")]/a[@href="' . self::PROFILE_HOME . '"]');
    $this->assertSame(1, $links->length, 'The title links to the profile.');
    $link = $links->item(0);
    assert($link instanceof \DOMElement);
    $this->assertStringContainsString(self::MEMBER_NAME, $link->textContent);
    $this->assertFalse($link->hasAttribute('tabindex'));
    $this->assertSame(0, (new \DOMXPath($link->ownerDocument))->query('ancestor-or-self::*[@aria-hidden="true"]', $link)->length);
  }

  /**
   * Parses rendered HTML for XPath queries.
   */
  private function domOf(string $html): \DOMXPath {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="utf-8"?><div id="teaser-root">' . $html . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new \DOMXPath($document);
  }

}
