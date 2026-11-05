<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\localnodes_platform\StackMiddleware\CloudflareClientIpMiddleware;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The fallback records its use in state, throttled, and only when it fires.
 *
 * @group localnodes_platform
 */
final class CloudflareClientIpMiddlewareTest extends UnitTestCase {

  private const NOW = 1793900000;

  /**
   * Values written to state.
   */
  private array $writes = [];

  /**
   * The value state returns for the flag.
   */
  private int $stored = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once $this->root . '/sites/default/trusted-proxies.php';
  }

  /**
   * Builds the middleware over a site with reverse proxy on.
   */
  private function middleware(bool $reverse_proxy = TRUE): CloudflareClientIpMiddleware {
    $settings = new Settings([
      'reverse_proxy' => $reverse_proxy,
      'reverse_proxy_addresses' => localnodes_trusted_proxies('10.0.8.5', NULL),
    ]);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn () => $this->stored);
    $state->method('set')->willReturnCallback(function ($key, $value) {
      $this->writes[] = [$key, $value];
    });
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    return new CloudflareClientIpMiddleware($this->createMock(HttpKernelInterface::class), $settings, $state, $time);
  }

  /**
   * A request from Traefik with the given X-Forwarded-For.
   */
  private function request(string $xff): Request {
    return Request::create('https://boulder.localnodes.xyz/', 'GET', [], [], [], [
      'REMOTE_ADDR' => '10.0.8.2',
      'HTTP_X_FORWARDED_FOR' => $xff,
      'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
    ]);
  }

  /**
   * Normal operation (two-hop chain): no rewrite, the flag stays unset.
   */
  public function testTwoHopChainLeavesFlagUnset(): void {
    $request = $this->request('203.0.113.7, 172.70.34.12');
    $this->assertFalse($this->middleware()->applyFallback($request));
    $this->assertSame([], $this->writes);
    $this->assertSame('203.0.113.7, 172.70.34.12', $request->headers->get('X-Forwarded-For'));
  }

  /**
   * Lost trustedIPs (single Cloudflare hop): rewrite and record the time.
   */
  public function testFallbackRecordsTime(): void {
    $request = $this->request('172.70.34.12');
    $this->assertTrue($this->middleware()->applyFallback($request));
    $this->assertSame([[CloudflareClientIpMiddleware::STATE_KEY, self::NOW]], $this->writes);
    $this->assertSame('203.0.113.7, 172.70.34.12', $request->headers->get('X-Forwarded-For'));
  }

  /**
   * A recent record isn't rewritten on every request.
   */
  public function testRecentRecordIsNotRewritten(): void {
    $this->stored = self::NOW - 60;
    $this->assertTrue($this->middleware()->applyFallback($this->request('172.70.34.12')));
    $this->assertSame([], $this->writes);
  }

  /**
   * With reverse proxy off the middleware does nothing.
   */
  public function testReverseProxyOffDoesNothing(): void {
    $this->assertFalse($this->middleware(FALSE)->applyFallback($this->request('172.70.34.12')));
    $this->assertSame([], $this->writes);
  }

}
