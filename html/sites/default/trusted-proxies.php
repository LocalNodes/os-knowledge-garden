<?php

/**
 * @file
 * Reverse proxies whose X-Forwarded-For/-Proto headers Drupal trusts.
 *
 * The request chain on Coolify is client -> Cloudflare -> Traefik -> Drupal.
 * For X-Forwarded-For to resolve to the real client, Drupal must trust both
 * hops: Cloudflare's edge, and the Traefik container's own network.
 *
 * Only that exact Traefik network is trusted, never whole private ranges:
 * the VPS is shared with other apps and containers, and any neighbour that
 * is trusted could forge X-Forwarded-For. Set the network per instance with
 * DRUPAL_TRUSTED_PROXIES: a comma- or space-separated list of IPs or CIDR
 * ranges (normally the Docker network Traefik reaches this app on). It is
 * ADDED to Cloudflare's ranges, so an instance can't drop Cloudflare by
 * accident. Without it, Traefik is untrusted and every visitor appears as
 * Traefik's address (safe, but per-IP flood limits then apply site-wide).
 *
 * Trusting every address (0.0.0.0/0) would let any client pick its IP and
 * walk past per-IP flood control (core login, siwe_login TM-24).
 *
 * Returns the list of trusted proxy addresses.
 */

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

$instance = [];
$env = getenv('DRUPAL_TRUSTED_PROXIES');
if (is_string($env) && trim($env) !== '') {
  $instance = array_values(array_filter(preg_split('/[\s,]+/', trim($env))));
}

return array_values(array_unique(array_merge($instance, $cloudflare)));
