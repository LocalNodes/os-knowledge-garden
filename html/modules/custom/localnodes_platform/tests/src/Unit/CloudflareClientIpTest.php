<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\localnodes_platform\Proxy\CloudflareClientIp;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fallback for a Traefik that lost its Cloudflare trustedIPs setting.
 *
 * Traefik normally trusts Cloudflare (Tom's choice A). If that setting is
 * dropped, Traefik replaces X-Forwarded-For with the single hop it saw (the
 * Cloudflare edge, or a direct attacker). CloudflareClientIp uses
 * CF-Connecting-IP as the client only when all three hold:
 * - REMOTE_ADDR is in the app subnet (Traefik);
 * - the single X-Forwarded-For hop is inside Cloudflare's ranges;
 * - CF-Connecting-IP is a valid IP.
 * Otherwise the client is the X-Forwarded-For hop (Symfony's normal result).
 *
 * The middleware rewrites X-Forwarded-For to "<cf-connecting-ip>, <edge>"
 * before ReverseProxyMiddleware resolves the client, so the trusted list
 * (app subnet + Cloudflare) yields the visitor.
 *
 * @group localnodes_platform
 */
final class CloudflareClientIpTest extends UnitTestCase {

  private const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

  private const APP_SUBNETS = ['10.0.8.0/24'];
  private const TRAEFIK = '10.0.8.2';
  private const OTHER_APP = '10.0.9.7';
  private const CF_EDGE = '172.70.34.12';
  private const CF_EDGE_V6 = '2606:4700:3030::6815:1a2b';
  private const CLIENT = '203.0.113.7';
  private const CLIENT_V6 = '2001:db8:1::7';
  private const ATTACKER = '198.51.100.9';

  /**
   * Whether the last clientIp() call used the fallback.
   */
  private bool $rewrote = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Request::setTrustedProxies([], -1);
    parent::tearDown();
  }

  /**
   * Builds a request, applies the rewrite, and returns the client IP.
   */
  private function clientIp(string $remote, ?string $xff, ?string $cfConnectingIp): ?string {
    require_once $this->root . '/sites/default/trusted-proxies.php';
    Request::setTrustedProxies(localnodes_trusted_proxies('10.0.8.5', NULL), self::HEADERS);
    $server = ['REMOTE_ADDR' => $remote];
    if ($xff !== NULL) {
      $server['HTTP_X_FORWARDED_FOR'] = $xff;
    }
    if ($cfConnectingIp !== NULL) {
      $server['HTTP_CF_CONNECTING_IP'] = $cfConnectingIp;
    }
    $request = Request::create('https://cascadia.localnodes.xyz/siwe/nonce', 'GET', [], [], [], $server);
    $this->rewrote = CloudflareClientIp::apply($request, self::APP_SUBNETS, localnodes_cloudflare_ranges());
    return $request->getClientIp();
  }

  /**
   * Visitor via Cloudflare: Traefik's single hop is the edge.
   */
  public function testCloudflareVisitorYieldsConnectingIp(): void {
    $this->assertSame(self::CLIENT, $this->clientIp(self::TRAEFIK, self::CF_EDGE, self::CLIENT));
    $this->assertTrue($this->rewrote);
    $this->assertSame(self::CLIENT_V6, $this->clientIp(self::TRAEFIK, self::CF_EDGE_V6, self::CLIENT_V6));
  }

  /**
   * A direct hit with a forged CF-Connecting-IP is ignored.
   *
   * Traefik's hop is then the attacker, not a Cloudflare edge.
   */
  public function testDirectForgedConnectingIpIsIgnored(): void {
    $this->assertSame(self::ATTACKER, $this->clientIp(self::TRAEFIK, self::ATTACKER, '1.2.3.4'));
    $this->assertFalse($this->rewrote);
  }

  /**
   * A request not from Traefik (another app's container) can't use it.
   */
  public function testOtherAppCannotUseConnectingIp(): void {
    $this->assertSame(self::OTHER_APP, $this->clientIp(self::OTHER_APP, self::CF_EDGE, '1.2.3.4'));
  }

  /**
   * An invalid CF-Connecting-IP leaves the edge as the client.
   */
  public function testInvalidConnectingIpFallsBackToHop(): void {
    foreach (['', 'not-an-ip', '1.2.3.4, 5.6.7.8', '999.1.1.1'] as $bad) {
      $this->assertSame(self::CF_EDGE, $this->clientIp(self::TRAEFIK, self::CF_EDGE, $bad), var_export($bad, TRUE));
    }
  }

  /**
   * Without CF-Connecting-IP the client is the X-Forwarded-For hop.
   */
  public function testMissingConnectingIpYieldsHop(): void {
    $this->assertSame(self::CF_EDGE, $this->clientIp(self::TRAEFIK, self::CF_EDGE, NULL));
  }

  /**
   * A two-hop X-Forwarded-For is not rewritten.
   *
   * Traefik kept Cloudflare's header (it trusts Cloudflare), so the
   * fallback isn't needed.
   */
  public function testMultiHopHeaderIsNotRewritten(): void {
    $this->assertSame(self::CLIENT, $this->clientIp(self::TRAEFIK, self::CLIENT . ', ' . self::CF_EDGE, '1.2.3.4'));
    $this->assertFalse($this->rewrote, 'Two-hop chain (Traefik trusts Cloudflare) leaves the fallback unused');
  }

  /**
   * No X-Forwarded-For at all: the client is Traefik's address.
   */
  public function testMissingForwardedForYieldsRemote(): void {
    $this->assertSame(self::TRAEFIK, $this->clientIp(self::TRAEFIK, NULL, self::CLIENT));
  }

}
