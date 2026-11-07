<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Search pages and AI endpoints say noindex, nofollow (D-27d).
 *
 * Crawlers following search links would index result pages and, on the AI
 * endpoints, trigger paid calls. robots.txt covers polite crawlers
 * (22-31); the header covers a page reached some other way.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class NoIndexHeaderTest extends GroupAccessKernelTestBase {

  /**
   * The response subscriber's service id (contract).
   */
  private const SERVICE = 'social_ai_indexing.noindex_header_subscriber';

  /**
   * Runs a response through the subscriber and returns it.
   */
  private function respond(string $path, int $type = HttpKernelInterface::MAIN_REQUEST, ?Response $response = NULL): Response {
    $this->assertTrue($this->container->has(self::SERVICE), 'The noindex response subscriber is registered.');
    $subscriber = $this->container->get(self::SERVICE);
    $listeners = $this->container->get('event_dispatcher')->getListeners(KernelEvents::RESPONSE);
    $this->assertContains([$subscriber, 'onResponse'], $listeners, 'It listens on KernelEvents::RESPONSE.');

    $response ??= new Response('ok');
    $event = new ResponseEvent($this->container->get('http_kernel'), Request::create($path), $type, $response);
    $subscriber->onResponse($event);
    return $event->getResponse();
  }

  /**
   * Search pages and AI endpoints get the header.
   */
  public function testSearchAndAiPathsAreNoindex(): void {
    foreach (['/search', '/search/all/garden', '/search/content/garden', '/api/ai/overview'] as $path) {
      $this->assertSame('noindex, nofollow', $this->respond($path)->headers->get('X-Robots-Tag'), "$path is noindex, nofollow.");
    }
  }

  /**
   * Other pages don't.
   */
  public function testOtherPathsAreUntouched(): void {
    foreach (['/node/1', '/searching-for-food', '/group/1/stream', '/api/deepchat', '/user/login'] as $path) {
      $this->assertFalse($this->respond($path)->headers->has('X-Robots-Tag'), "$path has no X-Robots-Tag.");
    }
  }

  /**
   * Sub-requests add nothing, and the header is never doubled.
   */
  public function testSubRequestAndNoDuplicate(): void {
    $this->assertFalse($this->respond('/search/all/garden', HttpKernelInterface::SUB_REQUEST)->headers->has('X-Robots-Tag'), 'A sub-request adds no header.');

    $response = $this->respond('/search/all/garden');
    $response = $this->respond('/search/all/garden', HttpKernelInterface::SUB_REQUEST, $response);
    $response = $this->respond('/search/all/garden', HttpKernelInterface::MAIN_REQUEST, $response);
    $this->assertSame(['noindex, nofollow'], $response->headers->all('X-Robots-Tag'), 'One header, never two.');
  }

}
