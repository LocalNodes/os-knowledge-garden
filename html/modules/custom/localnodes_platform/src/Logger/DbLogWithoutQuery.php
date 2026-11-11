<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Logger;

use Drupal\Core\Logger\RfcLoggerTrait;
use Psr\Log\LoggerInterface;

/**
 * Stores watchdog rows with the page path but no query string or fragment.
 *
 * Core adds the full request URL and Referer to every log row. Search and the
 * AI overview carry a member's words in the query string (?keys=, ?q=),
 * Open Social's search pages carry them in the path (/search/all/<words>),
 * and watchdog is readable by site managers and the host. The path still
 * shows which page the row came from; a search path keeps its first two
 * parts and loses the words, the same rule the image's Apache access log
 * applies (docker/apache-access-log.sh).
 *
 * Core's 403 and 404 rows also carry the request address in the message
 * placeholder @uri, path and query string both, so it gets the same cuts.
 */
final class DbLogWithoutQuery implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * A search page's path up to its kind: [/index.php][/language]/search/<kind>.
   *
   * Any case and repeated slashes, as Drupal routes those to the same page.
   * The words are whatever follows.
   */
  private const SEARCH_PATH = '#^((?:/+index\.php)?(?:/+[a-z]{2,3}(?:-[a-z0-9]+)?)?/+search/+[^/]+)/+[^/]#i';

  public function __construct(
    private readonly LoggerInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    foreach (['request_uri', 'referer', '@uri'] as $key) {
      if (isset($context[$key]) && is_string($context[$key])) {
        $context[$key] = self::withoutSearchWords(self::withoutQuery($context[$key]));
      }
    }
    $this->inner->log($level, $message, $context);
  }

  /**
   * Cuts a URL at its first "?" or "#".
   */
  public static function withoutQuery(string $url): string {
    return preg_replace('/[?#].*$/s', '', $url) ?? '';
  }

  /**
   * Replaces the words of a search page's path with "-".
   *
   * /search/all/<words> becomes /search/all/-, with or without a language
   * prefix, a scheme and host, encoded letters or an encoded slash. Any other
   * URL, and /search/all with nothing after it, is returned unchanged. Give
   * it a URL whose query string is already cut.
   */
  public static function withoutSearchWords(string $url): string {
    if (!preg_match('#^([a-z][a-z0-9+.\-]*://[^/]*)?(.*)$#is', $url, $parts)) {
      return $url;
    }
    // The raw path catches an encoded slash in the words; the decoded one
    // catches encoded letters in "search" or the language.
    foreach ([$parts[2], rawurldecode($parts[2])] as $path) {
      if (preg_match(self::SEARCH_PATH, $path, $match)) {
        return $parts[1] . $match[1] . '/-';
      }
    }
    return $url;
  }

}
