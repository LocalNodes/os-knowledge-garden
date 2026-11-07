<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps search pages and AI endpoints out of search engines (D-27d).
 *
 * Crawlers following search links index result pages and, on /api/ai, make
 * paid AI calls. robots.txt asks polite crawlers to stay away (core's
 * /search/ rule and 22-31's /api/ai/ append); this header covers a page a
 * crawler reached anyway.
 *
 * The page cache middleware stores the response after every RESPONSE
 * subscriber has run, and the priority sits above dynamic_page_cache's (100),
 * so cached copies carry the header too.
 */
final class NoIndexHeaderSubscriber implements EventSubscriberInterface {

  /**
   * The header value.
   */
  public const VALUE = 'noindex, nofollow';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', 200]];
  }

  /**
   * Sets X-Robots-Tag on /search, /search/* and /api/ai/* responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $path = $event->getRequest()->getPathInfo();
    if ($path === '/search' || str_starts_with($path, '/search/') || str_starts_with($path, '/api/ai/')) {
      // set() replaces, so the header is never doubled.
      $event->getResponse()->headers->set('X-Robots-Tag', self::VALUE);
    }
  }

}
