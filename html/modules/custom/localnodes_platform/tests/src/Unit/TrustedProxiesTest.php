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

  private const HEADERS = Request::HEADER_X_FORWARDED_FOR
    | Request::HEADER_X_FORWARDED_HOST
    | Request::HEADER_X_FORWARDED_PORT
    | Request::HEADER_X_FORWARDED_PROTO;

  // Traefik on a Coolify Docker network.
  private const TRAEFIK = '10.0.1.5';

  // A Cloudflare edge address (inside 172.64.0.0/13).
  private const CF_EDGE = '172.70.34.12';

  private const CLIENT = '203.0.113.7';

  private const ATTACKER = '198.51.100.9';

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Request::setTrustedProxies([], -1);
    putenv('DRUPAL_TRUSTED_PROXIES');
    parent::tearDown();
  }

  /**
   * Loads the list the way settings.php does.
   */
  private function trustedProxies(): array {
    return require $this->root . '/sites/default/trusted-proxies.php';
  }

  /**
   * Builds a request arriving from $remote with an X-Forwarded-For chain.
   */
  private function clientIp(string $remote, string $xff, ?array $proxies = NULL): ?string {
    Request::setTrustedProxies($proxies ?? $this->trustedProxies(), self::HEADERS);
    $request = Request::create('https://cascadia.localnodes.xyz/siwe/nonce', 'GET', [], [], [], [
      'REMOTE_ADDR' => $remote,
      'HTTP_X_FORWARDED_FOR' => $xff,
    ]);
    return $request->getClientIp();
  }

  /**
   * Client -> Cloudflare -> Traefik -> Drupal yields the client.
   */
  public function testRealChainYieldsClient(): void {
    $this->assertSame(self::CLIENT, $this->clientIp(self::TRAEFIK, self::CLIENT . ', ' . self::CF_EDGE));
  }

  /**
   * A forged entry before the real hops is ignored.
   */
  public function testForgedHeaderThroughCloudflareYieldsSender(): void {
    $xff = '1.2.3.4, ' . self::ATTACKER . ', ' . self::CF_EDGE;
    $this->assertSame(self::ATTACKER, $this->clientIp(self::TRAEFIK, $xff));
  }

  /**
   * A direct hit on the VPS (no Cloudflare) with a forged header shows the
   * sender: Traefik appends the attacker's own address, which isn't trusted.
   */
  public function testForgedHeaderDirectToVpsYieldsSender(): void {
    $this->assertSame(self::ATTACKER, $this->clientIp(self::TRAEFIK, '1.2.3.4, ' . self::ATTACKER));
  }

  /**
   * A request that doesn't come from a trusted proxy can't set the header.
   */
  public function testUntrustedRemoteIgnoresHeader(): void {
    $this->assertSame(self::ATTACKER, $this->clientIp(self::ATTACKER, '1.2.3.4'));
  }

  /**
   * The old 0.0.0.0/0 setting let any forged value through (regression guard).
   */
  public function testTrustAllWasSpoofable(): void {
    $this->assertSame('1.2.3.4', $this->clientIp(self::TRAEFIK, '1.2.3.4, ' . self::ATTACKER, ['0.0.0.0/0']));
    $this->assertNotContains('0.0.0.0/0', $this->trustedProxies());
  }

  /**
   * DRUPAL_TRUSTED_PROXIES replaces the default list.
   */
  public function testEnvOverrideReplacesDefault(): void {
    putenv('DRUPAL_TRUSTED_PROXIES=10.9.0.0/16, 192.0.2.1');
    $list = $this->trustedProxies();
    $this->assertSame(['10.9.0.0/16', '192.0.2.1'], $list);
    // Cloudflare is no longer trusted, so its edge becomes the client.
    $this->assertSame(self::CF_EDGE, $this->clientIp('10.9.0.4', self::CLIENT . ', ' . self::CF_EDGE, $list));
  }

}
