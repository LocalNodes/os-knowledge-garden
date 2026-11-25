<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\EventSubscriber;

use Drupal\localnodes_web3\Votes\MembersOnlyRefusedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers the group save check's refusal with a plain page.
 *
 * A save that would turn a group with a fund members-only is refused by
 * the save check (localnodes_web3_group_presave()). The person
 * is allowed, but the group's state forbids the change, so the answer is
 * 409 with the refusal's plain line (the one the group's edit form uses;
 * the developer line stays for drush and the log), on the same bare page
 * PublicVotesRefusal uses, never cached. Only that refusal: every other error keeps the
 * site's normal handling.
 */
final class MembersOnlyRefusal implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly PublicVotesRefusal $pages,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Ahead of core's logging and error pages; the storage has already
   * logged the refused save.
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::EXCEPTION => ['onException', 60]];
  }

  /**
   * Answers the refusal, wrapped or not; leaves every other error alone.
   */
  public function onException(ExceptionEvent $event): void {
    $refused = NULL;
    for ($throwable = $event->getThrowable(); $throwable !== NULL; $throwable = $throwable->getPrevious()) {
      if ($throwable instanceof MembersOnlyRefusedException) {
        $refused = $throwable;
        break;
      }
    }
    if ($refused === NULL) {
      return;
    }
    if ($event->getRequest()->getRequestFormat() === 'json') {
      $response = new JsonResponse(['error' => $refused->plainLine()], 409);
      $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
      $event->setResponse($response);
      return;
    }
    $content = [
      'sentence' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        // The line is MembersOnlyWait::LINE or ::FROM_HERE.
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        '#value' => t($refused->plainLine()),
      ],
    ];
    $event->setResponse($this->pages->page($content, $refused->groupLabel(), 409));
  }

}
