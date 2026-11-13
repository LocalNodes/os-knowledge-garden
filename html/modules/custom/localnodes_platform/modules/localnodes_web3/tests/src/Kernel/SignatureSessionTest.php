<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\SignInMethod;
use Drupal\localnodes_web3\Controller\SignatureProofController;
use Drupal\localnodes_web3\EventSubscriber\SignatureGuard;
use Drupal\localnodes_web3\SignatureSession;
use Drupal\siwe_login\Controller\SiweAuthController;
use Drupal\siwe_login\Service\SiweMessageValidator;
use Drupal\siwe_login\Service\SiweNonceStore;
use Drupal\siwe_login\Siwe\VerifiedSiweLogin;
use Drupal\Tests\siwe_login\Traits\SiweTestTrait;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * A session is proven only by the account's own wallet.
 *
 * Fund and vote changes trust the browser session, so a stolen or shared
 * password must not be enough on its own:
 *
 * - A password or emailed-link sign-in alone is not proven; nor is anything
 *   unknown or malformed.
 * - A sign-in through /siwe/verify is proven for that account and address
 *   only, and stops being proven if the address changes or the account is
 *   blocked.
 * - A password session proves itself by signing the message the site
 *   issues. The proof dies with the sign-in it was made in, and is refused
 *   for a replay, another key, another session, another account, a sign-in
 *   nonce, a changed message, or after the message expires.
 * - Changing the address ends every earlier proof, even if it changes back,
 *   and voids every message issued before the change, signed or not.
 * - The guard, through the real event dispatcher and router, refuses
 *   requests to a marked route until proven, deciding on the method the
 *   client really sent: only a real GET or HEAD passes, and not even those
 *   on a route marked for reads too. The endpoints need a CSRF header.
 *
 * No network: http_client answers nothing, as in siwe_login's kernel tests.
 *
 * @group localnodes_web3
 */
final class SignatureSessionTest extends KernelTestBase {

  use SiweTestTrait;

  /**
   * A second key, for "another wallet signed it".
   */
  private const OTHER_KEY = '59c6995e998f97a5a0044966f0945389dc9e86dae88c7a8412f4603b6b78690d';

  private const DOMAIN = 'repair.example';

  /**
   * What a browser sends when a form is submitted without JavaScript.
   */
  private const BROWSER_FORM = [
    'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'siwe_login'];

  /**
   * The browser session the tests act in.
   */
  private Session $session;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'siwe_login']);
    $this->config('system.site')->set('name', 'Repair Collective')->save();
    FieldStorageConfig::create([
      'field_name' => 'field_ethereum_address',
      'entity_type' => 'user',
      'type' => 'string',
      'settings' => ['max_length' => 42, 'case_sensitive' => FALSE, 'is_ascii' => TRUE],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ethereum_address',
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();
    $this->enableModules(['localnodes_platform', 'localnodes_web3']);
    $this->container->get('router.builder')->rebuild();
    $this->config('siwe_login.settings')
      ->set('expected_domain', self::DOMAIN)
      ->set('expected_uri', 'https://' . self::DOMAIN)
      ->set('chain_ids', [1])
      ->set('require_email_verification', FALSE)
      ->set('require_ens_or_username', FALSE)
      ->set('enable_ens_validation', FALSE)
      ->set('enable_reverse_ens_lookup', FALSE)
      ->save();
    // No live network: every request would need a queued answer.
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler())]));
    User::create(['name' => 'admin', 'mail' => 'admin@example.com', 'status' => 1])->save();
    $this->session = $this->newSession();
    $this->push('/');
  }

  /**
   * Password, emailed link, other and unknown sign-ins are not proven.
   */
  public function testOtherSignInsAreNotProven(): void {
    $member = $this->member('ana', $this->testAddress());
    $this->assertFalse($this->proven($member), 'Not signed in: not proven.');

    foreach (['user.login', 'user.reset.login', 'localnodes_web3.email_link_sign_in', 'siwe_login.email_verification_confirm', NULL] as $route) {
      $this->signIn($member, $route);
      $this->assertFalse($this->proven($member), 'Not proven after a sign-in on ' . ($route ?? 'no route'));
    }

    // Malformed or hand-made records.
    $good = [
      'method' => SignInMethod::SIGNATURE,
      'at' => 5,
      'uid' => (int) $member->id(),
      'address' => strtolower($this->testAddress()),
      'id' => str_repeat('a', 32),
      'sid' => hash('sha256', $this->session->getId()),
    ];
    foreach ([
      'legacy, no account or id' => ['method' => SignInMethod::SIGNATURE, 'at' => 5],
      'no id' => ['id' => NULL] + $good,
      'bad id' => ['id' => 'x'] + $good,
      'no session hash' => ['sid' => NULL] + $good,
      'another session' => ['sid' => hash('sha256', 'another')] + $good,
      'uid as string' => ['uid' => (string) $member->id()] + $good,
      'empty address' => ['address' => ''] + $good,
      'no time' => ['at' => NULL] + $good,
      'time not a number' => ['at' => ['not-a-time']] + $good,
      'time zero' => ['at' => 0] + $good,
      'password' => ['method' => SignInMethod::PASSWORD] + $good,
      'a string' => 'signature',
    ] as $label => $record) {
      $this->session->set(SignInMethod::KEY, $record);
      $this->assertFalse($this->proven($member), $label);
      $this->assertFalse(SignInMethod::signedInBySignature($this->session, (int) $member->id(), $this->testAddress()), $label . ' (base check)');
    }
    // The same record, well formed, is proven: the cases above fail for
    // their one difference.
    $this->session->set(SignInMethod::KEY, $good);
    $this->assertTrue($this->proven($member));
    $this->assertTrue(SignInMethod::signedInBySignature($this->session, (int) $member->id(), $this->testAddress()));

    // The whole record copied into another session is not.
    $this->session = $this->newSession();
    $this->push('/');
    $this->session->set('started', TRUE);
    $this->session->set(SignInMethod::KEY, $good);
    $this->assertFalse($this->proven($member), 'A sign-in record does not carry to another session.');
  }

  /**
   * A sign-in through /siwe/verify is proven, for that account and address.
   */
  public function testSignatureSignInIsProven(): void {
    $member = $this->member('ana', $this->testAddress());
    $other = $this->member('ben', NULL);

    $response = $this->signInThroughVerify();
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    $this->assertSame((int) $member->id(), (int) $this->container->get('current_user')->id());
    $record = $this->session->get(SignInMethod::KEY);
    $this->assertSame(SignInMethod::SIGNATURE, $record['method']);
    $this->assertSame((int) $member->id(), $record['uid']);
    $this->assertSame(strtolower($this->testAddress()), $record['address']);
    $this->assertTrue($this->proven($member));
    $this->assertFalse($this->proven($other), 'Not for another account.');

    $member->set('field_ethereum_address', strtolower($this->testAddress()))->save();
    $this->assertTrue($this->proven($member), 'The same address in another case is no change.');
    $member->block()->save();
    $this->assertFalse($this->proven($member), 'Not for a blocked account.');
    $member->activate()->save();
    $this->assertTrue($this->proven($member));

    // The address changes: the signature was another wallet's. Changing it
    // back does not bring the old sign-in back.
    $member->set('field_ethereum_address', $this->testAddress(self::OTHER_KEY))->save();
    $this->assertFalse($this->proven($member), 'Not after the address changes.');
    $member->set('field_ethereum_address', $this->testAddress())->save();
    $this->assertFalse($this->proven($member), 'Not after it changes back either.');
    $member->set('field_ethereum_address', NULL)->save();
    $this->assertFalse($this->proven($member), 'Not once the address is gone.');
  }

  /**
   * A password session proves itself in place; the proof ends with it.
   */
  public function testProofInPlace(): void {
    $member = $this->member('ana', $this->testAddress());
    $this->signIn($member, 'user.login');
    $this->assertFalse($this->proven($member));

    [$message, $signature] = $this->signedChallenge($member);
    $this->assertStringContainsString('Confirm it is you on Repair Collective.', $message);
    $this->assertStringContainsString('It moves no money and casts no vote.', $message);
    $this->assertTrue($this->service()->prove($member, $message, $signature));
    $this->assertTrue($this->proven($member));

    // Replayed: the message was used up.
    $this->assertFalse($this->service()->prove($member, $message, $signature), 'No replay.');

    // A proof copied into another session that signed in on its own.
    $proof = $this->session->get(SignatureSession::SESSION_KEY);
    $first = $this->session;
    $this->session = $this->newSession();
    $this->push('/');
    $this->signIn($member, 'user.login');
    $this->session->set(SignatureSession::SESSION_KEY, $proof);
    $this->assertFalse($this->proven($member), 'A proof does not carry to another session.');
    $this->session->set(SignInMethod::KEY, $first->get(SignInMethod::KEY));
    $this->assertFalse($this->proven($member), 'Nor with the sign-in record copied too.');

    // Back in the first session: a new sign-in on the same session ends it.
    $this->session = $first;
    $this->push('/');
    $this->assertTrue($this->proven($member));
    $this->signIn($member, 'user.login');
    $this->assertNotNull($this->session->get(SignatureSession::SESSION_KEY), 'The old proof is still in the session...');
    $this->assertFalse($this->proven($member), '...but it belongs to the earlier sign-in.');

    // Proven again, then the address changes.
    [$message, $signature] = $this->signedChallenge($member);
    $this->assertTrue($this->service()->prove($member, $message, $signature));
    $member->set('field_ethereum_address', $this->testAddress(self::OTHER_KEY))->save();
    $this->assertFalse($this->proven($member), 'Not after the address changes.');
    $member->set('field_ethereum_address', $this->testAddress())->save();
    $this->assertFalse($this->proven($member), 'Not after it changes back either.');

    // A new proof after the change counts.
    [$message, $signature] = $this->signedChallenge($member);
    $this->assertTrue($this->service()->prove($member, $message, $signature));
    $this->assertTrue($this->proven($member));
  }

  /**
   * A sign-in from a browser with no session cookie yet can prove in place.
   *
   * A password form posted without a cookie is the usual case: Drupal starts
   * that session lazily and gives it an id only when it is saved, after the
   * sign-in hooks ran. The next request comes back with that id.
   */
  public function testCookieLessSignInCanProve(): void {
    $member = $this->member('ana', $this->testAddress());
    $this->session = $this->lazySession();
    $this->signIn($member, 'user.login');
    $this->assertSame('', $this->session->getId(), 'The sign-in ran before the session had an id.');

    $this->session = $this->savedUnderNewId($this->session);
    $this->push('/');
    $this->assertFalse($this->proven($member));
    [$message, $signature] = $this->signedChallenge($member);
    $this->assertTrue($this->service()->prove($member, $message, $signature));
    $this->assertTrue($this->proven($member));

    // The proven session's data, whole, under another id is not proven.
    $this->session = $this->savedUnderNewId($this->session);
    $this->push('/');
    $this->assertFalse($this->proven($member), 'A copy under another session id is not proven.');
  }

  /**
   * Every way a proof can be wrong is refused, and leaves nothing proven.
   */
  public function testProofRefusals(): void {
    $member = $this->member('ana', $this->testAddress());
    $other = $this->member('ben', $this->testAddress(self::OTHER_KEY));
    $this->signIn($member, 'user.login');
    $service = $this->service();

    // Another wallet signs it (the classifier gets no network answer).
    $message = $service->challenge($member)['message'];
    $this->assertFalse($service->prove($member, $message, $this->signMessage($message, self::OTHER_KEY)), 'Another key.');

    // A changed message, correctly signed.
    $message = $service->challenge($member)['message'];
    $changed = str_replace('Repair Collective', 'Other Place', $message);
    $this->assertFalse($service->prove($member, $changed, $this->signMessage($changed)), 'A changed message.');

    // Expired.
    $message = $service->challenge($member)['message'];
    $store = $this->container->get('localnodes_web3.signature_session_tempstore')->get('localnodes_session_proof');
    $key = hash('sha256', $this->container->get('siwe_login.nonce_store')->currentBinding());
    $pending = $store->get($key);
    $pending['expires'] = $this->container->get('datetime.time')->getCurrentTime();
    $store->set($key, $pending);
    $this->assertFalse($service->prove($member, $message, $this->signMessage($message)), 'Expired.');

    // A sign-in nonce, correctly signed, is not a proof.
    $login = $this->nonceResponse();
    $loginMessage = $this->buildSiweMessage($this->fieldsFrom($login));
    $this->assertFalse($service->prove($member, $loginMessage, $this->signMessage($loginMessage)), 'A sign-in message.');

    // Nor is a proof message a sign-in.
    $message = $service->challenge($member)['message'];
    $response = $this->postVerify($message, $this->signMessage($message));
    $this->assertNotSame(200, $response->getStatusCode(), 'A proof message does not sign in.');

    // Issued in one session, signed back in another.
    $message = $service->challenge($member)['message'];
    $first = $this->session;
    $this->session = $this->newSession();
    $this->push('/');
    $this->signIn($member, 'user.login');
    $this->assertFalse($service->prove($member, $message, $this->signMessage($message)), 'Another session.');
    $this->assertFalse($this->proven($member));

    // Issued, then the whole session (sign-in record and nonce binding
    // included) copied under another id and signed back there.
    $this->session = $first;
    $this->push('/');
    $message = $service->challenge($member)['message'];
    $this->session = $this->savedUnderNewId($first);
    $this->push('/');
    $this->assertFalse($service->prove($member, $message, $this->signMessage($message)), 'A copy of the session.');
    $this->assertFalse($this->proven($member));

    // Issued to one account, used by another signing in on the same session.
    $this->session = $first;
    $this->push('/');
    $message = $service->challenge($member)['message'];
    $this->signIn($other, 'user.login');
    $this->assertFalse($service->prove($other, $message, $this->signMessage($message)), 'Another account.');
    $this->assertFalse($service->prove($member, $message, $this->signMessage($message)), 'Used up by the try.');

    // Issued before a new sign-in of the same account.
    $this->signIn($member, 'user.login');
    $message = $service->challenge($member)['message'];
    $this->signIn($member, 'user.login');
    $this->assertFalse($service->prove($member, $message, $this->signMessage($message)), 'Issued to an earlier sign-in.');

    $this->assertFalse($this->proven($member));
    $this->assertFalse($this->proven($other));

    // Signed for the address, which then changes away and back before the
    // message is sent: the message was issued before the change, so it is
    // void even though the address matches again.
    $this->signIn($member, 'user.login');
    [$message, $signature] = $this->signedChallenge($member);
    User::load($member->id())->set('field_ethereum_address', $this->testAddress(self::OTHER_KEY))->save();
    User::load($member->id())->set('field_ethereum_address', $this->testAddress())->save();
    $this->assertFalse($service->prove($member, $message, $signature), 'Issued before the address changed and changed back.');
    $this->assertFalse($this->proven($member));

    // The control: the same steps, done right, prove.
    [$message, $signature] = $this->signedChallenge($member);
    $this->assertTrue($service->prove($member, $message, $signature));
  }

  /**
   * An address changed away and back during the signature check proves nothing.
   *
   * The change time is read before the signature is checked. Another
   * session can change the address and change it back during that check; the
   * proof must not count.
   */
  public function testAddressChangeDuringValidation(): void {
    $member = $this->member('ana', $this->testAddress());
    $this->signIn($member, 'user.login');
    $during = function () use ($member): void {
      User::load($member->id())->set('field_ethereum_address', $this->testAddress(self::OTHER_KEY))->save();
      User::load($member->id())->set('field_ethereum_address', $this->testAddress())->save();
    };
    $racing = $this->serviceWith($this->validatorThatRuns($during));
    [$message, $signature] = $this->signedChallenge($member);
    $this->assertFalse($racing->prove($member, $message, $signature), 'Changed away and back during the check.');
    $this->assertFalse($this->proven($member));

    // The control: the same validator with nothing during the check proves.
    $quiet = $this->serviceWith($this->validatorThatRuns(static function (): void {}));
    [$message, $signature] = $this->signedChallenge($member);
    $this->assertTrue($quiet->prove($member, $message, $signature));
    $this->assertTrue($this->proven($member));

    // A proof stamped after a change it was not issued under does not count:
    // the change time it carries must be the current one.
    $proof = $this->session->get(SignatureSession::SESSION_KEY);
    $during();
    $this->session->set(SignatureSession::SESSION_KEY, ['at' => $this->container->get('datetime.time')->getCurrentMicroTime() + 1] + $proof);
    $this->assertFalse($this->proven($member), 'A later stamp with the old change time.');
    $this->session->set(SignatureSession::SESSION_KEY, ['changed' => NULL] + $proof);
    $this->assertFalse($this->proven($member), 'No change time.');
  }

  /**
   * The two endpoints: fixed answers, never cached, login and CSRF required.
   */
  public function testEndpoints(): void {
    $provider = $this->container->get('router.route_provider');
    foreach (['localnodes_web3.signature_proof_challenge', 'localnodes_web3.signature_proof'] as $name) {
      $route = $provider->getRouteByName($name);
      $this->assertSame(['POST'], $route->getMethods(), $name);
      $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'), $name);
      $this->assertSame('TRUE', $route->getRequirement('_user_is_logged_in'), $name);
      $this->assertTrue($route->getOption('no_cache'), $name);
    }

    $member = $this->member('ana', $this->testAddress());
    $noWallet = $this->member('ben', NULL);
    $controller = fn () => SignatureProofController::create($this->container);

    $this->signIn($noWallet, 'user.login');
    $response = $controller()->challenge($this->post([]));
    $this->assertAnswer(409, ['error' => 'wallet_not_attached'], $response);

    $this->signIn($member, 'user.login');
    $response = $controller()->challenge($this->post([]));
    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode($response->getContent(), TRUE);
    $this->assertSame($this->testAddress(), $body['expectedAddress']);
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

    $response = $controller()->prove($this->post(['message' => $body['message'], 'signature' => $this->signMessage($body['message'], self::OTHER_KEY)]));
    $this->assertAnswer(403, ['error' => 'not_proven'], $response);
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

    $body = json_decode($controller()->challenge($this->post([]))->getContent(), TRUE);
    $response = $controller()->prove($this->post(['message' => $body['message'], 'signature' => $this->signMessage($body['message'])]));
    $this->assertAnswer(200, ['proven' => TRUE], $response);
    $this->assertAnswer(200, ['proven' => TRUE], $controller()->challenge($this->post([])), 'Already proven.');

    // Five failures an hour, then a fixed answer even for a good signature.
    $this->signIn($member, 'user.login');
    for ($i = 0; $i < 4; $i++) {
      $this->assertAnswer(403, ['error' => 'not_proven'], $controller()->prove($this->post(['message' => 'x', 'signature' => 'y'])));
    }
    $body = json_decode($controller()->challenge($this->post([]))->getContent(), TRUE);
    $response = $controller()->prove($this->post(['message' => $body['message'], 'signature' => $this->signMessage($body['message'])]));
    $this->assertAnswer(429, ['error' => 'too_many_attempts'], $response);
  }

  /**
   * Status says what the page should offer, without issuing a message.
   */
  public function testStatus(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_web3.signature_proof_status');
    $this->assertSame(['GET'], $route->getMethods());
    $this->assertSame('TRUE', $route->getRequirement('_user_is_logged_in'));
    $this->assertTrue($route->getOption('no_cache'));
    $this->assertNull($route->getOption(SignatureSession::ROUTE_OPTION));

    $member = $this->member('ana', $this->testAddress());
    $noWallet = $this->member('ben', NULL);
    $controller = fn () => SignatureProofController::create($this->container);

    $this->signIn($noWallet, 'user.login');
    $response = $controller()->status();
    $this->assertAnswer(200, ['proven' => FALSE, 'canProve' => FALSE, 'addUrl' => '/user/' . $noWallet->id() . '/wallet'], $response, "No wallet; the link goes to this member's own wallet page.");
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

    $this->signIn($member, 'user.login');
    $this->assertAnswer(200, ['proven' => FALSE, 'canProve' => TRUE, 'addUrl' => NULL], $controller()->status());
    // Asking for status issues nothing and spends no attempt.
    $this->assertTrue($this->container->get('flood')->isAllowed('localnodes_session_proof_challenge', 1, 3600, (string) $member->id()));
    [$message, $signature] = $this->signedChallenge($member);
    $this->assertTrue($this->service()->prove(User::load($member->id()), $message, $signature));
    $this->assertAnswer(200, ['proven' => TRUE, 'canProve' => TRUE, 'addUrl' => NULL], $controller()->status());
  }

  /**
   * The guard: marked routes refuse changing requests until proven.
   */
  public function testGuard(): void {
    $member = $this->member('ana', $this->testAddress());
    $this->signIn($member, 'user.login');
    $marked = new Route('/fund', [], [], [SignatureSession::ROUTE_OPTION => TRUE]);
    $plain = new Route('/post');

    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
      $response = $this->guard($marked, $method);
      $this->assertNotNull($response, $method);
      $this->assertAnswer(403, ['error' => 'signature_proof_required'], $response);
      $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
    foreach (['GET', 'HEAD'] as $method) {
      $this->assertNull($this->guard($marked, $method), $method . ' passes: the page shows and offers the proof.');
    }
    // Only GET and HEAD pass; every other method needs the proof.
    $this->assertNotNull($this->guard($marked, 'OPTIONS'), 'OPTIONS is refused.');

    // A real POST that names another method in X-HTTP-Method-Override still
    // carries the form values, and Drupal still processes them. The guard
    // goes by the method actually sent.
    foreach (['OPTIONS', 'GET', 'HEAD'] as $override) {
      $response = $this->guard($marked, 'POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => $override]);
      $request = $this->container->get('request_stack')->getCurrentRequest();
      $this->assertSame($override, $request->getMethod(), 'Symfony reads the override.');
      $this->assertSame('POST', $request->getRealMethod());
      $this->assertNotNull($response, "A POST overridden to $override is refused.");
      $this->assertAnswer(403, ['error' => 'signature_proof_required'], $response);
    }

    // A route whose reads change something needs the proof for reads too.
    $reads = new Route('/prepare', [], [], [SignatureSession::ROUTE_OPTION => TRUE, SignatureSession::READ_OPTION => TRUE]);
    foreach (['GET', 'HEAD', 'POST'] as $method) {
      $response = $this->guard($reads, $method);
      $this->assertNotNull($response, $method . ' on a route marked for reads.');
      $this->assertAnswer(403, ['error' => 'signature_proof_required'], $response);
    }
    $this->assertNull($this->guard(new Route('/y', [], [], [SignatureSession::READ_OPTION => TRUE]), 'GET'), 'The reads mark alone does not mark a route.');
    $this->assertNull($this->guard($plain, 'POST'), 'An unmarked route is not guarded.');
    $this->assertNull($this->guard(new Route('/x', [], [], [SignatureSession::ROUTE_OPTION => 'TRUE']), 'POST'), 'Only the boolean TRUE marks a route (YAML true).');

    [$message, $signature] = $this->signedChallenge($member);
    $this->assertTrue($this->service()->prove($member, $message, $signature));
    $this->assertNull($this->guard($marked, 'POST'), 'Proven: the guard lets it through.');
    $this->assertNull($this->guard($marked, 'POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'OPTIONS']));
    $this->assertNull($this->guard($reads, 'GET'), 'Proven: reads pass on a route marked for reads.');
  }

  /**
   * A browser form post, without JavaScript, gets a page in words.
   */
  public function testGuardPageForBrowserFormPosts(): void {
    $member = $this->member('ana', $this->testAddress());
    $noWallet = $this->member('ben', NULL);
    $marked = new Route('/fund', [], [], [SignatureSession::ROUTE_OPTION => TRUE]);

    $this->signIn($member, 'user.login');
    $page = $this->guard($marked, 'POST', self::BROWSER_FORM + ['HTTP_REFERER' => 'http://localhost/group/7/funds?tab=send']);
    $html = $this->assertPage($page);
    $this->assertStringContainsString('Confirm your sign-in', $html);
    $this->assertStringContainsString('Confirm it is you to continue. This costs nothing.', $html);
    $this->assertStringContainsString('href="/group/7/funds?tab=send"', $html, 'The link goes back to the page the form was on.');
    $this->assertStringNotContainsString('/wallet"', $html, 'A member with a wallet is not sent to add one.');

    $html = $this->assertPage($this->guard($marked, 'POST', self::BROWSER_FORM + ['HTTP_REFERER' => 'https://elsewhere.example/phish']));
    $this->assertStringNotContainsString('elsewhere.example', $html, 'Another site is never linked.');
    $this->assertStringContainsString('href="/guarded"', $html, 'Without a same-site referrer, the link is the form page itself.');

    $html = $this->assertPage($this->guard($marked, 'POST', self::BROWSER_FORM + ['HTTP_REFERER' => 'http://localhost//evil.example/x']));
    $this->assertStringNotContainsString('evil.example', $html, 'A protocol-relative path is not linked.');

    $html = $this->assertPage($this->guard($marked, 'POST', self::BROWSER_FORM, '/fund/"><script>x</script>'));
    $this->assertStringNotContainsString('<script>x', $html, 'The path is escaped.');

    $this->signIn($noWallet, 'user.login');
    $html = $this->assertPage($this->guard($marked, 'POST', self::BROWSER_FORM));
    $this->assertStringContainsString('This account has no wallet yet, so it cannot confirm this step.', $html);
    $this->assertStringContainsString('href="/user/' . $noWallet->id() . '/wallet"', $html, "The no-wallet page links to the member's own wallet page.");
    $this->assertStringNotContainsString('This costs nothing', $html);

    // Anything that is not a plain browser form post keeps the JSON answer.
    $html = self::BROWSER_FORM['HTTP_ACCEPT'];
    $form = self::BROWSER_FORM['CONTENT_TYPE'];
    $json = [
      'fetch default' => ['HTTP_ACCEPT' => '*/*', 'CONTENT_TYPE' => $form],
      'asks for JSON' => ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $form],
      'JSON before HTML' => ['HTTP_ACCEPT' => 'application/json, text/html;q=0.9', 'CONTENT_TYPE' => $form],
      'XHR asking for HTML' => ['HTTP_ACCEPT' => $html, 'CONTENT_TYPE' => $form, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
      'JSON body' => ['HTTP_ACCEPT' => $html, 'CONTENT_TYPE' => 'application/json'],
      'no Accept' => ['HTTP_ACCEPT' => '', 'CONTENT_TYPE' => $form],
    ];
    foreach ($json as $label => $server) {
      $this->assertAnswer(403, ['error' => 'signature_proof_required'], $this->guard($marked, 'POST', $server), $label);
    }
    foreach (['/guarded?_wrapper_format=drupal_ajax', '/guarded?_format=json'] as $path) {
      $this->assertAnswer(403, ['error' => 'signature_proof_required'], $this->guard($marked, 'POST', self::BROWSER_FORM, $path), $path);
    }

    $this->signIn($member, 'user.login');
    [$message, $signature] = $this->signedChallenge($member);
    $this->assertTrue($this->service()->prove(User::load($member->id()), $message, $signature));
    $this->assertNull($this->guard($marked, 'POST', self::BROWSER_FORM), 'Proven: a browser form post goes through.');
  }

  /**
   * Asserts the guard's page: 403, HTML, never cached; returns the HTML.
   */
  private function assertPage(?\Symfony\Component\HttpFoundation\Response $response): string {
    $this->assertNotNull($response);
    $this->assertNotInstanceOf(JsonResponse::class, $response);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    return (string) $response->getContent();
  }

  /**
   * The guard is wired: the real dispatcher, after routing, on a real route.
   */
  public function testGuardThroughDispatcher(): void {
    $this->enableModules(['localnodes_web3_proof_test']);
    $this->container->get('router.builder')->rebuild();
    $dispatcher = $this->container->get('event_dispatcher');
    $guard = $this->container->get('localnodes_web3.signature_guard');
    $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$guard, 'onRequest']);
    $this->assertNotNull($priority, 'The guard listens to every request.');
    $router = $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$this->container->get('router_listener'), 'onKernelRequest']);
    $this->assertNotNull($router);
    $this->assertLessThan($router, $priority, 'The guard runs after routing and route access.');

    // Not signed in, or a password session: refused.
    $response = $this->dispatchRequest('POST');
    $this->assertNotNull($response);
    $this->assertAnswer(403, ['error' => 'signature_proof_required'], $response);
    $page = $this->dispatchRequest('POST', self::BROWSER_FORM);
    $this->assertNotNull($page);
    $this->assertSame(403, $page->getStatusCode());
    $this->assertStringStartsWith('text/html', (string) $page->headers->get('Content-Type'), 'The wired guard answers a browser form post with a page.');
    // A POST overridden to GET or HEAD is routed as one, but a form on the
    // page still reads the posted values and runs its submit handlers.
    // (Core answers any OPTIONS request itself, before routing.)
    foreach (['GET', 'HEAD'] as $method) {
      $override = $this->dispatchRequest('POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => $method]);
      $this->assertSame($method, $this->container->get('request_stack')->getCurrentRequest()->getMethod(), "Routed as $method.");
      $this->assertNotNull($override, "A POST overridden to $method is refused through the router too.");
      $this->assertAnswer(403, ['error' => 'signature_proof_required'], $override);
    }
    $this->assertNull($this->dispatchRequest('GET'), 'GET passes.');
    $this->assertSame('localnodes_web3_proof_test.marked', $this->container->get('request_stack')->getCurrentRequest()->attributes->get('_route'), 'Routed.');
  }

  /**
   * The endpoints need a signed-in account and the CSRF header.
   */
  public function testEndpointsNeedCsrfHeader(): void {
    $member = $this->member('ana', $this->testAddress());
    $router = $this->container->get('router');
    $request = function (?string $token): Request {
      $request = $this->push('/user/signature-proof/challenge', NULL, 'POST', '{}');
      $name = $this->container->get('session_configuration')->getOptions($request)['name'];
      $request->cookies->set($name, 'cookie');
      $this->container->get('router.request_context')->fromRequest($request);
      if ($token !== NULL) {
        $request->headers->set('X-CSRF-Token', $token);
      }
      return $request;
    };

    $this->signIn($member, 'user.login');
    $token = $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);
    foreach (['no header' => NULL, 'wrong header' => 'wrong'] as $label => $value) {
      try {
        $router->matchRequest($request($value));
        $this->fail('Refused with ' . $label);
      }
      catch (AccessDeniedHttpException) {
        $this->addToAssertionCount(1);
      }
    }
    $this->assertSame('localnodes_web3.signature_proof_challenge', $router->matchRequest($request($token))['_route'], 'Allowed with the header.');
  }

  /**
   * Whether the current session is proven for this account.
   */
  private function proven(UserInterface $account): bool {
    return $this->service()->isProven(User::load($account->id()));
  }

  /**
   * The service, built from the current container.
   */
  private function service(): SignatureSession {
    return $this->container->get('localnodes_web3.signature_session');
  }

  /**
   * The session service, with this validator in place of its own.
   */
  private function serviceWith(SiweMessageValidator $validator): SignatureSession {
    $c = $this->container;
    return new SignatureSession($c->get('user.data'), $c->get('entity_type.manager'), $c->get('request_stack'), $c->get('siwe_login.nonce_store'), $validator, $c->get('localnodes_web3.signature_session_tempstore'), $c->get('lock'), $c->get('datetime.time'), $c->get('config.factory'));
  }

  /**
   * The real validator, running $during before it checks the signature.
   */
  private function validatorThatRuns(\Closure $during): SiweMessageValidator {
    $inner = $this->container->get('localnodes_web3.signature_session_validator');
    return new class($inner, $during) extends SiweMessageValidator {

      public function __construct(
        private readonly SiweMessageValidator $inner,
        private readonly \Closure $during,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function validateMessage(string $message, string $signature, string $purpose = SiweNonceStore::PURPOSE_LOGIN, ?int $uid = NULL): VerifiedSiweLogin {
        ($this->during)();
        return $this->inner->validateMessage($message, $signature, $purpose, $uid);
      }

    };
  }

  /**
   * An active account with this wallet address, or none.
   */
  private function member(string $name, ?string $address): UserInterface {
    $account = User::create(['name' => $name, 'mail' => $name . '@example.com', 'status' => 1, 'field_ethereum_address' => $address]);
    $account->save();
    return $account;
  }

  /**
   * A fresh browser session.
   */
  private function newSession(): Session {
    return new Session(new MockArraySessionStorage());
  }

  /**
   * A session started the way Drupal starts one for a request with no cookie.
   *
   * It holds data but has no id until it is saved (SessionManager::start()).
   */
  private function lazySession(): Session {
    return new Session(new class extends MockArraySessionStorage {

      /**
       * {@inheritdoc}
       */
      public function start(): bool {
        if (!$this->started) {
          $this->loadSession();
        }
        return TRUE;
      }

    });
  }

  /**
   * The session's data saved under a new id, as the next request finds it.
   */
  private function savedUnderNewId(Session $session): Session {
    $saved = $this->newSession();
    $saved->start();
    foreach ($session->all() as $name => $value) {
      $saved->set($name, $value);
    }
    $this->assertNotSame('', $saved->getId());
    return $saved;
  }

  /**
   * Pushes a request in the current session, on a route if given.
   */
  private function push(string $path, ?string $route = NULL, string $method = 'GET', ?string $body = NULL, array $server = []): Request {
    $request = Request::create($path, $method, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'], $body);
    $request->setSession($this->session);
    if ($route !== NULL) {
      $request->attributes->set('_route', $route);
      $request->attributes->set('_route_object', $this->container->get('router.route_provider')->getRouteByName($route));
    }
    $this->container->get('request_stack')->push($request);
    return $request;
  }

  /**
   * Signs this account in on this route, through core's own finalize step.
   */
  private function signIn(UserInterface $account, ?string $route): void {
    $this->push('/sign-in', $route);
    user_login_finalize(User::load($account->id()));
  }

  /**
   * Signs in with the test wallet through siwe_login's own nonce and verify.
   */
  private function signInThroughVerify(): JsonResponse {
    $nonce = $this->nonceResponse();
    $message = $this->buildSiweMessage($this->fieldsFrom($nonce));
    return $this->postVerify($message, $this->signMessage($message));
  }

  /**
   * A sign-in nonce from /siwe/nonce, in the current session.
   */
  private function nonceResponse(): array {
    $request = Request::create('/siwe/nonce', 'GET', ['address' => $this->testAddress()], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
    $request->setSession($this->session);
    $this->container->get('request_stack')->push($request);
    $response = SiweAuthController::create($this->container)->getNonce($request);
    $this->assertSame(200, $response->getStatusCode(), $response->getContent());
    return json_decode($response->getContent(), TRUE);
  }

  /**
   * The SIWE fields the browser builds from a nonce answer.
   */
  private function fieldsFrom(array $server): array {
    return [
      'domain' => $server['domain'],
      'address' => $this->testAddress(),
      'statement' => $server['statement'],
      'uri' => $server['uri'],
      'version' => '1',
      'chainId' => $server['chainId'],
      'nonce' => $server['nonce'],
      'issuedAt' => $server['issuedAt'],
      'expirationTime' => $server['expirationTime'],
    ];
  }

  /**
   * POSTs to /siwe/verify on its route, so the sign-in hook sees the route.
   */
  private function postVerify(string $message, string $signature): JsonResponse {
    $request = $this->push('/siwe/verify', 'siwe_login.verify', 'POST', json_encode(['message' => $message, 'signature' => $signature]));
    return SiweAuthController::create($this->container)->verify($request);
  }

  /**
   * Issues a proof message for the account and signs it with the test key.
   */
  private function signedChallenge(UserInterface $account): array {
    $message = $this->service()->challenge($account)['message'];
    return [$message, $this->signMessage($message)];
  }

  /**
   * A JSON POST in the current session.
   */
  private function post(array $body): Request {
    return $this->push('/user/signature-proof', NULL, 'POST', json_encode($body));
  }

  /**
   * Runs the guard on a request for this route and method.
   */
  private function guard(Route $route, string $method, array $server = [], string $path = '/guarded'): ?\Symfony\Component\HttpFoundation\Response {
    $request = $this->push($path, NULL, $method, NULL, $server);
    $request->attributes->set('_route_object', $route);
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('localnodes_web3.signature_guard')->onRequest($event);
    return $event->getResponse();
  }

  /**
   * Sends a request to the marked test route through the real dispatcher.
   */
  private function dispatchRequest(string $method, array $server = []): ?\Symfony\Component\HttpFoundation\Response {
    $request = $this->push('/localnodes-proof-test', NULL, $method, NULL, $server);
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::REQUEST);
    return $event->getResponse();
  }

  /**
   * Asserts a JSON answer's status and exact body.
   */
  private function assertAnswer(int $status, array $body, JsonResponse $response, string $message = ''): void {
    $this->assertSame($status, $response->getStatusCode(), $message . ' ' . $response->getContent());
    $this->assertSame($body, json_decode($response->getContent(), TRUE), $message);
  }

}
