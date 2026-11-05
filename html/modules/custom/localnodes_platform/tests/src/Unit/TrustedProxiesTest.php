<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Trusted proxies on Coolify: client -> Cloudflare -> Traefik -> Drupal.
 *
 * Server facts (Tom's read-only check, 2026-11-05): coolify-proxy joins
 * each app's own Docker network, so REMOTE_ADDR is Traefik's address on
 * the app's /24 (Cascadia: 10.0.8.2). The app network holds only the app's
 * own services and the proxy, so trusting that /24 is safe; other apps'
 * networks (10.0.9.x, 10.0.42.x, …) are not trusted.
 *
 * trusted-proxies.php defines localnodes_trusted_proxies($containerIp,
 * $override): the container's own IPv4 /24 (or DRUPAL_TRUSTED_PROXIES) plus
 * Cloudflare's ranges. Drupal's ReverseProxyMiddleware calls
 * Request::setTrustedProxies() with that list, as this test does.
 *
 * Traefik trusts Cloudflare (forwardedHeaders.trustedIPs, Tom's choice A on
 * 2026-11-05), so it keeps Cloudflare's X-Forwarded-For and appends the
 * edge, and the list alone resolves the client.
 *
 * @group localnodes_platform
 */
final class TrustedProxiesTest extends UnitTestCase {

  private const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

  // This app's container and Traefik on the app's own network.
  private const APP_IP = '10.0.8.5';
  private const TRAEFIK = '10.0.8.2';

  // Another app's container on its own network (same VPS).
  private const OTHER_APP = '10.0.9.7';

  private const CF_EDGE = '172.70.34.12';
  private const CF_EDGE_V6 = '2606:4700:3030::6815:1a2b';
  private const CLIENT = '203.0.113.7';
  private const CLIENT_V6 = '2001:db8:1::7';
  private const ATTACKER = '198.51.100.9';

  /**
   * DRUPAL_TRUSTED_PROXIES before the test, restored afterwards.
   */
  private string|false $savedEnv;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->savedEnv = getenv('DRUPAL_TRUSTED_PROXIES');
    putenv('DRUPAL_TRUSTED_PROXIES');
    require_once $this->root . '/sites/default/trusted-proxies.php';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Request::setTrustedProxies([], -1);
    putenv($this->savedEnv === FALSE ? 'DRUPAL_TRUSTED_PROXIES' : 'DRUPAL_TRUSTED_PROXIES=' . $this->savedEnv);
    parent::tearDown();
  }

  /**
   * The client IP Drupal sees for a request from $remote.
   */
  private function clientIp(array $proxies, string $remote, ?string $xff): ?string {
    Request::setTrustedProxies($proxies, self::HEADERS);
    $server = ['REMOTE_ADDR' => $remote];
    if ($xff !== NULL) {
      $server['HTTP_X_FORWARDED_FOR'] = $xff;
    }
    return Request::create('https://cascadia.localnodes.xyz/siwe/nonce', 'GET', [], [], [], $server)->getClientIp();
  }

  /**
   * The app subnet is the container's own IPv4 /24.
   */
  public function testAppSubnetFromContainerIp(): void {
    $list = localnodes_trusted_proxies(self::APP_IP, NULL);
    $this->assertContains('10.0.8.0/24', $list);
    $this->assertNotContains('10.0.0.0/8', $list);
    $this->assertNotContains('10.0.1.0/24', $list, 'The shared coolify network is not trusted');
  }

  /**
   * No usable container IPv4 means Cloudflare only.
   *
   * None, loopback, IPv6 or garbage: Traefik is then untrusted (safe; per-IP
   * limits go site-wide).
   */
  public function testNoContainerIpTrustsCloudflareOnly(): void {
    foreach ([NULL, '127.0.0.1', '::1', 'fe80::1', 'not-an-ip', ''] as $ip) {
      $list = localnodes_trusted_proxies($ip, NULL);
      $this->assertContains('172.64.0.0/13', $list);
      $this->assertEmpty(array_filter($list, fn ($r) => str_starts_with($r, '10.') || str_starts_with($r, '127.')), var_export($ip, TRUE));
    }
  }

  /**
   * DRUPAL_TRUSTED_PROXIES replaces the derived subnet; Cloudflare stays.
   */
  public function testOverrideReplacesDerivedSubnet(): void {
    $list = localnodes_trusted_proxies(self::APP_IP, '10.0.42.0/24, 192.0.2.1');
    $this->assertContains('10.0.42.0/24', $list);
    $this->assertContains('192.0.2.1', $list);
    $this->assertNotContains('10.0.8.0/24', $list);
    $this->assertContains('2606:4700::/32', $list);
  }

  /**
   * The file's return value reads the environment like settings.php does.
   */
  public function testFileReturnsListFromEnvironment(): void {
    putenv('DRUPAL_TRUSTED_PROXIES=10.0.8.0/24');
    $list = require $this->root . '/sites/default/trusted-proxies.php';
    $this->assertContains('10.0.8.0/24', $list);
    $this->assertContains('172.64.0.0/13', $list);
  }

  /**
   * Never trusts everything, nor whole private ranges.
   */
  public function testNeverTrustsBroadRanges(): void {
    $list = localnodes_trusted_proxies(self::APP_IP, NULL);
    foreach (['0.0.0.0/0', '::/0', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $broad) {
      $this->assertNotContains($broad, $list);
    }
  }

  /**
   * Client -> CF -> Traefik (keeps CF's XFF) yields the client.
   */
  public function testChainThroughCloudflareYieldsClient(): void {
    $list = localnodes_trusted_proxies(self::APP_IP, NULL);
    $this->assertSame(self::CLIENT, $this->clientIp($list, self::TRAEFIK, self::CLIENT . ', ' . self::CF_EDGE));
    $this->assertSame(self::CLIENT_V6, $this->clientIp($list, self::TRAEFIK, self::CLIENT_V6 . ', ' . self::CF_EDGE_V6));
  }

  /**
   * Forged entries via CF or direct to the VPS show the sender.
   */
  public function testForgedHeaderYieldsSender(): void {
    $list = localnodes_trusted_proxies(self::APP_IP, NULL);
    $this->assertSame(self::ATTACKER, $this->clientIp($list, self::TRAEFIK, '1.2.3.4, ' . self::ATTACKER . ', ' . self::CF_EDGE));
    $this->assertSame(self::ATTACKER, $this->clientIp($list, self::TRAEFIK, '1.2.3.4, ' . self::ATTACKER));
  }

  /**
   * Another app's container (different /24) can't forge X-Forwarded-For.
   */
  public function testOtherAppNetworkCannotForge(): void {
    $list = localnodes_trusted_proxies(self::APP_IP, NULL);
    $this->assertSame(self::OTHER_APP, $this->clientIp($list, self::OTHER_APP, '1.2.3.4'));
  }

  /**
   * No X-Forwarded-For: the client is the connecting address.
   */
  public function testMissingHeaderYieldsRemote(): void {
    $list = localnodes_trusted_proxies(self::APP_IP, NULL);
    $this->assertSame(self::TRAEFIK, $this->clientIp($list, self::TRAEFIK, NULL));
  }

}
