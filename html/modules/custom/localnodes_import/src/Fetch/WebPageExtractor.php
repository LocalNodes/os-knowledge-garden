<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Fetch;

use Drupal\localnodes_import\Service\HtmlSanitizer;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;

/**
 * Turns a web address into a WebPage, and finds the licence a page states.
 *
 * FetchPage() fetches the address once through SafeFetcher. A MediaWiki page
 * then goes through the wiki's API (MediaWikiClient); any other page goes
 * through readability.php's main-content extraction (D-13). Both bodies pass
 * through HtmlSanitizer.
 *
 * The licence is detected, never chosen (D-25a, c): a MediaWiki's siteinfo
 * rightsinfo, or for other sites the page's own rel=license link or a single
 * CC badge, each through DetectedLicense::fromUrl().
 */
final class WebPageExtractor {

  /**
   * LicenseFor() and siteFor() answers, by host, for the life of this object.
   *
   * @var array<string, array{license: ?\Drupal\localnodes_import\Fetch\DetectedLicense, site_name: string, api: ?string, wiki: ?array}>
   */
  private array $sites = [];

  public function __construct(
    private readonly SafeFetcher $fetcher,
    private readonly MediaWikiClient $mediaWiki,
    private readonly HtmlSanitizer $sanitizer,
  ) {}

  /**
   * Fetches an address and reads it as a page.
   *
   * @return \Drupal\localnodes_import\Fetch\WebPage|\Drupal\localnodes_import\Fetch\FetchResult
   *   The page, or the failure (a fetch failure passes through unchanged).
   */
  public function fetchPage(string $url): WebPage|FetchResult {
    $result = $this->fetcher->get($url, 'html');
    if (!$result->ok) {
      return $result;
    }
    $final_url = (string) ($result->finalUrl ?? $url);
    $body = (string) $result->body;

    $api = $this->mediaWiki->detect($body, $final_url);
    if ($api !== NULL) {
      $site = $this->mediaWiki->siteInfo($api);
      if (is_array($site)) {
        $title = $this->mediaWiki->titleFromUrl($site, $final_url);
        if ($title !== NULL) {
          return $this->mediaWiki->parse($api, $title);
        }
      }
      // The wiki's API didn't answer, or the address isn't an article (a
      // special page, a diff): read the page we already have.
    }
    return $this->extract($body, $final_url);
  }

  /**
   * Reads an ordinary page's main content (readability.php).
   *
   * @param string $html
   *   The page HTML.
   * @param string $url
   *   The address it came from; relative links resolve against it.
   *
   * @return \Drupal\localnodes_import\Fetch\WebPage|\Drupal\localnodes_import\Fetch\FetchResult
   *   The page, or not_a_page when no main content can be found.
   */
  public function extract(string $html, string $url): WebPage|FetchResult {
    if (trim($html) === '') {
      return FetchResult::failed(FetchResult::NOT_A_PAGE);
    }
    $configuration = (new Configuration())
      ->setFixRelativeURLs(TRUE)
      ->setOriginalURL($url);
    $readability = new Readability($configuration);
    try {
      $readability->parse($html);
    }
    catch (ParseException) {
      return FetchResult::failed(FetchResult::NOT_A_PAGE);
    }
    $content = $this->sanitizer->sanitize((string) $readability->getContent(), $url);
    if (trim(strip_tags($content)) === '') {
      return FetchResult::failed(FetchResult::NOT_A_PAGE);
    }

    $document = HtmlSanitizer::loadDocument($html);
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $title = self::metaContent($document, 'og:title')
      ?? self::onlyHeading($document)
      ?? self::plainText((string) $readability->getTitle())
      ?? $host;

    return new WebPage(
      title: mb_substr($title, 0, 255),
      html: $content,
      sourceUrl: self::canonicalUrl($url),
      siteName: mb_substr(self::metaContent($document, 'og:site_name') ?? $host, 0, 255),
      license: $this->licenseIn($html),
    );
  }

  /**
   * The open licence a page's own markup states, or NULL.
   *
   * In order, and the first that exists decides:
   * 1. the first link element whose rel includes "license";
   * 2. the first a element whose rel includes "license";
   * 3. CC badges (a link to creativecommons.org wrapping an img), used only
   *    when exactly one distinct licence appears and no badge names a
   *    licence that isn't open.
   * The visible wording of the page or the link is never read.
   */
  public function licenseIn(string $html): ?DetectedLicense {
    if (trim($html) === '') {
      return NULL;
    }
    $document = HtmlSanitizer::loadDocument($html);
    foreach (['link', 'a'] as $tag) {
      foreach ($document->getElementsByTagName($tag) as $element) {
        if (HtmlSanitizer::hasRelToken($element, 'license')) {
          return DetectedLicense::fromUrl($element->getAttribute('href'));
        }
      }
    }

    $found = [];
    foreach ($document->getElementsByTagName('a') as $link) {
      if ($link->getElementsByTagName('img')->length === 0) {
        continue;
      }
      $href = $link->getAttribute('href');
      $license = DetectedLicense::fromUrl($href);
      if ($license !== NULL) {
        $found[$license->label] = $license;
      }
      elseif (preg_match('#^https?://(?:www\.)?creativecommons\.org/(?:licenses|publicdomain)/#i', trim($href))) {
        // A badge for a licence that isn't open (NC, ND, ...): the page's
        // terms are mixed, so no licence is recorded.
        return NULL;
      }
    }
    return count($found) === 1 ? reset($found) : NULL;
  }

  /**
   * The licence one address's site states, for the S3 form (22-24).
   *
   * Fetches the address through SafeFetcher (one page fetch per host, plus
   * one siteinfo call for a MediaWiki) and caches the answer per host for
   * the life of this object.
   *
   * @return \Drupal\localnodes_import\Fetch\DetectedLicense|null
   *   The licence, or NULL on any fetch failure or when none is stated.
   */
  public function licenseFor(string $url): ?DetectedLicense {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
      return NULL;
    }
    return $this->site($url, $host)['license'];
  }

  /**
   * What the S3 form shows for one address (22-24, D-25a/c).
   *
   * The licence its site states and the site's name come from the same one
   * fetch per host as licenseFor() (cached per host). The address is the
   * one the page will be credited with: for a MediaWiki, the canonical
   * article address from its siteinfo (no parse call); otherwise
   * canonicalUrl(). The name is the wiki's sitename, the page's og:site_name
   * or, failing both (or when the site doesn't answer), its host.
   *
   * @return array{license: ?\Drupal\localnodes_import\Fetch\DetectedLicense, site_name: string, source_url: string, blocked: bool, unread: bool}
   *   The licence (NULL when none is stated or the fetch failed), the site
   *   name as plain text, the address to credit, whether the site
   *   answered with a bot check (every import from it would fail the same
   *   way, 22-30 Q5), and whether it couldn't be read at all for another
   *   reason (no answer, not found…), so nothing is known about its terms
   *   (22-30, Codex CX-16).
   */
  public function siteFor(string $url): array {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
      return ['license' => NULL, 'site_name' => '', 'source_url' => $url, 'blocked' => FALSE, 'unread' => TRUE];
    }
    $site = $this->site($url, $host);
    $source_url = self::canonicalUrl($url);
    if ($site['api'] !== NULL && is_array($site['wiki'])) {
      $title = $this->mediaWiki->titleFromUrl($site['wiki'], $url);
      if ($title !== NULL) {
        $source_url = $this->mediaWiki->canonicalUrl($site['api'], $site['wiki'], $title);
      }
    }
    return ['license' => $site['license'], 'site_name' => $site['site_name'], 'source_url' => $source_url, 'blocked' => $site['blocked'], 'unread' => $site['unread']];
  }

  /**
   * One host's licence, name and wiki details, fetched once per host.
   *
   * @return array{license: ?\Drupal\localnodes_import\Fetch\DetectedLicense, site_name: string, api: ?string, wiki: ?array, blocked: bool, unread: bool}
   *   The cached answer for the host.
   */
  private function site(string $url, string $host): array {
    if (isset($this->sites[$host])) {
      return $this->sites[$host];
    }
    $answer = ['license' => NULL, 'site_name' => $host, 'api' => NULL, 'wiki' => NULL, 'blocked' => FALSE, 'unread' => FALSE];
    $result = $this->fetcher->get($url, 'html');
    if (!$result->ok) {
      $answer['blocked'] = $result->reason === FetchResult::BOT_CHALLENGE;
      $answer['unread'] = !$answer['blocked'];
      return $this->sites[$host] = $answer;
    }
    $body = (string) $result->body;
    $api = $this->mediaWiki->detect($body, (string) ($result->finalUrl ?? $url));
    if ($api !== NULL) {
      $wiki = $this->mediaWiki->siteInfo($api);
      if (is_array($wiki)) {
        return $this->sites[$host] = [
          'license' => $wiki['license'],
          'site_name' => $wiki['sitename'],
          'api' => $api,
          'wiki' => $wiki,
          'blocked' => FALSE,
          'unread' => FALSE,
        ];
      }
    }
    $answer['license'] = $this->licenseIn($body);
    $document = HtmlSanitizer::loadDocument($body);
    $answer['site_name'] = mb_substr(self::metaContent($document, 'og:site_name') ?? $host, 0, 255);
    return $this->sites[$host] = $answer;
  }

  /**
   * An ordinary page's canonical address.
   *
   * Scheme and host lower-cased, the default port and the fragment dropped,
   * an empty path made "/". The query stays: it can name the page.
   */
  public static function canonicalUrl(string $url): string {
    $parts = parse_url(trim($url));
    if ($parts === FALSE || !isset($parts['scheme'], $parts['host'])) {
      return $url;
    }
    $scheme = strtolower($parts['scheme']);
    $port = $parts['port'] ?? NULL;
    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
      $port = NULL;
    }
    return $scheme . '://' . strtolower($parts['host'])
      . ($port !== NULL ? ':' . $port : '')
      . (($parts['path'] ?? '') !== '' ? $parts['path'] : '/')
      . (isset($parts['query']) ? '?' . $parts['query'] : '');
  }

  /**
   * A meta property's (or name's) content as plain text, or NULL.
   */
  private static function metaContent(\DOMDocument $document, string $property): ?string {
    foreach ($document->getElementsByTagName('meta') as $meta) {
      if (strtolower($meta->getAttribute('property')) === $property || strtolower($meta->getAttribute('name')) === $property) {
        return self::plainText($meta->getAttribute('content'));
      }
    }
    return NULL;
  }

  /**
   * The text of the page's only h1, or NULL when there are none or several.
   */
  private static function onlyHeading(\DOMDocument $document): ?string {
    $headings = $document->getElementsByTagName('h1');
    return $headings->length === 1 ? self::plainText((string) $headings->item(0)?->textContent) : NULL;
  }

  /**
   * Text with tags removed and whitespace collapsed, or NULL when empty.
   */
  private static function plainText(string $text): ?string {
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $text === '' ? NULL : $text;
  }

}
