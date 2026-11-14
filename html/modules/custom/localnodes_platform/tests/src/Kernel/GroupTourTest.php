<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\ginvite\GroupInvitationLoaderInterface;
use Drupal\localnodes_platform\Controller\GetHelpController;
use Drupal\localnodes_platform\FirstLanding;
use Drupal\localnodes_platform\GroupTour;
use Drupal\localnodes_platform\InviteLanding;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * The community-wide group's tour, and the lines around a first landing.
 *
 * Builds the page bottom as core's Tour module does on a group page, and
 * reads the tips it would hand the browser.
 *
 * @group localnodes_platform
 */
final class GroupTourTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['tour', 'localnodes_platform'];

  /**
   * The tips, word for word, as on a housing co-op named below.
   */
  private const COOP_TIPS = [
    [
      'Your shared group',
      "Willow &amp; Elm Co-op is the co-op's shared group. Read the stream, or use the post box to say hello.",
    ],
    [
      "What's coming up",
      "Open Events to see what's coming up in the co-op. Choose an event for its time, place and sign-up options.",
    ],
    [
      'Read the library',
      "Open Topics for the co-op's documents, such as meeting notes and guides. You can read without opening the assistant.",
    ],
    [
      'Ask and check',
      "Ask the assistant about the co-op's documents. Open a Sources link and check the passage; an answer can be wrong.",
    ],
    [
      'Your other groups',
      'Find houses in <a href="/all-groups">Groups</a>. Join makes you a member; Request to join asks an organizer for approval. Members-only groups need an invitation.',
    ],
  ];

  /**
   * The community-wide group.
   */
  private GroupInterface $community;

  /**
   * A house: a starting group not marked everyone.
   */
  private GroupInterface $house;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installShippedConfig('social_group_flexible_group', [
      'field.storage.group.field_group_allowed_join_method',
      'field.field.group.flexible_group.field_group_allowed_join_method',
    ]);
    $this->installShippedConfig('localnodes_platform', ['tour.tour.' . GroupTour::TOUR_ID]);
    $this->community = $this->createFlexibleGroup('Willow & Elm Co-op', 'community');
    $this->house = $this->createFlexibleGroup('Rowan House', 'community');
    $this->recordTemplate('housing_coop');
    user_role_grant_permissions('authenticated', ['access tour']);
  }

  /**
   * On the community-wide group: five tips in order, words filled in.
   */
  public function testTipsOnTheCommunityGroup(): void {
    $tips = $this->tipsOn($this->community, 'social_group.stream');
    $this->assertSame(self::COOP_TIPS, array_map(fn(array $t) => [$t['title'], $this->bodyOf($t)], $tips));
    $this->assertSame([
      '#social-post-entity-form',
      '.navbar-secondary a[href$="/events"]',
      '.navbar-secondary a[href$="/topics"]',
      '.chatbot-socialblue',
      NULL,
    ], array_column($tips, 'selector'));
  }

  /**
   * The tour matches the group's address under either route name.
   */
  public function testBothRouteNamesMatch(): void {
    $this->assertCount(5, $this->tipsOn($this->community, 'entity.group.canonical'));
  }

  /**
   * Another group's page, or no template: no tour at all.
   */
  public function testNoTourElsewhere(): void {
    $this->assertSame([], $this->tipsOn($this->house, 'social_group.stream'));
    $this->container->get('state')->delete(FirstLanding::TEMPLATE_STATE);
    $this->assertSame([], $this->tipsOn($this->community, 'social_group.stream'));
  }

  /**
   * Without the permission, the page carries no tour.
   */
  public function testNoTourWithoutThePermission(): void {
    user_role_revoke_permissions('authenticated', ['access tour']);
    $this->assertSame([], $this->tipsOn($this->community, 'social_group.stream'));
  }

  /**
   * Each recipe's nouns; an unknown template says community and groups.
   *
   * @dataProvider recipes
   */
  public function testRecipeNouns(string $template, string $tip1, string $tip5): void {
    $this->recordTemplate($template);
    $tips = $this->tipsOn($this->community, 'social_group.stream');
    $this->assertStringStartsWith($tip1, $this->bodyOf($tips[0]));
    $this->assertStringStartsWith($tip5, $this->bodyOf($tips[4]));
  }

  /**
   * Template ids with the start of tips 1 and 5.
   */
  public static function recipes(): array {
    return [
      'market' => ['market', "Willow &amp; Elm Co-op is the market's shared group.", 'Find vendors in'],
      'neighbourhood' => [
        'neighbourhood',
        "Willow &amp; Elm Co-op is the neighbourhood's shared group.",
        'Find blocks in',
      ],
      'unknown' => ['repair_cafe', "Willow &amp; Elm Co-op is the community's shared group.", 'Find groups in'],
    ];
  }

  /**
   * A group name with markup is shown as text, never as markup.
   */
  public function testGroupNameIsText(): void {
    $this->community->set('label', '<img src=x onerror=alert(1)>Co-op')->save();
    $body = $this->bodyOf($this->tipsOn($this->community, 'social_group.stream')[0]);
    $this->assertStringStartsWith('&lt;img src=x onerror=alert(1)&gt;Co-op is', $body);
  }

  /**
   * The fresh install lets every signed-in person see tours.
   */
  public function testInstallGrantsTheTour(): void {
    user_role_revoke_permissions('authenticated', ['access tour']);
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_grant_tour_access();
    $this->assertTrue(Role::load('authenticated')->hasPermission('access tour'));
  }

  /**
   * The first landing shows "You're in ..." once, on the group.
   */
  public function testFirstLandingLine(): void {
    $member = $this->createPersona('ana');
    $this->landing()->target($member);
    $this->assertSame(["You're in Willow &amp; Elm Co-op. Take a quick look around, then find your other groups."], $this->statusLines());

    $this->landing()->target($member);
    $this->assertSame([], $this->statusLines(), 'A later sign-in is not told it joined again.');
  }

  /**
   * Invited: the invitation line instead, on the invitations page.
   */
  public function testInvitationLine(): void {
    $member = $this->createPersona('ben');
    $loader = $this->createMock(GroupInvitationLoaderInterface::class);
    $loader->method('loadByUser')->willReturn(['pending']);
    $invited = new InviteLanding($loader, $this->container->get('localnodes_platform.first_landing'), $this->container->get('messenger'), $this->container->get('localnodes_platform.group_tour'));

    $this->assertSame('social_core.my_invites', $invited->target($member)?->getRouteName());
    $this->assertSame(['Your house invitation is here. Choose Accept, then Join if asked.'], $this->statusLines());
    $invited->target($member);
    $this->assertSame([], $this->statusLines());
  }

  /**
   * A destination in the address wins, and no line is shown.
   *
   * No line about a landing that didn't happen, and the landing is spent.
   */
  public function testNoLineWhenDestinationWins(): void {
    $member = $this->createPersona('cara');
    $request = Request::create('/user/login', 'POST');
    $request->query = new InputBag(['destination' => '/node/5']);
    $this->landing()->afterSignIn($member, $request, 'user.login');
    $this->assertSame([], $this->statusLines());

    $hal = $this->createPersona('hal');
    $verify = Request::create('/siwe/verify', 'POST', [], [], [], ['HTTP_REFERER' => 'https://example.com/user/login?destination=/node/7']);
    $this->assertSame('/node/7', $this->landing()->walletRedirect($hal, $verify));
    $this->assertSame([], $this->statusLines());
    $this->assertNull($this->landing()->target($hal), 'The landing was spent.');
  }

  /**
   * Two community-wide groups recorded: no tips on either, no replay link.
   */
  public function testTwoEveryoneGroupsHaveNoTour(): void {
    $this->container->get('state')->set(FirstLanding::TEMPLATE_STATE, [
      'id' => 'housing_coop',
      'version' => 1,
      'groups' => [
        ['group' => (int) $this->community->id(), 'everyone' => TRUE, 'group_template' => NULL],
        ['group' => (int) $this->house->id(), 'everyone' => TRUE, 'group_template' => NULL],
      ],
      'repeatable' => [],
    ]);
    $this->assertSame([], $this->tipsOn($this->community, 'social_group.stream'));
    $this->assertSame([], $this->tipsOn($this->house, 'social_group.stream'));
    $this->container->get('current_user')->setAccount($this->createPersona('tia'));
    $this->assertNull($this->helpBuild()['#tour_url']);
  }

  /**
   * The help page offers the tour again to signed-in members only.
   */
  public function testHelpPageReplayLink(): void {
    $this->assertNull($this->helpBuild()['#tour_url'], 'Not for a visitor who is not signed in.');

    $member = $this->createPersona('dev');
    $this->container->get('current_user')->setAccount($member);
    $this->assertSame('/group/' . $this->community->id() . '/stream?tour=1', $this->helpBuild()['#tour_url']);
    $build = $this->helpBuild();
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('>Take the group tour</a>', $html);

    // Replaying joins no one.
    $this->assertFalse((bool) $this->community->getMember(User::load($member->id())));
  }

  /**
   * No replay link to a group the person cannot open; the access is cached.
   *
   * The community group becomes members-only and a member is removed: the
   * help page offers no tour, and carries what the group's access answer
   * depends on, so the page changes when that does.
   */
  public function testNoReplayLinkToAGroupThePersonCannotOpen(): void {
    $member = $this->createPersona('eve');
    $this->addMember($this->community, $member);
    $this->community->set('field_flexible_group_visibility', 'members')->save();
    $this->container->get('current_user')->setAccount($member);
    $this->assertNotNull($this->helpBuild()['#tour_url'], 'A member of a members-only group still has it.');

    $this->community->removeMember(User::load($member->id()));
    // As on a later request: no access answer kept from the first check.
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('group')->resetCache();
    $group = $this->container->get('entity_type.manager')->getStorage('group')->load($this->community->id());
    $access = $group->access('view', $member, TRUE);
    $this->assertFalse($access->isAllowed());

    $build = $this->helpBuild();
    $this->assertNull($build['#tour_url']);
    foreach ($access->getCacheContexts() as $context) {
      $this->assertContains($context, $build['#cache']['contexts']);
    }
    foreach (array_merge($access->getCacheTags(), $group->getCacheTags()) as $tag) {
      $this->assertContains($tag, $build['#cache']['tags']);
    }
  }

  /**
   * The tips the page bottom hands the browser on this group's page.
   *
   * @return array<int, array<string, mixed>>
   *   Each tip's title, body and selector, in order.
   */
  private function tipsOn(GroupInterface $group, string $route): array {
    $member = $this->createPersona('viewer' . $this->randomMachineName(6));
    $this->container->get('current_user')->setAccount($member);
    $request = Request::create('/group/' . $group->id() . '/stream');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/group/{group}/stream'));
    $request->attributes->set('group', $group);
    $request->attributes->set('_raw_variables', new InputBag(['group' => (string) $group->id()]));
    $this->container->get('request_stack')->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
    $page_bottom = [];
    tour_page_bottom($page_bottom);
    $this->container->get('request_stack')->pop();
    $this->container->get('current_route_match')->resetRouteMatch();
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    return $page_bottom['tour']['#attached']['drupalSettings']['_tour_internal'] ?? [];
  }

  /**
   * A tip's body without core's paragraph wrapper.
   */
  private function bodyOf(array $tip): string {
    return trim((string) preg_replace('#^<p[^>]*>(.*)</p>$#s', '$1', trim($tip['body'])));
  }

  /**
   * The status lines shown so far, cleared.
   *
   * @return string[]
   *   Each line as markup.
   */
  private function statusLines(): array {
    return array_map('strval', $this->container->get('messenger')->deleteByType('status'));
  }

  /**
   * The help page's build for the current user.
   */
  private function helpBuild(): array {
    return GetHelpController::create($this->container)->page();
  }

  /**
   * The landing service as the site builds it.
   */
  private function landing(): InviteLanding {
    return $this->container->get('localnodes_platform.invite_landing');
  }

  /**
   * Records a template with the community group as its everyone group.
   */
  private function recordTemplate(string $id): void {
    $this->container->get('state')->set(FirstLanding::TEMPLATE_STATE, [
      'id' => $id,
      'version' => 1,
      'groups' => [
        ['group' => (int) $this->community->id(), 'everyone' => TRUE, 'group_template' => NULL],
        ['group' => (int) $this->house->id(), 'everyone' => FALSE, 'group_template' => NULL],
      ],
      'repeatable' => [],
    ]);
  }

}
