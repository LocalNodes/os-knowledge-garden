<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;

/**
 * The anonymous front-page hero speaks for the community (WINDOWS 47).
 *
 * Open Social's social_core_install() creates the hero block_content
 * (hero_call_to_action_block, uuid 8bb9d4bb-...) with the stock text "This
 * community is powered by Open Social, the plug-and-play community solution
 * for NGOs and semi-governmental organizations." It reads instead: heading
 * "Welcome to [site name]", body "Sign in to see what's new, find what the
 * group knows and take part." No crypto words.
 *
 * The hero is a content entity, so config/install can't hold it:
 * _localnodes_platform_configure_hero_copy() rewrites it from
 * localnodes_platform_install(). The field has no token filter, so the heading
 * is stored as the placeholder [site:name] and a block_content view alter
 * resolves it at render time, which keeps it right if the site is renamed.
 *
 * @group localnodes_platform
 * @group phase22_1
 */
final class HeroCopyTest extends KernelTestBase {

  private const SITE_NAME = 'Repair Collective';

  private const HERO_UUID = '8bb9d4bb-f182-4afc-b138-8a4b802824e4';

  private const STOCK = '<h3>Become a member or log in to your community</h3><p>This community is powered by Open Social, the plug-and-play community solution for NGOs and semi-governmental organizations.</p>';

  private const BODY = "Sign in to see what's new, find what the group knows and take part.";

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'filter', 'field', 'text', 'block', 'block_content',
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
    $this->installEntitySchema('block_content');
    $this->config('system.site')->set('name', self::SITE_NAME)->save();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');

    // No filters on the format, so the stored markup reaches the page as is,
    // the way Open Social's full_html does for admin-authored text.
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML'])->save();

    // The shape social_core ships: bundle hero_call_to_action_block with a
    // field_text_block (text_long), shown by the default display.
    BlockContentType::create(['id' => 'hero_call_to_action_block', 'label' => 'Hero call to action block'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_text_block',
      'entity_type' => 'block_content',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_text_block',
      'entity_type' => 'block_content',
      'bundle' => 'hero_call_to_action_block',
      'label' => 'Text',
    ])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'hero_call_to_action_block',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_text_block', ['type' => 'text_default', 'label' => 'hidden'])->save();

    BlockContent::create([
      'type' => 'hero_call_to_action_block',
      'info' => 'AN homepage hero block',
      'uuid' => self::HERO_UUID,
      'field_text_block' => ['value' => self::STOCK, 'format' => 'full_html'],
    ])->save();
  }

  /**
   * Renders the hero block the way the page's block plugin does.
   */
  private function renderHero(): string {
    $block = $this->container->get('plugin.manager.block')->createInstance('block_content:' . self::HERO_UUID);
    $build = $block->build();
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Fails clearly (not with a fatal) until the helper exists.
   */
  private function runHelper(): void {
    $this->assertTrue(function_exists('_localnodes_platform_configure_hero_copy'), '_localnodes_platform_configure_hero_copy() exists.');
    _localnodes_platform_configure_hero_copy();
  }

  /**
   * Precondition: a stock install shows the stock text.
   *
   * Without this the next tests could pass on an empty hero.
   */
  public function testStockHeroShowsOpenSocialText(): void {
    $html = $this->renderHero();
    $this->assertStringContainsString('powered by Open Social', $html);
    $this->assertStringContainsString('NGOs and semi-governmental', $html);
  }

  /**
   * After the helper the hero greets visitors by the site's name.
   */
  public function testHeroShowsTheRuledCopy(): void {
    $this->runHelper();
    $html = $this->renderHero();

    $this->assertMatchesRegularExpression('#<h3>\s*Welcome to ' . preg_quote(self::SITE_NAME, '#') . '\s*</h3>#', $html, 'Heading welcomes visitors by the site name.');
    $this->assertStringContainsString(htmlspecialchars(self::BODY, ENT_QUOTES | ENT_HTML5), $html, 'Body is the sign-in sentence.');
  }

  /**
   * The stock Open Social wording is gone from the rendered hero.
   */
  public function testStockTextIsGone(): void {
    $this->runHelper();
    $html = $this->renderHero();

    $this->assertStringNotContainsStringIgnoringCase('powered by Open Social', $html);
    $this->assertStringNotContainsStringIgnoringCase('NGOs and semi-governmental', $html);
    $this->assertStringNotContainsStringIgnoringCase('plug-and-play', $html);
    $this->assertStringNotContainsStringIgnoringCase('Become a member or log in to your community', $html);
  }

  /**
   * No crypto words in the hero.
   */
  public function testNoCryptoWords(): void {
    $this->runHelper();
    $text = strtolower(strip_tags($this->renderHero()));

    foreach (['crypto', 'token', 'blockchain', 'wallet', 'web3'] as $word) {
      $this->assertStringNotContainsString($word, $text, "No '$word' in the hero.");
    }
    $this->assertDoesNotMatchRegularExpression('#\bdao\b#', $text, "No 'DAO' in the hero.");
    // The stored value, not only the rendering, is free of the words too.
    $stored = strtolower((string) BlockContent::load(1)->get('field_text_block')->value);
    foreach (['crypto', 'blockchain', 'wallet', 'web3'] as $word) {
      $this->assertStringNotContainsString($word, $stored);
    }
  }

  /**
   * The heading is the site's name at render time, so a rename follows.
   */
  public function testHeadingFollowsTheSiteName(): void {
    $this->runHelper();
    $this->assertStringContainsString('<h3>Welcome to ' . self::SITE_NAME . '</h3>', $this->renderHero());

    $this->config('system.site')->set('name', 'Mutual Aid Network')->save();
    $html = $this->renderHero();
    $this->assertStringContainsString('Mutual Aid Network', $html);
    $this->assertStringNotContainsString(self::SITE_NAME, $html);
  }

  /**
   * The hero's build depends on system.site, so a rename invalidates it.
   */
  public function testBuildCarriesTheSiteConfigCacheTag(): void {
    $this->runHelper();
    $block = $this->container->get('plugin.manager.block')->createInstance('block_content:' . self::HERO_UUID);
    $build = $block->build();
    $this->container->get('renderer')->renderRoot($build);
    $tags = CacheableMetadata::createFromRenderArray($build)->getCacheTags();
    $this->assertContains('config:system.site', $tags);
  }

  /**
   * The site name is escaped (a name is admin input).
   */
  public function testSiteNameIsEscaped(): void {
    $this->runHelper();
    $this->config('system.site')->set('name', '<script>alert(1)</script> & Co')->save();
    $html = $this->renderHero();

    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
  }

  /**
   * A second run changes nothing; a site without the hero block is fine.
   */
  public function testHelperIsIdempotent(): void {
    $this->runHelper();
    $first = BlockContent::load(1);
    $changed = $first->getChangedTime();
    $rev = $first->getRevisionId();

    $this->runHelper();
    $second = BlockContent::load(1);
    $this->assertSame($rev, $second->getRevisionId(), 'No new revision on a second run.');
    $this->assertSame($changed, $second->getChangedTime());

    $second->delete();
    _localnodes_platform_configure_hero_copy();
    $this->assertNull(BlockContent::load(1));
  }

  /**
   * localnodes_platform_install() calls the helper (wiring guard).
   */
  public function testInstallHookCallsTheHelper(): void {
    $ref = new \ReflectionFunction('localnodes_platform_install');
    $lines = file($ref->getFileName());
    $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    $this->assertStringContainsString('_localnodes_platform_configure_hero_copy();', $body);
  }

}
