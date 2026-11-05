<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The trusted proxy list resolves the real client behind Cloudflare+Traefik.
 *
 * Drupal's ReverseProxyMiddleware calls Request::setTrustedProxies() with
 * $settings['reverse_proxy_addresses'] and the trusted headers, so this test
 * does the same with the list from sites/default/trusted-proxies.php.
 *
 * @group localnodes_platform
 */
final class TrustedProxiesTest extends UnitTestCase {

  private const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

  // The instance's Traefik network and Traefik's address on it.
  private const TRAEFIK_NET = '10.0.1.0/24';
  private const TRAEFIK = '10.0.1.5';

  // A neighbouring container on the same VPS, on another Docker network.
  private const NEIGHBOUR = '172.18.0.9';

  // Cloudflare edge addresses (inside 172.64.0.0/13 and 2606:4700::/32).
  private const CF_EDGE = '172.70.34.12';
  private const CF_EDGE_V6 = '2606:4700:3030::6815:1a2b';

  private const CLIENT = '203.0.113.7';
  private const CLIENT_V6 = '2001:db8:1::7';
  private const ATTACKER = '198.51.100.9';

  /**
   * The DRUPAL_TRUSTED_PROXIES value before the test, restored afterwards.
   */
  private string|false $savedEnv;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->savedEnv = getenv('DRUPAL_TRUSTED_PROXIES');
    putenv('DRUPAL_TRUSTED_PROXIES=' . self::TRAEFIK_NET);
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
   * Loads the list the way settings.php does.
   */
  private function trustedProxies(): array {
    return require $this->root . '/sites/default/trusted-proxies.php';
  }

  /**
   * The client IP Drupal sees for a request from $remote with $xff.
   */
  private function clientIp(string $remote, ?string $xff): ?string {
    Request::setTrustedProxies($this->trustedProxies(), self::HEADERS);
    $server = ['REMOTE_ADDR' => $remote];
    if ($xff !== NULL) {
      $server['HTTP_X_FORWARDED_FOR'] = $xff;
    }
    return Request::create('https://cascadia.localnodes.xyz/siwe/nonce', 'GET', [], [], [], $server)->getClientIp();
  }

  /**
   * Client -> Cloudflare -> Traefik -> Drupal yields the client.
   */
  public function testRealChainYieldsClient(): void {
    $this->assertSame(self::CLIENT, $this->clientIp(self::TRAEFIK, self::CLIENT . ', ' . self::CF_EDGE));
  }

  /**
   * The same over IPv6 (client and Cloudflare edge).
   */
  public function testRealChainIpv6YieldsClient(): void {
    $this->assertSame(self::CLIENT_V6, $this->clientIp(self::TRAEFIK, self::CLIENT_V6 . ', ' . self::CF_EDGE_V6));
  }

  /**
   * A forged entry before the real hops is ignored.
   */
  public function testForgedHeaderThroughCloudflareYieldsSender(): void {
    $this->assertSame(self::ATTACKER, $this->clientIp(self::TRAEFIK, '1.2.3.4, ' . self::ATTACKER . ', ' . self::CF_EDGE));
  }

  /**
   * A direct hit on the VPS (no Cloudflare) with a forged header shows the
   * sender: Traefik appends the attacker's own address, which isn't trusted.
   */
  public function testForgedHeaderDirectToVpsYieldsSender(): void {
    $this->assertSame(self::ATTACKER, $this->clientIp(self::TRAEFIK, '1.2.3.4, ' . self::ATTACKER));
  }

  /**
   * A neighbouring container on another private network can't forge it.
   */
  public function testNeighbourContainerCannotForge(): void {
    $this->assertSame(self::NEIGHBOUR, $this->clientIp(self::NEIGHBOUR, '1.2.3.4'));
    $this->assertNotContains('172.16.0.0/12', $this->trustedProxies());
    $this->assertNotContains('10.0.0.0/8', $this->trustedProxies());
    $this->assertNotContains('192.168.0.0/16', $this->trustedProxies());
  }

  /**
   * A remote outside every trusted range can't set the header.
   */
  public function testUntrustedRemoteIgnoresHeader(): void {
    $this->assertSame(self::ATTACKER, $this->clientIp(self::ATTACKER, '1.2.3.4'));
  }

  /**
   * No X-Forwarded-For: the client is the connecting address.
   */
  public function testMissingHeaderYieldsRemote(): void {
    $this->assertSame(self::TRAEFIK, $this->clientIp(self::TRAEFIK, NULL));
    $this->assertSame(self::ATTACKER, $this->clientIp(self::ATTACKER, NULL));
  }

  /**
   * The list never trusts everything (regression guard for 0.0.0.0/0).
   */
  public function testNeverTrustsEverything(): void {
    $list = $this->trustedProxies();
    $this->assertNotContains('0.0.0.0/0', $list);
    $this->assertNotContains('::/0', $list);
  }

  /**
   * DRUPAL_TRUSTED_PROXIES is added to Cloudflare's ranges, not a
   * replacement, so an instance can't drop Cloudflare by accident.
   */
  public function testInstanceNetworkIsAddedToCloudflare(): void {
    putenv('DRUPAL_TRUSTED_PROXIES=10.9.0.0/16, 192.0.2.1');
    $list = $this->trustedProxies();
    $this->assertContains('10.9.0.0/16', $list);
    $this->assertContains('192.0.2.1', $list);
    $this->assertContains('172.64.0.0/13', $list);
    $this->assertContains('2606:4700::/32', $list);
  }

  /**
   * Without the instance network, Traefik is untrusted: every visitor shows
   * as Traefik's address (safe, not spoofable).
   */
  public function testWithoutInstanceNetworkTraefikIsUntrusted(): void {
    putenv('DRUPAL_TRUSTED_PROXIES');
    $this->assertSame(self::TRAEFIK, $this->clientIp(self::TRAEFIK, self::CLIENT . ', ' . self::CF_EDGE));
  }

}
