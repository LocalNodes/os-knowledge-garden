<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\block\Entity\Block;
use Drupal\KernelTests\KernelTestBase;

/**
 * The community site's header name and footer (22.1-07, criterion 6).
 *
 * Tom (2026-11-06, cards 2 and 11): the header shows the community's own name
 * as text, and every page's footer reads "‹community› · a community on
 * LocalNodes · Open source", with the small node mark before "LocalNodes",
 * replacing Open Social's "#MadeToShare by Open Social" block.
 *
 * Module list: system, user, filter, field, file, block, social_footer (for
 * the block this plan switches off) and localnodes_platform; the theme is the
 * stub from LocalNodesEmailTest. Kernel tests do not check module
 * dependencies, so social_core is not needed.
 *
 * @group localnodes_platform
 * @group phase22_1
 */
final class LocalNodesBrandingTest extends KernelTestBase {

  private const SITE_NAME = 'Repair Collective';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'filter', 'field', 'file', 'block', 'social_footer',
    'localnodes_platform',
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
    $this->installConfig(['system', 'user', 'filter']);
    $this->installEntitySchema('user');
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    $this->config('system.site')->set('name', self::SITE_NAME)->save();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
  }

  /**
   * Renders the footer block the way a page does.
   *
   * @param array $configuration
   *   Block configuration, for example ['repository_url' => '...'].
   */
  private function renderFooter(array $configuration = []): string {
    $block = $this->container->get('plugin.manager.block')->createInstance('localnodes_footer', $configuration);
    $build = $block->build();
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * The footer carries Tom's wording, the mark and the code link.
   */
  public function testFooterWording(): void {
    $html = $this->renderFooter();

    $this->assertStringContainsString(self::SITE_NAME, $html);
    $this->assertStringContainsString('a community on', $html);
    $this->assertStringContainsString('LocalNodes', $html);
    $this->assertMatchesRegularExpression('#<img[^>]+src="[^"]*images/localnodes-mark-reversed\.svg"[^>]*alt=""#', $html);
    $this->assertMatchesRegularExpression('#<a[^>]+href="https://github\.com/LocalNodes/os-knowledge-garden"[^>]*>Open source</a>#', $html);
    $this->assertMatchesRegularExpression('#a community on\s*<img[^>]*>\s*LocalNodes#', $html);
    $this->assertStringNotContainsString('MadeToShare', $html);
    $this->assertStringNotContainsString('Open Social', $html);
  }

  /**
   * The repository link keeps only http(s) addresses (Q11b-2, T-22.1-19).
   *
   * A stored value with any other scheme is not used: the footer shows the
   * default repository link instead, and no link carries the bad address.
   *
   * @dataProvider unsafeRepositoryUrlProvider
   */
  public function testFooterDropsAnUnsafeRepositoryUrl(string $unsafe): void {
    $html = $this->renderFooter(['repository_url' => $unsafe]);

    $this->assertStringNotContainsString($unsafe, $html, 'The unsafe address is not in the footer at all.');
    $this->assertDoesNotMatchRegularExpression('#href="\s*(?:javascript|data|vbscript):#i', $html);
    // The safe default is used.
    $this->assertMatchesRegularExpression('#<a[^>]+href="https://github\.com/LocalNodes/os-knowledge-garden"[^>]*>Open source</a>#', $html);
  }

  /**
   * Addresses the guard must not let through.
   */
  public static function unsafeRepositoryUrlProvider(): array {
    return [
      'javascript' => ['javascript:alert(1)'],
      'data' => ['data:text/html,x'],
      'mixed case javascript' => ['JaVaScript:alert(1)'],
      'leading space' => [' javascript:alert(1)'],
      'scheme-relative' => ['//evil.example/x'],
    ];
  }

  /**
   * An http or https repository address is kept as the link.
   *
   * @dataProvider safeRepositoryUrlProvider
   */
  public function testFooterKeepsAnHttpRepositoryUrl(string $safe): void {
    $html = $this->renderFooter(['repository_url' => $safe]);

    $this->assertMatchesRegularExpression('#<a[^>]+href="' . preg_quote($safe, '#') . '"[^>]*>Open source</a>#', $html);
    $this->assertStringNotContainsString('github.com/LocalNodes/os-knowledge-garden', $html, 'The default is not used when the address is a safe one.');
  }

  /**
   * Addresses the guard keeps.
   */
  public static function safeRepositoryUrlProvider(): array {
    return [
      'http' => ['http://git.example.org/community/repo'],
      'https' => ['https://git.example.org/community/repo'],
    ];
  }

  /**
   * A site name with markup shows as text (T-22.1-19).
   */
  public function testFooterEscapesSiteName(): void {
    $this->config('system.site')->set('name', '<script>alert(1)</script>Co-op')->save();
    $html = $this->renderFooter();

    $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Co-op', $html);
  }

  /**
   * Renaming the site changes the footer on the next render.
   */
  public function testFooterFollowsSiteName(): void {
    $this->renderFooter();
    $this->config('system.site')->set('name', 'Another Co-op')->save();
    $this->assertStringContainsString('Another Co-op', $this->renderFooter());
  }

  /**
   * The header shows the community's name as text and no image.
   */
  public function testHeaderShowsNameAsText(): void {
    $build = [
      '#theme' => 'block__system_branding_block__localnodes',
      '#plugin_id' => 'system_branding_block',
      '#base_plugin_id' => 'system_branding_block',
      '#derivative_plugin_id' => NULL,
      '#configuration' => ['id' => 'system_branding_block', 'provider' => 'system', 'label' => 'Site branding', 'label_display' => '0'],
      '#attributes' => [],
      '#content' => [],
      'content' => [
        'site_logo' => ['#access' => TRUE, '#uri' => '/profiles/contrib/social/themes/socialblue/logo.svg'],
        'site_name' => ['#access' => TRUE, '#markup' => self::SITE_NAME],
        'site_slogan' => ['#access' => FALSE, '#markup' => ''],
      ],
    ];
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('navbar-brand', $html);
    $this->assertMatchesRegularExpression('#<span class="ln-site-name">\s*' . self::SITE_NAME . '\s*</span>#', $html);
    $this->assertStringNotContainsString('<img', $html);
  }

  /**
   * Only the branding block gets the name-as-text template.
   */
  public function testBrandingSuggestionOnlyForTheBrandingBlock(): void {
    $suggestions = ['block__system_branding_block'];
    localnodes_platform_theme_suggestions_block_alter($suggestions, ['elements' => ['#plugin_id' => 'system_branding_block']]);
    $this->assertSame('block__system_branding_block__localnodes', end($suggestions));

    $other = ['block__system_main_block'];
    localnodes_platform_theme_suggestions_block_alter($other, ['elements' => ['#plugin_id' => 'system_main_block']]);
    $this->assertSame(['block__system_main_block'], $other);
  }

  /**
   * The helper switches off Open Social's powered-by block, once.
   */
  public function testPoweredByBlockIsSwitchedOff(): void {
    Block::create([
      'id' => 'socialblue_footer_powered',
      'theme' => 'localnodes_stock_email_test',
      'region' => 'footer',
      'plugin' => 'social_footer_powered_by_block',
      'status' => TRUE,
      'settings' => ['id' => 'social_footer_powered_by_block', 'label' => 'Powered by', 'logo' => '', 'text' => ['value' => '', 'format' => 'basic_html'], 'link' => ['url' => 'https://getopensocial.com/', 'title' => 'x']],
    ])->save();

    _localnodes_platform_configure_branding();
    $this->assertFalse(Block::load('socialblue_footer_powered')->status());

    // A second run changes nothing, and a site without the block is fine.
    _localnodes_platform_configure_branding();
    $this->assertFalse(Block::load('socialblue_footer_powered')->status());
    Block::load('socialblue_footer_powered')->delete();
    _localnodes_platform_configure_branding();
    $this->assertNull(Block::load('socialblue_footer_powered'));
  }

  /**
   * The shipped mark files hold shapes only (T-22.1-22).
   */
  public function testMarkFilesHoldShapesOnly(): void {
    $dir = $this->container->get('extension.list.module')->getPath('localnodes_platform') . '/images';
    foreach (['localnodes-mark.svg', 'localnodes-mark-reversed.svg'] as $file) {
      $svg = (string) file_get_contents($dir . '/' . $file);
      $this->assertStringContainsString('<circle', $svg);
      foreach (['<script', 'foreignobject', 'href', 'xlink', ' on', '<image', '<text', '@import', 'url('] as $bad) {
        $this->assertStringNotContainsStringIgnoringCase($bad, $svg, "$file has no '$bad'.");
      }
      // The one URL is the SVG namespace.
      $this->assertSame(1, preg_match_all('#https?://#', $svg), "$file has only the xmlns URL.");
    }
  }

}
