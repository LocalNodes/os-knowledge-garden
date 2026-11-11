<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Functional;

use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\search_api\Entity\Server;
use Drupal\symfony_mailer_test\MailerTestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * POST /localnodes/activate on the full social profile (23-35, B-1).
 *
 * A sign-up site makes its organizer blocked at install (drush
 * localnodes:organizer-setup) and tells the sign-up app "ready". After the
 * first payment the sign-up app calls this route with the sign-up's own
 * bearer token. The route unblocks the organizer, sends the 23-04 welcome mail
 * once and answers 200 {"activated":true}; any later call changes nothing.
 *
 * Why Functional: the bearer travels in the Authorization header, and only a
 * real HTTP request through the social profile's authentication providers
 * proves no provider claims it before the controller runs (T-23-155). The
 * test installs localnodes_platform after the social profile, once Open
 * Social's Solr server points at a host that doesn't resolve, and
 * symfony_mailer_test, which captures mail in state with a null transport.
 *
 * The token, the organizer's address and the sign-in link are only ever
 * checked for absence in replies; no assertion message carries them.
 *
 * Runs in the DDEV web container (scripts/run-phpunit.sh, PHPUNIT_MODE=web).
 *
 * @group localnodes_platform
 * @group phase23
 * @group phase22_functional
 */
final class ActivateRouteTest extends BrowserTestBase {

  use MailerTestTrait;

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
   * This sign-up's id: 22 characters, as the sign-up app makes them.
   */
  private const PROVISION_ID = 'k3Jx9_Qm-2LpWv7RtYd4Nz';

  /**
   * Another sign-up's id, of the same shape.
   */
  private const OTHER_PROVISION_ID = 'Zz9_Qm-2LpWv7RtYd4Nk3J';

  /**
   * This sign-up's callback token (fictional; 64 hex characters).
   */
  private const TOKEN = '7f3a9c0e1b2d4f6a8c0e2b4d6f8a0c2e4b6d8f0a2c4e6b8d0f2a4c6e8b0d2f4a';

  /**
   * The organizer: fictional, on a domain the mail filter lets pass.
   */
  private const ORGANIZER_EMAIL = 'robin.activation@mail.localnodes.xyz';

  /**
   * The community's name.
   */
  private const SITE_NAME = 'Riverside Neighbors';

  /**
   * The organizer's password in this test only (set on the blocked account).
   */
  private const PASSWORD = 'correct-horse-battery-staple-23';

  /**
   * {@inheritdoc}
   *
   * The sign-up identity goes into the site's settings.php, as the container
   * settings.php puts it from PROVISION_ID and PROVISION_CALLBACK_TOKEN.
   */
  protected function prepareSettings() {
    parent::prepareSettings();
    $this->writeSettings([
      'settings' => [
        'localnodes_provision' => (object) [
          'value' => ['id' => self::PROVISION_ID, 'callback_token' => self::TOKEN],
          'required' => TRUE,
        ],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to the DDEV Solr prints its response (as in
    // ProfileEmailPrivacyTest).
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

    // The route lives in localnodes_platform; symfony_mailer_test swaps the
    // transport for a null one and keeps every sent mail in state.
    $this->container->get('module_installer')->install(['localnodes_platform', 'symfony_mailer_test']);
    $this->rebuildAll();

    putenv('ORGANIZER_EMAIL=' . self::ORGANIZER_EMAIL);
    putenv('ORGANIZER_NAME=Robin');
    putenv('SITE_NAME=' . self::SITE_NAME);
    $this->config('system.site')->set('name', self::SITE_NAME)->save();

    // The install has finished: the entrypoint writes this marker before the
    // ready callback.
    $this->container->get('state')->set('localnodes.install_complete', gmdate('Y-m-d\TH:i:s\Z'));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('ORGANIZER_EMAIL');
    putenv('ORGANIZER_NAME');
    putenv('SITE_NAME');
    parent::tearDown();
  }

  /**
   * Runs drush localnodes:organizer-setup in this process.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything the command printed.
   */
  private function runSetup(): array {
    $output = new BufferedOutput();
    $command = new OrganizerCommands();
    $command->setOutput($output);
    $code = $command->organizerSetup();
    return [$code, $output->fetch()];
  }

  /**
   * POSTs to the activation route.
   *
   * @param array<string, string> $headers
   *   The request headers.
   * @param string $body
   *   The raw request body.
   * @param string $method
   *   The HTTP method.
   */
  private function callActivate(array $headers, string $body, string $method = 'POST'): ResponseInterface {
    return $this->getHttpClient()->request($method, $this->buildUrl('/localnodes/activate'), [
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
      'headers' => $headers,
      'body' => $body,
    ]);
  }

  /**
   * The headers and body the sign-up app sends.
   *
   * @return array{0: array<string, string>, 1: string}
   *   Headers and body.
   */
  private function signUpRequest(): array {
    return [
      [
        'Authorization' => 'Bearer ' . self::TOKEN,
        'Content-Type' => 'application/json',
        'User-Agent' => 'LocalNodes-Onboarding/1 (activate)',
      ],
      json_encode(['provision_id' => self::PROVISION_ID]),
    ];
  }

  /**
   * The organizer's account, read fresh from the database.
   */
  private function organizer(): ?UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache();
    $found = $storage->loadByProperties(['mail' => self::ORGANIZER_EMAIL]);
    return $found ? reset($found) : NULL;
  }

  /**
   * A state value as the site under test holds it now.
   */
  private function stateValue(string $key): mixed {
    $state = $this->container->get('state');
    $state->resetCache();
    return $state->get($key);
  }

  /**
   * Asserts a reply carries no address, no link and no part of the token.
   */
  private function assertReplyLeaksNothing(ResponseInterface $response): void {
    $body = (string) $response->getBody();
    $headers = json_encode($response->getHeaders());
    foreach ([$body, $headers] as $text) {
      $this->assertFalse(str_contains($text, self::ORGANIZER_EMAIL), 'The reply holds no address.');
      $this->assertFalse(str_contains($text, 'robin.activation'), 'The reply holds no part of the address.');
      $this->assertFalse(str_contains($text, '/user/reset/'), 'The reply holds no sign-in link.');
      $this->assertFalse(str_contains($text, substr(self::TOKEN, 0, 12)), 'The reply holds no part of the token.');
      $this->assertFalse(str_contains($text, substr(self::TOKEN, -12)), 'The reply holds no part of the token.');
    }
  }

  /**
   * Asserts a JSON reply: status, body and no-store.
   */
  private function assertJsonReply(ResponseInterface $response, int $status, array $body): void {
    $this->assertSame($status, $response->getStatusCode());
    $this->assertSame($body, json_decode((string) $response->getBody(), TRUE));
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $this->assertReplyLeaksNothing($response);
  }

  /**
   * Behaviour 1: organizer-setup makes a blocked site manager and the first
   * group, keeps the uid in state, sends no mail and prints no address.
   */
  public function testSetupMakesABlockedOrganizerAndTheFirstGroupWithoutMail(): void {
    [$code, $printed] = $this->runSetup();
    $this->assertSame(0, $code);
    $this->assertFalse(str_contains($printed, self::ORGANIZER_EMAIL), 'The output holds no address.');
    $this->assertFalse(str_contains($printed, 'http'), 'The output holds no link.');

    $organizer = $this->organizer();
    $this->assertNotNull($organizer, 'The organizer account exists.');
    $this->assertTrue($organizer->isBlocked(), 'The organizer is blocked until the first payment.');
    $this->assertTrue($organizer->hasRole('sitemanager'));
    $this->assertFalse($organizer->hasRole('administrator'));
    $this->assertSame((int) $organizer->id(), (int) $this->stateValue('localnodes.organizer_uid'));
    $this->assertNotEmpty($this->stateValue('localnodes.install_step.organizer_setup'));

    $groups = $this->container->get('entity_type.manager')->getStorage('group')
      ->loadByProperties(['type' => 'flexible_group', 'label' => self::SITE_NAME, 'uid' => $organizer->id()]);
    $this->assertCount(1, $groups, 'The first group exists, owned by the organizer.');
    $member = reset($groups)->getMember($organizer);
    $this->assertNotFalse($member);
    $this->assertArrayHasKey('flexible_group-group_manager', $member->getRoles(FALSE));

    $this->assertEmpty($this->stateValue('localnodes.install_step.organizer_mail_sent'));
    $this->noMail();
  }

  /**
   * Behaviours 2, 3 and 5: the first call unblocks and sends one mail; a
   * second identical call answers the same and changes nothing; no reply
   * holds an address, a link or the token.
   */
  public function testActivateUnblocksSendsOneMailAndIsIdempotent(): void {
    $this->runSetup();
    [$headers, $body] = $this->signUpRequest();

    $first = $this->callActivate($headers, $body);
    $this->assertJsonReply($first, 200, ['activated' => TRUE]);

    $organizer = $this->organizer();
    $this->assertTrue($organizer->isActive(), 'The organizer is active after activation.');
    $this->assertNotEmpty($this->stateValue('localnodes.organizer_activated'));
    $this->assertNotEmpty($this->stateValue('localnodes.install_step.organizer_mail_sent'));

    // readMail() asserts there is exactly one mail, then clears the store.
    $this->container->get('state')->resetCache();
    $this->readMail();
    $this->assertSubject('Welcome to ' . self::SITE_NAME);
    $recipients = array_map(fn ($address) => $address->getEmail(), $this->email->getTo());
    $this->assertSame([self::ORGANIZER_EMAIL], $recipients, 'The mail goes to the organizer only.');
    $this->assertTrue(str_contains((string) $this->email->getHtmlBody(), '/user/reset/'), 'The mail body holds a one-time sign-in link.');
    $activated_at = $this->stateValue('localnodes.organizer_activated');
    $changed = $organizer->getChangedTime();

    $second = $this->callActivate($headers, $body);
    $this->assertJsonReply($second, 200, ['activated' => TRUE]);
    $this->container->get('state')->resetCache();
    $this->noMail();
    $this->assertSame($activated_at, $this->stateValue('localnodes.organizer_activated'));
    $again = $this->organizer();
    $this->assertTrue($again->isActive());
    $this->assertSame($changed, $again->getChangedTime(), 'The second call does not save the account.');
  }

  /**
   * Behaviour 4: before activation the organizer cannot sign in; after it,
   * the same name and password work.
   */
  public function testBlockedOrganizerCannotSignInUntilActivated(): void {
    $this->runSetup();
    $organizer = $this->organizer();
    $organizer->setPassword(self::PASSWORD)->save();
    $this->assertTrue($this->organizer()->isBlocked());

    $this->drupalGet('user/login');
    $this->submitForm(['name_or_mail' => $organizer->getAccountName(), 'pass' => self::PASSWORD], 'Log in');
    $this->assertSession()->pageTextContains('has not been activated or is blocked');

    [$headers, $body] = $this->signUpRequest();
    $this->assertSame(200, $this->callActivate($headers, $body)->getStatusCode());
    $this->container->get('state')->resetCache();
    $this->readMail();

    $this->drupalGet('user/login');
    $this->submitForm(['name_or_mail' => $organizer->getAccountName(), 'pass' => self::PASSWORD], 'Log in');
    $this->assertSession()->pageTextNotContains('has not been activated or is blocked');
    $this->drupalGet('user/' . $organizer->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Task 2: every refusal answers its fixed JSON with no-store, leaks
   * nothing, leaves the organizer blocked and sends no mail. The token is
   * checked before the body: a wrong token with a broken body is a 401.
   */
  public function testRefusalsChangeNothing(): void {
    $this->runSetup();
    $json = 'application/json';
    $good_body = json_encode(['provision_id' => self::PROVISION_ID]);
    $wrong_token = str_repeat('0', strlen(self::TOKEN));
    $cases = [
      'no Authorization header' => [[], $good_body, 401, ['error' => 'unauthorized']],
      'a wrong token of the same length' => [['Authorization' => 'Bearer ' . $wrong_token], $good_body, 401, ['error' => 'unauthorized']],
      'the token without the Bearer word' => [['Authorization' => self::TOKEN], $good_body, 401, ['error' => 'unauthorized']],
      'Basic credentials' => [['Authorization' => 'Basic ' . base64_encode('admin:' . self::TOKEN)], $good_body, 401, ['error' => 'unauthorized']],
      'the token with a character more' => [['Authorization' => 'Bearer ' . self::TOKEN . 'a'], $good_body, 401, ['error' => 'unauthorized']],
      'a wrong token with a broken body (token first)' => [['Authorization' => 'Bearer ' . $wrong_token], 'not json', 401, ['error' => 'unauthorized']],
      'another sign-up\'s provision id' => [['Authorization' => 'Bearer ' . self::TOKEN], json_encode(['provision_id' => self::OTHER_PROVISION_ID]), 401, ['error' => 'unauthorized']],
      'a body that is not JSON' => [['Authorization' => 'Bearer ' . self::TOKEN], 'provision_id=' . self::PROVISION_ID, 400, ['error' => 'bad_request']],
      'an empty body' => [['Authorization' => 'Bearer ' . self::TOKEN], '', 400, ['error' => 'bad_request']],
      'a JSON list' => [['Authorization' => 'Bearer ' . self::TOKEN], json_encode([self::PROVISION_ID]), 400, ['error' => 'bad_request']],
      'a provision id of 21 characters' => [['Authorization' => 'Bearer ' . self::TOKEN], json_encode(['provision_id' => substr(self::PROVISION_ID, 1)]), 400, ['error' => 'bad_request']],
      'a provision id that is a number' => [['Authorization' => 'Bearer ' . self::TOKEN], json_encode(['provision_id' => 1234567890123456789012]), 400, ['error' => 'bad_request']],
      'no provision id' => [['Authorization' => 'Bearer ' . self::TOKEN], json_encode(['id' => self::PROVISION_ID]), 400, ['error' => 'bad_request']],
    ];
    foreach ($cases as $label => [$headers, $body, $status, $reply]) {
      $response = $this->callActivate($headers + ['Content-Type' => $json], $body);
      $this->assertSame($status, $response->getStatusCode(), "$label: status");
      $this->assertSame($reply, json_decode((string) $response->getBody(), TRUE), "$label: body");
      $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), "$label: no-store");
      $this->assertReplyLeaksNothing($response);
    }

    // A GET is refused by the router.
    [$headers, $body] = $this->signUpRequest();
    $get = $this->callActivate($headers, '', 'GET');
    $this->assertSame(405, $get->getStatusCode());
    $this->assertReplyLeaksNothing($get);

    $this->assertTrue($this->organizer()->isBlocked(), 'No refusal unblocked the organizer.');
    $this->assertEmpty($this->stateValue('localnodes.organizer_activated'));
    $this->assertEmpty($this->stateValue('localnodes.install_step.organizer_mail_sent'));
    $this->container->get('state')->resetCache();
    $this->noMail();

    // Not ready: the install marker is gone (a site still installing).
    $this->container->get('state')->delete('localnodes.install_complete');
    $this->assertJsonReply($this->callActivate($headers, $body), 409, ['error' => 'not_ready']);
    $this->assertTrue($this->organizer()->isBlocked(), 'not_ready changes nothing.');
    $this->container->get('state')->resetCache();
    $this->noMail();
  }

  /**
   * 23-43 Task 5: while another request holds the activation lock, the route
   * answers 409 {"error":"busy"} with no-store and changes nothing; once the
   * lock is free the next call activates and sends one mail. The bearer is
   * still checked first: a wrong token gets 401, not busy.
   */
  public function testBusyWhileAnotherRequestHoldsTheLock(): void {
    $this->runSetup();
    [$headers, $body] = $this->signUpRequest();
    // This test process stands in for a second activate request: its own lock
    // backend, so its own lock id, on the site's database.
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire('localnodes_organizer_activate', 180));

    $this->assertJsonReply($this->callActivate($headers, $body), 409, ['error' => 'busy']);
    $wrong = $this->callActivate(['Authorization' => 'Bearer ' . str_repeat('0', strlen(self::TOKEN))] + $headers, $body);
    $this->assertJsonReply($wrong, 401, ['error' => 'unauthorized']);
    $this->assertTrue($this->organizer()->isBlocked(), 'busy leaves the organizer blocked.');
    $this->assertEmpty($this->stateValue('localnodes.organizer_activated'));
    $this->assertEmpty($this->stateValue('localnodes.install_step.organizer_mail_sent'));
    $this->container->get('state')->resetCache();
    $this->noMail();

    $other->release('localnodes_organizer_activate');
    $this->assertJsonReply($this->callActivate($headers, $body), 200, ['activated' => TRUE]);
    $this->assertTrue($this->organizer()->isActive());
    $this->container->get('state')->resetCache();
    $this->readMail();
    $this->assertSubject('Welcome to ' . self::SITE_NAME);
  }

  /**
   * Task 2: a site with no callback token (a demo or manual instance)
   * answers 404 to every request, with the right-looking token or without.
   */
  public function testSiteWithoutTokenAnswersNotFound(): void {
    $this->runSetup();
    $this->writeSettings([
      'settings' => [
        'localnodes_provision' => (object) [
          'value' => ['id' => '', 'callback_token' => ''],
          'required' => TRUE,
        ],
      ],
    ]);
    [$headers, $body] = $this->signUpRequest();
    foreach ([
      'the sign-up token' => $headers,
      'an empty bearer' => ['Authorization' => 'Bearer '] + $headers,
      'no header' => ['Content-Type' => 'application/json'],
    ] as $label => $request_headers) {
      $response = $this->callActivate($request_headers, $body);
      $this->assertSame(404, $response->getStatusCode(), "$label: status");
      $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), "$label: no-store");
      $this->assertReplyLeaksNothing($response);
    }
    $this->assertTrue($this->organizer()->isBlocked());
    $this->container->get('state')->resetCache();
    $this->noMail();
  }

}
