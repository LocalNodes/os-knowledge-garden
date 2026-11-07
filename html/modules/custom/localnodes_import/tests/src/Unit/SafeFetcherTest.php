<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Unit;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Drupal\localnodes_import\Fetch\SafeFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The fetch-safety contract for organizer-pasted addresses (D-13, D-23).
 *
 * No test here touches the network: every response comes from
 * MockHttpClient, and hostnames resolve through a stub resolver. An address
 * a stub doesn't know fails the test instead of reaching real DNS.
 *
 * Reason codes are written as plain strings on purpose, so the contract
 * reads the same whatever constants FetchResult defines.
 *
 * @coversDefaultClass \Drupal\localnodes_import\Fetch\SafeFetcher
 * @group phase22
 * @group phase22_unit
 */
final class SafeFetcherTest extends TestCase {

  /**
   * The honest User-Agent 22-18 passes in.
   */
  private const UA = 'LocalNodes-Importer/1.0 (+https://garden.example; steward@garden.example)';

  /**
   * A public IPv4 address (not in any blocked or documentation range).
   */
  private const PUBLIC_V4 = '93.184.216.34';

  /**
   * Stub DNS answers used by every test.
   */
  private const DNS = [
    'wiki.example.org' => self::PUBLIC_V4,
    'mirror.example.net' => '151.101.1.69',
    'other.example.com' => '104.16.132.229',
    'v6.example.org' => '2606:4700::6810:84e5',
    'rebind.example' => '10.0.0.5',
    'loopback6.example' => '::1',
    'metadata.example' => '169.254.169.254',
    'sneaky.example' => self::PUBLIC_V4,
    'p2pfoundation.net' => '172.67.150.10',
  ];

  /**
   * Every request the mock client saw: method, url and normalised options.
   *
   * @var array<int, array{method: string, url: string, options: array}>
   */
  private array $seen = [];

  /**
   * Seconds the fetcher asked to sleep, in order.
   *
   * @var float[]
   */
  private array $sleeps = [];

  /**
   * The fake clock, in seconds.
   */
  private float $clock = 1000.0;

  /**
   * Hostnames the stub resolver was asked about.
   *
   * @var string[]
   */
  private array $lookups = [];

  /**
   * Builds a fetcher over a queue of canned responses.
   *
   * @param \Symfony\Component\HttpClient\Response\MockResponse[] $responses
   *   Responses handed out in order, one per request.
   * @param string $userAgent
   *   The User-Agent to construct the fetcher with.
   */
  private function fetcher(array $responses, string $userAgent = self::UA): SafeFetcher {
    $this->seen = [];
    $this->sleeps = [];
    $this->lookups = [];
    $seen = &$this->seen;
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$seen): MockResponse {
      $seen[] = ['method' => $method, 'url' => $url, 'options' => $options];
      if ($responses === []) {
        return new MockResponse('', ['error' => 'TEST: unexpected request to ' . $url]);
      }
      return array_shift($responses);
    });
    $sleep = function (float $seconds): void {
      $this->sleeps[] = $seconds;
      $this->clock += $seconds;
    };
    $now = fn (): float => $this->clock;
    $resolve = function (string $host): ?string {
      $this->lookups[] = $host;
      if (!array_key_exists($host, self::DNS)) {
        $this->fail("The stub resolver has no answer for '$host': a test tried to reach real DNS.");
      }
      return self::DNS[$host];
    };
    return new SafeFetcher($client, $userAgent, $sleep, $now, $resolve);
  }

  /**
   * A 200 HTML response.
   */
  private static function page(string $body = '<html><body><p>Hello</p></body></html>', array $headers = [], array $info = []): MockResponse {
    return new MockResponse($body, $info + [
      'http_code' => 200,
      'response_headers' => $headers + ['content-type' => 'text/html; charset=UTF-8'],
    ]);
  }

  /**
   * A redirect response.
   */
  private static function redirect(string $location, int $code = 302, array $headers = []): MockResponse {
    return new MockResponse('', [
      'http_code' => $code,
      'response_headers' => $headers + ['location' => $location],
    ]);
  }

  /**
   * Addresses that must never be fetched.
   *
   * Each row has the responses (if any) the mock hands out before the block
   * happens.
   *
   * @return array<string, array{0: string, 1: \Symfony\Component\HttpClient\Response\MockResponse[]}>
   *   Rows of [url, responses].
   */
  public static function blockedProvider(): array {
    return [
      // IPv4: loopback, RFC 1918, link-local / cloud metadata, CGNAT,
      // "this network", IETF protocol assignments and benchmarking.
      'loopback v4' => ['http://127.0.0.1/', []],
      'loopback v4 other' => ['http://127.8.8.8/', []],
      'rfc1918 10/8' => ['http://10.0.0.5/', []],
      'rfc1918 192.168/16' => ['http://192.168.1.1/', []],
      'rfc1918 172.16/12' => ['http://172.16.0.1/', []],
      'rfc1918 172.31 edge' => ['https://172.31.255.254/', []],
      'cloud metadata' => ['http://169.254.169.254/latest/meta-data/', []],
      'link-local v4' => ['http://169.254.1.1/', []],
      'cgnat 100.64/10' => ['http://100.64.0.1/', []],
      'this network 0.0.0.0' => ['http://0.0.0.0/', []],
      'ietf 192.0.0/24' => ['http://192.0.0.8/', []],
      'benchmarking 198.18/15' => ['http://198.18.0.1/', []],
      'reserved 240/4' => ['http://240.0.0.1/', []],
      // IPv6: loopback, unique-local, link-local, unspecified, IPv4-mapped.
      'loopback v6' => ['http://[::1]/', []],
      'unique-local fc00::/7' => ['http://[fc00::1]/', []],
      'unique-local fd00' => ['http://[fd12:3456:789a::1]/', []],
      'link-local fe80::/10' => ['http://[fe80::1]/', []],
      'unspecified ::' => ['http://[::]/', []],
      'ipv4-mapped ::ffff:127.0.0.1' => ['http://[::ffff:127.0.0.1]/', []],
      'ipv4-mapped ::ffff:169.254.169.254' => ['http://[::ffff:a9fe:a9fe]/', []],
      // The five IPv6 transition prefixes from the 22-03 hand-off, one each.
      'ipv4-compatible ::/96' => ['http://[::7f00:1]/', []],
      '6to4 2002::/16' => ['http://[2002:7f00:1::]/', []],
      'teredo 2001::/32' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/', []],
      'nat64 64:ff9b::/96' => ['http://[64:ff9b::7f00:1]/', []],
      'nat64 local-use 64:ff9b:1::/48' => ['http://[64:ff9b:1::a00:1]/', []],
      // Hostnames whose DNS answer is private (checked after resolution).
      'dns answer rfc1918' => ['https://rebind.example/', []],
      'dns answer loopback v6' => ['https://loopback6.example/', []],
      'dns answer metadata' => ['http://metadata.example/latest/', []],
      // The connection itself reports a private peer (rebinding after the
      // check): NoPrivateNetworkHttpClient re-checks primary_ip.
      'connected primary_ip private' => ['https://sneaky.example/', [
        new MockResponse('<html></html>', [
          'primary_ip' => '10.0.0.5',
          'response_headers' => ['content-type' => 'text/html'],
        ]),
      ],
      ],
      // Redirects to private or otherwise refused targets, re-checked per hop.
      'redirect to loopback' => ['https://wiki.example.org/', [self::redirect('http://127.0.0.1/')]],
      'redirect to metadata' => ['https://wiki.example.org/', [
        self::redirect('http://169.254.169.254/latest/meta-data/'),
      ],
      ],
      'redirect to private dns name' => ['https://wiki.example.org/', [self::redirect('https://rebind.example/admin')]],
      'redirect to nat64' => ['https://wiki.example.org/', [self::redirect('http://[64:ff9b::a00:5]/')]],
      'redirect to other port' => ['https://wiki.example.org/', [
        self::redirect('https://wiki.example.org:6333/collections'),
      ],
      ],
      'redirect to ftp' => ['https://wiki.example.org/', [self::redirect('ftp://wiki.example.org/file')]],
      'redirect with userinfo' => ['https://wiki.example.org/', [
        self::redirect('https://admin:secret@wiki.example.org/'),
      ],
      ],
      'second hop to private' => ['https://wiki.example.org/', [
        self::redirect('https://mirror.example.net/a'),
        self::redirect('http://10.0.0.5/'),
      ],
      ],
      // Ports other than 80 and 443 (Coolify dashboard, Qdrant, dev servers).
      'port 8000' => ['http://93.184.216.34:8000/', []],
      'port 6333' => ['http://93.184.216.34:6333/', []],
      'port 8443 on a name' => ['https://wiki.example.org:8443/', []],
      'port 22' => ['http://wiki.example.org:22/', []],
      // Credentials in the address.
      'userinfo user:pass' => ['https://user:pass@wiki.example.org/', []],
      'userinfo user only' => ['https://user@wiki.example.org/', []],
      // Schemes other than http(s).
      'ftp' => ['ftp://93.184.216.34/', []],
      'file' => ['file:///etc/passwd', []],
      'gopher' => ['gopher://93.184.216.34:70/_', []],
      'javascript' => ['javascript:alert(1)', []],
      'data' => ['data:text/html,<p>x</p>', []],
      // Names and number forms that sneak loopback past a naive check.
      'localhost' => ['http://localhost/', []],
      'localhost trailing dot' => ['http://localhost./', []],
      'subdomain of localhost' => ['http://qdrant.localhost/', []],
      'decimal ipv4' => ['http://2130706433/', []],
      'hex ipv4' => ['http://0x7f000001/', []],
      'octal ipv4' => ['http://0177.0.0.1/', []],
      'short ipv4' => ['http://127.1/', []],
      'ipv6 zone id' => ['http://[fe80::1%25eth0]/', []],
      // Not an address at all.
      'empty' => ['', []],
      'no host' => ['https:///path', []],
      'relative' => ['/node/1', []],
    ];
  }

  /**
   * Every blocked address fails with blocked_address.
   *
   * @dataProvider blockedProvider
   * @covers ::get
   */
  public function testBlockedAddresses(string $url, array $responses): void {
    $result = $this->fetcher($responses)->get($url, 'html');
    $this->assertFalse($result->ok, "$url must not be fetched");
    $this->assertSame('blocked_address', $result->reason, "$url must be reported as a blocked address");
    $this->assertNull($result->body);
  }

  /**
   * A literal private address never reaches the HTTP client at all.
   *
   * @covers ::get
   */
  public function testPrivateLiteralIsRefusedBeforeAnyRequest(): void {
    $urls = [
      'http://127.0.0.1/',
      'http://169.254.169.254/',
      'http://[::1]/',
      'http://[64:ff9b::7f00:1]/',
      'http://93.184.216.34:6333/',
    ];
    foreach ($urls as $url) {
      $this->fetcher([self::page()])->get($url, 'html');
      $this->assertSame([], $this->seen, "$url reached the HTTP client");
    }
  }

  /**
   * The redirect hop to a private address is never requested.
   *
   * @covers ::get
   */
  public function testPrivateRedirectTargetIsNeverRequested(): void {
    $result = $this->fetcher([self::redirect('http://127.0.0.1/'), self::page()])->get('https://wiki.example.org/', 'html');
    $this->assertSame('blocked_address', $result->reason);
    $this->assertCount(1, $this->seen);
  }

  /**
   * Hostnames are resolved once, and the answer is pinned for the request.
   *
   * @covers ::get
   */
  public function testResolvedAddressIsPinned(): void {
    $result = $this->fetcher([self::page()])->get('https://wiki.example.org/wiki/Bioregionalism', 'html');
    $this->assertTrue($result->ok);
    $this->assertSame(['wiki.example.org'], $this->lookups);
    $this->assertSame(self::PUBLIC_V4, $this->seen[0]['options']['resolve']['wiki.example.org'] ?? NULL, 'The resolved IP must be pinned so the connection cannot be rebound');
  }

  /**
   * A public IPv6 answer is allowed.
   *
   * @covers ::get
   */
  public function testPublicIpv6IsAllowed(): void {
    $result = $this->fetcher([self::page()])->get('https://v6.example.org/', 'html');
    $this->assertTrue($result->ok, (string) $result->reason);
  }

  /**
   * A plain page comes back with its body, media type and address.
   *
   * @covers ::get
   */
  public function testHtmlPageIsFetched(): void {
    $result = $this->fetcher([self::page('<html><body><p>Bioregions</p></body></html>')])->get('https://wiki.example.org/wiki/Bioregionalism', 'html');
    $this->assertTrue($result->ok);
    $this->assertNull($result->reason);
    $this->assertSame('<html><body><p>Bioregions</p></body></html>', $result->body);
    $this->assertSame('text/html', $result->contentType);
    $this->assertSame('https://wiki.example.org/wiki/Bioregionalism', $result->finalUrl);
  }

  /**
   * XHTML counts as a page; JSON is accepted when asked for.
   *
   * @covers ::get
   */
  public function testAcceptedContentTypes(): void {
    $result = $this->fetcher([self::page('<html/>', ['content-type' => 'application/xhtml+xml'])])->get('https://wiki.example.org/', 'html');
    $this->assertTrue($result->ok);
    $this->assertSame('application/xhtml+xml', $result->contentType);

    $json = $this->fetcher([self::page('{"query":{}}', ['content-type' => 'application/json; charset=utf-8'])])->get('https://wiki.example.org/w/api.php?action=query', 'json');
    $this->assertTrue($json->ok);
    $this->assertSame('application/json', $json->contentType);
    $this->assertSame('{"query":{}}', $json->body);
    $this->assertStringContainsString('application/json', $this->seen[0]['options']['normalized_headers']['accept'][0]);
  }

  /**
   * The wrong content type is refused with not_a_page.
   *
   * @covers ::get
   */
  public function testWrongContentTypeIsRefused(): void {
    $png = $this->fetcher([self::page("\x89PNG\r\n", ['content-type' => 'image/png'])])->get('https://wiki.example.org/logo.png', 'html');
    $this->assertFalse($png->ok);
    $this->assertSame('not_a_page', $png->reason);

    $pdf = $this->fetcher([self::page('%PDF-1.7', ['content-type' => 'application/pdf'])])->get('https://wiki.example.org/a.pdf', 'html');
    $this->assertSame('not_a_page', $pdf->reason);

    $none = $this->fetcher([new MockResponse('<p>x</p>', ['http_code' => 200])])->get('https://wiki.example.org/', 'html');
    $this->assertSame('not_a_page', $none->reason, 'A missing Content-Type is not a page');

    $htmlForJson = $this->fetcher([self::page()])->get('https://wiki.example.org/w/api.php', 'json');
    $this->assertSame('not_a_page', $htmlForJson->reason);

    $jsonForHtml = $this->fetcher([self::page('{}', ['content-type' => 'application/json'])])->get('https://wiki.example.org/', 'html');
    $this->assertSame('not_a_page', $jsonForHtml->reason);
  }

  /**
   * HTTP 404 and 410 are not_found.
   *
   * @covers ::get
   */
  public function testGoneAndMissingAreNotFound(): void {
    foreach ([404, 410] as $code) {
      $result = $this->fetcher([self::page('<p>Not here</p>', [], ['http_code' => $code])])->get('https://wiki.example.org/wiki/Nope', 'html');
      $this->assertFalse($result->ok);
      $this->assertSame('not_found', $result->reason, "HTTP $code");
    }
  }

  /**
   * A body over 5 MB is refused while it downloads.
   *
   * @covers ::get
   */
  public function testOversizeBodyIsTooBig(): void {
    $big = str_repeat('a', 6 * 1024 * 1024);
    $result = $this->fetcher([self::page($big)])->get('https://wiki.example.org/huge', 'html');
    $this->assertFalse($result->ok);
    $this->assertSame('too_big', $result->reason);
    $this->assertNull($result->body);

    // Streamed in chunks with no Content-Length: stops once the cap passes.
    $chunks = (static function () {
      for ($i = 0; $i < 6; $i++) {
        yield str_repeat('b', 1024 * 1024);
      }
    })();
    $streamed = $this->fetcher([new MockResponse($chunks, ['response_headers' => ['content-type' => 'text/html']])])->get('https://wiki.example.org/stream', 'html');
    $this->assertSame('too_big', $streamed->reason);
  }

  /**
   * A body of exactly the cap is still allowed.
   *
   * @covers ::get
   */
  public function testBodyAtTheCapIsAllowed(): void {
    $result = $this->fetcher([self::page(str_repeat('c', 5242880))])->get('https://wiki.example.org/edge', 'html');
    $this->assertTrue($result->ok, (string) $result->reason);
    $this->assertSame(5242880, strlen((string) $result->body));
  }

  /**
   * A Content-Length over the cap is refused before the body is read.
   *
   * @covers ::get
   */
  public function testOversizeContentLengthIsRefusedWithoutReading(): void {
    $read = FALSE;
    $body = (static function () use (&$read) {
      $read = TRUE;
      yield '<p>x</p>';
    })();
    $response = new MockResponse($body, [
      'response_headers' => [
        'content-type' => 'text/html',
        'content-length' => (string) (6 * 1024 * 1024),
      ],
    ]);
    $result = $this->fetcher([$response])->get('https://wiki.example.org/declared-huge', 'html');
    $this->assertSame('too_big', $result->reason);
    $this->assertFalse($read, 'The body must not be read once Content-Length is over the cap');
  }

  /**
   * Gzip bodies are decoded, and a gzip bomb is too_big.
   *
   * @covers ::get
   */
  public function testGzipIsDecodedWithinTheCap(): void {
    $html = '<html><body><p>Commons</p></body></html>';
    $result = $this->fetcher([self::page(gzencode($html), ['content-encoding' => 'gzip'])])->get('https://wiki.example.org/gz', 'html');
    $this->assertTrue($result->ok, (string) $result->reason);
    $this->assertSame($html, $result->body);

    $bomb = gzencode(str_repeat('a', 6 * 1024 * 1024), 9);
    $this->assertLessThan(5242880, strlen($bomb));
    $boom = $this->fetcher([self::page($bomb, ['content-encoding' => 'gzip'])])->get('https://wiki.example.org/bomb', 'html');
    $this->assertSame('too_big', $boom->reason);

    $broken = $this->fetcher([self::page('not gzip at all', ['content-encoding' => 'gzip'])])->get('https://wiki.example.org/broken', 'html');
    $this->assertFalse($broken->ok);
    $this->assertSame('unknown', $broken->reason);
  }

  /**
   * Timeouts and transport errors are no_response.
   *
   * @covers ::get
   */
  public function testTimeoutIsNoResponse(): void {
    $idle = $this->fetcher([new MockResponse([''], ['response_headers' => ['content-type' => 'text/html']])])->get('https://wiki.example.org/slow', 'html');
    $this->assertFalse($idle->ok);
    $this->assertSame('no_response', $idle->reason);

    $connect = $this->fetcher([new MockResponse('', ['error' => 'Operation timed out after 10001 milliseconds'])])->get('https://wiki.example.org/slow', 'html');
    $this->assertSame('no_response', $connect->reason);

    $refused = $this->fetcher([new MockResponse('', ['error' => 'Failed to connect to wiki.example.org port 443: Connection refused'])])->get('https://wiki.example.org/', 'html');
    $this->assertSame('no_response', $refused->reason);
  }

  /**
   * Server errors are no_response; other client errors are unknown.
   *
   * @covers ::get
   */
  public function testOtherStatusCodes(): void {
    $this->assertSame('no_response', $this->fetcher([self::page('', [], ['http_code' => 503])])->get('https://wiki.example.org/', 'html')->reason);
    $this->assertSame('no_response', $this->fetcher([self::page('', [], ['http_code' => 500])])->get('https://wiki.example.org/', 'html')->reason);
    $this->assertSame('unknown', $this->fetcher([self::page('', [], ['http_code' => 401])])->get('https://wiki.example.org/', 'html')->reason);
    $this->assertSame('unknown', $this->fetcher([self::page('<p>Forbidden</p>', [], ['http_code' => 403])])->get('https://wiki.example.org/', 'html')->reason, 'A plain 403 is not a bot challenge');
    $this->assertSame('unknown', $this->fetcher([new MockResponse('', ['http_code' => 302])])->get('https://wiki.example.org/', 'html')->reason, 'A redirect with no Location');
  }

  /**
   * A Cloudflare challenge is bot_challenge, after exactly one request.
   *
   * @covers ::get
   */
  public function testBotChallengeIsReportedAndNeverRetried(): void {
    $challenge = new MockResponse('<html><title>Just a moment...</title></html>', [
      'http_code' => 403,
      'response_headers' => ['cf-mitigated' => 'challenge', 'content-type' => 'text/html'],
    ]);
    $fetcher = $this->fetcher([$challenge, self::page(), self::page()]);
    $result = $fetcher->get('https://p2pfoundation.net/Bioregionalism', 'html');
    $this->assertFalse($result->ok);
    $this->assertSame('bot_challenge', $result->reason);
    $this->assertCount(1, $this->seen, 'A challenge must never be retried');

    // A later address on the same host is not tried again either.
    $again = $fetcher->get('https://p2pfoundation.net/Cosmo-Localism', 'html');
    $this->assertSame('bot_challenge', $again->reason);
    $this->assertCount(1, $this->seen, 'A challenged host is not asked again');

    // Other hosts are unaffected.
    $this->assertTrue($fetcher->get('https://wiki.example.org/', 'html')->ok);
  }

  /**
   * A challenge page recognised by its body also counts.
   *
   * @covers ::get
   */
  public function testBotChallengeByBody(): void {
    $challenge = new MockResponse('<script src="https://challenges.cloudflare.com/turnstile/v0/api.js"></script>', [
      'http_code' => 403,
      'response_headers' => ['content-type' => 'text/html'],
    ]);
    $result = $this->fetcher([$challenge, self::page()])->get('https://p2pfoundation.net/', 'html');
    $this->assertSame('bot_challenge', $result->reason);
    $this->assertCount(1, $this->seen);
  }

  /**
   * A challenge behind a redirect is still a challenge, with no retry.
   *
   * @covers ::get
   */
  public function testBotChallengeAfterRedirect(): void {
    $challenge = new MockResponse('', [
      'http_code' => 403,
      'response_headers' => ['cf-mitigated' => 'challenge'],
    ]);
    $result = $this->fetcher([self::redirect('https://p2pfoundation.net/wiki/X'), $challenge, self::page()])->get('https://wiki.example.org/go', 'html');
    $this->assertSame('bot_challenge', $result->reason);
    $this->assertCount(2, $this->seen);
  }

  /**
   * Redirects stop after 3 hops.
   *
   * @covers ::get
   */
  public function testFourRedirectsFail(): void {
    $result = $this->fetcher([
      self::redirect('/r1'),
      self::redirect('/r2'),
      self::redirect('/r3'),
      self::redirect('/r4'),
      self::page(),
    ])->get('https://wiki.example.org/r0', 'html');
    $this->assertFalse($result->ok);
    $this->assertSame('no_response', $result->reason);
    $this->assertCount(4, $this->seen, 'The first request plus 3 followed hops, never a fifth');
  }

  /**
   * Three redirects are fine.
   *
   * @covers ::get
   */
  public function testThreeRedirectsAreFollowed(): void {
    $result = $this->fetcher([
      self::redirect('/r1', 301),
      self::redirect('https://mirror.example.net/r2', 307),
      self::redirect('r3', 308),
      self::page(),
    ])->get('https://wiki.example.org/r0', 'html');
    $this->assertTrue($result->ok, (string) $result->reason);
    $this->assertSame('https://mirror.example.net/r3', $result->finalUrl);
    $this->assertSame([
      'https://wiki.example.org/r0',
      'https://wiki.example.org/r1',
      'https://mirror.example.net/r2',
      'https://mirror.example.net/r3',
    ], array_column($this->seen, 'url'));
  }

  /**
   * A redirect to another public https host on 443 is followed.
   *
   * @covers ::get
   */
  public function testRedirectToPublicHost(): void {
    $result = $this->fetcher([
      self::redirect('https://mirror.example.net:443/wiki/Bioregionalism'),
      self::page('<p>Mirror</p>'),
    ])->get('http://wiki.example.org/wiki/Bioregionalism', 'html');
    $this->assertTrue($result->ok, (string) $result->reason);
    $this->assertSame('https://mirror.example.net:443/wiki/Bioregionalism', $result->finalUrl);
    $this->assertSame('<p>Mirror</p>', $result->body);
    $this->assertSame(['wiki.example.org', 'mirror.example.net'], $this->lookups, 'Each hop host is resolved and checked');
  }

  /**
   * Relative Location headers resolve against the current address.
   *
   * @covers ::get
   */
  public function testRelativeRedirects(): void {
    $result = $this->fetcher([
      self::redirect('../other/page?x=1#frag'),
      self::redirect('//mirror.example.net/abs'),
      self::page(),
    ])->get('https://wiki.example.org/wiki/sub/start', 'html');
    $this->assertTrue($result->ok, (string) $result->reason);
    $this->assertSame([
      'https://wiki.example.org/wiki/sub/start',
      'https://wiki.example.org/wiki/other/page?x=1',
      'https://mirror.example.net/abs',
    ], array_column($this->seen, 'url'));
  }

  /**
   * Every request carries the same honest headers and no cookies.
   *
   * @covers ::get
   */
  public function testHonestHeadersOnEveryHop(): void {
    $this->fetcher([
      self::redirect('/next', 302, ['set-cookie' => 'cf_clearance=abc; path=/']),
      self::redirect('https://mirror.example.net/end', 302, ['set-cookie' => 'session=xyz']),
      self::page(),
    ])->get('https://wiki.example.org/start', 'html');
    $this->assertCount(3, $this->seen);
    foreach ($this->seen as $i => $request) {
      $options = $request['options'];
      $headers = $options['normalized_headers'];
      $this->assertSame('GET', $request['method']);
      $this->assertSame(['User-Agent: ' . self::UA], $headers['user-agent'] ?? NULL, "hop $i User-Agent");
      $this->assertSame(['Accept-Encoding: gzip'], $headers['accept-encoding'] ?? NULL, "hop $i Accept-Encoding");
      $this->assertStringContainsString('text/html', $headers['accept'][0] ?? '', "hop $i Accept");
      $this->assertArrayNotHasKey('cookie', $headers, "hop $i must carry no Cookie");
      $this->assertArrayNotHasKey('authorization', $headers, "hop $i must carry no Authorization");
      $this->assertSame(0, $options['max_redirects'], 'Our code follows redirects, not the client');
      $this->assertEquals(10, $options['timeout']);
      $this->assertEquals(20, $options['max_duration']);
      $ua = $headers['user-agent'][0];
      $this->assertDoesNotMatchRegularExpression('/Mozilla|Chrome\/|Safari\/|Gecko|AppleWebKit/i', $ua);
    }
  }

  /**
   * The default User-Agent names LocalNodes and isn't a browser.
   *
   * @covers ::__construct
   */
  public function testDefaultUserAgentIsHonest(): void {
    $responses = [self::page()];
    $seen = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responses, &$seen): MockResponse {
      $seen[] = $options;
      return array_shift($responses);
    });
    $fetcher = new SafeFetcher($client, sleep: static fn (float $s) => NULL);
    $fetcher->get('https://93.184.216.34/', 'html');
    $this->assertSame(['User-Agent: LocalNodes-Importer/1.0'], $seen[0]['normalized_headers']['user-agent']);
  }

  /**
   * Two fetches to one host wait out the rest of the 1 s interval.
   *
   * @covers ::get
   */
  public function testPolitePerHostInterval(): void {
    $fetcher = $this->fetcher([self::page(), self::page(), self::page(), self::page()]);
    $fetcher->get('https://wiki.example.org/a', 'html');
    $this->assertSame([], $this->sleeps, 'The first request to a host does not wait');

    $this->clock += 0.25;
    $fetcher->get('https://wiki.example.org/b', 'html');
    $this->assertCount(1, $this->sleeps);
    $this->assertEqualsWithDelta(0.75, $this->sleeps[0], 0.0001, 'Waits 1.0 s minus the 0.25 s already gone');
    $this->assertGreaterThanOrEqual(1.0 - 0.25 - 0.0001, $this->sleeps[0]);

    // Another host does not wait for this one.
    $fetcher->get('https://other.example.com/', 'html');
    $this->assertCount(1, $this->sleeps);

    // Enough time has passed: no wait.
    $this->clock += 1.5;
    $fetcher->get('https://wiki.example.org/c', 'html');
    $this->assertCount(1, $this->sleeps);
  }

  /**
   * Redirect hops to the same host are polite too.
   *
   * @covers ::get
   */
  public function testPoliteIntervalAppliesToRedirectHops(): void {
    $this->fetcher([self::redirect('/b'), self::page()])->get('https://wiki.example.org/a', 'html');
    $this->assertCount(1, $this->sleeps);
    $this->assertGreaterThanOrEqual(1.0 - 0.0001, $this->sleeps[0]);
  }

  /**
   * The guard client gets the explicit, complete blocklist (22-03 "both").
   *
   * With an explicit list NoPrivateNetworkHttpClient skips PHP's own
   * private-range filter flags, so the list must hold every PRIVATE_SUBNETS
   * entry plus the extra ranges, and must not fall back to the library
   * default (which would track whatever http-foundation version is locked).
   *
   * @covers ::__construct
   * @covers ::blockedSubnets
   */
  public function testGuardClientUsesTheExplicitBlocklist(): void {
    $list = SafeFetcher::blockedSubnets();
    foreach (IpUtils::PRIVATE_SUBNETS as $subnet) {
      $this->assertContains($subnet, $list);
    }
    $extra = [
      '100.64.0.0/10',
      '192.0.0.0/24',
      '198.18.0.0/15',
      '::/96',
      '2002::/16',
      '2001::/32',
      '64:ff9b::/96',
      '64:ff9b:1::/48',
    ];
    foreach ($extra as $subnet) {
      $this->assertContains($subnet, $list);
    }

    $fetcher = new SafeFetcher(new MockHttpClient([]));
    $client = (new \ReflectionProperty(SafeFetcher::class, 'client'))->getValue($fetcher);
    $this->assertInstanceOf(NoPrivateNetworkHttpClient::class, $client);
    $subnets = (new \ReflectionProperty($client, 'subnets'))->getValue($client);
    $this->assertSame($list, $subnets, 'The guard must be built with the explicit list, not the library default');
  }

  /**
   * An $accept other than html or json is a programming error.
   *
   * @covers ::get
   */
  public function testUnknownAcceptIsRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->fetcher([])->get('https://wiki.example.org/', 'xml');
  }

  /**
   * Addresses for the form-level check (22-24).
   *
   * @return array<string, array{0: string, 1: bool}>
   *   Rows of [url, allowed].
   */
  public static function isAllowedProvider(): array {
    return [
      'https page' => ['https://p2pfoundation.net/Bioregionalism', TRUE],
      'http page' => ['http://wiki.example.org/wiki/Main_Page', TRUE],
      'explicit 80' => ['http://wiki.example.org:80/x', TRUE],
      'explicit 443' => ['https://wiki.example.org:443/x', TRUE],
      'upper-case scheme' => ['HTTPS://Wiki.Example.org/', TRUE],
      'query string' => ['https://wiki.example.org/index.php?title=Bioregionalism', TRUE],
      'public ipv4' => ['https://93.184.216.34/', TRUE],
      'public ipv6' => ['https://[2606:4700::6810:84e5]/', TRUE],
      'loopback' => ['http://127.0.0.1/', FALSE],
      'metadata' => ['http://169.254.169.254/', FALSE],
      'cgnat' => ['http://100.64.0.1/', FALSE],
      'rfc1918' => ['http://10.1.2.3/', FALSE],
      'ula' => ['http://[fc00::1]/', FALSE],
      'mapped' => ['http://[::ffff:127.0.0.1]/', FALSE],
      'nat64' => ['http://[64:ff9b::7f00:1]/', FALSE],
      'nat64 local-use' => ['http://[64:ff9b:1::a00:1]/', FALSE],
      '6to4' => ['http://[2002:7f00:1::]/', FALSE],
      'teredo' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/', FALSE],
      'ipv4-compatible' => ['http://[::7f00:1]/', FALSE],
      'port 8000' => ['http://wiki.example.org:8000/', FALSE],
      'port 0' => ['http://wiki.example.org:0/', FALSE],
      'userinfo' => ['https://a:b@wiki.example.org/', FALSE],
      'ftp' => ['ftp://wiki.example.org/', FALSE],
      'file' => ['file:///etc/passwd', FALSE],
      'localhost' => ['http://localhost/', FALSE],
      'decimal' => ['http://2130706433/', FALSE],
      'hex' => ['http://0x7f.0.0.1/', FALSE],
      'octal' => ['http://0177.0.0.1/', FALSE],
      'short' => ['http://127.1/', FALSE],
      'zone id' => ['http://[fe80::1%25eth0]/', FALSE],
      'empty' => ['', FALSE],
      'garbage' => ['not a url', FALSE],
      'no host' => ['https:///x', FALSE],
      'whitespace inside' => ["https://wiki.example.org/a b\nc", FALSE],
    ];
  }

  /**
   * IsAllowedUrl() checks scheme, port, userinfo and literal addresses.
   *
   * @dataProvider isAllowedProvider
   * @covers ::isAllowedUrl
   */
  public function testIsAllowedUrl(string $url, bool $allowed): void {
    $this->assertSame($allowed, SafeFetcher::isAllowedUrl($url), $url);
  }

}
