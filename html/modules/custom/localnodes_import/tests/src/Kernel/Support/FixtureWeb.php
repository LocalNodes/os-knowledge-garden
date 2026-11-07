<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel\Support;

use Drupal\localnodes_import\Fetch\SafeFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A small fake web that SafeFetcher reads, with no network (D-23).
 *
 * The real SafeFetcher runs over a MockHttpClient, so every request takes
 * the code path a live fetch takes: the per-hop address check, the
 * NoPrivateNetworkHttpClient pin and recheck, content-type and size checks,
 * the challenge memory. Hostnames resolve through a stub that knows only the
 * hosts registered here and throws on any other, so no test can reach real
 * DNS. Waiting is a fake clock, so the 1 s per-host interval costs nothing.
 *
 * - A wiki answers its api.php with siteinfo and action=parse JSON, and any
 *   other path on its host with its article page HTML (detection only reads
 *   the page's generator meta and EditURI link).
 * - Other pages answer at their exact address.
 * - Anything else is a 404.
 */
final class FixtureWeb {

  /**
   * The public address every fake host resolves to and connects from.
   */
  public const PUBLIC_IP = '93.184.215.14';

  /**
   * Every request made, as "METHOD url", in order.
   *
   * @var string[]
   */
  public array $requests = [];

  /**
   * Called with (method, url) as each request arrives, before the answer.
   *
   * Lets a test change the site while a fetch is in flight (merge review
   * R2: a refresh must notice what changed during its fetch).
   *
   * @var (\Closure(string, string): void)|null
   */
  public ?\Closure $onRequest = NULL;

  /**
   * Wikis by host.
   *
   * @var array<string, array{api: string, siteinfo: string, parse: array<string, string>, page: string}>
   */
  private array $wikis = [];

  /**
   * Plain pages by exact address.
   *
   * @var array<string, array{0: string, 1: int, 2: array<string, string>}>
   */
  private array $pages = [];

  /**
   * Hosts that answer every request with a Cloudflare challenge.
   *
   * @var array<string, true>
   */
  private array $challenged = [];

  /**
   * The P2P Foundation Wiki, served from the 22-02 fixtures.
   */
  public static function p2p(): self {
    $dir = dirname(__DIR__, 3) . '/fixtures/mediawiki';
    $parse = [];
    foreach (glob($dir . '/*.parse.json') as $file) {
      $json = (string) file_get_contents($file);
      $title = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR)['parse']['title'];
      $parse[$title] = $json;
    }
    return (new self())->addWiki(
      'wiki.p2pfoundation.net',
      '/api.php',
      (string) file_get_contents($dir . '/siteinfo.json'),
      $parse,
      (string) file_get_contents($dir . '/bioregionalism.page.html'),
    );
  }

  /**
   * The five P2P page addresses (fixture README), in its order.
   *
   * @return array<string, string>
   *   Address => the page title and the fixture's revid.
   */
  public static function p2pAddresses(): array {
    return [
      'https://wiki.p2pfoundation.net/Bioregionalism' => ['Bioregionalism', 151033],
      'https://wiki.p2pfoundation.net/Cosmo-Localization' => ['Cosmo-Localization', 143653],
      'https://wiki.p2pfoundation.net/Cosmo-Localism' => ['Cosmo-Localism', 148307],
      'https://wiki.p2pfoundation.net/Commons-Based_Peer_Production' => ['Commons-Based Peer Production', 150616],
      'https://wiki.p2pfoundation.net/Commons_Transition' => ['Commons Transition', 118570],
    ];
  }

  /**
   * Registers a wiki.
   *
   * @param string $host
   *   Its host name.
   * @param string $api_path
   *   The api.php path, for example /api.php or /w/api.php.
   * @param string $siteinfo
   *   The meta=siteinfo JSON.
   * @param array<string, string> $parse
   *   action=parse JSON by the page parameter it answers.
   * @param string $page
   *   The article HTML served on every other path.
   */
  public function addWiki(string $host, string $api_path, string $siteinfo, array $parse, string $page): self {
    $this->wikis[$host] = ['api' => $api_path, 'siteinfo' => $siteinfo, 'parse' => $parse, 'page' => $page];
    return $this;
  }

  /**
   * Adds or replaces one action=parse answer on a registered wiki.
   */
  public function addParse(string $host, string $page, string $json): self {
    $this->wikis[$host]['parse'][$page] = $json;
    return $this;
  }

  /**
   * Registers a plain page at an exact address.
   */
  public function addPage(string $url, string $body, int $code = 200, array $headers = ['content-type' => 'text/html; charset=UTF-8']): self {
    $this->pages[$url] = [$body, $code, $headers];
    return $this;
  }

  /**
   * Makes a host answer with a Cloudflare bot challenge.
   */
  public function challenge(string $host): self {
    $this->challenged[strtolower($host)] = TRUE;
    return $this;
  }

  /**
   * How many requests went to a host.
   */
  public function requestsTo(string $host): int {
    return count(array_filter($this->requests, static fn (string $line): bool => strtolower((string) parse_url(substr($line, strpos($line, ' ') + 1), PHP_URL_HOST)) === $host));
  }

  /**
   * A real SafeFetcher over this fake web.
   */
  public function fetcher(): SafeFetcher {
    $client = new MockHttpClient(fn (string $method, string $url, array $options): MockResponse => $this->answer($method, $url));
    $clock = 1000.0;
    $sleep = static function (float $seconds) use (&$clock): void {
      $clock += $seconds;
    };
    $now = static function () use (&$clock): float {
      return $clock;
    };
    $resolve = function (string $host): string {
      $host = strtolower($host);
      if (!isset($this->wikis[$host]) && !isset($this->challenged[$host]) && !in_array($host, $this->pageHosts(), TRUE)) {
        throw new \RuntimeException("TEST: no fake DNS answer for '$host'; a test tried to reach the real network.");
      }
      return self::PUBLIC_IP;
    };
    return new SafeFetcher($client, 'LocalNodes-Importer/1.0 (+https://test.example; test@example.com)', $sleep, $now, $resolve);
  }

  /**
   * Answers one request.
   */
  private function answer(string $method, string $url): MockResponse {
    $this->requests[] = "$method $url";
    if ($this->onRequest !== NULL) {
      ($this->onRequest)($method, $url);
    }
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');

    if (isset($this->challenged[$host])) {
      return self::response('<html><title>Just a moment...</title></html>', 403, [
        'content-type' => 'text/html; charset=UTF-8',
        'cf-mitigated' => 'challenge',
      ]);
    }

    if (isset($this->wikis[$host])) {
      $wiki = $this->wikis[$host];
      if (($parts['path'] ?? '/') !== $wiki['api']) {
        return self::response($wiki['page'], 200, ['content-type' => 'text/html; charset=UTF-8']);
      }
      parse_str($parts['query'] ?? '', $query);
      $json = ['content-type' => 'application/json; charset=utf-8'];
      if (($query['action'] ?? '') === 'query' && ($query['meta'] ?? '') === 'siteinfo') {
        return self::response($wiki['siteinfo'], 200, $json);
      }
      if (($query['action'] ?? '') === 'parse') {
        $page = (string) ($query['page'] ?? '');
        $body = $wiki['parse'][$page] ?? '{"error":{"code":"missingtitle","info":"The page you specified doesn\'t exist."}}';
        return self::response($body, 200, $json);
      }
      return self::response('{"error":{"code":"badvalue"}}', 200, $json);
    }

    if (isset($this->pages[$url])) {
      [$body, $code, $headers] = $this->pages[$url];
      return self::response($body, $code, $headers);
    }
    return self::response('<html><body>Not found</body></html>', 404, ['content-type' => 'text/html']);
  }

  /**
   * The hosts of the plain pages.
   *
   * @return string[]
   *   Lower-case host names.
   */
  private function pageHosts(): array {
    return array_map(static fn (string $url): string => strtolower((string) parse_url($url, PHP_URL_HOST)), array_keys($this->pages));
  }

  /**
   * A canned response from a public address.
   */
  private static function response(string $body, int $code, array $headers): MockResponse {
    return new MockResponse($body, [
      'http_code' => $code,
      'response_headers' => $headers,
      'primary_ip' => self::PUBLIC_IP,
    ]);
  }

}
