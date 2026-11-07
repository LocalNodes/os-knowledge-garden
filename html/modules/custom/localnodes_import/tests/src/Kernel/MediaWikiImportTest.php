<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_import\Fetch\DetectedLicense;
use Drupal\localnodes_import\Fetch\FetchResult;
use Drupal\localnodes_import\Fetch\MediaWikiClient;
use Drupal\localnodes_import\Fetch\WebPage;
use Drupal\localnodes_import\Fetch\WebPageExtractor;
use Drupal\localnodes_import\Service\HtmlSanitizer;
use Drupal\Tests\localnodes_import\Kernel\Support\FixtureWeb;

/**
 * MediaWiki and ordinary web pages, read through the real SafeFetcher.
 *
 * Every request goes through SafeFetcher over FixtureWeb's MockHttpClient,
 * so the P2P fixtures travel the code path a live fetch takes, with no
 * network (D-23). Licence detection (D-25a, c) is checked on small inline
 * pages: only CC BY, CC BY-SA and CC0 on creativecommons.org count, and the
 * label always comes from the importer's own map (threat T-22-85).
 *
 * No Drupal modules are needed: the classes are plain PHP over core's
 * Html/Xss utilities.
 *
 * @group phase22
 * @group localnodes_import
 */
final class MediaWikiImportTest extends KernelTestBase {

  private const P2P_API = 'https://wiki.p2pfoundation.net/api.php';

  /**
   * The fake web the fetcher reads.
   */
  private FixtureWeb $web;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->web = FixtureWeb::p2p();
  }

  /**
   * A MediaWiki client over the fake web.
   */
  private function client(): MediaWikiClient {
    return new MediaWikiClient($this->web->fetcher(), new HtmlSanitizer());
  }

  /**
   * An extractor over the fake web, sharing one fetcher with its client.
   */
  private function extractor(): WebPageExtractor {
    $fetcher = $this->web->fetcher();
    $sanitizer = new HtmlSanitizer();
    return new WebPageExtractor($fetcher, new MediaWikiClient($fetcher, $sanitizer), $sanitizer);
  }

  /**
   * The saved Bioregionalism article page.
   */
  private static function p2pPage(): string {
    return (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/mediawiki/bioregionalism.page.html');
  }

  /**
   * An ordinary article page, long enough for readability (500 characters).
   */
  private static function articlePage(string $head = '', string $extra = ''): string {
    $paragraph = '<p>The seed library lends packets of local, open-pollinated seed each spring. Borrowers grow the plants out, save seed from the strongest ones and bring some back in autumn, so the collection adapts to the valley a little more every year. See <a href="/how-to-save-seed">how to save seed</a>.</p>';
    return '<!DOCTYPE html><html><head><title>Seed library | Valley Commons</title><meta property="og:site_name" content="Valley Commons">' . $head . '</head><body>'
      . '<nav class="menu"><a href="/">Home</a> <a href="/about">About</a></nav>'
      . '<article><h1>Seed library</h1>' . str_repeat($paragraph, 4)
      . '<script>document.write("tracking")</script><img src="/seeds.jpg" alt="Seeds">' . $extra . '</article>'
      . '<footer>Footer links</footer></body></html>';
  }

  /**
   * A MediaWiki is found from its EditURI link or its generator meta.
   */
  public function testDetect(): void {
    $client = $this->client();
    $this->assertSame(self::P2P_API, $client->detect(self::p2pPage(), 'https://wiki.p2pfoundation.net/Bioregionalism'), 'EditURI on the P2P page');

    $generator_only = '<html><head><meta name="generator" content="MediaWiki 1.42.3"><link rel="search" type="application/opensearchdescription+xml" href="/w/opensearch_desc.php" title="Wiki"></head><body><p>x</p></body></html>';
    $this->assertSame('https://wiki.example.org/w/api.php', $client->detect($generator_only, 'https://wiki.example.org/wiki/Main_Page'), 'Generator meta, script path from the opensearch link');

    $this->assertNull($client->detect(self::articlePage(), 'https://valley.example/seed-library'), 'An ordinary page is not a wiki');
    $foreign = '<html><head><meta name="generator" content="MediaWiki 1.43.0"><link rel="EditURI" type="application/rsd+xml" href="https://elsewhere.example/api.php?action=rsd"></head></html>';
    $this->assertNull($client->detect($foreign, 'https://wiki.example.org/Page'), 'An api.php on another host is not trusted');
  }

  /**
   * Siteinfo gives the name and the detected licence, once per wiki.
   */
  public function testSiteInfo(): void {
    $client = $this->client();
    $site = $client->siteInfo(self::P2P_API);
    $this->assertIsArray($site);
    $this->assertSame('P2P Foundation Wiki', $site['sitename']);
    $this->assertSame('/$1', $site['articlepath']);
    $this->assertSame('https://wiki.p2pfoundation.net', $site['server']);
    $this->assertInstanceOf(DetectedLicense::class, $site['license']);
    $this->assertSame('cc_by_sa_4_0', $site['license']->key);
    $this->assertSame('CC BY-SA 4.0', $site['license']->label);

    $client->siteInfo(self::P2P_API);
    $this->assertSame(1, $this->web->requestsTo('wiki.p2pfoundation.net'), 'Siteinfo is fetched once per wiki');

    // Wikipedia's shape: protocol-relative server and a /deed.en licence.
    $this->web->addWiki('wiki.example.org', '/w/api.php', json_encode([
      'query' => [
        'general' => [
          'sitename' => 'Example Wiki',
          'articlepath' => '/wiki/$1',
          'server' => '//wiki.example.org',
          'mainpage' => 'Main Page',
        ],
        'rightsinfo' => [
          'url' => 'https://creativecommons.org/licenses/by-sa/4.0/deed.en',
          'text' => 'Creative Commons Attribution-Share Alike 4.0',
        ],
      ],
    ]), [], '<html></html>');
    $site = $this->client()->siteInfo('https://wiki.example.org/w/api.php');
    $this->assertSame('https://wiki.example.org', $site['server'], 'A protocol-relative server takes the api.php scheme');
    $this->assertSame('cc_by_sa_4_0', $site['license']?->key, 'A trailing /deed.en is the same licence');

    // A wiki whose licence isn't an open CC licence records none.
    $this->web->addWiki('gfdl.example.org', '/api.php', json_encode([
      'query' => [
        'general' => ['sitename' => 'GFDL Wiki', 'articlepath' => '/$1', 'server' => 'https://gfdl.example.org'],
        'rightsinfo' => ['url' => 'https://www.gnu.org/copyleft/fdl.html', 'text' => 'GNU Free Documentation License'],
      ],
    ]), [], '<html></html>');
    $this->assertNull($this->client()->siteInfo('https://gfdl.example.org/api.php')['license']);
  }

  /**
   * Only BY, BY-SA and CC0 on creativecommons.org are recognised (D-25a, c).
   */
  public function testDetectedLicenseFromUrl(): void {
    $by4 = [
      'cc_by_4_0',
      'CC BY 4.0',
      'Creative Commons Attribution 4.0 license',
      'https://creativecommons.org/licenses/by/4.0/',
    ];
    $by_sa4 = [
      'cc_by_sa_4_0',
      'CC BY-SA 4.0',
      'Creative Commons Attribution-ShareAlike 4.0 license',
      'https://creativecommons.org/licenses/by-sa/4.0/',
    ];
    $by_sa3 = [
      NULL,
      'CC BY-SA 3.0',
      'Creative Commons Attribution-ShareAlike 3.0 license',
      'https://creativecommons.org/licenses/by-sa/3.0/',
    ];
    $by25 = [
      NULL,
      'CC BY 2.5',
      'Creative Commons Attribution 2.5 license',
      'https://creativecommons.org/licenses/by/2.5/',
    ];
    $cc0 = [
      'cc0_1_0',
      'CC0 1.0',
      'CC0 1.0 Universal public domain dedication',
      'https://creativecommons.org/publicdomain/zero/1.0/',
    ];
    $open = [
      'https://creativecommons.org/licenses/by/4.0/' => $by4,
      'https://creativecommons.org/licenses/by-sa/4.0/' => $by_sa4,
      'https://creativecommons.org/licenses/by-sa/4.0/deed.en' => $by_sa4,
      'http://creativecommons.org/licenses/by-sa/3.0/deed.en' => $by_sa3,
      'https://www.creativecommons.org/licenses/by/2.5/legalcode' => $by25,
      'https://creativecommons.org/licenses/by/4.0/legalcode.en' => $by4,
      'https://creativecommons.org/publicdomain/zero/1.0/' => $cc0,
      'HTTPS://CreativeCommons.org/publicdomain/zero/1.0/deed.fr' => $cc0,
    ];
    foreach ($open as $url => [$key, $label, $name, $deed]) {
      $license = DetectedLicense::fromUrl($url);
      $this->assertNotNull($license, "$url is an open licence");
      $this->assertSame([$key, $label, $name, $deed], [$license->key, $license->label, $license->name, $license->url], $url);
    }

    $refused = [
      'https://creativecommons.org/licenses/by-nc/4.0/',
      'https://creativecommons.org/licenses/by-nd/4.0/',
      'https://creativecommons.org/licenses/by-nc-sa/4.0/',
      'https://creativecommons.org/licenses/by/9.9/',
      'https://creativecommons.org/licenses/by/4.0/somewhere-else/',
      'https://creativecommons.org/publicdomain/mark/1.0/',
      'https://www.gnu.org/copyleft/fdl.html',
      'https://evil.example/licenses/by/4.0/',
      'https://creativecommons.org.evil.example/licenses/by/4.0/',
      'https://user@creativecommons.org/licenses/by/4.0/',
      'https://creativecommons.org:8443/licenses/by/4.0/',
      'javascript:alert(1)//creativecommons.org/licenses/by/4.0/',
      'ftp://creativecommons.org/licenses/by/4.0/',
      '//creativecommons.org/licenses/by/4.0/',
      '/licenses/by/4.0/',
      '',
    ];
    foreach ($refused as $url) {
      $this->assertNull(DetectedLicense::fromUrl($url), "'$url' is not an open licence");
    }
  }

  /**
   * Every label fromUrl() produces reads back through fromLabel().
   */
  public function testFromLabelRoundTrips(): void {
    foreach (['by', 'by-sa'] as $family) {
      foreach (['1.0', '2.0', '2.5', '3.0', '4.0'] as $version) {
        $license = DetectedLicense::fromUrl("https://creativecommons.org/licenses/$family/$version/");
        $this->assertNotNull($license, "$family $version");
        $this->assertEquals($license, DetectedLicense::fromLabel($license->label), $license->label);
      }
    }
    $cc0 = DetectedLicense::fromUrl('https://creativecommons.org/publicdomain/zero/1.0/');
    $this->assertEquals($cc0, DetectedLicense::fromLabel('CC0 1.0'));
    foreach (['CC BY-NC 4.0', 'CC BY 9.9', 'cc by 4.0x', 'Public domain', ''] as $label) {
      $this->assertNull(DetectedLicense::fromLabel($label), "'$label' is not a label the importer makes");
    }
  }

  /**
   * A page's own rel=license link or one CC badge names its licence.
   */
  public function testLicenseIn(): void {
    $extractor = $this->extractor();
    $wrap = static fn (string $head, string $body = ''): string => "<html><head>$head</head><body><p>Text</p>$body</body></html>";

    $this->assertSame('CC BY 4.0', $extractor->licenseIn($wrap('<link rel="license" href="https://creativecommons.org/licenses/by/4.0/">'))?->label, 'link rel=license');
    $this->assertSame('cc_by_4_0', $extractor->licenseIn($wrap('<link rel="License Alternate" href="https://creativecommons.org/licenses/by/4.0/">'))?->key, 'rel is a token list, any case');
    $this->assertSame('CC BY-SA 3.0', $extractor->licenseIn($wrap('', '<p><a rel="license" href="http://creativecommons.org/licenses/by-sa/3.0/">Some rights reserved</a></p>'))?->label, 'a rel=license');

    $badge = '<a href="https://creativecommons.org/licenses/by-sa/4.0/"><img src="https://licensebuttons.net/l/by-sa/4.0/88x31.png" alt="CC BY-SA"></a>';
    $this->assertSame('cc_by_sa_4_0', $extractor->licenseIn($wrap('', $badge))?->key, 'A single CC badge');
    $this->assertSame('cc_by_sa_4_0', $extractor->licenseIn($wrap('', $badge . $badge))?->key, 'The same badge twice is one licence');

    $other = '<a href="https://creativecommons.org/licenses/by/4.0/"><img src="b.png" alt="CC BY"></a>';
    $this->assertNull($extractor->licenseIn($wrap('', $badge . $other)), 'Two different badges and no rel=license: none');
    $this->assertNull($extractor->licenseIn($wrap('<link rel="license" href="https://evil.example/licenses/by/4.0/">', $badge)), 'rel=license on another host decides, and it is not open');
    $this->assertNull($extractor->licenseIn($wrap('', '<a href="https://creativecommons.org/licenses/by/4.0/">CC BY</a>')), 'A plain text link is not a badge');
    $this->assertNull($extractor->licenseIn($wrap('<link rel="license" href="https://creativecommons.org/licenses/by-nc/4.0/">')), 'NC is not open');
    $this->assertNull($extractor->licenseIn($wrap('', '<p>This page is licensed CC BY 4.0.</p>')), 'Wording alone is not a licence');

    // The page's own wording never becomes the label.
    $spoof = $extractor->licenseIn($wrap('', '<a rel="license" href="https://creativecommons.org/licenses/by/4.0/">Public domain, do anything</a>'));
    $this->assertSame('CC BY 4.0', $spoof?->label);
    $this->assertSame('Creative Commons Attribution 4.0 license', $spoof?->name);
  }

  /**
   * All five P2P pages parse with their titles, revids and credit.
   */
  public function testTheFiveP2pPagesParse(): void {
    $client = $this->client();
    foreach (FixtureWeb::p2pAddresses() as $address => [$title, $revid]) {
      $page = $client->parse(self::P2P_API, $title);
      $this->assertInstanceOf(WebPage::class, $page, $title);
      $this->assertSame($title, $page->title);
      $this->assertSame($revid, $page->revid);
      $this->assertSame($address, $page->sourceUrl, "$title canonical address");
      $this->assertSame('P2P Foundation Wiki', $page->siteName);
      $this->assertSame('cc_by_sa_4_0', $page->license?->key);
      $this->assertNotSame('', trim(strip_tags($page->html)), "$title has text");
      foreach (['<img', '<script', 'class=', 'style=', 'href="/'] as $banned) {
        $this->assertStringNotContainsString($banned, $page->html, "$title: $banned");
      }
    }

    $bioregionalism = $client->parse(self::P2P_API, 'Bioregionalism');
    $this->assertSame(2, substr_count($bioregionalism->html, '<blockquote>'), 'Blockquotes kept');
    $this->assertStringContainsString('(Source: Peter Berg and Raymond Dasmann', $bioregionalism->html, 'The credit line is kept');
    $this->assertStringContainsString('href="https://wiki.p2pfoundation.net/Bioregional_Movement"', $bioregionalism->html, 'Links are absolute');
  }

  /**
   * One page, however it is addressed, has one canonical address.
   */
  public function testCanonicalUrl(): void {
    $client = $this->client();
    $site = $client->siteInfo(self::P2P_API);
    $this->assertSame('https://wiki.p2pfoundation.net/Bioregionalism', $client->canonicalUrl(self::P2P_API, $site, 'Bioregionalism'));
    $this->assertSame('https://wiki.p2pfoundation.net/Commons-Based_Peer_Production', $client->canonicalUrl(self::P2P_API, $site, 'Commons-Based Peer Production'));

    // A parse that followed a redirect reports the target's title.
    $redirected = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/mediawiki/bioregionalism.parse.json'), TRUE);
    $redirected['parse']['redirects'] = [['from' => 'Bioregion', 'to' => 'Bioregionalism']];
    $this->web->addParse('wiki.p2pfoundation.net', 'Bioregion', json_encode($redirected));

    $extractor = $this->extractor();
    foreach ([
      'https://wiki.p2pfoundation.net/Bioregionalism',
      'https://wiki.p2pfoundation.net/index.php?title=Bioregionalism',
      'https://WIKI.p2pfoundation.net/Bioregionalism#History',
      'https://wiki.p2pfoundation.net/Bioregion',
    ] as $address) {
      $page = $extractor->fetchPage($address);
      $this->assertInstanceOf(WebPage::class, $page, $address);
      $this->assertSame('https://wiki.p2pfoundation.net/Bioregionalism', $page->sourceUrl, $address);
      $this->assertSame(151033, $page->revid, $address);
    }
  }

  /**
   * A missing page is not_found; a fetch failure passes through unchanged.
   */
  public function testFailures(): void {
    $missing = $this->client()->parse(self::P2P_API, 'No Such Page');
    $this->assertInstanceOf(FetchResult::class, $missing);
    $this->assertSame(FetchResult::NOT_FOUND, $missing->reason);

    $this->web->challenge('blocked.example');
    $challenged = $this->extractor()->fetchPage('https://blocked.example/Some_Page');
    $this->assertInstanceOf(FetchResult::class, $challenged);
    $this->assertSame(FetchResult::BOT_CHALLENGE, $challenged->reason);
    $this->assertSame(FetchResult::BOT_CHALLENGE, $this->client()->parse('https://blocked.example/api.php', 'Some Page')->reason, 'A challenged api.php is a bot challenge');

    $this->web->addPage('https://valley.example/gone', 'gone', 404);
    $this->assertSame(FetchResult::NOT_FOUND, $this->extractor()->fetchPage('https://valley.example/gone')->reason);
    $this->assertSame(FetchResult::BLOCKED_ADDRESS, $this->extractor()->fetchPage('http://127.0.0.1/admin')->reason);

    $this->assertSame(FetchResult::NOT_A_PAGE, $this->extractor()->extract('', 'https://valley.example/empty')->reason, 'Nothing readable is not a page');
  }

  /**
   * An ordinary page goes through readability and the sanitiser.
   */
  public function testOrdinaryPage(): void {
    $url = 'https://valley.example/library/seed-library';
    $this->web->addPage($url, self::articlePage('<link rel="license" href="https://creativecommons.org/licenses/by/4.0/">'));
    $page = $this->extractor()->fetchPage($url);
    $this->assertInstanceOf(WebPage::class, $page);
    $this->assertSame('Seed library', $page->title);
    $this->assertSame($url, $page->sourceUrl);
    $this->assertSame('Valley Commons', $page->siteName);
    $this->assertNull($page->revid);
    $this->assertSame('cc_by_4_0', $page->license?->key);
    $this->assertStringContainsString('The seed library lends packets', $page->html);
    $this->assertStringContainsString('href="https://valley.example/how-to-save-seed"', $page->html, 'Relative links resolve against the page');
    foreach (['<script', '<img', 'tracking', 'class=', 'Footer links'] as $banned) {
      $this->assertStringNotContainsString($banned, $page->html, $banned);
    }

    // No site name meta: the host stands in.
    $page = $this->extractor()->extract(str_replace('<meta property="og:site_name" content="Valley Commons">', '', self::articlePage()), $url);
    $this->assertSame('valley.example', $page->siteName);
    $this->assertNull($page->license);
  }

  /**
   * LicenseFor() reads one address's licence, once per host.
   */
  public function testLicenseFor(): void {
    $this->web->addPage('https://valley.example/a', self::articlePage('<link rel="license" href="https://creativecommons.org/licenses/by-sa/3.0/">'));
    $this->web->addPage('https://valley.example/b', self::articlePage());
    $extractor = $this->extractor();

    $this->assertSame('cc_by_sa_4_0', $extractor->licenseFor('https://wiki.p2pfoundation.net/Bioregionalism')?->key, 'A MediaWiki: its siteinfo licence');
    $this->assertSame(2, $this->web->requestsTo('wiki.p2pfoundation.net'), 'One page fetch and one siteinfo call');
    $this->assertSame('cc_by_sa_4_0', $extractor->licenseFor('https://wiki.p2pfoundation.net/Commons_Transition')?->key);
    $this->assertSame(2, $this->web->requestsTo('wiki.p2pfoundation.net'), 'Cached per host for the request');

    $this->assertSame('CC BY-SA 3.0', $extractor->licenseFor('https://valley.example/a')?->label, 'Another site: its rel=license');
    $this->web->challenge('blocked.example');
    $this->assertNull($extractor->licenseFor('https://blocked.example/x'), 'A fetch failure is no licence');
    $this->assertNull($this->extractor()->licenseFor('https://valley.example/b'), 'No signal is no licence');
  }

}
