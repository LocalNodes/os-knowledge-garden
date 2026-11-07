<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Flood\DatabaseBackend;
use Drupal\Core\Session\AccountInterface;
use Drupal\social_ai_indexing\Service\AiOverviewService;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\OverviewTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The overview's spend is bounded per member and per IP (D-27a, D-27e).
 *
 * Calls the controller directly with synthetic requests that carry a
 * REMOTE_ADDR. The model is the scripted LLM (no provider is called); a spy
 * subclass of the overview service counts retrieve() calls.
 * - A repeated question is one model call; cached answers register no
 *   flood event.
 * - Every answer-cache miss past retrieve() registers one event per user
 *   and per IP, whether it ends in an answer, an empty result or 'no
 *   overview' (W-1).
 * - Over a limit: 429, {"summary_html": null}, Retry-After, no retrieval,
 *   no model call; the log record holds a reason code, never the question.
 * - A question over 300 characters: 413 before anything else (T-22-99).
 * - The limits are shipped config with a schema.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class OverviewCostTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use OverviewTrait;
  use SchemaCheckTestTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'ai_test',
  ];

  /**
   * Flood event names (contract with the controller).
   */
  private const USER_EVENT = 'social_ai_indexing.overview_user';
  private const IP_EVENT = 'social_ai_indexing.overview_ip';

  /**
   * The default client address of synthetic requests (TEST-NET-3).
   */
  private const IP = '203.0.113.10';

  /**
   * The spy overview service: records every retrieve() query.
   */
  private AiOverviewService $spy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpOverviewSearch();

    // The shipped settings, when the file exists (testShippedConfig checks
    // that it does).
    $path = $this->container->get('extension.list.module')->getPath('social_ai_indexing') . '/config/install';
    if ((new FileStorage($path))->exists('social_ai_indexing.settings')) {
      $this->installShippedConfig('social_ai_indexing', ['social_ai_indexing.settings']);
    }

    $this->spy = new class(
      $this->container->get('social_ai_indexing.hybrid_search'),
      $this->container->get('ai.provider'),
      $this->container->get('social_ai_indexing.citation_rewriter'),
      $this->container->get('logger.channel.social_ai_indexing'),
    ) extends AiOverviewService {

      /**
       * Queries retrieve() was called with.
       *
       * @var list<string>
       */
      public array $queries = [];

      /**
       * {@inheritdoc}
       */
      public function retrieve(string $query, AccountInterface $account): array {
        $this->queries[] = $query;
        return parent::retrieve($query, $account);
      }

    };
    $this->container->set('social_ai_indexing.ai_overview', $this->spy);

    // The production flood backend (KernelTestBase registers the memory
    // one), so the counts below read the flood table a live site uses.
    $this->container->set('flood', new DatabaseBackend(
      $this->container->get('database'),
      $this->container->get('request_stack'),
      $this->container->get('datetime.time'),
    ));
  }

  /**
   * Sets the overview limits for this test.
   */
  private function setLimits(int $user_limit, int $ip_limit, int $window = 3600): void {
    $this->config('social_ai_indexing.settings')
      ->set('overview_flood', ['user_limit' => $user_limit, 'ip_limit' => $ip_limit, 'window' => $window])
      ->set('overview_max_query_chars', 300)
      ->save();
  }

  /**
   * Requests the overview and returns the raw response (any status).
   */
  private function request(AccountInterface $account, string $query, string $ip = self::IP): JsonResponse {
    $this->setCurrentUser($account);
    $this->resetAccessCaches();
    $request = Request::create('/api/ai/overview', 'GET', ['q' => $query], [], [], ['REMOTE_ADDR' => $ip]);
    return $this->overviewController()->overview($request);
  }

  /**
   * The decoded body of a response.
   */
  private static function body(JsonResponse $response): array {
    return (array) Json::decode((string) $response->getContent());
  }

  /**
   * Asserts a 200 response with an answer, and returns its body.
   */
  private function assertAnswered(JsonResponse $response, string $message): array {
    $this->assertSame(200, $response->getStatusCode(), "$message: 200.");
    $body = self::body($response);
    $this->assertNotNull($body['summary_html'] ?? NULL, "$message: an answer.");
    return $body;
  }

  /**
   * Asserts the rate-limited response: 429, null summary, Retry-After.
   */
  private function assertRateLimited(JsonResponse $response, int $window, string $message): void {
    $this->assertSame(429, $response->getStatusCode(), "$message: 429.");
    $this->assertSame(['summary_html' => NULL], self::body($response), "$message: body is {\"summary_html\": null}.");
    $this->assertSame((string) $window, $response->headers->get('Retry-After'), "$message: Retry-After is the window.");
  }

  /**
   * Live flood events for an event name and identifier.
   */
  private function floodCount(string $event, string $identifier): int {
    try {
      return (int) $this->container->get('database')->select('flood', 'f')
        ->condition('event', $event)
        ->condition('identifier', $identifier)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      // The flood table is created on the first register().
      return 0;
    }
  }

  /**
   * The user flood count for an account.
   */
  private function userCount(AccountInterface $account): int {
    return $this->floodCount(self::USER_EVENT, 'uid:' . $account->id());
  }

  /**
   * Moves every flood event back past the window (time passes).
   */
  private function expireFloodWindow(int $window): void {
    $this->container->get('database')->update('flood')
      ->expression('timestamp', 'timestamp - :shift', [':shift' => $window + 1])
      ->execute();
  }

  /**
   * One model call for a repeated question; cached repeats register nothing.
   */
  public function testRepeatedQuestionIsOneCallAndFree(): void {
    $member = $this->personas['P-MEM'];
    $this->armCiteAll();

    $first = $this->assertAnswered($this->request($member, self::KEYWORD_ONLY_TERM), 'First ask');
    $this->assertCount(1, $this->overviewCalls(), 'First ask: one model call.');
    $this->assertSame(1, $this->userCount($member), 'First ask is a miss: one user flood event.');
    $this->assertSame(1, $this->floodCount(self::IP_EVENT, self::IP), 'First ask is a miss: one IP flood event.');

    $second = $this->assertAnswered($this->request($member, self::KEYWORD_ONLY_TERM), 'Second ask');
    $this->assertSame($first, $second, 'The repeat gets the identical response.');
    $this->assertCount(1, $this->overviewCalls(), 'The repeat makes no second model call (D-27e).');

    for ($i = 0; $i < 5; $i++) {
      $this->assertAnswered($this->request($member, self::KEYWORD_ONLY_TERM), "Cached repeat $i");
    }
    $this->assertSame(1, $this->userCount($member), 'Five cached repeats leave the user count unchanged.');
    $this->assertSame(1, $this->floodCount(self::IP_EVENT, self::IP), 'Five cached repeats leave the IP count unchanged.');
    $this->assertCount(1, $this->overviewCalls());

    // retrieve() gets the normalised question, so the embedding text
    // matches the answer key (22-32's embedding cache).
    $this->spy->queries = [];
    $this->assertAnswered($this->request($member, "  ZQ7-Title \t "), 'Unnormalised repeat');
    $this->assertSame(['zq7-title'], $this->spy->queries, 'retrieve() receives the normalised question.');
    $this->assertCount(1, $this->overviewCalls(), 'The normalised repeat is a cache hit.');
  }

  /**
   * Over the user limit: 429, no retrieval, no call, even for cached ones.
   */
  public function testUserLimitTrips(): void {
    $member = $this->personas['P-MEM'];
    $this->setLimits(2, 120, 3600);
    $this->armCiteAll();

    $cached = $this->assertAnswered($this->request($member, 'fictional alpha notes'), 'Question 1');
    $this->assertAnswered($this->request($member, 'fictional beta notes'), 'Question 2');
    $this->assertCount(2, $this->overviewCalls());
    $this->assertSame(2, $this->userCount($member));

    $this->spy->queries = [];
    $this->assertRateLimited($this->request($member, 'fictional gamma notes'), 3600, 'Question 3');
    $this->assertSame([], $this->spy->queries, 'Rate limited: no retrieval.');
    $this->assertCount(2, $this->overviewCalls(), 'Rate limited: no model call.');
    $this->assertSame(2, $this->userCount($member), 'A refused request registers no event.');

    // Over the limit, even a cached question is refused.
    $this->assertRateLimited($this->request($member, 'fictional alpha notes'), 3600, 'Cached question over the limit');
    $this->assertSame([], $this->spy->queries);

    // Another member on another address is unaffected.
    $this->assertAnswered($this->request($this->personas['P-MEMH'], 'fictional gamma notes', '203.0.113.20'), 'Another member');

    // Once the window has passed, the cached answer is served again, free.
    $this->expireFloodWindow(3600);
    $again = $this->assertAnswered($this->request($member, 'fictional alpha notes'), 'After the window');
    $this->assertSame($cached, $again, 'The cached answer, unchanged.');
    $this->assertCount(3, $this->overviewCalls(), 'No new model call for the cached answer.');
  }

  /**
   * Two members on one IP share the IP limit.
   */
  public function testIpLimitAcrossMembers(): void {
    $this->setLimits(30, 3, 3600);
    $this->armCiteAll();
    $a = $this->personas['P-MEM'];
    $b = $this->personas['P-NON'];

    $this->assertAnswered($this->request($a, 'fictional first question'), 'A, miss 1');
    $this->assertAnswered($this->request($a, 'fictional second question'), 'A, miss 2');
    $this->assertAnswered($this->request($b, 'fictional third question'), 'B, miss 3');
    $this->assertSame(3, $this->floodCount(self::IP_EVENT, self::IP), 'Three misses from the one address.');

    $calls = count($this->overviewCalls());
    $this->spy->queries = [];
    $this->assertRateLimited($this->request($b, 'fictional fourth question'), 3600, 'B, miss 4 from the same address');
    $this->assertSame([], $this->spy->queries, 'No retrieval.');
    $this->assertCount($calls, $this->overviewCalls(), 'No model call.');

    // The same member from another address is under both limits.
    $this->assertAnswered($this->request($b, 'fictional fourth question', '198.51.100.7'), 'B from another address');
  }

  /**
   * Empty-result misses count too (W-1): nonsense can't bypass the limit.
   */
  public function testEmptyResultMissesCount(): void {
    $member = $this->personas['P-MEM'];
    $this->setLimits(3, 120, 3600);
    // The vector leg fails (and is logged), the keyword leg matches
    // nothing: an empty result set, as a nonsense question gets.
    $this->setStubMode('throw');
    $this->armCiteAll();

    foreach (['qxvzzkalpha', 'qxvzzkbeta', 'qxvzzkgamma'] as $i => $question) {
      $response = $this->request($member, $question);
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame(['summary_html' => NULL], self::body($response), "Nonsense $i: no overview.");
      $this->assertSame($i + 1, $this->userCount($member), "Nonsense $i registered one event.");
    }
    $this->assertSame([], $this->overviewCalls(), 'Empty results: no model call.');
    $this->assertSame(['qxvzzkalpha', 'qxvzzkbeta', 'qxvzzkgamma'], $this->spy->queries, 'Each nonsense question was retrieved.');

    $this->assertRateLimited($this->request($member, 'qxvzzkdelta'), 3600, 'Fourth nonsense question');
  }

  /**
   * A 'no overview' miss registers once; its cached repeat registers none.
   */
  public function testNoOverviewMissCountsOnce(): void {
    $member = $this->personas['P-MEM'];
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([ScriptedLlmSubscriber::text('NO_RELEVANT_CONTENT')]);

    $response = $this->request($member, 'fictional notice');
    $this->assertSame(['summary_html' => NULL], self::body($response), "NO_RELEVANT_CONTENT: no overview.");
    $this->assertCount(1, $this->overviewCalls());
    $this->assertSame(1, $this->userCount($member), "A 'no overview' miss registers one event.");

    $response = $this->request($member, 'fictional notice');
    $this->assertSame(['summary_html' => NULL], self::body($response));
    $this->assertCount(1, $this->overviewCalls(), "The 10-minute 'no overview' entry is served.");
    $this->assertSame(1, $this->userCount($member), "Repeating it registers none.");
  }

  /**
   * The rate-limited log record: reason code and limit, never the question.
   */
  public function testRateLimitedLogHoldsNoQuestion(): void {
    $spy = $this->container->get('social_ai_indexing_test.spy_logger');
    $this->assertInstanceOf(SpyLogger::class, $spy);
    $member = $this->personas['P-MEM'];
    $other = $this->personas['P-MEMH'];
    $third = $this->personas['P-NON'];
    $this->setLimits(1, 2, 3600);
    $this->armCiteAll();
    $canary = 'fictional canary qv8-lantern-ridge';

    $this->assertAnswered($this->request($member, 'fictional opening question'), 'Member, miss 1 on the address');
    $spy->reset();
    // The member's own limit trips, from another address.
    $this->assertRateLimited($this->request($member, $canary, '198.51.100.7'), 3600, 'User limit');
    // A second member fills the address's limit; a third is refused by it.
    $this->assertAnswered($this->request($other, 'fictional other question'), 'Other member, miss 2 on the address');
    $this->assertRateLimited($this->request($third, $canary . ' again'), 3600, 'IP limit');

    $limited = [];
    foreach ($spy->records() as $record) {
      $flat = SpyLogger::flatten($record);
      $this->assertStringNotContainsString('qv8', $flat, 'No log record holds the question.');
      $this->assertStringNotContainsString('lantern', $flat, 'No log record holds the question.');
      if (str_starts_with($record['message'], 'overview_rate_limited')) {
        $limited[] = $record['context'];
      }
    }
    $this->assertCount(2, $limited, 'One overview_rate_limited record per refused request.');
    $this->assertSame('user', $limited[0]['@limit']);
    $this->assertSame('ip', $limited[1]['@limit']);
    foreach ($limited as $context) {
      $this->assertSame(3600, $context['@window']);
      $own = array_diff_key($context, array_flip(['channel', 'link', 'uid', 'request_uri', 'referer', 'ip', 'timestamp']));
      $this->assertSame(['@limit', '@window'], array_keys($own), 'The record carries only the limit and the window.');
    }
  }

  /**
   * Over 300 characters: 413 before retrieval, model call or flood event.
   */
  public function testLengthLimit(): void {
    $member = $this->personas['P-MEM'];
    $this->armCiteAll();
    $long = str_repeat('fictional', 34);
    $this->assertSame(306, mb_strlen($long));
    $too_long = mb_substr($long, 0, 301);
    $just_right = mb_substr($long, 0, 300);

    $response = $this->request($member, $too_long);
    $this->assertSame(413, $response->getStatusCode(), 'A 301-character question gets 413.');
    $this->assertSame(['summary_html' => NULL], self::body($response));
    $this->assertSame([], $this->spy->queries, '413: no retrieval.');
    $this->assertSame([], $this->allCalls(), '413: no model call.');
    $this->assertSame(0, $this->userCount($member), '413: no flood event.');
    $this->assertSame(0, $this->floodCount(self::IP_EVENT, self::IP), '413: no IP flood event.');

    $response = $this->request($member, $just_right);
    $this->assertSame(200, $response->getStatusCode(), 'A 300-character question goes through.');
    $this->assertSame([$just_right], $this->spy->queries, 'The 300-character question is retrieved.');
    $this->assertSame(1, $this->userCount($member), 'It is a counted miss.');
  }

  /**
   * The shipped limits, and their schema.
   */
  public function testShippedConfig(): void {
    $path = $this->container->get('extension.list.module')->getPath('social_ai_indexing') . '/config/install';
    $data = (new FileStorage($path))->read('social_ai_indexing.settings');
    $this->assertIsArray($data, 'config/install ships social_ai_indexing.settings.');
    $this->assertSame(['user_limit' => 30, 'ip_limit' => 120, 'window' => 3600], $data['overview_flood'] ?? NULL);
    $this->assertSame(300, $data['overview_max_query_chars'] ?? NULL);

    $this->assertSame(30, $this->config('social_ai_indexing.settings')->get('overview_flood.user_limit'), 'Installed: user_limit 30.');
    $this->assertConfigSchemaByName('social_ai_indexing.settings');
  }

}
