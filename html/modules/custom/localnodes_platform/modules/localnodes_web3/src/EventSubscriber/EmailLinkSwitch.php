<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\EventSubscriber;

use Drupal\localnodes_web3\EmailLink;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * While the emailed link is off, its pages are not found, for everyone.
 *
 * This runs before routing, so a signed-in visitor gets "not found" too, not
 * the "access denied" the pages' own signed-out requirement would give.
 * The pages themselves also refuse while it is off (EmailLink), for any way
 * to them this path check does not see.
 */
final class EmailLinkSwitch implements EventSubscriberInterface {

  /**
   * The path of the request page; the other two pages sit under it.
   */
  private const PATH = '/user/email-link';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Routing, with its access checks, runs at 32.
    return [KernelEvents::REQUEST => ['onRequest', 33]];
  }

  /**
   * Answers "not found" for the link's pages while it is off.
   */
  public function onRequest(RequestEvent $event): void {
    // The "not found" page is built in a sub-request for the same address;
    // refusing that one too would never end.
    if (!$event->isMainRequest()) {
      return;
    }
    $path = rtrim($event->getRequest()->getPathInfo(), '/');
    if ($path === self::PATH || str_starts_with($path, self::PATH . '/')) {
      EmailLink::requireSwitchedOn();
    }
  }

}
