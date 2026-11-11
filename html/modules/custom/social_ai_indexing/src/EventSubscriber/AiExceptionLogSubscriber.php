<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\ai\Event\AiExceptionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logs a failed AI call without the provider's error message.
 *
 * Takes the place of the ai module's own exception subscriber, which logs
 * the provider's message. A provider can repeat the request in that message:
 * a member's question, a members-only page being indexed, or the sources
 * given to the model. This line keeps what an admin needs to tell failures
 * apart (provider, operation, model, exception class and code) and leaves
 * the text out.
 *
 * It only logs. It doesn't change the event or stop it, so the failover
 * subscriber at the same priority still runs.
 */
final class AiExceptionLogSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Logs the failure by provider, operation, model, class and code.
   */
  public function onAiException(AiExceptionEvent $event): void {
    $exception = $event->exception;
    $previous = $exception->getPrevious();
    $this->loggerFactory->get('ai')->error('AI call failed: provider @provider, operation @operation, model @model: @class (code @code@previous)', [
      '@provider' => $event->getProviderId(),
      '@operation' => $event->getOperationType(),
      '@model' => $event->getModelId(),
      '@class' => $exception::class,
      '@code' => $exception->getCode(),
      '@previous' => $previous ? ', from ' . $previous::class . ' code ' . $previous->getCode() : '',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiExceptionEvent::class => 'onAiException',
    ];
  }

}
