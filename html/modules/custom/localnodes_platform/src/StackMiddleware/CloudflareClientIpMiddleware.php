<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\StackMiddleware;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\localnodes_platform\Proxy\CloudflareClientIp;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Applies the CF-Connecting-IP fallback before the client IP is resolved.
 *
 * Runs ahead of core's ReverseProxyMiddleware. When the fallback fires, it
 * records the time in state (at most once an hour, not per request) so
 * localnodes_platform_requirements() can warn that Traefik has lost its
 * Cloudflare trustedIPs setting.
 */
final class CloudflareClientIpMiddleware implements HttpKernelInterface {

  /**
   * State key holding the last time the fallback was used.
   */
  public const STATE_KEY = 'localnodes_platform.cf_connecting_ip_fallback';

  /**
   * Minimum seconds between state writes.
   */
  private const WRITE_INTERVAL = 3600;

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly Settings $settings,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    if ($type === self::MAIN_REQUEST) {
      $this->applyFallback($request);
    }
    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Rewrites the request when the fallback applies, and records it.
   */
  public function applyFallback(Request $request): bool {
    if (!$this->settings->get('reverse_proxy', FALSE) || !function_exists('localnodes_cloudflare_ranges')) {
      return FALSE;
    }
    $cloudflare = localnodes_cloudflare_ranges();
    $app_subnets = array_values(array_diff((array) $this->settings->get('reverse_proxy_addresses', []), $cloudflare));

    if (!CloudflareClientIp::apply($request, $app_subnets, $cloudflare)) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $last = (int) $this->state->get(self::STATE_KEY, 0);
    if ($now - $last >= self::WRITE_INTERVAL) {
      $this->state->set(self::STATE_KEY, $now);
    }
    return TRUE;
  }

}
