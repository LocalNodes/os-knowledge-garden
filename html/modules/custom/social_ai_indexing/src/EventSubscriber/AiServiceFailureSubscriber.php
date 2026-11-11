<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\ai\Event\AiExceptionEvent;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Notes when the AI service is out of quota or isn't set up.
 *
 * The assistant can then say it isn't available, instead of saying it found
 * nothing. Only the kind of failure is recorded, taken from the exception's
 * class. The exception's message is never read, and nothing is logged here:
 * a provider's error text can repeat the member's words.
 *
 * It runs after the other listeners. When one of them answered in the failed
 * call's place (a backup model, for example), the member got an answer, so
 * nothing is recorded.
 */
final class AiServiceFailureSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly SourceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiExceptionEvent::class => ['onAiException', -1000],
    ];
  }

  /**
   * Records the kind of failure for the current assistant run.
   */
  public function onAiException(AiExceptionEvent $event): void {
    if ($event->getForcedOutputObject() !== NULL) {
      return;
    }
    $exception = $event->exception;
    if ($exception instanceof AiQuotaException) {
      $this->registry->markServiceFailure('quota');
    }
    elseif ($exception instanceof AiSetupFailureException) {
      $this->registry->markServiceFailure('setup');
    }
  }

}
