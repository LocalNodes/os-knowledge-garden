<?php

/**
 * @file
 * Reverse proxies whose X-Forwarded-* headers Drupal trusts.
 *
 * The request chain on Coolify is client -> Cloudflare -> Traefik -> Drupal.
 * Drupal must trust both hops so X-Forwarded-For resolves to the real
 * client: Traefik and the Docker networks (private ranges), and Cloudflare's
 * edge. Anything else, such as a direct hit on the VPS IP with a forged
 * header, still shows the sender's own address. Trusting every address
 * (0.0.0.0/0) would let any client pick its IP and walk past per-IP flood
 * control (core login, siwe_login TM-24).
 *
 * Override per instance with DRUPAL_TRUSTED_PROXIES: a comma- or
 * space-separated list of IPs or CIDR ranges that replaces this default.
 *
 * Returns the list of trusted proxy addresses.
 */

// Private ranges: Traefik and the Docker networks it reaches Drupal on.
$private = [
  '10.0.0.0/8',
  '172.16.0.0/12',
  '192.168.0.0/16',
  '127.0.0.1',
  '::1',
  'fc00::/7',
];

// Cloudflare edge ranges, from https://www.cloudflare.com/ips-v4 and
// https://www.cloudflare.com/ips-v6, fetched 2026-11-05. Re-check when
// Cloudflare announces changes.
$cloudflare = [
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

$override = getenv('DRUPAL_TRUSTED_PROXIES');
if (is_string($override) && trim($override) !== '') {
  return array_values(array_filter(preg_split('/[\s,]+/', trim($override))));
}

return array_merge($private, $cloudflare);
