<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\ai\Event\AiExceptionEvent;
use Drupal\ai\Exception\AiQuotaException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\social_ai_indexing\EventSubscriber\AiServiceFailureSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Which AI failures make the assistant say it isn't available.
 *
 * Quota and setup failures do, unless another subscriber answered in the
 * failed call's place (a backup model, for example): then the member got an
 * answer and the assistant was available after all.
 *
 * @coversDefaultClass \Drupal\social_ai_indexing\EventSubscriber\AiServiceFailureSubscriber
 * @group social_ai_indexing
 */
final class AiServiceFailureSubscriberTest extends UnitTestCase {

  /**
   * Quota and setup failures are recorded by kind; others are not.
   */
  public function testQuotaAndSetupAreRecorded(): void {
    foreach ([
      'quota' => new AiQuotaException('out of quota'),
      'setup' => new AiSetupFailureException('no key'),
    ] as $kind => $exception) {
      $registry = new SourceRegistry();
      $this->dispatch($registry, $this->event($exception));
      $this->assertSame($kind, $registry->serviceFailure());
    }
    $registry = new SourceRegistry();
    $this->dispatch($registry, $this->event(new AiRateLimitException('slow down')));
    $this->assertNull($registry->serviceFailure());
  }

  /**
   * A failure that another subscriber answered for is not recorded.
   *
   * The recovering subscriber is registered at the default priority, as a
   * failover module would be, so this also checks that the failure is read
   * after it.
   */
  public function testAnAnsweredFailureIsNotRecorded(): void {
    $registry = new SourceRegistry();
    $recovery = static function (AiExceptionEvent $event): void {
      $event->setForcedOutputObject(new ChatOutput(new ChatMessage('assistant', 'NO_RELEVANT_CONTENT'), [], []));
    };
    $this->dispatch($registry, $this->event(new AiQuotaException('out of quota')), $recovery);
    $this->assertNull($registry->serviceFailure());
  }

  /**
   * Dispatches the event through the subscriber, and an optional listener.
   */
  private function dispatch(SourceRegistry $registry, AiExceptionEvent $event, ?callable $listener = NULL): void {
    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new AiServiceFailureSubscriber($registry));
    if ($listener !== NULL) {
      $dispatcher->addListener(AiExceptionEvent::class, $listener);
    }
    $dispatcher->dispatch($event, AiExceptionEvent::class);
  }

  /**
   * An exception event for a chat call.
   */
  private function event(\Exception $exception): AiExceptionEvent {
    return new AiExceptionEvent($exception, 'thread', 'gemini', 'chat', [], NULL, 'model');
  }

}
