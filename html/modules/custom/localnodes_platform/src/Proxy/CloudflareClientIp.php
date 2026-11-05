<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Proxy;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fallback for a Traefik that doesn't trust Cloudflare.
 *
 * Normally Traefik trusts Cloudflare (forwardedHeaders.trustedIPs), keeps
 * Cloudflare's X-Forwarded-For and appends the edge: "client, edge". If a
 * Coolify upgrade or proxy reset drops that setting, Traefik replaces the
 * header with the single hop it saw (the edge), and every visitor would
 * appear as a Cloudflare address.
 *
 * Then, and only then, the client is taken from CF-Connecting-IP. The
 * header is rewritten to "<cf-connecting-ip>, <edge>" so Drupal's trusted
 * proxies (app subnet + Cloudflare) resolve the visitor. All of these must
 * hold:
 * - the request comes from the app subnet (Traefik on this app's network);
 * - X-Forwarded-For is exactly one hop, inside Cloudflare's ranges;
 * - CF-Connecting-IP is a single valid IP.
 * A direct hit on the VPS with a forged CF-Connecting-IP fails the second
 * test, because Traefik's hop is then the sender's own address.
 */
final class CloudflareClientIp {

  /**
   * Rewrites X-Forwarded-For from CF-Connecting-IP when the rules hold.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param string[] $app_subnets
   *   The trusted proxy ranges that are not Cloudflare's (Traefik's network).
   * @param string[] $cloudflare
   *   Cloudflare's edge ranges.
   *
   * @return bool
   *   TRUE when the header was rewritten (the fallback was used).
   */
  public static function apply(Request $request, array $app_subnets, array $cloudflare): bool {
    $remote = (string) $request->server->get('REMOTE_ADDR', '');
    if ($app_subnets === [] || !IpUtils::checkIp($remote, $app_subnets)) {
      return FALSE;
    }

    $xff = trim((string) $request->headers->get('X-Forwarded-For', ''));
    if ($xff === '' || str_contains($xff, ',')) {
      return FALSE;
    }
    if (!filter_var($xff, FILTER_VALIDATE_IP) || !IpUtils::checkIp($xff, $cloudflare)) {
      return FALSE;
    }

    $client = trim((string) $request->headers->get('CF-Connecting-IP', ''));
    if ($client === '' || !filter_var($client, FILTER_VALIDATE_IP)) {
      return FALSE;
    }

    $request->headers->set('X-Forwarded-For', $client . ', ' . $xff);
    $request->server->set('HTTP_X_FORWARDED_FOR', $client . ', ' . $xff);
    return TRUE;
  }

}
