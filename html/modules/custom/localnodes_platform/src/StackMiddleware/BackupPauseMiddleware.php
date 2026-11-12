<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\StackMiddleware;

use Drupal\Core\Site\Settings;
use Drupal\localnodes_platform\Backup\BackupPause;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Closes the whole site while a backup or restore holds the pause file.
 *
 * A nightly copy must match itself: the database, then the files, are read
 * one after the other, so nothing may write in between. Maintenance mode is
 * not enough, because administrators can still write through it. So every
 * request gets a plain 503 while the pause is in force, signed in or not.
 *
 * It sits after the reverse-proxy middleware (priority 300), so the client
 * address is the real one, and before page cache (200) and session (50), so
 * no cached page is served and no session starts during the pause.
 *
 * One request is let through, without reaching Drupal: GET /user/login from
 * the container itself, the healthcheck. It gets a bare 200 so the container
 * is not restarted in the middle of a backup.
 */
final class BackupPauseMiddleware implements HttpKernelInterface {

  /**
   * Seconds a client is asked to wait before trying again.
   */
  public const RETRY_AFTER = BackupPause::RETRY_AFTER;

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly Settings $settings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    $private = (string) $this->settings->get('file_private_path', '');
    $pause = $private === '' ? NULL : (new BackupPause($private))->active();
    if ($pause === NULL) {
      return $this->httpKernel->handle($request, $type, $catch);
    }
    if ($request->isMethod('GET') && $request->getPathInfo() === '/user/login' && IpUtils::checkIp((string) $request->getClientIp(), ['127.0.0.0/8', '::1'])) {
      return new Response('paused', 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
    return new Response(BackupPause::page($pause['reason']), 503, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Retry-After' => (string) self::RETRY_AFTER,
      'Cache-Control' => 'no-store',
    ]);
  }

}
