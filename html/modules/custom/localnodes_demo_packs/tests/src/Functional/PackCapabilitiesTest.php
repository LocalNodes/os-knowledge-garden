<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * What a pack can say: dates, accounts, the export owner and closed polls.
 *
 * Loads the smoke fixture with the day-token anchor fixed at noon on
 * Thursday 12 November 2026, in the site's timezone.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackCapabilitiesTest extends BrowserTestBase {

  use PackLoadTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  private const PROFILE = 'tests/fixtures/packs/smoke/';

  private const TOPIC = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d20';

  private const OWNER = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d01';

  private const MEMBER = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d02';

  private const CLOSED_POLL = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d30';

  private const OPEN_POLL = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d31';

  private const BADMAIL ='5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d99';

  protected function setUp(): void {
    $this->setUpPackSite();
    $this->container->get('state')->set('localnodes_demo_packs.anchor', mktime(12, 0, 0, 11, 12, 2026));
  }

  /**
   * A day token in pack text loads as the weekday and date.
   */
  public function testDayTokenLoadsAsADate(): void {
    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);
    $topic = $this->container->get('entity.repository')->loadEntityByUuid('node', self::TOPIC);
    $this->assertNotNull($topic);
    $body = (string) $topic->get('body')->value;
    $this->assertStringContainsString('We meet again on Thursday 19 November.', $body);
    $this->assertStringNotContainsString('{{', $body);
  }

  /**
   * No seeded account can be signed into with its username as password.
   */
  public function testNoPasswordMatchesTheUsername(): void {
    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);
    $this->resetEntityCaches();
    $password = $this->container->get('password');
    foreach ([self::OWNER, self::MEMBER] as $uuid) {
      $account = $this->account($uuid);
      $hash = (string) $account->getPassword();
      $this->assertNotSame('', $hash, "Account $uuid has a password hash.");
      $this->assertFalse($password->check($account->getAccountName(), $hash), "Account $uuid's password is not its username.");
    }
  }

  /**
   * A user load that stops part way leaves no account and no owner behind.
   *
   * social_demo saves each account with its username as password before
   * this plugin replaces it, so a half-finished load must keep nothing. An
   * existing account with the second user's name makes that save fail.
   * Once the clash is gone, the next load creates everyone and the owner.
   */
  public function testInterruptedLoadKeepsNothing(): void {
    $clash = User::create(['name' => 'smoke_two', 'mail' => 'clash@smoke.example', 'status' => 1]);
    $clash->save();
    // The first pack account would take the next uid.
    $next_uid = (int) $clash->id() + 1;
    // From the live container, as in a drush run: the module install in
    // setUp() rebuilt it, so $this->container holds older services.
    $plugin = \Drupal::service('plugin.manager.demo_content')->createInstance('pack_user');
    $plugin->setProfile(self::PROFILE);
    // The memory check runs in the same request as the load: the memory
    // cache dates an expired entry by the current request's time, and a
    // drush run has only one request.
    [$stopped, $remembered] = $this->asSiteAdmin(function () use ($plugin, $next_uid) {
      try {
        $plugin->createContent();
        return [FALSE, NULL];
      }
      catch (\Exception $e) {
        // No cache reset here: nothing in memory may still hold the account.
        return [TRUE, User::load($next_uid)];
      }
    });
    $this->assertTrue($stopped, 'The load stopped at the clashing name.');
    $this->assertNull($remembered, 'The first account is gone from memory too.');
    $this->assertNull($this->container->get('entity.repository')->loadEntityByUuid('user', self::OWNER), 'The first account was rolled back.');
    $state = $this->container->get('state');
    $state->resetCache();
    $this->assertSame(0, (int) $state->get('localnodes.organizer_uid', 0), 'No owner was recorded.');

    // The same plugin, retried once the clash is gone, creates everyone.
    $clash->delete();
    $created = $this->asSiteAdmin(fn () => $plugin->createContent());
    $this->assertCount(3, $created);
    $this->resetEntityCaches();
    $state->resetCache();
    $owner = $this->account(self::OWNER);
    $this->assertSame((int) $owner->id(), (int) $state->get('localnodes.organizer_uid'));
    $this->assertFalse($this->container->get('password')->check('smoke_one', (string) $owner->getPassword()));
  }

  /**
   * A pack with an address outside the reserved domains creates nobody.
   */
  public function testUnreservedAddressStopsTheLoad(): void {
    $before = count($this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(FALSE)->execute());
    try {
      $this->runPlugins(['pack_user'], 'createContent', 'tests/fixtures/packs/badmail/');
      $this->fail('The bad-mail pack loaded.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Pack user ' . self::BADMAIL, $e->getMessage());
      $this->assertStringNotContainsString('bad@localnodes.xyz', $e->getMessage());
    }
    $this->resetEntityCaches();
    $this->assertNull($this->container->get('entity.repository')->loadEntityByUuid('user', self::BADMAIL));
    $after = count($this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(FALSE)->execute());
    $this->assertSame($before, $after, 'No account was created.');
  }

  /**
   * The pack's owner becomes the export owner, unless one is already set.
   */
  public function testExportOwnerIsSetOnce(): void {
    $state = $this->container->get('state');
    $this->assertSame(0, (int) $state->get('localnodes.organizer_uid', 0), 'A demo install starts with no owner.');

    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);
    $this->resetEntityCaches();
    $state->resetCache();
    $owner = $this->account(self::OWNER);
    $this->assertSame((int) $owner->id(), (int) $state->get('localnodes.organizer_uid'));
    $this->assertContains('sitemanager', $owner->getRoles());
    $this->assertNotContains('sitemanager', $this->account(self::MEMBER)->getRoles(), 'Only the marked user gets the role.');
    $this->assertTrue($this->container->get('localnodes_platform.export_access')->hasOwner());

    // A site that already has an owner keeps it.
    $this->runPlugins(array_reverse(self::PLUGINS), 'removeContent', self::PROFILE);
    $state->set('localnodes.organizer_uid', 1);
    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);
    $state->resetCache();
    $this->assertSame(1, (int) $state->get('localnodes.organizer_uid'), 'An existing owner is left alone.');
  }

  /**
   * A presenter's one-time link signs the walk-through user in.
   */
  public function testOneTimeLinkSignsIn(): void {
    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);
    $this->resetEntityCaches();
    $member = $this->account(self::MEMBER);
    $uid = (int) $member->id();

    // /user sends a visitor to the sign-in form and a member to their page.
    $this->drupalGet('user');
    $this->assertSession()->addressMatches('#/user/login$#');

    // The link and the session cookie it sets must not reach the browser
    // output files, which record each request's URI and response headers.
    $output_enabled = $this->htmlOutputEnabled;
    $written = $this->htmlOutputCounter;
    $this->htmlOutputEnabled = FALSE;
    try {
      $this->drupalGet(user_pass_reset_url($member) . '/login');
      $this->drupalGet('user');
      $this->assertSession()->addressMatches("#/user/$uid(/|$)#");
    }
    finally {
      $this->htmlOutputEnabled = $output_enabled;
    }
    $this->assertSame($written, $this->htmlOutputCounter, 'Nothing from the signed-in requests was written to browser output.');
  }

  /**
   * A poll marked closed loads closed with its answers; others load open.
   */
  public function testClosedPollLoadsClosed(): void {
    $this->runPlugins(self::PLUGINS, 'createContent', self::PROFILE);
    $this->resetEntityCaches();
    $repository = $this->container->get('entity.repository');

    $closed = $repository->loadEntityByUuid('poll', self::CLOSED_POLL);
    $this->assertNotNull($closed, 'The closed poll loaded.');
    $this->assertSame(0, (int) $closed->get('status')->value, 'closed: true loads the poll closed.');
    $votes = (int) $this->container->get('database')->select('poll_vote', 'v')
      ->condition('pid', $closed->id())
      ->countQuery()->execute()->fetchField();
    $this->assertSame(2, $votes, 'The closed poll keeps both answers.');

    $open = $repository->loadEntityByUuid('poll', self::OPEN_POLL);
    $this->assertNotNull($open, 'The open poll loaded.');
    $this->assertSame(1, (int) $open->get('status')->value, 'A poll not marked closed loads open.');
  }

  /**
   * Loads a seeded account by uuid.
   */
  private function account(string $uuid): User {
    $account = $this->container->get('entity.repository')->loadEntityByUuid('user', $uuid);
    $this->assertInstanceOf(User::class, $account, "Account $uuid exists.");
    return $account;
  }

  /**
   * Runs a callback as the site's first account, as social-demo:add does.
   */
  private function asSiteAdmin(callable $callback): mixed {
    $this->container->get('request_stack')->push(Request::create('/'));
    $this->container->get('current_user')->setAccount(User::load(1));
    try {
      return $callback();
    }
    finally {
      $this->container->get('current_user')->setAccount(User::getAnonymousUser());
      $this->container->get('request_stack')->pop();
    }
  }

}
