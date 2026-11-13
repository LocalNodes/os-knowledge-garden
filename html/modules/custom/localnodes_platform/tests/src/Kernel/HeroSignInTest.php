<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;

/**
 * The front-page welcome block's button and picture.
 *
 * Open Social's hero ships two buttons, "Sign up" to the registration page
 * and "Login" to the password page, over a stock photo of strangers
 * (public://homepage-block.jpg). The install makes them one "Sign in" to the
 * log-in page, where the site's sign-in is, and the stock photo is left out
 * at render time; it stays stored because the image field is required. A
 * community's own picture still shows.
 *
 * @group localnodes_platform
 */
final class HeroSignInTest extends KernelTestBase {

  private const HERO_UUID = '8bb9d4bb-f182-4afc-b138-8a4b802824e4';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'filter', 'field', 'text', 'link', 'file', 'image',
    'block', 'block_content', 'localnodes_platform',
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
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('block_content');
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML'])->save();

    // The shape social_core ships: text, up to two links, a required image.
    BlockContentType::create(['id' => 'hero_call_to_action_block', 'label' => 'Hero call to action block'])->save();
    $fields = [
      'field_text_block' => ['text_long', 1],
      'field_call_to_action_link' => ['link', 2],
      'field_hero_image' => ['image', 1],
    ];
    $display = EntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'hero_call_to_action_block',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    foreach ($fields as $name => [$type, $cardinality]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'block_content',
        'type' => $type,
        'cardinality' => $cardinality,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'block_content',
        'bundle' => 'hero_call_to_action_block',
        'label' => $name,
        'required' => $name !== 'field_call_to_action_link',
      ])->save();
      $display->setComponent($name, ['type' => $type === 'text_long' ? 'text_default' : ($type === 'link' ? 'link' : 'image'), 'label' => 'hidden']);
    }
    $display->save();

    BlockContent::create([
      'type' => 'hero_call_to_action_block',
      'info' => 'AN homepage hero block',
      'uuid' => self::HERO_UUID,
      'field_text_block' => ['value' => '<p>Stock</p>', 'format' => 'full_html'],
      'field_call_to_action_link' => [
        ['uri' => 'internal:/user/register', 'title' => 'Sign up'],
        ['uri' => 'internal:/user/login', 'title' => 'Login'],
      ],
      'field_hero_image' => ['target_id' => $this->image('public://homepage-block.jpg')->id(), 'alt' => 'Anonymous front page image homepage'],
    ])->save();
  }

  /**
   * A permanent image file entity at the given URI.
   */
  private function image(string $uri): File {
    $file = File::create(['uri' => $uri, 'filename' => basename($uri)]);
    $file->setPermanent();
    $file->save();
    return $file;
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
   * The two stock buttons become one "Sign in" to the log-in page.
   */
  public function testOneSignInButton(): void {
    $html = $this->renderHero();
    $this->assertStringContainsString('Sign up', $html, 'Precondition: the stock buttons show.');

    _localnodes_platform_configure_hero_copy();
    $stored = BlockContent::load(1)->get('field_call_to_action_link')->getValue();
    $this->assertCount(1, $stored);
    $this->assertSame('internal:/user/login', $stored[0]['uri']);
    $this->assertSame('Sign in', $stored[0]['title']);

    $html = $this->renderHero();
    $this->assertMatchesRegularExpression('#<a href="[^"]*/user/login"[^>]*>Sign in</a>#', $html);
    $this->assertStringNotContainsString('/user/register', $html);
    $this->assertStringNotContainsString('Sign up', $html);
    $this->assertStringNotContainsString('>Login<', $html);
  }

  /**
   * A second run saves nothing.
   */
  public function testSecondRunSavesNothing(): void {
    _localnodes_platform_configure_hero_copy();
    $rev = BlockContent::load(1)->getRevisionId();
    $changed = BlockContent::load(1)->getChangedTime();
    _localnodes_platform_configure_hero_copy();
    $this->assertSame($rev, BlockContent::load(1)->getRevisionId());
    $this->assertSame($changed, BlockContent::load(1)->getChangedTime());
  }

  /**
   * Open Social's stock photo is not shown, though it stays stored.
   */
  public function testStockPhotoIsLeftOut(): void {
    _localnodes_platform_configure_hero_copy();
    $this->assertStringNotContainsString('homepage-block.jpg', $this->renderHero());
    $this->assertFalse(BlockContent::load(1)->get('field_hero_image')->isEmpty(), 'The required field keeps its file, so the edit form still saves.');
  }

  /**
   * A community's own picture still shows.
   */
  public function testOwnPictureShows(): void {
    $block = BlockContent::load(1);
    $block->set('field_hero_image', ['target_id' => $this->image('public://our-river.jpg')->id(), 'alt' => 'Our river']);
    $block->save();
    $this->assertStringContainsString('our-river.jpg', $this->renderHero());
  }

}
