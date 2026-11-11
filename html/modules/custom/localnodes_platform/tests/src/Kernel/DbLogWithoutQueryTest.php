<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Logger\DbLogWithoutQuery;
use Symfony\Component\HttpFoundation\Request;

/**
 * Watchdog rows keep the page path but not its query string or fragment.
 *
 * Search and the AI overview put a member's words in the query string, Open
 * Social's search pages put them in the path (/search/all/<words>), and core
 * stores the full request URL and Referer with every log row.
 *
 * @group localnodes_platform
 */
final class DbLogWithoutQueryTest extends KernelTestBase {

  private const URL_CANARY = 'QZXURL3317';
  private const REFERER_CANARY = 'QZXREF6402';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'dblog',
    'localnodes_platform',
    'localnodes_log_uri_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('dblog', ['watchdog']);
  }

  /**
   * A row written during a request with words in the URL and Referer.
   */
  public function testLocationAndRefererLoseQueryAndFragment(): void {
    $request = Request::create('https://site.test/search/all?keys=' . self::URL_CANARY . '#' . self::URL_CANARY);
    $request->headers->set('Referer', 'https://site.test/api/ai/overview?q=' . self::REFERER_CANARY . '#frag' . self::REFERER_CANARY);
    $this->container->get('request_stack')->push($request);

    $this->container->get('logger.factory')->get('gate_a_test')->warning('A line with no member text.');

    $row = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['location', 'referer', 'message'])
      ->condition('type', 'gate_a_test')
      ->execute()
      ->fetchAssoc();
    $this->assertNotFalse($row, 'The row was stored once.');
    $this->assertSame('A line with no member text.', $row['message']);
    $this->assertSame('https://site.test/search/all', $row['location']);
    $this->assertSame('https://site.test/api/ai/overview', $row['referer']);
    $this->assertSame(1, (int) $this->container->get('database')->select('watchdog', 'w')
      ->condition('type', 'gate_a_test')->countQuery()->execute()->fetchField(), 'Exactly one row: the decorated logger replaces dblog, it does not add to it.');
  }

  /**
   * A URL without a query string is stored unchanged.
   */
  public function testRowWithoutQueryIsUnchanged(): void {
    $request = Request::create('https://site.test/node/5');
    $this->container->get('request_stack')->push($request);
    $this->container->get('logger.factory')->get('gate_a_test')->notice('Plain.');

    $row = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['location', 'referer'])
      ->condition('type', 'gate_a_test')
      ->execute()
      ->fetchAssoc();
    $this->assertSame('https://site.test/node/5', $row['location']);
    $this->assertSame('', $row['referer']);
  }

  /**
   * Words in a search page's path are replaced in both the URL and Referer.
   */
  public function testSearchPathLosesTheWords(): void {
    $request = Request::create('https://site.test/search/content/' . self::URL_CANARY . '%20two?page=1');
    $request->headers->set('Referer', 'https://site.test/nl/search/all/' . self::REFERER_CANARY);
    $this->container->get('request_stack')->push($request);
    $this->container->get('logger.factory')->get('gate_a_test')->warning('Search.');

    $row = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['location', 'referer'])
      ->condition('type', 'gate_a_test')
      ->execute()
      ->fetchAssoc();
    $this->assertSame('https://site.test/search/content/-', $row['location']);
    $this->assertSame('https://site.test/nl/search/all/-', $row['referer']);
  }

  /**
   * Core's 403 and 404 rows put the request address in the @uri placeholder.
   *
   * A real request through the HTTP kernel, so the row is the one core's
   * exception logger writes, and the placeholder is read back from the
   * stored variables.
   *
   * @dataProvider deniedAndMissing
   */
  public function testUriPlaceholderLosesTheWords(string $address, int $status, string $type, string $logged): void {
    $response = $this->container->get('http_kernel')->handle(Request::create($address));
    $this->assertSame($status, $response->getStatusCode());

    $rows = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['variables', 'location'])
      ->condition('type', $type)
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $rows);
    $variables = unserialize($rows[0]->variables, ['allowed_classes' => FALSE]);
    $this->assertSame($logged, $variables['@uri']);
    $this->assertStringNotContainsString(self::URL_CANARY, $rows[0]->variables);
    $this->assertStringNotContainsString(self::URL_CANARY, $rows[0]->location);
  }

  /**
   * Search addresses that are refused or not found, and the logged @uri.
   */
  public static function deniedAndMissing(): array {
    return [
      '403, words in the path' => ['/search/all/' . self::URL_CANARY . '%20two', 403, 'access denied', '/search/all/-'],
      '403, words in the query' => ['/search/all/x/?keys=' . self::URL_CANARY, 403, 'access denied', '/search/all/-'],
      '404, words in the path' => ['/search/content/' . self::URL_CANARY, 404, 'page not found', '/search/content/-'],
      '404, words in the query' => ['/no-such-page?keys=' . self::URL_CANARY, 404, 'page not found', '/no-such-page'],
    ];
  }

  /**
   * The rule on its own: what is cut and what is left alone.
   *
   * @dataProvider searchPaths
   */
  public function testSearchPathRule(string $url, string $logged): void {
    $this->assertSame($logged, DbLogWithoutQuery::withoutSearchWords($url));
  }

  /**
   * Paths and what is logged for them.
   */
  public static function searchPaths(): array {
    return [
      'search words' => ['/search/all/words', '/search/all/-'],
      'full URL' => ['http://site.test/search/users/a%20name', 'http://site.test/search/users/-'],
      'language prefix' => ['/pt-br/search/groups/x', '/pt-br/search/groups/-'],
      'any case, repeated slashes' => ['//Search//ALL//x', '//Search//ALL/-'],
      'encoded letters' => ['/%73earch/all/x', '/search/all/-'],
      'encoded slash in the words' => ['/search/all/a%2Fb', '/search/all/-'],
      'front controller' => ['https://site.test/index.php/search/all/words', 'https://site.test/index.php/search/all/-'],
      'front controller and language' => ['/index.php/nl/search/content/x', '/index.php/nl/search/content/-'],
      'no words' => ['/search/all', '/search/all'],
      'trailing slash only' => ['/search/all/', '/search/all/'],
      'other page' => ['https://site.test/node/5', 'https://site.test/node/5'],
      'search deeper in the path' => ['/admin/config/search/path/patterns', '/admin/config/search/path/patterns'],
      'empty' => ['', ''],
    ];
  }

}
