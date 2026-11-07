<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Fetch;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches organizer-pasted addresses without exposing the private network.
 *
 * D-13 / ASVS 5.2.6. Every hop is checked here (http or https, port 80 or
 * 443, no user:password part, no private literal address) and again by
 * Symfony's NoPrivateNetworkHttpClient, which resolves the host itself, pins
 * the answer for the connection (so DNS rebinding can't swap it) and
 * re-checks the connected peer. The blocklist is passed explicitly: an
 * explicit list skips PHP's own private/reserved filter flags, so it must be
 * complete on its own, and it keeps the IPv6 transition prefixes blocked even
 * if http-foundation ever drops below 6.4.41 (22-03 hand-off).
 *
 * Redirects are followed by this class (the client gets max_redirects 0) so
 * each hop goes through the same checks. Bodies are capped while they
 * download and again after gzip decoding. Requests send one honest
 * User-Agent, go one at a time, and wait MIN_HOST_INTERVAL between requests
 * to the same host. A bot challenge is reported as bot_challenge and the
 * host is not asked again by this fetcher: never retried under another
 * identity (D-23).
 *
 * Response bodies are never logged.
 */
final class SafeFetcher {

  /**
   * Largest body accepted, in bytes (5 MB), declared, downloaded or decoded.
   */
  public const MAX_BYTES = 5242880;

  /**
   * Redirect hops followed after the first request.
   */
  public const MAX_REDIRECTS = 3;

  /**
   * Idle timeout per request, in seconds.
   */
  public const TIMEOUT = 10;

  /**
   * Total time allowed per request, in seconds.
   */
  public const MAX_DURATION = 20;

  /**
   * Minimum gap between two requests to the same host, in seconds.
   */
  public const MIN_HOST_INTERVAL = 1.0;

  /**
   * Ranges blocked on top of IpUtils::PRIVATE_SUBNETS.
   *
   * CGNAT, IETF protocol assignments, benchmarking, and the five IPv6
   * transition prefixes that embed an IPv4 address (IPv4-compatible, 6to4,
   * Teredo, NAT64 well-known and local-use). http-foundation 6.4.50 already
   * lists most of these; repeating them is defence in depth (22-03 "both").
   */
  public const EXTRA_BLOCKED = [
    '100.64.0.0/10',
    '192.0.0.0/24',
    '198.18.0.0/15',
    '::/96',
    '2002::/16',
    '2001::/32',
    '64:ff9b::/96',
    '64:ff9b:1::/48',
  ];

  /**
   * Accepted media types and the Accept header for each $accept mode.
   */
  private const ACCEPT = [
    'html' => [
      'header' => 'text/html, application/xhtml+xml;q=0.9',
      'types' => ['text/html', 'application/xhtml+xml'],
    ],
    'json' => [
      'header' => 'application/json',
      'types' => ['application/json'],
    ],
  ];

  /**
   * Ports a fetched address may use.
   */
  private const PORTS = [80, 443];

  /**
   * The SSRF-guarded client.
   */
  private readonly HttpClientInterface $client;

  /**
   * Sleeps for a number of seconds.
   */
  private readonly \Closure $sleep;

  /**
   * Returns the current time in seconds.
   */
  private readonly \Closure $now;

  /**
   * When the last request to each host finished, by lower-case host.
   *
   * @var array<string, float>
   */
  private array $lastRequest = [];

  /**
   * Hosts that answered with a bot challenge, by lower-case host.
   *
   * @var array<string, true>
   */
  private array $challenged = [];

  /**
   * Builds the fetcher.
   *
   * @param \Symfony\Contracts\HttpClient\HttpClientInterface|null $inner
   *   The transport to wrap. Defaults to HttpClient::create().
   * @param string $userAgent
   *   The honest User-Agent, for example
   *   "LocalNodes-Importer/1.0 (+https://site.example; steward@site.example)".
   * @param \Closure|null $sleep
   *   fn(float $seconds): void. Defaults to usleep().
   * @param \Closure|null $now
   *   fn(): float, seconds. Defaults to microtime(TRUE).
   * @param \Closure|null $resolve
   *   fn(string $host): ?string, a test seam that answers DNS instead of the
   *   client. The answer is passed as the request's "resolve" option, so it
   *   is pinned and checked exactly as a real lookup would be. Leave NULL in
   *   production: NoPrivateNetworkHttpClient resolves and pins by itself.
   */
  public function __construct(
    ?HttpClientInterface $inner = NULL,
    private readonly string $userAgent = 'LocalNodes-Importer/1.0',
    ?\Closure $sleep = NULL,
    ?\Closure $now = NULL,
    private readonly ?\Closure $resolve = NULL,
  ) {
    $this->client = new NoPrivateNetworkHttpClient($inner ?? HttpClient::create(), self::blockedSubnets());
    $this->sleep = $sleep ?? static function (float $seconds): void {
      if ($seconds > 0) {
        usleep((int) ceil($seconds * 1000000));
      }
    };
    $this->now = $now ?? static fn (): float => microtime(TRUE);
  }

  /**
   * The full blocklist: IpUtils::PRIVATE_SUBNETS plus EXTRA_BLOCKED.
   *
   * @return string[]
   *   CIDR ranges.
   */
  public static function blockedSubnets(): array {
    return array_values(array_unique(array_merge(IpUtils::PRIVATE_SUBNETS, self::EXTRA_BLOCKED)));
  }

  /**
   * Whether an address may be fetched, without resolving it.
   *
   * Checks the scheme (http, https), port (80, 443), the absence of a
   * user:password part, and, for literal addresses, the blocklist. Also
   * refuses localhost names and the decimal, hex, octal and short number
   * forms that resolvers turn into loopback. A hostname that passes can
   * still resolve to a private address: get() checks that after resolution.
   * Form validation (22-24) uses this for a quick, offline answer.
   */
  public static function isAllowedUrl(string $url): bool {
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
      return FALSE;
    }
    $parts = parse_url($url);
    if ($parts === FALSE || !isset($parts['scheme'], $parts['host'])) {
      return FALSE;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE)) {
      return FALSE;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
      return FALSE;
    }
    if (isset($parts['port']) && !in_array($parts['port'], self::PORTS, TRUE)) {
      return FALSE;
    }
    $host = strtolower($parts['host']);
    if ($host === '' || str_contains($host, '%')) {
      return FALSE;
    }

    // IPv6 literal.
    if ($host[0] === '[') {
      if (!str_ends_with($host, ']')) {
        return FALSE;
      }
      $ip = substr($host, 1, -1);
      if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === FALSE) {
        return FALSE;
      }
      return !IpUtils::checkIp($ip, self::blockedSubnets());
    }

    // Canonical dotted-quad IPv4 literal.
    if (filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== FALSE) {
      return !IpUtils::checkIp($host, self::blockedSubnets());
    }

    $host = rtrim($host, '.');
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
      return FALSE;
    }
    // A numeric last label is an IPv4 number form (2130706433, 0x7f000001,
    // 0177.0.0.1, 127.1), never a real host name.
    $labels = explode('.', $host);
    if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels))) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Fetches one address.
   *
   * @param string $url
   *   The organizer-supplied address.
   * @param string $accept
   *   Either 'html' (text/html, application/xhtml+xml) or 'json'
   *   (application/json).
   *
   * @return \Drupal\localnodes_import\Fetch\FetchResult
   *   The body, or a failure with a reason code. Never throws for anything
   *   the remote site does.
   */
  public function get(string $url, string $accept): FetchResult {
    if (!isset(self::ACCEPT[$accept])) {
      throw new \InvalidArgumentException(sprintf('Unknown accept mode "%s"; use "html" or "json".', $accept));
    }

    $current = $url;
    for ($hop = 0;; $hop++) {
      if (!self::isAllowedUrl($current)) {
        return FetchResult::failed(FetchResult::BLOCKED_ADDRESS);
      }
      $host = strtolower((string) parse_url($current, \PHP_URL_HOST));
      if (isset($this->challenged[$host])) {
        return FetchResult::failed(FetchResult::BOT_CHALLENGE);
      }

      $options = [
        'max_redirects' => 0,
        'timeout' => self::TIMEOUT,
        'max_duration' => self::MAX_DURATION,
        'headers' => [
          'User-Agent' => $this->userAgent,
          'Accept' => self::ACCEPT[$accept]['header'],
          'Accept-Encoding' => 'gzip',
        ],
      ];
      if ($this->resolve !== NULL && $host[0] !== '[' && filter_var($host, \FILTER_VALIDATE_IP) === FALSE) {
        $ip = ($this->resolve)($host);
        if (!is_string($ip) || $ip === '') {
          return FetchResult::failed(FetchResult::BLOCKED_ADDRESS);
        }
        $options['resolve'] = [$host => $ip];
      }

      $this->waitForHost($host);
      try {
        $outcome = $this->hop($current, $options, $accept);
      }
      finally {
        $this->lastRequest[$host] = ($this->now)();
      }

      if ($outcome instanceof FetchResult) {
        if ($outcome->reason === FetchResult::BOT_CHALLENGE) {
          $this->challenged[$host] = TRUE;
        }
        return $outcome;
      }

      // A redirect: $outcome is the Location header.
      if ($hop >= self::MAX_REDIRECTS) {
        return FetchResult::failed(FetchResult::NO_RESPONSE);
      }
      $current = self::resolveLocation($current, $outcome);
    }
  }

  /**
   * Sends one request and classifies the answer.
   *
   * @return \Drupal\localnodes_import\Fetch\FetchResult|string
   *   A final result, or the Location of a redirect to follow.
   */
  private function hop(string $url, array $options, string $accept): FetchResult|string {
    $tooBig = FALSE;
    $options['on_progress'] = static function (int $dlNow, int $dlSize) use (&$tooBig): void {
      if ($dlNow > self::MAX_BYTES || $dlSize > self::MAX_BYTES) {
        $tooBig = TRUE;
        throw new \RuntimeException('Response is over the size cap.');
      }
    };

    $response = NULL;
    try {
      $response = $this->client->request('GET', $url, $options);
      $status = $response->getStatusCode();
      $headers = $response->getHeaders(FALSE);

      // A bot check (Cloudflare managed challenge). Reported, never retried.
      if (self::headerContains($headers, 'cf-mitigated', 'challenge')) {
        return FetchResult::failed(FetchResult::BOT_CHALLENGE);
      }
      if ($status === 403 || $status === 503) {
        $body = $response->getContent(FALSE);
        if (stripos($body, 'challenges.cloudflare.com') !== FALSE) {
          return FetchResult::failed(FetchResult::BOT_CHALLENGE);
        }
        return FetchResult::failed($status === 503 ? FetchResult::NO_RESPONSE : FetchResult::UNKNOWN);
      }

      if (in_array($status, [301, 302, 303, 307, 308], TRUE)) {
        self::discard($response);
        $location = trim($headers['location'][0] ?? '');
        return $location === '' ? FetchResult::failed(FetchResult::UNKNOWN) : $location;
      }
      if ($status === 404 || $status === 410) {
        return FetchResult::failed(FetchResult::NOT_FOUND);
      }
      if ($status >= 500) {
        return FetchResult::failed(FetchResult::NO_RESPONSE);
      }
      if ($status < 200 || $status >= 300) {
        return FetchResult::failed(FetchResult::UNKNOWN);
      }

      if ((int) ($headers['content-length'][0] ?? 0) > self::MAX_BYTES) {
        return FetchResult::failed(FetchResult::TOO_BIG);
      }
      $type = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
      if (!in_array($type, self::ACCEPT[$accept]['types'], TRUE)) {
        return FetchResult::failed(FetchResult::NOT_A_PAGE);
      }

      $body = $response->getContent(FALSE);
      $encoding = strtolower(trim($headers['content-encoding'][0] ?? 'identity'));
      if ($encoding !== '' && $encoding !== 'identity') {
        $decoded = self::decode($body, $encoding);
        if (is_array($decoded)) {
          return FetchResult::failed($decoded[0]);
        }
        $body = $decoded;
      }
      if (strlen($body) > self::MAX_BYTES) {
        return FetchResult::failed(FetchResult::TOO_BIG);
      }
      return FetchResult::ok($body, $type, $url);
    }
    catch (TransportExceptionInterface $e) {
      if ($tooBig) {
        return FetchResult::failed(FetchResult::TOO_BIG);
      }
      // NoPrivateNetworkHttpClient: 'Host "x" is blocked for "url".' or
      // 'IP "x" is blocked for "url".'.
      if (str_contains($e->getMessage(), ' is blocked for ')) {
        return FetchResult::failed(FetchResult::BLOCKED_ADDRESS);
      }
      return FetchResult::failed(FetchResult::NO_RESPONSE);
    }
    catch (ExceptionInterface $e) {
      return FetchResult::failed($tooBig ? FetchResult::TOO_BIG : FetchResult::UNKNOWN);
    }
    catch (\RuntimeException $e) {
      // The size guard thrown straight out of on_progress (some transports
      // don't wrap it).
      if ($tooBig) {
        return FetchResult::failed(FetchResult::TOO_BIG);
      }
      throw $e;
    }
    finally {
      if ($response !== NULL) {
        self::discard($response);
      }
    }
  }

  /**
   * Waits out the rest of MIN_HOST_INTERVAL since the last request to $host.
   */
  private function waitForHost(string $host): void {
    if (!isset($this->lastRequest[$host])) {
      return;
    }
    $wait = self::MIN_HOST_INTERVAL - (($this->now)() - $this->lastRequest[$host]);
    if ($wait > 0) {
      ($this->sleep)($wait);
    }
  }

  /**
   * Whether a response header contains a token, case-insensitively.
   */
  private static function headerContains(array $headers, string $name, string $token): bool {
    foreach ($headers[$name] ?? [] as $value) {
      if (stripos($value, $token) !== FALSE) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Stops a response we no longer need, so its destructor can't throw.
   */
  private static function discard(ResponseInterface $response): void {
    try {
      $response->cancel();
    }
    catch (\Throwable) {
      // Nothing to clean up.
    }
  }

  /**
   * Decodes a Content-Encoding without letting it grow past MAX_BYTES.
   *
   * We send Accept-Encoding ourselves, which turns off the transport's own
   * decoding, so the body arrives compressed. A small gzip body can expand
   * to gigabytes, so it is inflated in slices and stopped at the cap.
   *
   * @return string|string[]
   *   The decoded body, or [reason code] on failure.
   */
  private static function decode(string $body, string $encoding): string|array {
    $mode = match ($encoding) {
      'gzip', 'x-gzip' => \ZLIB_ENCODING_GZIP,
      'deflate' => \ZLIB_ENCODING_DEFLATE,
      default => NULL,
    };
    if ($mode === NULL) {
      return [FetchResult::UNKNOWN];
    }
    $context = inflate_init($mode);
    if ($context === FALSE) {
      return [FetchResult::UNKNOWN];
    }
    $out = '';
    $length = strlen($body);
    for ($offset = 0; $offset < $length; $offset += 8192) {
      $part = @inflate_add($context, substr($body, $offset, 8192), \ZLIB_SYNC_FLUSH);
      if ($part === FALSE) {
        return [FetchResult::UNKNOWN];
      }
      $out .= $part;
      if (strlen($out) > self::MAX_BYTES) {
        return [FetchResult::TOO_BIG];
      }
    }
    // A complete stream has already ended; finishing it again reports a
    // buffer error, so only finish a stream that hasn't.
    if (inflate_get_status($context) !== \ZLIB_STREAM_END) {
      $part = @inflate_add($context, '', \ZLIB_FINISH);
      if ($part === FALSE || inflate_get_status($context) !== \ZLIB_STREAM_END) {
        return [FetchResult::UNKNOWN];
      }
      $out .= $part;
    }
    return strlen($out) > self::MAX_BYTES ? [FetchResult::TOO_BIG] : $out;
  }

  /**
   * Resolves a Location header against the address it came from.
   *
   * Drops any fragment; the result goes through isAllowedUrl() before use.
   */
  private static function resolveLocation(string $base, string $location): string {
    $location = explode('#', $location, 2)[0];
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location)) {
      return $location;
    }
    $b = parse_url($base);
    $scheme = strtolower($b['scheme'] ?? 'https');
    if (str_starts_with($location, '//')) {
      return $scheme . ':' . $location;
    }
    $origin = $scheme . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    $basePath = ($b['path'] ?? '') === '' ? '/' : $b['path'];
    if ($location === '') {
      return $origin . $basePath . (isset($b['query']) ? '?' . $b['query'] : '');
    }
    if ($location[0] === '?') {
      return $origin . $basePath . $location;
    }
    [$path, $query] = array_pad(explode('?', $location, 2), 2, NULL);
    if ($path === '') {
      $path = $basePath;
    }
    elseif ($path[0] !== '/') {
      $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $path;
    }
    return $origin . self::removeDotSegments($path) . ($query !== NULL ? '?' . $query : '');
  }

  /**
   * RFC 3986 section 5.2.4 dot-segment removal for an absolute path.
   */
  private static function removeDotSegments(string $path): string {
    $segments = explode('/', $path);
    $out = [];
    $last = count($segments) - 1;
    foreach ($segments as $i => $segment) {
      if ($segment === '..') {
        if (count($out) > 1) {
          array_pop($out);
        }
      }
      elseif ($segment !== '.') {
        $out[] = $segment;
      }
      if ($i === $last && ($segment === '.' || $segment === '..')) {
        $out[] = '';
      }
    }
    $result = implode('/', $out);
    return str_starts_with($result, '/') ? $result : '/' . $result;
  }

}
