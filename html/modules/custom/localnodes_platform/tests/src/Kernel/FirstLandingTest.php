<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\ginvite\GroupInvitationLoaderInterface;
use Drupal\localnodes_platform\FirstLanding;
use Drupal\localnodes_platform\InviteLanding;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserDataInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * A new member joins the community-wide group and lands there, once.
 *
 * Signs in through core's own finalize step on each sign-in route, so the
 * platform's hook_user_login runs as on the site, and reads where the member
 * would go: the destination the hook sets for a page redirect, or the
 * sign-in button's answer. Works with web3 off: no wallet module is enabled.
 *
 * @group localnodes_platform
 */
final class FirstLandingTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The platform module for its hooks, services and config schema.
   */
  protected static $modules = ['localnodes_platform'];

  /**
   * The community-wide group (everyone: true, anyone may join).
   */
  private GroupInterface $community;

  /**
   * A second starting group not marked everyone.
   */
  private GroupInterface $other;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installShippedConfig('social_group_flexible_group', [
      'field.storage.group.field_group_allowed_join_method',
      'field.field.group.flexible_group.field_group_allowed_join_method',
    ]);
    $this->community = $this->createFlexibleGroup('Willow Housing Co-op', 'community');
    $this->other = $this->createFlexibleGroup('Garden', 'community');
    $this->recordTemplate([[$this->community, TRUE], [$this->other, FALSE]]);
  }

  /**
   * Password sign-in, not invited: joined, lands on the group with the tour.
   */
  public function testPasswordSignInJoinsAndLandsOnTheGroup(): void {
    $member = $this->createPersona('ana');
    $request = $this->signIn($member, 'user.login');

    $this->assertTrue($this->isMember($this->community, $member));
    $this->assertFalse($this->isMember($this->other, $member), 'A group not marked everyone is never joined.');
    $this->assertSame($this->landingPath($this->community), $request->query->get('destination'));
  }

  /**
   * A second sign-in lands where the path goes by itself; still a member.
   */
  public function testOnlyTheFirstSignInLands(): void {
    $member = $this->createPersona('ben');
    $this->signIn($member, 'user.login');
    $request = $this->signIn($member, 'user.login');

    $this->assertFalse($request->query->has('destination'));
    $this->assertTrue($this->isMember($this->community, $member));
  }

  /**
   * A destination already in the address wins, and the landing is spent.
   */
  public function testDestinationInTheAddressWins(): void {
    $member = $this->createPersona('cara');
    $request = $this->signIn($member, 'user.login', ['destination' => '/node/5']);

    $this->assertSame('/node/5', $request->query->get('destination'));
    $this->assertTrue($this->isMember($this->community, $member), 'The join happens anyway.');
    $this->assertFalse($this->signIn($member, 'user.login')->query->has('destination'));
  }

  /**
   * The sign-in button's email and name steps land the same way.
   *
   * @dataProvider walletStepRoutes
   */
  public function testWalletStepsLandOnTheGroup(string $route): void {
    $member = $this->createPersona('dev');
    $request = $this->signIn($member, $route);
    $this->assertSame($this->landingPath($this->community), $request->query->get('destination'));
  }

  /**
   * The email confirmation step and the name step.
   */
  public static function walletStepRoutes(): array {
    return [
      'email confirm' => ['siwe_login.email_verification_confirm'],
      'name step' => ['siwe_login.username_creation_form'],
    ];
  }

  /**
   * The welcome mail's one-time link: no destination, joined; its save lands.
   */
  public function testOneTimeLinkLandsAtThePasswordSave(): void {
    $member = $this->createPersona('eli');
    $request = $this->signIn($member, 'user.reset.login');

    $this->assertFalse($request->query->has('destination'), 'The set-password form must open.');
    $this->assertTrue($this->isMember($this->community, $member));
    $target = $this->landing()->target($member);
    $this->assertSame($this->landingPath($this->community), $target?->toString());
  }

  /**
   * The emailed sign-in link asks target() after finalize: the same landing.
   */
  public function testEmailSignInLinkLandsOnTheGroup(): void {
    $member = $this->createPersona('fay');
    $this->signIn($member, 'localnodes_web3.email_link_sign_in');
    $this->assertSame($this->landingPath($this->community), $this->landing()->target($member)?->toString());
  }

  /**
   * The sign-in button's direct answer: the group, or a local destination.
   */
  public function testSignInButtonAnswer(): void {
    $member = $this->createPersona('gus');
    $this->signIn($member, 'siwe_login.verify');
    $this->assertSame($this->landingPath($this->community), $this->landing()->walletRedirect($member, Request::create('/siwe/verify', 'POST')));

    // A returning member: nothing, so the page reloads as before.
    $this->assertNull($this->landing()->walletRedirect($member, Request::create('/siwe/verify', 'POST')));

    // A destination in the page's address wins; one off the site does not.
    $hal = $this->createPersona('hal');
    $this->signIn($hal, 'siwe_login.verify');
    $this->assertSame('/node/7', $this->landing()->walletRedirect($hal, $this->verifyFrom('/user/login?destination=/node/7')));
    $ivy = $this->createPersona('ivy');
    $this->signIn($ivy, 'siwe_login.verify');
    $this->assertSame($this->landingPath($this->community), $this->landing()->walletRedirect($ivy, $this->verifyFrom('/user/login?destination=//evil.example/x')));
  }

  /**
   * A destination holding a control character is refused.
   *
   * A browser drops tab, newline and carriage return from an address, so
   * "/<tab>/evil.example/x" opens another site. In the page's address the
   * character arrives encoded; the filter itself sees it raw.
   *
   * @dataProvider controlCharacterDestinations
   */
  public function testDestinationWithControlCharacterIsRefused(string $destination): void {
    $this->assertNull(InviteLanding::localDestination($destination));
    $this->assertNull(InviteLanding::localDestination(rawurldecode($destination)));

    $member = $this->createPersona('pia');
    $this->signIn($member, 'siwe_login.verify');
    $this->assertSame($this->landingPath($this->community), $this->landing()->walletRedirect($member, $this->verifyFrom('/user/login?destination=' . $destination)));
  }

  /**
   * Destinations with a control character, encoded as in a page's address.
   */
  public static function controlCharacterDestinations(): array {
    return [
      'tab' => ['/%09/evil.example/x'],
      'newline' => ['/%0A/evil.example/x'],
      'carriage return' => ['/%0D/evil.example/x'],
      'tab after a path' => ['/node/5%09//evil.example/x'],
      'at the end' => ['/node/5%0A'],
      'null byte' => ['/%00/evil.example/x'],
      'delete' => ['/%7F/evil.example/x'],
      'escape' => ['/%1B/evil.example/x'],
    ];
  }

  /**
   * Ordinary local destinations still pass the filter.
   */
  public function testOrdinaryDestinationsPass(): void {
    $this->assertSame('/node/5', InviteLanding::localDestination('/node/5'));
    $this->assertSame('/search?q=a%20b', InviteLanding::localDestination('/search?q=a%20b'));
    $this->assertNull(InviteLanding::localDestination('//evil.example/x'));
    $this->assertNull(InviteLanding::localDestination('/\\evil.example/x'));
  }

  /**
   * Invitations waiting win over the group, and the first landing is spent.
   */
  public function testInvitationsWinOverTheGroup(): void {
    $member = $this->createPersona('jo');
    $loader = $this->createMock(GroupInvitationLoaderInterface::class);
    $loader->method('loadByUser')->willReturn(['pending']);
    $invited = new InviteLanding($loader, $this->container->get('localnodes_platform.first_landing'));

    $this->assertSame('social_core.my_invites', $invited->target($member)?->getRouteName());
    $this->assertTrue($this->isMember($this->community, $member));
    $this->assertNull($this->landing()->target($member), 'The first landing was this sign-in.');
  }

  /**
   * A member who left is not joined again.
   */
  public function testMemberWhoLeftIsNotRejoined(): void {
    $member = $this->createPersona('kai');
    $this->signIn($member, 'user.login');
    $this->community->removeMember(User::load($member->id()));
    $this->assertFalse($this->isMember($this->community, $member));

    $this->signIn($member, 'user.login');
    $this->assertFalse($this->isMember($this->community, $member));
  }

  /**
   * A group whose join method is not open is never joined, and not landed on.
   *
   * @dataProvider closedJoinMethods
   */
  public function testClosedGroupIsNeverJoined(array $methods): void {
    $this->community->set('field_group_allowed_join_method', $methods)->save();
    $member = $this->createPersona('lou');
    $request = $this->signIn($member, 'user.login');

    $this->assertFalse($this->isMember($this->community, $member));
    $this->assertFalse($request->query->has('destination'));
  }

  /**
   * Join methods that do not let anyone join by themselves.
   */
  public static function closedJoinMethods(): array {
    return [
      'added by managers' => [['added']],
      'request' => [['request']],
      'none' => [[]],
    ];
  }

  /**
   * The baseline the refused cases differ from: Join is open to this member.
   *
   * Also checks the kernel router carries Open Social's own Join checks, so
   * the refused cases below are refused by the real route.
   */
  public function testJoinRouteAllowsAVerifiedOutsider(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.group.join');
    $this->assertSame('group.view', $route->getRequirement('_entity_access'));
    $this->assertSame('TRUE', $route->getRequirement('_social_group_access'));
    $this->assertSame('join group', $route->getRequirement('_group_permission'));

    $member = $this->createPersona('uma');
    $this->assertTrue($this->mayJoin($member));
  }

  /**
   * An account without the verified role may not Join: not joined or landed.
   */
  public function testAccountThatMayNotJoinIsNotJoined(): void {
    $member = $this->createUser([], 'vic', FALSE);
    $this->assertFalse($this->mayJoin($member));

    $request = $this->signIn($member, 'user.login');
    $this->assertFalse($this->isMember($this->community, $member));
    $this->assertFalse($request->query->has('destination'));
    $this->assertSame([], $this->statusLines(), 'The group is never named.');
  }

  /**
   * A group hidden from outsiders is not joined, even with an open join field.
   */
  public function testHiddenGroupIsNotJoined(): void {
    $this->community->set('field_flexible_group_visibility', 'members')->save();
    $member = $this->createPersona('wes');
    $this->assertFalse($this->mayJoin($member));

    $request = $this->signIn($member, 'user.login');
    $this->assertFalse($this->isMember($this->community, $member));
    $this->assertFalse($request->query->has('destination'));
    $this->assertSame([], $this->statusLines());
  }

  /**
   * A blocked account is not joined.
   */
  public function testBlockedAccountIsNotJoined(): void {
    $member = $this->createPersona('xan');
    $member->block()->save();
    $this->container->get('localnodes_platform.first_landing')->join($member);
    $this->assertFalse($this->isMember($this->community, $member));
  }

  /**
   * A member who may no longer view the group is not landed there.
   */
  public function testMemberWhoMayNotViewIsNotLanded(): void {
    $member = $this->createPersona('yara');
    $this->addMember($this->community, $member);
    $this->community->setUnpublished()->save();
    $this->assertFalse($this->community->access('view', $member));

    $request = $this->signIn($member, 'user.login');
    $this->assertFalse($request->query->has('destination'));
    $this->assertSame([], $this->statusLines());
  }

  /**
   * No template, or none with an everyone group: nothing changes.
   */
  public function testNoTemplateChangesNothing(): void {
    $this->container->get('state')->delete(FirstLanding::TEMPLATE_STATE);
    $member = $this->createPersona('max');
    $request = $this->signIn($member, 'user.login');
    $this->assertFalse($this->isMember($this->community, $member));
    $this->assertFalse($request->query->has('destination'));
    $this->assertNotNull($this->container->get('user.data')->get(FirstLanding::MODULE, (int) $member->id(), FirstLanding::JOINED), 'The first sign-in is recorded all the same.');

    $this->recordTemplate([[$this->community, FALSE]]);
    $nia = $this->createPersona('nia');
    $this->assertFalse($this->signIn($nia, 'user.login')->query->has('destination'));
    $this->assertFalse($this->isMember($this->community, $nia));
  }

  /**
   * Two community-wide groups recorded: neither is joined, no landing.
   */
  public function testTwoEveryoneGroupsJoinNothing(): void {
    $this->recordTemplate([[$this->community, TRUE], [$this->other, TRUE]]);
    $member = $this->createPersona('quin');
    $request = $this->signIn($member, 'user.login');

    $this->assertFalse($this->isMember($this->community, $member));
    $this->assertFalse($this->isMember($this->other, $member));
    $this->assertFalse($request->query->has('destination'));
  }

  /**
   * The same group recorded twice still counts as one.
   */
  public function testTheSameGroupRecordedTwiceIsOne(): void {
    $this->recordTemplate([[$this->community, TRUE], [$this->community, TRUE]]);
    $member = $this->createPersona('rex');
    $this->assertSame($this->landingPath($this->community), $this->signIn($member, 'user.login')->query->get('destination'));
  }

  /**
   * A recorded group that no longer exists does not count.
   */
  public function testADeletedEveryoneGroupDoesNotCount(): void {
    $gone = $this->createFlexibleGroup('Gone', 'community');
    $this->recordTemplate([[$gone, TRUE], [$this->community, TRUE]]);
    $gone->delete();
    $member = $this->createPersona('sam');
    $this->assertSame($this->landingPath($this->community), $this->signIn($member, 'user.login')->query->get('destination'));
  }

  /**
   * The site setting turns it off; the shipped setting is on.
   */
  public function testSettingTurnsItOff(): void {
    $this->installShippedConfig('localnodes_platform', [FirstLanding::SETTINGS]);
    $this->assertTrue($this->config(FirstLanding::SETTINGS)->get('join_everyone'));

    $this->config(FirstLanding::SETTINGS)->set('join_everyone', FALSE)->save();
    $member = $this->createPersona('oli');
    $this->assertFalse($this->signIn($member, 'user.login')->query->has('destination'));
    $this->assertFalse($this->isMember($this->community, $member));
  }

  /**
   * A first sign-in while joining is off spends the join for good.
   *
   * The member joins by hand, leaves, and signs in again once joining is
   * back on: they are not joined again.
   */
  public function testFirstSignInWhileOffIsNeverJoinedLater(): void {
    $this->config(FirstLanding::SETTINGS)->set('join_everyone', FALSE)->save();
    $member = $this->createPersona('zed');
    $this->signIn($member, 'user.login');
    $this->addMember($this->community, $member);
    $this->community->removeMember(User::load($member->id()));

    $this->config(FirstLanding::SETTINGS)->set('join_everyone', TRUE)->save();
    $request = $this->signIn($member, 'user.login');
    $this->assertFalse($this->isMember($this->community, $member));
    $this->assertFalse($request->query->has('destination'));
  }

  /**
   * A first sign-in before the template exists spends the join too.
   */
  public function testFirstSignInBeforeTheTemplateIsNeverJoinedLater(): void {
    $this->container->get('state')->delete(FirstLanding::TEMPLATE_STATE);
    $member = $this->createPersona('abe');
    $this->signIn($member, 'user.login');

    $this->recordTemplate([[$this->community, TRUE]]);
    $this->assertFalse($this->signIn($member, 'user.login')->query->has('destination'));
    $this->assertFalse($this->isMember($this->community, $member));
  }

  /**
   * Someone removed from the group before ever signing in is not joined.
   */
  public function testRemovedBeforeTheFirstSignInIsNotJoined(): void {
    $member = $this->createPersona('bea');
    $this->addMember($this->community, $member);
    $this->community->removeMember(User::load($member->id()));

    $request = $this->signIn($member, 'user.login');
    $this->assertFalse($this->isMember($this->community, $member));
    $this->assertFalse($request->query->has('destination'));
  }

  /**
   * The first-sign-in record cannot be written: nothing is joined, no landing.
   */
  public function testRecordWriteFailureJoinsNothing(): void {
    $member = $this->createPersona('cal');
    $this->failWrites(FirstLanding::JOINED);
    $request = $this->signIn($member, 'user.login');

    $this->assertFalse($this->isMember($this->community, $member), 'Not joined while unrecorded.');
    $this->assertFalse($request->query->has('destination'));
  }

  /**
   * Only the first record write fails: that sign-in still joins nothing.
   *
   * The sign-in asks to join more than once (the sign-in hook, then the
   * landing); the second ask must not retry the failed one.
   */
  public function testRecordWriteFailureIsNotRetriedDuringSignIn(): void {
    $member = $this->createPersona('cal');
    $this->failWrites(FirstLanding::JOINED, 1);
    $request = $this->signIn($member, 'user.login');

    $this->assertFalse($this->isMember($this->community, $member), 'Not joined in the sign-in whose record write failed.');
    $this->assertFalse($request->query->has('destination'));
  }

  /**
   * The landing cannot be written: the sign-in goes on, with no landing.
   *
   * With no template and with one: the join is recorded either way, then the
   * landing's own write fails.
   */
  public function testLandingWriteFailureDoesNotStopSignIn(): void {
    $this->failWrites(FirstLanding::LANDED);
    $member = $this->createPersona('dee');
    $request = $this->signIn($member, 'user.login');
    $this->assertTrue($this->isMember($this->community, $member), 'The join is recorded before the landing.');
    $this->assertFalse($request->query->has('destination'));

    $this->container->get('state')->delete(FirstLanding::TEMPLATE_STATE);
    $other = $this->createPersona('eli');
    $this->assertFalse($this->signIn($other, 'user.login')->query->has('destination'));
    $this->assertNotNull($this->container->get('user.data')->get(FirstLanding::MODULE, (int) $other->id(), FirstLanding::JOINED));
  }

  /**
   * Makes writing this user data name throw, as a failing database would.
   *
   * @param string $name
   *   The user data name.
   * @param int|null $times
   *   How many writes fail before writes work again; NULL fails every one.
   */
  private function failWrites(string $name, ?int $times = NULL): void {
    $real = $this->container->get('user.data');
    $this->container->set('user.data', new class($real, $name, $times) implements UserDataInterface {

      public function __construct(private readonly UserDataInterface $real, private readonly string $name, private ?int $times) {}

      public function get($module, $uid = NULL, $name = NULL) {
        return $this->real->get($module, $uid, $name);
      }

      public function set($module, $uid, $name, $value) {
        if ($name === $this->name && ($this->times === NULL || $this->times-- > 0)) {
          throw new \RuntimeException('Write refused.');
        }
        $this->real->set($module, $uid, $name, $value);
      }

      public function delete($module = NULL, $uid = NULL, $name = NULL) {
        $this->real->delete($module, $uid, $name);
      }

    });
    // Built again with the failing store.
    $this->container->set('localnodes_platform.first_landing', NULL);
    $this->container->set('localnodes_platform.invite_landing', NULL);
  }

  /**
   * Records the template's groups as the organizer step does.
   *
   * @param array<array{0: \Drupal\group\Entity\GroupInterface, 1: bool}> $groups
   *   Each group with its everyone flag.
   */
  private function recordTemplate(array $groups): void {
    $this->container->get('state')->set(FirstLanding::TEMPLATE_STATE, [
      'id' => 'housing_coop',
      'version' => 1,
      'groups' => array_map(fn(array $g) => [
        'group' => (int) $g[0]->id(),
        'everyone' => $g[1],
        'group_template' => NULL,
      ], $groups),
      'repeatable' => [],
    ]);
  }

  /**
   * Signs the account in on a route, through core's finalize step.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The sign-in request, to read the destination the hooks set.
   */
  private function signIn(AccountInterface $account, string $route, array $query = []): Request {
    $request = Request::create('/sign-in', 'POST', $query);
    $request->query->replace($query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/sign-in'));
    $this->container->get('request_stack')->push($request);
    user_login_finalize(User::load($account->id()));
    $this->container->get('request_stack')->pop();
    // A sign-in leaves this account as the current user; the next one is new.
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    return $request;
  }

  /**
   * A verify request posted from a page with this address.
   */
  private function verifyFrom(string $page): Request {
    return Request::create('/siwe/verify', 'POST', [], [], [], ['HTTP_REFERER' => 'https://example.com' . $page]);
  }

  /**
   * The landing service as the site builds it.
   */
  private function landing(): InviteLanding {
    return $this->container->get('localnodes_platform.invite_landing');
  }

  /**
   * The group page (Open Social routes it to the stream) with the tour started.
   */
  private function landingPath(GroupInterface $group): string {
    return '/group/' . $group->id() . '/stream?tour=1';
  }

  /**
   * Whether the Join route lets this account join the community group.
   */
  private function mayJoin(AccountInterface $account): bool {
    return $this->container->get('access_manager')->checkNamedRoute('entity.group.join', ['group' => $this->community->id()], $account);
  }

  /**
   * The status lines shown so far, cleared.
   *
   * @return string[]
   *   Each line as text.
   */
  private function statusLines(): array {
    return array_map('strval', $this->container->get('messenger')->deleteByType('status'));
  }

  /**
   * Whether the account is in the group (fresh from storage).
   */
  private function isMember(GroupInterface $group, $account): bool {
    $group = $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($group->id());
    return (bool) $group->getMember(User::load($account->id()));
  }

}
