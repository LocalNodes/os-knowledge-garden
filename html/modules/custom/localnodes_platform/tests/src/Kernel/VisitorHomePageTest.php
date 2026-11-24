<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Path\PathMatcherInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Entity\View;
use Symfony\Component\HttpFoundation\Request;

/**
 * The home page a signed-out visitor sees.
 *
 * Open Social's "Newest groups" view lists only the groups the viewer may see.
 * On a community whose groups are all members-only a visitor's list is empty,
 * and the block showed its heading over an empty box. The block display now
 * hides itself when the view has no rows, and lists groups when it has some.
 *
 * Metatag's front page defaults carry no title, so the global pattern
 * "[current-page:title] | [site:name]" made the browser title "| Site name".
 * The front page title is "Site name | slogan", or the site name alone when
 * no slogan is set. The title tests run metatag and token for real, with
 * metatag's shipped defaults and the front page matched.
 *
 * Both the view and metatag's front defaults exist before localnodes_platform
 * is enabled, so they are set from localnodes_platform_install().
 *
 * @group localnodes_platform
 */
final class VisitorHomePageTest extends KernelTestBase {

  use UserCreationTrait;

  private const SITE_NAME = 'Repair Collective';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'path_alias', 'views', 'token',
    'metatag', 'localnodes_platform',
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
    // Metatag picks its front page defaults through the path matcher, which
    // its manager receives when it is built, so the stand-in goes in first.
    $matcher = $this->createMock(PathMatcherInterface::class);
    $matcher->method('isFrontPage')->willReturn(TRUE);
    $this->container->set('path.matcher', $matcher);

    $this->installConfig(['system', 'user', 'filter', 'metatag']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installSchema('user', ['users_data']);
    $this->config('system.site')->set('name', self::SITE_NAME)->set('slogan', '')->save();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
  }

  /**
   * A view shaped like Open Social's newest_groups, listing accounts.
   *
   * Only the block display and its empty-result setting matter here, so the
   * rows are accounts instead of groups; the test site has none to start with.
   */
  private function createNewestGroupsView(): void {
    View::create([
      'id' => 'newest_groups',
      'label' => 'Newest groups',
      'base_table' => 'users_field_data',
      'base_field' => 'uid',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_title' => 'Default',
          'position' => 0,
          'display_options' => [
            'title' => 'Newest groups',
            'access' => ['type' => 'none', 'options' => []],
            'query' => ['type' => 'views_query', 'options' => ['disable_sql_rewrite' => TRUE]],
            'style' => ['type' => 'html_list', 'options' => []],
            'row' => ['type' => 'fields', 'options' => []],
            'fields' => [
              'name' => [
                'id' => 'name',
                'table' => 'users_field_data',
                'field' => 'name',
                'entity_type' => 'user',
                'entity_field' => 'name',
                'plugin_id' => 'field',
                'type' => 'string',
                'settings' => ['link_to_entity' => FALSE],
              ],
            ],
            'filters' => [
              'uid' => [
                'id' => 'uid',
                'table' => 'users_field_data',
                'field' => 'uid',
                'entity_type' => 'user',
                'entity_field' => 'uid',
                'plugin_id' => 'numeric',
                'operator' => '>',
                'value' => ['min' => '', 'max' => '', 'value' => '0'],
              ],
            ],
          ],
        ],
        'block_newest_groups' => [
          'id' => 'block_newest_groups',
          'display_plugin' => 'block',
          'display_title' => 'Block',
          'position' => 1,
          'display_options' => [],
        ],
      ],
    ])->save();
  }

  /**
   * Renders the view's block the way a block region does.
   *
   * @return string
   *   The block's markup, or an empty string when the block leaves itself out.
   */
  private function renderNewestGroupsBlock(): string {
    // The helper edits the view through the config factory; a new request
    // would load the edited view, so drop the one kept in memory.
    $this->container->get('entity_type.manager')->getStorage('view')->resetCache();
    $block = $this->container->get('plugin.manager.block')
      ->createInstance('views_block:newest_groups-block_newest_groups', []);
    $build = $block->build();
    if ($build === []) {
      return '';
    }
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * With nothing the viewer may see, the block is left out entirely.
   */
  public function testEmptyNewestGroupsIsLeftOut(): void {
    $this->createNewestGroupsView();
    _localnodes_platform_hide_empty_newest_groups();

    $this->assertSame('', $this->renderNewestGroupsBlock());
  }

  /**
   * With groups to list, the block lists them.
   */
  public function testNewestGroupsListsWhatTheViewerMaySee(): void {
    $this->createNewestGroupsView();
    _localnodes_platform_hide_empty_newest_groups();
    $this->createUser([], 'Watershed Guardians Collective');

    $html = $this->renderNewestGroupsBlock();
    $this->assertStringContainsString('Watershed Guardians Collective', $html);
  }

  /**
   * Running the helper again changes nothing; a site without the view is fine.
   */
  public function testNewestGroupsHelperIsSafeToRepeat(): void {
    _localnodes_platform_hide_empty_newest_groups();
    $this->assertTrue($this->config('views.view.newest_groups')->isNew());

    $this->createNewestGroupsView();
    _localnodes_platform_hide_empty_newest_groups();
    _localnodes_platform_hide_empty_newest_groups();
    $this->assertTrue($this->config('views.view.newest_groups')->get('display.block_newest_groups.display_options.block_hide_empty'));
  }

  /**
   * The front page title metatag builds, with the front page matched.
   */
  private function frontTitle(): string {
    $request = Request::create('/');
    $this->container->get('request_stack')->push($request);
    $tags = metatag_get_default_tags();
    $elements = $this->container->get('metatag.manager')->generateRawElements(['title' => $tags['title'] ?? '']);
    $this->container->get('request_stack')->pop();
    return (string) ($elements['title']['#attributes']['content'] ?? '');
  }

  /**
   * Metatag as shipped: the front title starts with a separator.
   *
   * This pins the cause, so the fix below is known to be what changes it.
   */
  public function testShippedFrontTitleStartsWithSeparator(): void {
    $this->assertSame('| ' . self::SITE_NAME, $this->frontTitle());
  }

  /**
   * With a slogan, the front title is "Site name | slogan".
   */
  public function testFrontTitleWithSlogan(): void {
    _localnodes_platform_configure_front_title();
    $this->config('system.site')->set('slogan', 'Fixing things together')->save();

    $this->assertSame(self::SITE_NAME . ' | Fixing things together', $this->frontTitle());
  }

  /**
   * Without a slogan, the front title is the site name alone.
   */
  public function testFrontTitleWithoutSlogan(): void {
    _localnodes_platform_configure_front_title();

    $this->assertSame(self::SITE_NAME, $this->frontTitle());
  }

  /**
   * Metatag's own front page tags are kept.
   */
  public function testFrontDefaultsKeepMetatagTags(): void {
    _localnodes_platform_configure_front_title();
    $tags = $this->config('metatag.metatag_defaults.front')->get('tags');
    $this->assertSame('[site:url]', $tags['canonical_url']);
    $this->assertSame('[site:url]', $tags['shortlink']);
  }

  /**
   * Without metatag's front page defaults, nothing is created.
   */
  public function testFrontTitleHelperSkipsSiteWithoutMetatag(): void {
    $this->config('metatag.metatag_defaults.front')->delete();
    _localnodes_platform_configure_front_title();
    $this->assertTrue($this->config('metatag.metatag_defaults.front')->isNew());
  }

}
