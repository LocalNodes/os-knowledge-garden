<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\FirstLanding;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;

/**
 * On a fresh pack site, a new member's first sign-in starts the group tour.
 *
 * Loads the Lantern Row pack as the entrypoint does, uninstalls the loader
 * and social_demo, then signs in a brand-new ordinary member: they are
 * joined to the co-op common room and land on it with the tour and its five
 * tips. Their second sign-in does not start the tour again.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackFirstLandingTest extends BrowserTestBase {

  use PackLoadTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  private const PROFILE = 'packs/lanternrow/';

  /**
   * The co-op common room in the Lantern Row pack.
   */
  private const COMMON_ROOM = 'cc5c4e0d-6bf1-4930-b736-96fd7d2eab2d';

  /**
   * The five tip titles, in order.
   */
  private const TIPS = [
    'Your shared group',
    "What's coming up",
    'Read the library',
    'Ask and check',
    'Your other groups',
  ];

  protected function setUp(): void {
    $this->setUpPackSite();
  }

  /**
   * A new member is joined, lands with the tour once, and never again.
   */
  public function testNewMemberGetsTheTourOnce(): void {
    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);
    // As the entrypoint: the loader and social_demo go once the pack is in.
    $this->container->get('module_installer')->uninstall(['localnodes_demo_packs', 'social_demo']);
    $this->rebuildAll();

    $common_room = $this->container->get('entity.repository')->loadEntityByUuid('group', self::COMMON_ROOM);
    $this->assertInstanceOf(GroupInterface::class, $common_room);
    $community = $this->container->get('localnodes_platform.first_landing')->communityGroup();
    $this->assertNotNull($community, 'The pack recorded a community-wide group.');
    $this->assertSame((int) $common_room->id(), (int) $community->id());
    $this->assertSame('housing_coop', $this->container->get('state')->get(FirstLanding::TEMPLATE_STATE)['id']);
    // Privacy: the group everyone is joined to is public or community-wide.
    $this->assertContains($common_room->get('field_flexible_group_visibility')->value, ['public', 'community']);

    $member = $this->drupalCreateUser();
    $this->assertFalse((bool) $common_room->getMember($member));
    $this->signIn($member);

    // The group's address answers on its stream page; core adds
    // check_logged_in after a sign-in.
    $this->assertMatchesRegularExpression('#/group/' . $common_room->id() . '(/stream)?\?tour=1(&|$)#', $this->getUrl(), 'The first sign-in lands on the common room with the tour.');
    $this->assertNotNull($this->loadGroup()->getMember($member), 'The new member was joined.');
    $this->assertSession()->pageTextContains("You're in Co-op common room.");
    $this->assertSame(self::TIPS, $this->tourTips());

    $this->drupalLogout();
    $this->signIn($member);
    $this->assertStringNotContainsString('tour=1', $this->getUrl(), 'The second sign-in does not start the tour.');
    $this->assertSession()->pageTextNotContains("You're in Co-op common room.");
  }

  /**
   * Signs in on the sign-in form with a password, as a member does.
   *
   * Not drupalLogin(): it uses a one-time link with its own destination,
   * and a destination in the address always wins over the first landing.
   */
  private function signIn(AccountInterface $account): void {
    $this->drupalGet(Url::fromRoute('user.login'));
    $this->submitForm([
      'name_or_mail' => $account->getAccountName(),
      'pass' => $account->passRaw,
    ], 'Log in');
    $account->sessionId = $this->getSession()->getCookie($this->container->get('session_configuration')->getOptions(\Drupal::request())['name']);
    $this->assertTrue($this->drupalUserIsLoggedIn($account), 'The member is signed in.');
    $this->loggedInUser = $account;
    $this->container->get('current_user')->setAccount($account);
  }

  /**
   * The common room, read again from the database.
   */
  private function loadGroup(): GroupInterface {
    $this->resetEntityCaches();
    return $this->container->get('entity.repository')->loadEntityByUuid('group', self::COMMON_ROOM);
  }

  /**
   * The tour step titles in this page's settings, in order.
   *
   * @return string[]
   *   The titles.
   */
  private function tourTips(): array {
    $settings = $this->getDrupalSettings();
    $titles = [];
    foreach ($settings['_tour_internal'] ?? [] as $step) {
      $titles[] = (string) ($step['title'] ?? '');
    }
    return $titles;
  }

}
