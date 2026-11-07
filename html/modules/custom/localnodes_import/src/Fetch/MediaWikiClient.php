<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Fetch;

use Drupal\localnodes_import\Service\HtmlSanitizer;

/**
 * Reads MediaWiki pages through the wiki's own API (D-13, RESEARCH 6).
 *
 * The page HTML is fetched once anyway; a MediaWiki is recognised from its
 * EditURI link or generator meta, then:
 * - api.php?action=query&meta=siteinfo&siprop=general|rightsinfo, once per
 *   wiki for the life of this object (the site name, the licence through
 *   DetectedLicense::fromUrl(), articlepath and server);
 * - api.php?action=parse for the page: clean article HTML without
 *   navigation, with blockquotes and quoted authors' credits, and the
 *   revision id. It follows redirects, so the title it reports is the
 *   canonical one.
 *
 * Every request goes through SafeFetcher. A fetch failure is returned as the
 * FetchResult it is (bot_challenge included), never retried here.
 */
final class MediaWikiClient {

  /**
   * MediaWiki API error codes that mean the page doesn't exist.
   */
  private const MISSING = ['missingtitle', 'invalidtitle', 'pagecannotexist', 'nosuchpageid', 'missingrevision'];

  /**
   * MediaWiki API error codes that mean "busy, try later".
   */
  private const BUSY = ['maxlag', 'ratelimited', 'readonly', 'internal_api_error_DBQueryTimeoutError'];

  /**
   * Siteinfo by api.php address: the site array, or the failure.
   *
   * @var array<string, array|\Drupal\localnodes_import\Fetch\FetchResult>
   */
  private array $siteInfo = [];

  public function __construct(
    private readonly SafeFetcher $fetcher,
    private readonly HtmlSanitizer $sanitizer,
  ) {}

  /**
   * The wiki's api.php address, when the page is a MediaWiki page.
   *
   * The EditURI link names api.php directly. Without one, a "MediaWiki ..."
   * generator meta plus any link to the wiki's scripts (opensearch_desc.php,
   * load.php, index.php, api.php) gives the script directory. The api.php
   * must be on the page's own host.
   *
   * @return string|null
   *   An absolute api.php address without a query, or NULL.
   */
  public function detect(string $page_html, string $page_url): ?string {
    $page_host = strtolower((string) parse_url($page_url, PHP_URL_HOST));
    if ($page_host === '' || trim($page_html) === '') {
      return NULL;
    }
    $document = HtmlSanitizer::loadDocument($page_html);

    foreach ($document->getElementsByTagName('link') as $link) {
      if (HtmlSanitizer::hasRelToken($link, 'edituri')) {
        $api = $this->apiAddress($link->getAttribute('href'), $page_url, $page_host, '#/api\.php$#');
        if ($api !== NULL) {
          return $api;
        }
      }
    }

    $is_mediawiki = FALSE;
    foreach ($document->getElementsByTagName('meta') as $meta) {
      if (strtolower($meta->getAttribute('name')) === 'generator' && stripos(trim($meta->getAttribute('content')), 'MediaWiki') === 0) {
        $is_mediawiki = TRUE;
        break;
      }
    }
    if (!$is_mediawiki) {
      return NULL;
    }
    foreach (['link', 'a'] as $tag) {
      foreach ($document->getElementsByTagName($tag) as $element) {
        $api = $this->apiAddress($element->getAttribute('href'), $page_url, $page_host, '#/(?:api|index|load|opensearch_desc)\.php$#');
        if ($api !== NULL) {
          return $api;
        }
      }
    }
    return NULL;
  }

  /**
   * The wiki's name, licence and address scheme, once per wiki.
   *
   * @return array|\Drupal\localnodes_import\Fetch\FetchResult
   *   On success: sitename, license (?DetectedLicense, NULL when the
   *   rightsinfo URL isn't an open CC licence), articlepath (with $1),
   *   server (scheme://host, no trailing slash), script and mainpage. On
   *   failure, the FetchResult.
   */
  public function siteInfo(string $api_url): array|FetchResult {
    if (isset($this->siteInfo[$api_url])) {
      return $this->siteInfo[$api_url];
    }
    $query = http_build_query([
      'action' => 'query',
      'meta' => 'siteinfo',
      'siprop' => 'general|rightsinfo',
      'format' => 'json',
      'formatversion' => 2,
    ], '', '&', PHP_QUERY_RFC3986);
    $result = $this->fetcher->get($api_url . '?' . $query, 'json');
    if (!$result->ok) {
      return $this->siteInfo[$api_url] = $result;
    }
    $data = json_decode((string) $result->body, TRUE);
    $general = is_array($data) ? ($data['query']['general'] ?? NULL) : NULL;
    if (!is_array($general)) {
      return $this->siteInfo[$api_url] = FetchResult::failed(FetchResult::UNKNOWN);
    }

    $api = parse_url($api_url);
    $origin = strtolower($api['scheme']) . '://' . strtolower($api['host']) . (isset($api['port']) ? ':' . $api['port'] : '');
    // The server the wiki reports, if it is the api's own host; a wiki can't
    // send credit to another site through it.
    $server = is_string($general['server'] ?? NULL) ? trim($general['server']) : '';
    if (str_starts_with($server, '//')) {
      $server = strtolower($api['scheme']) . ':' . $server;
    }
    $server_parts = parse_url($server);
    $server = isset($server_parts['scheme'], $server_parts['host'])
      && in_array(strtolower($server_parts['scheme']), ['http', 'https'], TRUE)
      && strtolower($server_parts['host']) === strtolower($api['host'])
      ? strtolower($server_parts['scheme']) . '://' . strtolower($server_parts['host']) . (isset($server_parts['port']) ? ':' . $server_parts['port'] : '')
      : $origin;

    $script = is_string($general['script'] ?? NULL) && str_starts_with($general['script'], '/') ? $general['script'] : '/index.php';
    $articlepath = is_string($general['articlepath'] ?? NULL) && str_starts_with($general['articlepath'], '/') && str_contains($general['articlepath'], '$1')
      ? $general['articlepath']
      : $script . '?title=$1';
    $sitename = trim(strip_tags(is_string($general['sitename'] ?? NULL) ? $general['sitename'] : ''));
    $rights_url = $data['query']['rightsinfo']['url'] ?? NULL;

    return $this->siteInfo[$api_url] = [
      'sitename' => mb_substr($sitename !== '' ? $sitename : (string) $api['host'], 0, 255),
      'license' => is_string($rights_url) ? DetectedLicense::fromUrl($rights_url) : NULL,
      'articlepath' => $articlepath,
      'server' => $server,
      'script' => $script,
      'mainpage' => is_string($general['mainpage'] ?? NULL) && $general['mainpage'] !== '' ? $general['mainpage'] : 'Main Page',
    ];
  }

  /**
   * The page title an address on the wiki names.
   *
   * From a title= query parameter, the wiki's own script with no title (its
   * main page), or the articlepath pattern. Underscores become spaces.
   *
   * @return string|null
   *   The title, or NULL when the address doesn't name an article.
   */
  public function titleFromUrl(array $site, string $url): ?string {
    $parts = parse_url($url);
    if ($parts === FALSE) {
      return NULL;
    }
    parse_str($parts['query'] ?? '', $query);
    if (isset($query['title']) && is_string($query['title']) && trim($query['title']) !== '') {
      return self::normaliseTitle($query['title']);
    }
    $path = $parts['path'] ?? '/';
    if ($path === '' || $path === '/' || $path === $site['script']) {
      return $site['mainpage'];
    }
    $pattern = (string) strtok($site['articlepath'], '?');
    if (!str_contains($pattern, '$1')) {
      return NULL;
    }
    [$prefix, $suffix] = explode('$1', $pattern, 2);
    if (!str_starts_with($path, $prefix) || ($suffix !== '' && !str_ends_with($path, $suffix))) {
      return NULL;
    }
    $title = substr($path, strlen($prefix), strlen($path) - strlen($prefix) - strlen($suffix));
    $title = self::normaliseTitle(rawurldecode($title));
    return $title === '' ? $site['mainpage'] : $title;
  }

  /**
   * Parses one page through the wiki's API.
   *
   * @return \Drupal\localnodes_import\Fetch\WebPage|\Drupal\localnodes_import\Fetch\FetchResult
   *   The page with sanitised HTML, its canonical address, the site name,
   *   the site's licence and the revision id; or a failure: not_found for a
   *   page the wiki doesn't have, no_response when the wiki is busy, or the
   *   fetch failure as it came.
   */
  public function parse(string $api_url, string $title): WebPage|FetchResult {
    $site = $this->siteInfo($api_url);
    if ($site instanceof FetchResult) {
      return $site;
    }
    $query = http_build_query([
      'action' => 'parse',
      'page' => $title,
      'prop' => 'text|revid|displaytitle',
      'disableeditsection' => 1,
      'disabletoc' => 1,
      'redirects' => 1,
      'format' => 'json',
      'formatversion' => 2,
      'maxlag' => 5,
    ], '', '&', PHP_QUERY_RFC3986);
    $result = $this->fetcher->get($api_url . '?' . $query, 'json');
    if (!$result->ok) {
      return $result;
    }
    $data = json_decode((string) $result->body, TRUE);
    if (!is_array($data)) {
      return FetchResult::failed(FetchResult::UNKNOWN);
    }
    if (isset($data['error'])) {
      $code = is_array($data['error']) ? (string) ($data['error']['code'] ?? '') : '';
      return FetchResult::failed(match (TRUE) {
        in_array($code, self::MISSING, TRUE) => FetchResult::NOT_FOUND,
        in_array($code, self::BUSY, TRUE) => FetchResult::NO_RESPONSE,
        default => FetchResult::UNKNOWN,
      });
    }
    $parse = $data['parse'] ?? NULL;
    if (!is_array($parse) || !is_string($parse['title'] ?? NULL) || $parse['title'] === '') {
      return FetchResult::failed(FetchResult::UNKNOWN);
    }
    // formatversion=2 gives a string; version 1 gives {"*": "..."}.
    $text = $parse['text'] ?? '';
    if (is_array($text)) {
      $text = $text['*'] ?? '';
    }

    $canonical = $this->canonicalUrl($api_url, $site, $parse['title']);
    return new WebPage(
      title: mb_substr(trim($parse['title']), 0, 255),
      html: $this->sanitizer->sanitize(is_string($text) ? $text : '', $canonical),
      sourceUrl: $canonical,
      siteName: $site['sitename'],
      license: $site['license'],
      revid: isset($parse['revid']) && is_numeric($parse['revid']) ? (int) $parse['revid'] : NULL,
    );
  }

  /**
   * The one address of a page, from the wiki's server and articlepath.
   *
   * Given the title the parse response reports (after redirects), the
   * addresses /Bioregionalism, /index.php?title=Bioregionalism and a
   * redirect to it all give the same canonical address (D-15).
   */
  public function canonicalUrl(string $api_url, array $site, string $title): string {
    $encoded = rawurlencode(str_replace(' ', '_', self::normaliseTitle($title)));
    // MediaWiki leaves these unencoded in its own links (wfUrlencode).
    $encoded = strtr($encoded, [
      '%3B' => ';',
      '%40' => '@',
      '%24' => '$',
      '%21' => '!',
      '%2A' => '*',
      '%28' => '(',
      '%29' => ')',
      '%2C' => ',',
      '%2F' => '/',
      '%7E' => '~',
      '%3A' => ':',
    ]);
    return $site['server'] . str_replace('$1', $encoded, $site['articlepath']);
  }

  /**
   * A title with underscores as spaces and runs of spaces collapsed.
   */
  private static function normaliseTitle(string $title): string {
    return trim((string) preg_replace('/[\s_]+/u', ' ', $title));
  }

  /**
   * An api.php address from a link to one of the wiki's scripts, or NULL.
   */
  private function apiAddress(string $href, string $page_url, string $page_host, string $script_pattern): ?string {
    $absolute = HtmlSanitizer::absolute($href, $page_url);
    if ($absolute === NULL) {
      return NULL;
    }
    $parts = parse_url($absolute);
    if (!isset($parts['scheme'], $parts['host'], $parts['path'])
      || !in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE)
      || strtolower($parts['host']) !== $page_host
      || !preg_match($script_pattern, $parts['path'])) {
      return NULL;
    }
    $directory = substr($parts['path'], 0, (int) strrpos($parts['path'], '/'));
    return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
      . (isset($parts['port']) ? ':' . $parts['port'] : '')
      . $directory . '/api.php';
  }

}
