<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\social_ai_indexing\Service\AiOverviewService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The search page's AI overview endpoint, /api/ai/overview?q= (S8).
 *
 * Returns {summary_html, sources} or {summary_html: null}.
 *
 * Cache (D-27, orchestrator decision 2026-11-04). Retrieval runs on every
 * request, before the cache lookup, and the key is the normalised query
 * plus a hash of the sources the requester may see (entity type, id and
 * language of each permission-filtered result). So:
 * - people who can see the same sources share one entry (one Gemini call),
 *   and an entry is only ever served to someone whose permitted retrieval is
 *   exactly the set it was built from; the uid is not part of the key;
 * - a new, removed or newly hidden matching item changes the key at once,
 *   and an edited source invalidates the entry through its own cache tag.
 *   The index-wide Search API list tag of social_posts is deliberately not
 *   used: it adds no safety here and would clear every answer on each
 *   indexing pass.
 * An answer is kept 24 hours, a 'no overview' result 10 minutes.
 *
 * Cost limits (D-27a, Tom 2026-11-05), in this order, all before retrieval:
 * - a question over overview_max_query_chars (300) gets 413;
 * - a requester with user_limit (30) or more counted events in the last
 *   window (3600s), or a client IP with ip_limit (120) or more, gets 429
 *   with Retry-After, cached answers included.
 * Every answer-cache miss past retrieve() is one counted event per user and
 * per IP, whatever it ends in, because the retrieval may have made a paid
 * embedding call. Cache hits count nothing. The per-user limit is the
 * binding one: the route is members-only, so every request has a uid. The
 * per-IP limit is advisory, since production trusts X-Forwarded-For from any
 * proxy. The settings live in social_ai_indexing.settings; the class
 * constants are the fallback when that config object is missing (an
 * instance installed before it shipped).
 */
class AiOverviewController extends ControllerBase {

  /**
   * Seconds an answer is kept (24 hours).
   */
  public const ANSWER_TTL = 86400;

  /**
   * Seconds a 'no overview' result is kept (10 minutes).
   */
  public const NO_OVERVIEW_TTL = 600;

  /**
   * Cache id prefix; v2 is the permitted-source-set key (D-27).
   */
  public const CID_PREFIX = 'social_ai_overview:v2:';

  /**
   * Flood event per requester (identifier "uid:<uid>").
   */
  public const FLOOD_USER_EVENT = 'social_ai_indexing.overview_user';

  /**
   * Flood event per client IP.
   */
  public const FLOOD_IP_EVENT = 'social_ai_indexing.overview_ip';

  /**
   * Fallback limits when social_ai_indexing.settings is missing.
   */
  public const DEFAULT_USER_LIMIT = 30;
  public const DEFAULT_IP_LIMIT = 120;
  public const DEFAULT_WINDOW = 3600;
  public const DEFAULT_MAX_QUERY_CHARS = 300;

  /**
   * Constructs an AiOverviewController.
   */
  public function __construct(
    protected AiOverviewService $aiOverview,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    protected FloodInterface $flood,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('social_ai_indexing.ai_overview'),
      $container->get('cache.default'),
      $container->get('datetime.time'),
      $container->get('flood'),
      $container->get('config.factory'),
    );
  }

  /**
   * Returns the AI overview for a search query.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request with the ?q= query parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   {summary_html, sources}, or {summary_html: null}; 413 for a question
   *   that's too long, 429 with Retry-After over a flood limit.
   */
  public function overview(Request $request): JsonResponse {
    $query = self::normaliseQuery((string) $request->query->get('q', ''));
    if (mb_strlen($query) < 2) {
      return new JsonResponse(['summary_html' => NULL]);
    }
    // Too long: refused before anything that costs or counts (T-22-99).
    if (mb_strlen($query) > $this->setting('overview_max_query_chars', self::DEFAULT_MAX_QUERY_CHARS)) {
      return new JsonResponse(['summary_html' => NULL], 413);
    }

    $window = $this->setting('overview_flood.window', self::DEFAULT_WINDOW);
    $user_id = 'uid:' . $this->currentUser()->id();
    $ip = (string) $request->getClientIp();
    $limit = NULL;
    if (!$this->flood->isAllowed(self::FLOOD_USER_EVENT, $this->setting('overview_flood.user_limit', self::DEFAULT_USER_LIMIT), $window, $user_id)) {
      $limit = 'user';
    }
    elseif (!$this->flood->isAllowed(self::FLOOD_IP_EVENT, $this->setting('overview_flood.ip_limit', self::DEFAULT_IP_LIMIT), $window, $ip)) {
      $limit = 'ip';
    }
    if ($limit !== NULL) {
      // The reason code and the limit only, never the question.
      $this->getLogger('social_ai_indexing')->notice('overview_rate_limited limit=@limit window=@window', [
        '@limit' => $limit,
        '@window' => $window,
      ]);
      return new JsonResponse(['summary_html' => NULL], 429, ['Retry-After' => (string) $window]);
    }

    try {
      // What this requester may see, before any cache read or model call.
      $results = $this->aiOverview->retrieve($query, $this->currentUser());
      $cid = self::cacheId($query, AiOverviewService::sourceKeys($results));
      $cached = $this->cache->get($cid);
      if ($cached !== FALSE) {
        return new JsonResponse($cached->data);
      }
      // A miss: the retrieval may have made a paid embedding call, and the
      // generate step may make more. Counted once, whatever it ends in
      // (answer, empty result or 'no overview').
      $this->flood->register(self::FLOOD_USER_EVENT, $window, $user_id);
      $this->flood->register(self::FLOOD_IP_EVENT, $window, $ip);
      return new JsonResponse($this->buildAndCache($cid, $query, $results));
    }
    catch (\Throwable $e) {
      // Never log the message: it can carry content (T-22-24).
      $this->getLogger('social_ai_indexing')->error('AI overview failed: @class', ['@class' => get_class($e)]);
      return new JsonResponse(['summary_html' => NULL]);
    }
  }

  /**
   * The cache miss path: generate, store, return (22-31 wraps this).
   *
   * @param string $cid
   *   The cache id from cacheId().
   * @param string $query
   *   The normalised query.
   * @param array $results
   *   The requester's permission-filtered results (AiOverviewService).
   *
   * @return array
   *   The response data.
   */
  protected function buildAndCache(string $cid, string $query, array $results): array {
    $overview = $this->aiOverview->generateFromResults($query, $results);

    // Each source entity's own tags only (D-27).
    $tags = [];
    foreach ($results as $result) {
      $tags = Cache::mergeTags($tags, $result['entity']->getCacheTags());
    }

    $now = $this->time->getRequestTime();
    if ($overview === NULL) {
      $data = ['summary_html' => NULL];
      $this->cache->set($cid, $data, $now + self::NO_OVERVIEW_TTL, $tags);
      return $data;
    }

    $data = [
      'summary_html' => $overview['summary_html'],
      'sources' => $overview['sources'],
    ];
    $this->cache->set($cid, $data, $now + self::ANSWER_TTL, $tags);
    return $data;
  }

  /**
   * An integer setting from social_ai_indexing.settings, or the fallback.
   */
  protected function setting(string $key, int $default): int {
    $value = $this->config('social_ai_indexing.settings')->get($key);
    return is_numeric($value) ? (int) $value : $default;
  }

  /**
   * Trim, lowercase and collapse runs of whitespace.
   */
  public static function normaliseQuery(string $query): string {
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $query)));
  }

  /**
   * The cache id: normalised query plus a hash of the permitted source set.
   *
   * @param string $query
   *   The normalised query.
   * @param string[] $source_keys
   *   "type:id:langcode" keys (AiOverviewService::sourceKeys()).
   */
  public static function cacheId(string $query, array $source_keys): string {
    sort($source_keys, SORT_STRING);
    return self::CID_PREFIX . hash('sha256', $query . "\n" . hash('sha256', implode("\n", $source_keys)));
  }

}
