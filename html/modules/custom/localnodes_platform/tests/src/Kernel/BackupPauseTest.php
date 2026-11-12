<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_backup_pause_test\RequestPolicyForTests;
use Drupal\localnodes_platform\Backup\BackupPause;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the pause file is in force, the whole site answers 503.
 *
 * Requests go through the real HTTP kernel stack, page cache included, so
 * the test also proves where the middleware sits: before page cache and
 * before the session.
 *
 * @group localnodes_platform
 */
final class BackupPauseTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'page_cache',
    'localnodes_platform',
    'localnodes_backup_pause_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The private files folder for this test.
   */
  private string $private;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->private = $this->siteDirectory . '/private';
    mkdir($this->private, 0700, TRUE);
    $this->setSetting('file_private_path', $this->private);
    $this->installConfig(['system']);
    $this->config('system.performance')->set('cache.page.max_age', 300)->save();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Core never stores a page under the CLI; let it here, so the cached page
    // test has a cached page.
    if ($container->hasDefinition('page_cache_request_policy')) {
      $container->getDefinition('page_cache_request_policy')->setClass(RequestPolicyForTests::class);
    }
  }

  /**
   * Sends a request through the whole stack.
   */
  private function send(string $method, string $path, string $client = '198.51.100.7', array $cookies = []): Response {
    $request = Request::create($path, $method, [], $cookies, [], ['REMOTE_ADDR' => $client]);
    $kernel = $this->container->get('http_kernel');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    return $response;
  }

  /**
   * Writes a pause file directly.
   */
  private function pause(?int $until, string $reason = 'backup'): void {
    (new BackupPause($this->private))->create($reason, $until);
  }

  /**
   * Asserts the closed page.
   */
  private function assertClosed(Response $response): void {
    $this->assertSame(503, $response->getStatusCode());
    $this->assertSame('300', $response->headers->get('Retry-After'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertStringNotContainsString('live', (string) $response->getContent());
    $this->assertSame([], $response->headers->getCookies(), 'No cookie is set during a pause.');
  }

  /**
   * No pause: the page is served, and page cache stores it.
   */
  public function testOpenSiteIsServed(): void {
    $response = $this->send('GET', '/pause-probe');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('live', $response->getContent());
  }

  /**
   * A pause closes GET and POST, for visitors and for a signed-in session.
   */
  public function testPauseClosesEveryRequest(): void {
    $this->pause(time() + 600);
    $this->assertClosed($this->send('GET', '/pause-probe'));
    $this->assertClosed($this->send('POST', '/pause-probe'));
    // A session cookie (an administrator's, say) changes nothing: the pause
    // is checked before any session is read.
    $this->assertClosed($this->send('POST', '/pause-probe', '198.51.100.7', [$this->container->get('session_configuration')->getOptions(Request::create('/'))['name'] => 'some-session-id']));
  }

  /**
   * A page already in page cache is not served during a pause.
   */
  public function testCachedPageIsNotServed(): void {
    $this->send('GET', '/pause-probe');
    $second = $this->send('GET', '/pause-probe');
    $this->assertSame('HIT', $second->headers->get('X-Drupal-Cache'), 'The page is in page cache before the pause.');
    $this->pause(time() + 600);
    $this->assertClosed($this->send('GET', '/pause-probe'));
  }

  /**
   * The container's healthcheck gets a bare 200; no one else does.
   */
  public function testHealthcheckException(): void {
    $this->pause(time() + 600);
    $response = $this->send('GET', '/user/login', '127.0.0.1');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('paused', $response->getContent());
    $this->assertSame([], $response->headers->getCookies());
    $this->assertClosed($this->send('GET', '/user/login', '198.51.100.7'));
    $this->assertClosed($this->send('POST', '/user/login', '127.0.0.1'));
    $this->assertClosed($this->send('GET', '/pause-probe', '127.0.0.1'));
  }

  /**
   * A backup pause past its end is ignored; a restore pause has no end.
   */
  public function testExpiry(): void {
    $this->pause(time() - 1);
    $this->assertSame(200, $this->send('GET', '/pause-probe')->getStatusCode());
    (new BackupPause($this->private))->clear();
    $this->pause(NULL, 'restore');
    $response = $this->send('GET', '/pause-probe');
    $this->assertClosed($response);
    $this->assertStringContainsString('restored', (string) $response->getContent());
  }

  /**
   * A pause file that cannot be read closes the site.
   */
  public function testUnreadablePauseCloses(): void {
    mkdir($this->private . '/.localnodes', 0700, TRUE);
    file_put_contents($this->private . '/.localnodes/backup-pause', 'not json');
    $this->assertClosed($this->send('GET', '/pause-probe'));
  }

  /**
   * The answer settings.php gives before Drupal starts matches the middleware.
   */
  public function testWebAnswerBeforeDrupal(): void {
    $pause = new BackupPause($this->private);
    $get = fn(string $method, string $uri, string $ip) => $pause->webAnswer(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => $ip]);
    $this->assertNull($get('GET', '/', '198.51.100.7'), 'An open site is left to Drupal.');

    $this->pause(NULL, 'restore');
    [$status, $headers, $body] = $get('GET', '/core/install.php', '198.51.100.7');
    $this->assertSame(503, $status);
    $this->assertSame('300', $headers['Retry-After']);
    $this->assertSame('no-store', $headers['Cache-Control']);
    $this->assertStringContainsString('restored', $body);
    $this->assertSame(503, $get('GET', '/user/login', '198.51.100.7')[0]);
    $this->assertSame(503, $get('POST', '/user/login', '127.0.0.1')[0]);
    $this->assertSame(503, $get('GET', '/user/login/x', '127.0.0.1')[0]);
    $this->assertSame([200, 'paused'], [$get('GET', '/user/login?destination=/', '127.0.0.1')[0], $get('GET', '/user/login', '::1')[2]]);

    $pause->clear();
    $this->pause(time() - 1);
    $this->assertNull($get('GET', '/', '198.51.100.7'), 'An expired backup pause is ignored.');
    $pause->clear();
    file_put_contents($this->private . '/.localnodes/backup-pause', 'not json');
    $this->assertSame(503, $get('GET', '/', '198.51.100.7')[0], 'An unreadable pause closes the site.');
  }

  /**
   * A web request holds the shared request lock until it ends.
   *
   * settings.php is included inside a function, so the lock must outlive the
   * scope that took it; a backup's exclusive wait must see it until the
   * request is over.
   */
  public function testWebRequestHoldsLock(): void {
    $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/node/add', 'REMOTE_ADDR' => '198.51.100.7'];
    $enter = fn () => (new BackupPause($this->private))->enterWebRequest($server);
    $free = function (): bool {
      $handle = (new BackupPause($this->private))->openLock(BackupPause::REQUEST_LOCK);
      $got = flock($handle, LOCK_EX | LOCK_NB);
      fclose($handle);
      return $got;
    };
    try {
      $this->assertDirectoryDoesNotExist($this->private . '/.localnodes');
      $this->assertNull($enter(), 'Without the lock folder the request makes it and carries on.');
      $this->assertFalse($free(), 'The request made the lock folder and holds the lock.');
      BackupPause::leaveWebRequest();

      $this->assertNull($enter(), 'An open site is left to Drupal.');
      $this->assertFalse($free(), 'The request still holds the lock after the call returned.');
      BackupPause::leaveWebRequest();
      $this->assertTrue($free(), 'The lock is free once the request is over.');

      $this->pause(NULL, 'restore');
      $this->assertSame(503, $enter()[0]);
      $this->assertTrue($free(), 'A paused request holds no lock.');

      // A backup holding the lock for its wait: the request answers paused
      // at once instead of waiting.
      $backup = (new BackupPause($this->private))->openLock(BackupPause::REQUEST_LOCK);
      $this->assertTrue(flock($backup, LOCK_EX | LOCK_NB));
      $started = microtime(TRUE);
      $this->assertSame(503, $enter()[0]);
      $this->assertLessThan(1, microtime(TRUE) - $started);
      fclose($backup);
    }
    finally {
      BackupPause::leaveWebRequest();
    }
  }

  /**
   * No web request runs untracked: a read-only lock still holds, none is 503.
   *
   * A backup's wait opens the lock read-only when it must, so a request that
   * skipped the lock would let the wait report drained while it still writes.
   */
  public function testUntrackableRequestRefused(): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
      $this->markTestSkipped('File modes do not bind the root user.');
    }
    $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/node/add', 'REMOTE_ADDR' => '198.51.100.7'];
    $enter = fn () => (new BackupPause($this->private))->enterWebRequest($server);
    $dir = $this->private . '/.localnodes';
    $lock = $dir . '/' . BackupPause::REQUEST_LOCK;
    $waited = function (): bool {
      try {
        (new BackupPause($this->private))->waitForRequests(microtime(TRUE) + 0.3);
        return TRUE;
      }
      catch (\RuntimeException) {
        return FALSE;
      }
    };
    $log = ini_get('error_log');
    $errors = tempnam(sys_get_temp_dir(), 'lnlog');
    ini_set('error_log', $errors);
    try {
      mkdir($dir, 0700);
      touch($lock);
      chmod($lock, 0444);
      $this->assertNull($enter(), 'A read-only lock file still lets the request run.');
      $this->assertFalse($waited(), 'A backup still waits for that request.');
      BackupPause::leaveWebRequest();
      $this->assertTrue($waited(), 'The wait ends once the request is over.');

      chmod($lock, 0644);
      unlink($lock);
      chmod($dir, 0500);
      $answer = $enter();
      $this->assertSame(503, $answer[0], 'A request whose lock cannot be opened is refused.');
      $this->assertStringContainsString('cannot take requests right now', $answer[2]);
      $logged = (string) file_get_contents($errors);
      $this->assertStringContainsString('cannot open the request lock', $logged);
      $this->assertStringNotContainsString('/node/add', $logged);
    }
    finally {
      BackupPause::leaveWebRequest();
      ini_set('error_log', $log === FALSE ? '' : $log);
      @unlink($errors);
      @chmod($dir, 0700);
      @chmod($lock, 0644);
    }
  }

  /**
   * settings.php starts every web request through the lock, not the bare check.
   */
  public function testSettingsTakesTheRequestLock(): void {
    $settings = file_get_contents(DRUPAL_ROOT . '/sites/default/settings.php');
    $this->assertStringContainsString('->enterWebRequest($_SERVER)', $settings);
    $this->assertStringNotContainsString('->webAnswer($_SERVER)', $settings);
  }

}
