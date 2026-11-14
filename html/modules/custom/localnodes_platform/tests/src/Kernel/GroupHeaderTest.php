<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\localnodes_platform\GroupHeader;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * The group header's one-line description and its two action buttons.
 *
 * The header (group--hero--localnodes--sky.html.twig) prints the group's own
 * description cut to its first line, and "New post" and "Invite" buttons.
 * Each button shows only when its page would let this person in (the route's
 * own access check, never a role name), and only to a member: a non-member
 * sees neither, even one the page would let in.
 *
 * Kernel limits: socialblue cannot boot here, so its "__sky" suggestion and
 * the template itself are checked in a browser (tests/a11y/group-header.spec.ts).
 * ginvite and social_group_invite are not enabled, so the invite page does not
 * exist here and Invite never shows; the browser spec covers Invite for a
 * person who may invite and a member who may not. Both buttons go through the
 * same access path, which the "New post" cases below prove.
 *
 * @group localnodes_platform
 */
final class GroupHeaderTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['localnodes_platform', 'localnodes_group_access_test'];

  private GroupInterface $group;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installShippedConfig('social_group', ['field.storage.group.field_group_description']);
    $this->installShippedConfig('social_group_flexible_group', [
      'field.field.group.flexible_group.field_group_description',
      // social_group's group preprocess reads the join method when it renders.
      'field.storage.group.field_group_allowed_join_method',
      'field.field.group.flexible_group.field_group_allowed_join_method',
    ]);
    user_role_grant_permissions('verified', ['create topic content']);
    $this->group = $this->createFlexibleGroup('Garden Circle', 'community');
  }

  /**
   * The description's first paragraph, as plain text.
   *
   * @dataProvider summaryProvider
   */
  public function testSummaryIsTheFirstLineAsPlainText(?string $html, string $expected): void {
    $this->group->set('field_group_description', $html === NULL ? NULL : ['value' => $html, 'format' => 'basic_html'])->save();
    $member = $this->createPersona('reader');
    $this->assertSame($expected, $this->header()->summary($this->group, $member));
  }

  /**
   * Descriptions as organizers write them, and what the header shows.
   */
  public static function summaryProvider(): array {
    return [
      'none' => [NULL, ''],
      'empty' => ['', ''],
      'only a blank paragraph' => ['<p>&nbsp;</p>', ''],
      'only whitespace and a break' => ["<p> \n <br> </p>", ''],
      'one paragraph' => ['<p>Raised beds on the south fence.</p>', 'Raised beds on the south fence.'],
      'first paragraph only' => ['<p>Work days and the seed budget.</p><p>Second paragraph.</p>', 'Work days and the seed budget.'],
      'blank first paragraph skipped' => ['<p>&nbsp;</p><p>Seed swap on Saturdays.</p>', 'Seed swap on Saturdays.'],
      'first line of a broken paragraph' => ['<p>Line one<br>Line two</p>', 'Line one'],
      'tags dropped, entities read' => ['<p><strong>Beds</strong> &amp; <em>tools</em> &lt;shared&gt;</p>', 'Beds & tools <shared>'],
      'whitespace collapsed' => ["<p>  Beds\t and   tools  </p>", 'Beds and tools'],
      'plain text with newlines' => ["First line\nSecond line", 'First line'],
    ];
  }

  /**
   * A long first line is cut at a word, with an ellipsis.
   */
  public function testLongSummaryIsCutAtAWord(): void {
    $line = trim(str_repeat('community garden ', 40));
    $this->group->set('field_group_description', ['value' => "<p>$line</p>", 'format' => 'basic_html'])->save();

    $summary = $this->header()->summary($this->group, $this->createPersona('reader'));

    $this->assertLessThanOrEqual(GroupHeader::SUMMARY_LIMIT, mb_strlen($summary));
    $this->assertStringEndsWith('…', $summary);
    $this->assertStringStartsWith('community garden community', $summary);
    $this->assertMatchesRegularExpression('/(garden|community)…$/u', $summary, 'Cut at a word boundary.');
  }

  /**
   * A member who may post sees "New post", linked to the group's topic form.
   */
  public function testMemberWhoMayPostSeesNewPost(): void {
    $member = $this->createPersona('member');
    $this->addMember($this->group, $member);

    $build = $this->actionsFor($member);

    $this->assertArrayHasKey('post', $build);
    $this->assertSame('New post', (string) $build['post']['#title']);
    $this->assertSame('/group/' . $this->group->id() . '/content/create/group_node%3Atopic', $build['post']['#url']->toString());
    $this->assertContains('btn-primary', $build['post']['#attributes']['class']);
    $this->assertArrayNotHasKey('invite', $build, 'The invite page does not exist here.');
  }

  /**
   * A member whose group role may not create topics sees no "New post".
   */
  public function testMemberWhoMayNotPostSeesNoNewPost(): void {
    GroupRole::load('flexible_group-member')->revokePermission('create group_node:topic entity')->save();
    $member = $this->createPersona('member');
    $this->addMember($this->group, $member);

    $this->assertArrayNotHasKey('post', $this->actionsFor($member));
  }

  /**
   * A non-member sees no button, even one the page itself would let in.
   */
  public function testNonMemberSeesNothingEvenWithAccess(): void {
    foreach (['flexible_group-outsider', 'flexible_group-verified'] as $role) {
      if ($group_role = GroupRole::load($role)) {
        $group_role->grantPermission('create group_node:topic entity')->save();
      }
    }
    $outsider = $this->createPersona('outsider');
    $this->setCurrentUser($outsider);
    $this->assertTrue(
      $this->container->get('access_manager')->checkNamedRoute('entity.group_content.create_form', ['group' => $this->group->id(), 'plugin_id' => 'group_node:topic'], $outsider),
      'Precondition: the topic form would let this non-member in.',
    );

    $build = $this->actionsFor($outsider);
    $this->assertArrayNotHasKey('post', $build);
    $this->assertArrayNotHasKey('invite', $build);
  }

  /**
   * A visitor who is not signed in sees no button.
   */
  public function testAnonymousSeesNothing(): void {
    $build = $this->actionsFor($this->anonymous());
    $this->assertArrayNotHasKey('post', $build);
    $this->assertArrayNotHasKey('invite', $build);
  }

  /**
   * The buttons vary by the person's site and group permissions.
   */
  public function testCacheVariesByPermissions(): void {
    $member = $this->createPersona('member');
    $this->addMember($this->group, $member);

    $contexts = $this->actionsFor($member)['#cache']['contexts'];

    $this->assertContains('user.group_permissions', $contexts);
    $this->assertContains('user.permissions', $contexts);
  }

  /**
   * A page check that looks at the person keeps the buttons per person.
   *
   * Two members with the same permissions can get different answers from a
   * check that reads the person; the button must not be served from one to
   * the other.
   */
  public function testPerPersonPageCheckKeepsItsContext(): void {
    $member = $this->createPersona('member');
    $this->addMember($this->group, $member);
    $this->setCurrentUser($member);
    $access = $this->createMock(AccessManagerInterface::class);
    $access->method('checkNamedRoute')->willReturn(AccessResult::allowed()->cachePerUser()->addCacheContexts(['session']));
    $header = new GroupHeader($access, $this->container->get('current_user'), $this->container->get('router.route_provider'));

    $build = $header->actions($this->group);

    $this->assertArrayHasKey('post', $build);
    $this->assertContains('user', $build['#cache']['contexts']);
    $this->assertContains('session', $build['#cache']['contexts']);
  }

  /**
   * No description from a group the person may not see.
   */
  public function testNoSummaryFromAGroupThePersonMayNotSee(): void {
    $this->group->set('field_group_description', ['value' => '<p>Seed swap on Saturdays.</p>', 'format' => 'basic_html'])->save();
    $reader = $this->createPersona('reader');
    $this->assertSame('Seed swap on Saturdays.', $this->header()->summary($this->group, $reader), 'Precondition: the reader sees it while allowed.');

    $this->container->get('state')->set('localnodes_group_access_test.group', [(int) $reader->id()]);
    $this->container->get('entity_type.manager')->getAccessControlHandler('group')->resetCache();

    $this->assertTrue($this->group->get('field_group_description')->access('view', $reader), 'Precondition: the field alone would let the reader in.');
    $this->assertSame('', $this->header()->summary($this->group, $reader));
  }

  /**
   * The description carries what its access checks varied by.
   */
  public function testSummaryCarriesItsAccessCacheMetadata(): void {
    $this->group->set('field_group_description', ['value' => '<p>Seed swap on Saturdays.</p>', 'format' => 'basic_html'])->save();
    $this->container->get('state')->set('localnodes_group_access_test.description', [-1]);
    $cache = new CacheableMetadata();

    $this->header()->summary($this->group, $this->createPersona('reader'), $cache);

    $this->assertContains('user', $cache->getCacheContexts());
    $this->assertEmpty(array_diff($this->group->getCacheTags(), $cache->getCacheTags()), 'The group\'s own tags.');
  }

  /**
   * The header's description element varies as its access checks did.
   *
   * Two people with the same permissions, one refused the description, must
   * not share a cached copy of it.
   */
  public function testSummaryElementCarriesItsAccessCacheMetadata(): void {
    $this->group->set('field_group_description', ['value' => '<p>Seed swap on Saturdays.</p>', 'format' => 'basic_html'])->save();
    $refused = $this->createPersona('refused');
    $this->container->get('state')->set('localnodes_group_access_test.description', [(int) $refused->id()]);
    $this->setCurrentUser($this->createPersona('allowed'));
    $display = $this->container->get('entity_display.repository')->getViewDisplay('group', 'flexible_group', 'hero');

    $build = [];
    localnodes_platform_group_view($build, $this->group, $display, 'hero');

    $this->assertSame('Seed swap on Saturdays.', $build['ln_group_summary']['text']['#plain_text']);
    $this->assertContains('user', $build['ln_group_summary']['#cache']['contexts']);
  }

  /**
   * Rendered and render-cached: a non-member is never served a member's copy.
   */
  public function testRenderedHeaderFollowsMembership(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();

    $member = $this->createPersona('member');
    $this->addMember($this->group, $member);
    $newcomer = $this->createPersona('newcomer');

    $this->assertStringContainsString('New post', $this->renderHero($member));
    $this->assertStringNotContainsString('New post', $this->renderHero($newcomer));

    $this->addMember($this->group, $newcomer);
    $this->assertStringContainsString('New post', $this->renderHero($newcomer), 'After joining, the newcomer gets the button.');
  }

  /**
   * The service under test.
   */
  private function header(): GroupHeader {
    return $this->container->get('localnodes_platform.group_header');
  }

  /**
   * The action buttons, built for an account as the current user.
   */
  private function actionsFor(AccountInterface $account): array {
    $this->setCurrentUser($account);
    return $this->header()->actions($this->group);
  }

  /**
   * Renders the group's header view as an account, through the render cache.
   */
  private function renderHero(AccountInterface $account): string {
    $this->setCurrentUser($account);
    $build = $this->container->get('entity_type.manager')->getViewBuilder('group')->view($this->group, 'hero');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
