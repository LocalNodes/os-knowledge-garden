<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Who sees a member's email address, on Open Social's real pages (23-26).
 *
 * The site terms say: "organizers and site managers can see your account
 * details ... Other members can't see your email unless you choose to show
 * it on your profile." With localnodes_platform's install helper applied:
 * - an ordinary member opening another member's /user/N/information sees no
 *   address;
 * - a site manager does;
 * - each member's own "Show my email on my profile" switch is unlocked and
 *   off, and once a member turns it on, other members see that address.
 *
 * Why Functional: social_profile's privacy checks and the profile pages
 * don't boot in a Kernel test. localnodes_platform itself is not installed
 * (it pulls in the AI stack); its .install file is loaded by path and the
 * helper is called in setUp, as hook_install calls it (the same pattern as
 * GroupFeatureDefaultsTest). The Kernel ProfileEmailPrivacyTest proves
 * hook_install calls the helper.
 *
 * Runs in the DDEV web container (scripts/run-phpunit.sh, PHPUNIT_MODE=web).
 *
 * @group localnodes_platform
 * @group phase23
 * @group phase22_functional
 */
final class ProfileEmailPrivacyTest extends BrowserTestBase {

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
    // request to the DDEV Solr prints its response (as in
    // GroupFeatureDefaultsTest).
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }

    // Keep the fixture out of the lane's one Solr core.
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }

    $this->assertTrue(
      (bool) $this->config('social_profile.settings')->get('social_profile_show_email'),
      'Open Social installs with every address shown, so the helper is what changes it.',
    );
    $path = $this->container->get('extension.list.module')->getPath('localnodes_platform');
    require_once DRUPAL_ROOT . '/' . $path . '/localnodes_platform.install';
    _localnodes_platform_hide_profile_email();
    $this->assertFalse((bool) $this->config('social_profile.settings')->get('social_profile_show_email'));
  }

  /**
   * Creates a member with the given roles.
   */
  private function createAccount(string $name, array $roles): UserInterface {
    $account = $this->createUser([], $name, FALSE, ['roles' => $roles]);
    $this->assertInstanceOf(UserInterface::class, $account);
    return $account;
  }

  /**
   * Members don't see each other's address; site managers do; a member can
   * choose to show theirs.
   */
  public function testWhoSeesAMembersEmail(): void {
    $alex = $this->createAccount('alex_member', ['verified']);
    $blair = $this->createAccount('blair_member', ['verified']);
    $casey = $this->createAccount('casey_member', ['verified']);
    $steward = $this->createAccount('site_steward', ['verified', 'sitemanager']);
    $alex_info = '/user/' . $alex->id() . '/information';

    // An ordinary member sees Alex's profile but not the address.
    $this->drupalLogin($blair);
    $this->drupalGet($alex_info);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('alex_member');
    $this->assertSession()->responseNotContains($alex->getEmail());

    // A site manager sees it.
    $this->drupalLogin($steward);
    $this->drupalGet($alex_info);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains($alex->getEmail());

    // Alex's own switch is there, off, and not locked.
    $this->drupalLogin($alex);
    $this->drupalGet('/user/' . $alex->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $switch = $this->assertSession()->fieldExists('profile_privacy[social_profile_show_email]');
    $this->assertFalse($switch->isChecked(), 'The switch is off by default.');
    $this->assertFalse($switch->hasAttribute('disabled'), 'The switch is not locked by a site-wide setting.');

    // Alex turns it on; another member now sees the address.
    $this->submitForm(['profile_privacy[social_profile_show_email]' => TRUE], 'Save');
    $this->drupalLogin($casey);
    $this->drupalGet($alex_info);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains($alex->getEmail());
  }

}
