<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\social_ai_indexing\EventSubscriber\AiExceptionLogSubscriber;

/**
 * Swaps the ai module's exception logger for one that leaves out the text.
 *
 * The original class is final, so the service keeps its id, argument and
 * tag and only its class changes. See AiExceptionLogSubscriber for why.
 */
final class SocialAiIndexingServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('ai.exception_event_subscriber')) {
      $container->getDefinition('ai.exception_event_subscriber')->setClass(AiExceptionLogSubscriber::class);
    }
  }

}
