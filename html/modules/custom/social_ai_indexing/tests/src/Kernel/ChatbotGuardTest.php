<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Flood\DatabaseBackend;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\Tests\SchemaCheckTestTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The chatbot's length limit and flood caps (D-27; Tom, 2026-11-05).
 *
 * /api/deepchat is open to anonymous visitors and members, and every
 * message that reaches DeepChatApi starts a paid agent loop. The guard runs
 * on KernelEvents::REQUEST after routing and the access check, and refuses
 * before the controller:
 * - a latest user message over max_message_chars: 413;
 * - over the per-member limit, the shared anonymous bucket or the per-IP
 *   limit: 429 with Retry-After.
 * Synthetic requests carry the route attribute and a DeepChat-shaped JSON
 * body, and go straight to the subscriber; the production flood backend
 * counts.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class ChatbotGuardTest extends GroupAccessKernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
  ];

  /**
   * Contract with the subscriber.
   */
  private const SERVICE = 'social_ai_indexing.chatbot_guard_subscriber';
  private const CONFIG = 'social_ai_indexing.chatbot_limits';
  private const USER_EVENT = 'social_ai_indexing.chatbot_user';
  private const ANON_EVENT = 'social_ai_indexing.chatbot_anonymous';
  private const IP_EVENT = 'social_ai_indexing.chatbot_ip';

  /**
   * Copy (22-UI-SPEC Copywriting Contract, S7).
   */
  private const TOO_LONG = 'That message is too long. Keep it under 2,000 characters.';
  private const TOO_MANY = "You've sent a lot of messages in a short time. Wait a little while, then ask again.";

  /**
   * The default client address of synthetic requests (TEST-NET-3).
   */
  private const IP = '203.0.113.20';

  /**
   * Two members.
   */
  private AccountInterface $alice;
  private AccountInterface $bob;

  /**
   * PreGenerateResponseEvents seen.
   */
  private int $preEvents = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The production flood backend (KernelTestBase registers the memory
    // one), set before the subscriber is first built.
    $this->container->set('flood', new DatabaseBackend(
      $this->container->get('database'),
      $this->container->get('request_stack'),
      $this->container->get('datetime.time'),
    ));
    $path = $this->container->get('extension.list.module')->getPath('social_ai_indexing') . '/config/install';
    if ((new FileStorage($path))->exists(self::CONFIG)) {
      $this->installShippedConfig('social_ai_indexing', [self::CONFIG]);
    }
    $this->alice = $this->createUser([], 'fictional_chat_alice');
    $this->bob = $this->createUser([], 'fictional_chat_bob');
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      function (): void {
        $this->preEvents++;
      },
    );
  }

  /**
   * Sets the chatbot limits for this test.
   */
  private function setLimits(int $user = 60, int $anonymous = 200, int $ip = 120, int $window = 3600, int $max = 2000): void {
    $this->config(self::CONFIG)
      ->set('max_message_chars', $max)
      ->set('user_limit', $user)
      ->set('anonymous_limit', $anonymous)
      ->set('ip_limit', $ip)
      ->set('window', $window)
      ->save();
  }

  /**
   * A DeepChat-shaped request body: earlier turns, then the latest text.
   */
  private static function body(string $latest): string {
    return Json::encode([
      'assistant_id' => 'group_assistant',
      'thread_id' => 'fictional-thread',
      'messages' => [
        ['role' => 'user', 'text' => 'Fictional earlier question'],
        ['role' => 'ai', 'text' => 'Fictional earlier answer'],
        ['role' => 'user', 'text' => $latest],
      ],
    ]);
  }

  /**
   * Sends one chatbot request through the guard; returns its response or NULL.
   */
  private function send(AccountInterface $account, string $body, string $ip = self::IP, string $route = 'ai_chatbot.api', string $method = 'POST'): ?Response {
    $this->assertTrue($this->container->has(self::SERVICE), 'The chatbot guard subscriber is registered.');
    $this->setCurrentUser($account);
    $request = Request::create('/api/deepchat', $method, [], [], [], ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json'], $body);
    $request->attributes->set('_route', $route);
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get(self::SERVICE)->onRequest($event);
    return $event->getResponse();
  }

  /**
   * Flood rows for an event and identifier.
   */
  private function floodCount(string $event, string $identifier): int {
    // DatabaseBackend creates the table on the first register().
    if (!$this->container->get('database')->schema()->tableExists('flood')) {
      return 0;
    }
    return (int) $this->container->get('database')
      ->query('SELECT COUNT(*) FROM {flood} WHERE event = :event AND identifier = :identifier', [':event' => $event, ':identifier' => $identifier])
      ->fetchField();
  }

  /**
   * Every flood row of the three chatbot events.
   */
  private function allChatbotRows(): int {
    if (!$this->container->get('database')->schema()->tableExists('flood')) {
      return 0;
    }
    return (int) $this->container->get('database')
      ->query('SELECT COUNT(*) FROM {flood} WHERE event IN (:events[])', [':events[]' => [self::USER_EVENT, self::ANON_EVENT, self::IP_EVENT]])
      ->fetchField();
  }

  /**
   * Asserts a 429 with the copy and Retry-After.
   */
  private function assertRateLimited(?Response $response, int $window, string $message): void {
    $this->assertNotNull($response, "$message: refused.");
    $this->assertSame(429, $response->getStatusCode(), "$message: 429.");
    $this->assertSame(['error' => self::TOO_MANY], Json::decode($response->getContent()), "$message: the copy-table error.");
    $this->assertSame((string) $window, $response->headers->get('Retry-After'), "$message: Retry-After is the window.");
  }

  /**
   * The guard runs after routing (32) and the access check (30).
   */
  public function testRunsAfterAccessCheck(): void {
    $this->assertTrue($this->container->has(self::SERVICE), 'The chatbot guard subscriber is registered.');
    $dispatcher = $this->container->get('event_dispatcher');
    $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$this->container->get(self::SERVICE), 'onRequest']);
    $this->assertSame(25, $priority, 'Priority 25: after the router and the access subscriber, before the controller.');
  }

  /**
   * Over 2,000 characters: 413; exactly 2,000 passes.
   */
  public function testLengthLimit(): void {
    $long = str_repeat('fictional ', 201);
    $too_long = mb_substr($long, 0, 2001);
    $just_right = mb_substr($long, 0, 2000);

    $response = $this->send($this->alice, self::body($too_long));
    $this->assertNotNull($response, 'A 2,001-character message is refused.');
    $this->assertSame(413, $response->getStatusCode());
    $this->assertSame(['error' => self::TOO_LONG], Json::decode($response->getContent()));
    $this->assertSame(0, $this->preEvents, '413: no provider call.');
    $this->assertSame(0, $this->allChatbotRows(), '413: no flood event.');

    $this->assertNull($this->send($this->alice, self::body($just_right)), 'Exactly 2,000 characters passes.');
    $this->assertSame(1, $this->floodCount(self::USER_EVENT, 'uid:' . $this->alice->id()));

    // Multibyte text is counted in characters, not bytes.
    $this->assertNull($this->send($this->alice, self::body(str_repeat('é', 2000))), '2,000 multibyte characters pass.');
  }

  /**
   * A member's third message over user_limit 2 gets 429.
   */
  public function testUserLimit(): void {
    $this->setLimits(user: 2);
    $this->assertNull($this->send($this->alice, self::body('Fictional one')));
    $this->assertNull($this->send($this->alice, self::body('Fictional two')));
    $this->assertSame(2, $this->floodCount(self::USER_EVENT, 'uid:' . $this->alice->id()));
    $this->assertSame(2, $this->floodCount(self::IP_EVENT, self::IP));

    $this->assertRateLimited($this->send($this->alice, self::body('Fictional three'), '198.51.100.9'), 3600, 'User limit');
    $this->assertSame(2, $this->floodCount(self::USER_EVENT, 'uid:' . $this->alice->id()), 'A refused message registers nothing.');
    $this->assertSame(0, $this->floodCount(self::IP_EVENT, '198.51.100.9'), 'A refused message registers no IP event.');
    $this->assertSame(0, $this->preEvents);

    // Another member is unaffected.
    $this->assertNull($this->send($this->bob, self::body('Fictional hello'), '198.51.100.10'));
  }

  /**
   * Anonymous visitors share one bucket, whatever their address.
   */
  public function testAnonymousShareOneBucket(): void {
    $this->setLimits(anonymous: 3);
    $anonymous = new AnonymousUserSession();
    foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3'] as $ip) {
      $this->assertNull($this->send($anonymous, self::body('Fictional visitor question'), $ip), "Visitor at $ip passes.");
      $this->assertSame(1, $this->floodCount(self::IP_EVENT, $ip));
    }
    $this->assertSame(3, $this->floodCount(self::ANON_EVENT, 'anonymous'));
    $this->assertSame(0, $this->floodCount(self::USER_EVENT, 'uid:0'), 'Visitors use the anonymous event, not the member one.');
    $this->assertRateLimited($this->send($anonymous, self::body('Fictional visitor question'), '192.0.2.4'), 3600, 'Anonymous bucket');
    $this->assertSame(3, $this->floodCount(self::ANON_EVENT, 'anonymous'));

    // Members are not in the visitors' bucket.
    $this->assertNull($this->send($this->alice, self::body('Fictional member question'), '192.0.2.5'));
  }

  /**
   * Two members on one address: the IP limit trips.
   */
  public function testIpLimitAcrossMembers(): void {
    $this->setLimits(ip: 2, window: 600);
    $this->assertNull($this->send($this->alice, self::body('Fictional alice')));
    $this->assertNull($this->send($this->bob, self::body('Fictional bob')));
    $this->assertRateLimited($this->send($this->alice, self::body('Fictional alice again')), 600, 'IP limit');
    $this->assertSame(2, $this->floodCount(self::IP_EVENT, self::IP));
    $this->assertSame(1, $this->floodCount(self::USER_EVENT, 'uid:' . $this->alice->id()), 'The refused message registers no user event.');
  }

  /**
   * Other routes, GET requests and bodies DeepChatApi itself rejects pass untouched.
   */
  public function testOtherRequestsUntouched(): void {
    $this->setLimits(user: 1, anonymous: 1, ip: 1, max: 5);
    $this->assertNull($this->send($this->alice, self::body('Fictional long text'), self::IP, 'ai_chatbot.session'), 'Another route.');
    $this->assertNull($this->send($this->alice, self::body('Fictional long text'), self::IP, 'social_ai_indexing.ai_overview'), 'The overview route.');
    $this->assertNull($this->send($this->alice, self::body('Fictional long text'), self::IP, 'ai_chatbot.api', 'GET'), 'A GET request.');
    $this->assertNull($this->send($this->alice, '{not json'), 'Invalid JSON: DeepChatApi answers it.');
    $this->assertNull($this->send($this->alice, Json::encode(['assistant_id' => 'group_assistant', 'messages' => [['role' => 'ai', 'text' => 'x']]])), 'No user message: DeepChatApi answers it.');
    $this->assertSame(0, $this->allChatbotRows(), 'Nothing registered.');
  }

  /**
   * Log records carry a reason code and the limit name, never the message.
   */
  public function testLogsHoldNoMessageText(): void {
    $spy = $this->container->get('social_ai_indexing_test.spy_logger');
    $this->assertInstanceOf(SpyLogger::class, $spy);
    $this->setLimits(user: 1, anonymous: 1, ip: 1);
    $canary = 'Fictional canary zq7-heron-meadow';
    $spy->reset();

    $this->send($this->alice, self::body($canary . str_repeat(' x', 1000)));
    $this->assertNull($this->send($this->alice, self::body($canary)));
    $this->send($this->alice, self::body($canary . ' user'), '198.51.100.21');
    $this->send(new AnonymousUserSession(), self::body($canary . ' visitor'), '198.51.100.22');
    $this->send(new AnonymousUserSession(), self::body($canary . ' visitor two'), '198.51.100.23');
    $this->send($this->bob, self::body($canary . ' ip'));

    $seen = [];
    foreach ($spy->records() as $record) {
      $flat = SpyLogger::flatten($record);
      $this->assertStringNotContainsString('zq7', $flat, 'No log record holds the message.');
      $this->assertStringNotContainsString('heron', $flat, 'No log record holds the message.');
      foreach (['chatbot_message_too_long', 'chatbot_rate_limited'] as $code) {
        if (str_starts_with($record['message'], $code)) {
          $own = array_diff_key($record['context'], array_flip(['channel', 'link', 'uid', 'request_uri', 'referer', 'ip', 'timestamp']));
          $this->assertSame(['@limit'], array_keys($own), 'The record carries only the limit name.');
          $seen[] = $code . ':' . $own['@limit'];
        }
      }
    }
    $this->assertSame([
      'chatbot_message_too_long:max_message_chars',
      'chatbot_rate_limited:user_limit',
      'chatbot_rate_limited:anonymous_limit',
      'chatbot_rate_limited:ip_limit',
    ], $seen);
  }

  /**
   * Built-in defaults apply where the config object is missing.
   *
   * Instances that enabled the module before this config shipped never get
   * it on redeploy (updatedb + cr only).
   */
  public function testDefaultsWithoutConfig(): void {
    $this->config(self::CONFIG)->delete();
    $response = $this->send($this->alice, self::body(str_repeat('a', 2001)));
    $this->assertSame(413, $response?->getStatusCode(), 'Default max 2,000.');
    $this->assertNull($this->send($this->alice, self::body('Fictional hello')), 'Default limits let a message through.');
    $this->assertSame(1, $this->floodCount(self::USER_EVENT, 'uid:' . $this->alice->id()));
  }

  /**
   * The shipped limits and their schema.
   */
  public function testShippedConfig(): void {
    $path = $this->container->get('extension.list.module')->getPath('social_ai_indexing') . '/config/install';
    $data = (new FileStorage($path))->read(self::CONFIG);
    $this->assertIsArray($data, 'config/install ships social_ai_indexing.chatbot_limits.');
    $this->assertSame([
      'max_message_chars' => 2000,
      'user_limit' => 60,
      'anonymous_limit' => 200,
      'ip_limit' => 120,
      'window' => 3600,
    ], $data);
    $this->assertSame(60, $this->config(self::CONFIG)->get('user_limit'), 'Installed: user_limit 60.');
    $this->assertConfigSchemaByName(self::CONFIG);
  }

}
