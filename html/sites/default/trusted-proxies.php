<?php

/**
 * @file
 * Reverse proxies whose X-Forwarded-For/-Proto headers Drupal trusts.
 *
 * The request chain on Coolify is client -> Cloudflare -> Traefik -> Drupal.
 * Traefik trusts Cloudflare (forwardedHeaders.trustedIPs on its http and
 * https entrypoints), so it keeps Cloudflare's X-Forwarded-For and appends
 * the edge address. Drupal then trusts both hops to resolve the client:
 *
 * - Traefik: coolify-proxy joins each app's own Docker network, so the
 *   request arrives from Traefik's address on this container's own /24
 *   (Cascadia: 10.0.8.2). That network holds only this app's services and
 *   the proxy, so it's derived from the container's own IPv4 at runtime.
 *   The shared coolify network and other apps' networks are not trusted:
 *   the VPS is shared, and a trusted neighbour could forge the header.
 * - Cloudflare's published edge ranges.
 *
 * DRUPAL_TRUSTED_PROXIES (comma- or space-separated IPs or CIDR ranges)
 * replaces the derived subnet; Cloudflare's ranges are always kept. With
 * neither, Traefik is untrusted and every visitor shows as its address:
 * safe, but per-IP flood limits then apply site-wide.
 *
 * Trusting every address (0.0.0.0/0) would let any client pick its IP and
 * walk past per-IP flood control (core login, siwe_login TM-24).
 *
 * If a proxy reset drops Traefik's trustedIPs, Traefik sends only the edge
 * address; localnodes_platform's CloudflareClientIpMiddleware then falls
 * back to CF-Connecting-IP and flags it on the status report.
 *
 * Returns the list of trusted proxy addresses.
 */

if (!function_exists('localnodes_cloudflare_ranges')) {

  /**
   * Cloudflare's published edge ranges.
   *
   * From https://www.cloudflare.com/ips-v4 and
   * https://www.cloudflare.com/ips-v6, fetched 2026-11-05. Re-check when
   * Cloudflare announces changes. Also read by localnodes_platform's
   * CF-Connecting-IP fallback.
   *
   * @return string[]
   *   CIDR ranges.
   */
  function localnodes_cloudflare_ranges(): array {
    return [
      '173.245.48.0/20',
      '103.21.244.0/22',
      '103.22.200.0/22',
      '103.31.4.0/22',
      '141.101.64.0/18',
      '108.162.192.0/18',
      '190.93.240.0/20',
      '188.114.96.0/20',
      '197.234.240.0/22',
      '198.41.128.0/17',
      '162.158.0.0/15',
      '104.16.0.0/13',
      '104.24.0.0/14',
      '172.64.0.0/13',
      '131.0.72.0/22',
      '2400:cb00::/32',
      '2606:4700::/32',
      '2803:f800::/32',
      '2405:b500::/32',
      '2405:8100::/32',
      '2a06:98c0::/29',
      '2c0f:f248::/32',
    ];
  }

}

if (!function_exists('localnodes_trusted_proxies')) {

  /**
   * Builds the trusted proxy list.
   *
   * @param string|null $container_ip
   *   This container's own address; only a private IPv4 counts.
   * @param string|null $override
   *   DRUPAL_TRUSTED_PROXIES, replacing the derived subnet when not empty.
   *
   * @return string[]
   *   IPs and CIDR ranges for Request::setTrustedProxies().
   */
  function localnodes_trusted_proxies(?string $container_ip, ?string $override): array {
    $local = [];
    if ($override !== NULL && trim($override) !== '') {
      $local = array_values(array_filter(preg_split('/[\s,]+/', trim($override))));
    }
    elseif ($container_ip !== NULL
      && filter_var($container_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
      // Private, not loopback: a real Docker network address.
      && !filter_var($container_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)
      && !str_starts_with($container_ip, '127.')) {
      $octets = explode('.', $container_ip);
      $local[] = "{$octets[0]}.{$octets[1]}.{$octets[2]}.0/24";
    }

    return array_values(array_unique(array_merge($local, localnodes_cloudflare_ranges())));
  }

}

$localnodes_env = getenv('DRUPAL_TRUSTED_PROXIES');
$localnodes_host = gethostname();
$localnodes_ip = $localnodes_host === FALSE ? NULL : gethostbyname($localnodes_host);

return localnodes_trusted_proxies(
  // gethostbyname() returns its input when it can't resolve.
  $localnodes_ip === $localnodes_host ? NULL : $localnodes_ip,
  $localnodes_env === FALSE ? NULL : $localnodes_env,
);
