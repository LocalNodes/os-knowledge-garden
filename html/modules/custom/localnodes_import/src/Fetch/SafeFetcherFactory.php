<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Fetch;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the site's SafeFetcher with its honest User-Agent (D-13).
 *
 * The User-Agent names the importer, the site it runs on and the site's
 * contact address, so a site owner can see who is fetching and write to
 * them: "LocalNodes-Importer/1.0 (+https://‹host›; ‹site mail›)". It never
 * pretends to be a browser.
 *
 * The container builds one fetcher per request, so a batch request's
 * operations share its per-host interval, challenge memory and DNS pins
 * (22-15).
 */
final class SafeFetcherFactory {

  /**
   * The service factory: localnodes_import.safe_fetcher.
   */
  public static function create(RequestStack $request_stack, ConfigFactoryInterface $config_factory): SafeFetcher {
    $request = $request_stack->getCurrentRequest();
    $host = $request?->getHost() ?? '';
    if ($host === '' || $host === 'default') {
      $host = (string) parse_url((string) ($GLOBALS['base_url'] ?? ''), PHP_URL_HOST);
    }
    $mail = (string) $config_factory->get('system.site')->get('mail');
    return new SafeFetcher(NULL, self::userAgent($host, $mail));
  }

  /**
   * The User-Agent for a site host and contact address.
   *
   * Only host-name characters and a valid e-mail address get in, so nothing
   * from the request or config can break the header.
   */
  public static function userAgent(string $host, string $mail): string {
    $host = strtolower((string) preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host));
    if ($host === '') {
      $host = 'localhost';
    }
    $contact = filter_var($mail, FILTER_VALIDATE_EMAIL) !== FALSE && !preg_match('/[\s;()]/', $mail) ? '; ' . $mail : '';
    return 'LocalNodes-Importer/1.0 (+https://' . $host . $contact . ')';
  }

}
