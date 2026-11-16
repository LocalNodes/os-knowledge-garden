<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\localnodes_platform\SiteGuide;
use Drupal\localnodes_platform\SiteGuidePages;
use Drupal\node\NodeInterface;
use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Installing the platform creates the Site guide pages and their account.
 *
 * Every site gets the member how-to pages and the tour page, owned by a site
 * guide account that nobody can sign in as. The pages must not stop the
 * start-up script from loading a demo that was asked for.
 *
 * Runs in the DDEV web container (scripts/run-phpunit.sh, PHPUNIT_MODE=web).
 *
 * @group localnodes_platform
 * @group phase22_functional
 */
final class SiteGuidePagesTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }
    $this->container->get('module_installer')->install(['localnodes_platform']);
    $this->rebuildAll();
    $this->htmlOutputEnabled = FALSE;
  }

  /**
   * The term, the blocked account and eleven pages, each linking to real screens.
   */
  public function testInstallCreatesThePages(): void {
    $repository = $this->container->get('entity.repository');
    $term = $repository->loadEntityByUuid('taxonomy_term', SiteGuide::TERM_UUID);
    $this->assertNotNull($term, 'The Site guide topic type exists.');
    $this->assertSame('Site guide', $term->label());

    $account = $repository->loadEntityByUuid('user', SiteGuidePages::ACCOUNT_UUID);
    $this->assertInstanceOf(UserInterface::class, $account);
    $this->assertSame(SiteGuidePages::ACCOUNT_NAME, $account->getAccountName());
    $this->assertTrue($account->isBlocked(), 'The guide account is blocked.');
    $this->assertSame([], $account->getRoles(TRUE), 'The guide account holds no role.');
    $this->assertSame('', (string) $account->getPassword(), 'The guide account has no password.');
    $this->assertStringEndsWith('.example', (string) $account->getEmail());

    $pages = SiteGuidePages::pages();
    $this->assertCount(11, $pages, 'The files hold ten how-to pages and the tour page.');
    $router = $this->container->get('router.no_access_checks');
    $checked_links = 0;
    foreach ($pages as $uuid => $page) {
      $node = $repository->loadEntityByUuid('node', $uuid);
      $this->assertInstanceOf(NodeInterface::class, $node, "{$page['title']} exists.");
      $this->assertSame($page['title'], $node->label());
      $this->assertSame('topic', $node->bundle());
      $this->assertSame((string) $account->id(), (string) $node->getOwnerId(), "{$page['title']} is owned by the guide account.");
      $this->assertSame([(string) $term->id()], array_map('strval', array_column($node->get('field_topic_type')->getValue(), 'target_id')));
      $this->assertSame('community', $node->get('field_content_visibility')->value);
      $this->assertTrue($node->isPublished());
      $body = (string) $node->get('body')->value;
      $this->assertNotEmpty($page['links'], "{$page['title']} links to at least one screen.");
      foreach ($page['links'] as $link) {
        $path = (string) $link['path'];
        $this->assertStringContainsString('<a href="' . $path . '">' . $link['text'] . '</a>', $body, "{$page['title']} carries its link to $path.");
        $this->assertTrue(Url::fromUserInput($path)->isRouted(), "$path is a route on this site.");
        $router->match($path);
        $checked_links++;
      }
    }
    $this->assertGreaterThanOrEqual(11, $checked_links);

    // A site with no demo named gets no demo marker.
    $state = $this->container->get('state');
    $state->resetCache();
    $this->assertNull($state->get(SiteGuidePages::DEMO_STARTED_KEY), 'No demo was named, so no demo marker is set.');
    $this->assertNull($state->get(SiteGuidePages::DEMO_DONE_KEY));
  }

  /**
   * Nobody can sign in as the guide account, even with a password set on it.
   */
  public function testGuideAccountCannotSignIn(): void {
    $account = $this->container->get('entity.repository')->loadEntityByUuid('user', SiteGuidePages::ACCOUNT_UUID);
    $this->assertInstanceOf(UserInterface::class, $account);

    // The control: an active account with a test password signs in through
    // the same form, so the refusal below comes from the block.
    $control = $this->drupalCreateUser();
    $this->drupalLogin($control);
    $this->drupalLogout();

    // A password exists only in this test, to show the block refuses sign-in
    // even if one were ever set.
    $password = bin2hex(random_bytes(12));
    $account->setPassword($password)->save();
    $this->drupalGet('user/login');
    $this->submitForm(['name' => SiteGuidePages::ACCOUNT_NAME, 'pass' => $password], 'Log in');
    $this->assertFalse($this->drupalUserIsLoggedIn($account), 'The blocked guide account cannot sign in.');
    $this->assertSession()->pageTextContains('has not been activated or is blocked');

    // A one-time login link made for it is refused.
    $this->drupalGet(user_pass_reset_url($account) . '/login');
    $this->assertFalse($this->drupalUserIsLoggedIn($account), 'A one-time link does not sign in the guide account.');
  }

  /**
   * An account already named site_guide is left alone; the guide gets another name.
   *
   * A site installed with DRUPAL_ADMIN_USER=site_guide has that name on the
   * admin account before the platform module installs.
   */
  public function testNameTakenByAnotherAccount(): void {
    $users = $this->container->get('entity_type.manager')->getStorage('user');
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $uuids = array_keys(SiteGuidePages::pages());
    $nodes->delete($nodes->loadByProperties(['uuid' => $uuids]));
    $users->delete($users->loadByProperties(['uuid' => SiteGuidePages::ACCOUNT_UUID]));
    $this->assertSame([], $users->loadByProperties(['uuid' => SiteGuidePages::ACCOUNT_UUID]));

    /** @var \Drupal\user\UserInterface $admin */
    $admin = $users->load(1);
    // An installed site's admin account is active; the test site's may not be.
    $admin->setUsername(SiteGuidePages::ACCOUNT_NAME)->activate()->save();
    $before = [
      'name' => $admin->getAccountName(),
      'mail' => $admin->getEmail(),
      'active' => $admin->isActive(),
      'roles' => $admin->getRoles(),
      'pass' => $admin->getPassword(),
      'uuid' => $admin->uuid(),
    ];
    $this->assertSame(SiteGuidePages::ACCOUNT_NAME, $before['name']);
    $this->assertTrue($before['active'], 'The control: the admin account is active before the install.');

    SiteGuidePages::install();

    $users->resetCache();
    $admin = $users->load(1);
    $after = [
      'name' => $admin->getAccountName(),
      'mail' => $admin->getEmail(),
      'active' => $admin->isActive(),
      'roles' => $admin->getRoles(),
      'pass' => $admin->getPassword(),
      'uuid' => $admin->uuid(),
    ];
    $this->assertSame($before, $after, 'The account that held the name is unchanged.');

    $guide = $this->container->get('entity.repository')->loadEntityByUuid('user', SiteGuidePages::ACCOUNT_UUID);
    $this->assertInstanceOf(UserInterface::class, $guide);
    $this->assertNotSame('1', (string) $guide->id());
    $this->assertSame(SiteGuidePages::ACCOUNT_NAME . '_2', $guide->getAccountName());
    $this->assertTrue($guide->isBlocked());
    $this->assertSame([], $guide->getRoles(TRUE));
    $owned = $nodes->loadByProperties(['uuid' => $uuids]);
    $this->assertCount(11, $owned);
    foreach ($owned as $node) {
      $this->assertSame((string) $guide->id(), (string) $node->getOwnerId());
    }
  }

  /**
   * The demo marker is set only on a fresh site with a demo named.
   */
  public function testDemoMarker(): void {
    $state = $this->container->get('state');
    $nodes = $this->container->get('entity_type.manager')->getStorage('node');
    $uuids = array_keys(SiteGuidePages::pages());
    $others = (int) $nodes->getQuery()->accessCheck(FALSE)->condition('uuid', $uuids, 'NOT IN')->count()->execute();
    $this->assertSame(0, $others, 'The fresh site holds no content besides the guide pages.');

    $reinstall = function (?string $demo) use ($state, $nodes, $uuids): ?string {
      $nodes->delete($nodes->loadByProperties(['uuid' => $uuids]));
      $settings = Settings::getAll();
      if ($demo === NULL) {
        unset($settings['localnodes_demo_module']);
      }
      else {
        $settings['localnodes_demo_module'] = $demo;
      }
      new Settings($settings);
      SiteGuidePages::install();
      $state->resetCache();
      return $state->get(SiteGuidePages::DEMO_STARTED_KEY);
    };

    // A named demo on a fresh site: the started marker, never the done one.
    $this->assertNotNull($reinstall('localnodes_demo_packs'), 'A named demo on a fresh site sets the started marker.');
    $this->assertNull($state->get(SiteGuidePages::DEMO_DONE_KEY), 'The finished marker is never set.');
    $this->assertCount(11, $nodes->loadByProperties(['uuid' => $uuids]));

    // "none" sets nothing.
    $state->delete(SiteGuidePages::DEMO_STARTED_KEY);
    $this->assertNull($reinstall('none'), 'DEMO_MODULE=none sets no marker.');

    // A finished demo load is not started again.
    $state->set(SiteGuidePages::DEMO_DONE_KEY, '2026-01-01T00:00:00Z');
    $this->assertNull($reinstall('localnodes_demo_packs'), 'A finished demo load gets no started marker.');
    $state->delete(SiteGuidePages::DEMO_DONE_KEY);

    // A site with other content is not a fresh site.
    $other = $nodes->create(['type' => 'topic', 'title' => 'Someone else\'s page', 'uid' => 1]);
    $other->save();
    $this->assertNull($reinstall('localnodes_demo_packs'), 'A site with other content gets no marker.');
    $other->delete();

    // With every page already there, nothing is created and no marker set.
    $this->assertNotNull($reinstall('localnodes_demo_packs'), 'The control: a fresh site sets the marker again.');
    $state->delete(SiteGuidePages::DEMO_STARTED_KEY);
    SiteGuidePages::install();
    $state->resetCache();
    $this->assertNull($state->get(SiteGuidePages::DEMO_STARTED_KEY), 'Installing over existing pages sets no marker.');
  }

}
