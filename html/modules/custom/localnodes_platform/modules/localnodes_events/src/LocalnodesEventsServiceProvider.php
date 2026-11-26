<?php

declare(strict_types=1);

namespace Drupal\localnodes_events;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\localnodes_events\EventSubscriber\AttendeeRequestsRedirect;

/**
 * Swaps Open Social's enrollment-requests redirect for the rule's version.
 */
final class LocalnodesEventsServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('social_event.enroll_request_overview_subscriber')) {
      $container->getDefinition('social_event.enroll_request_overview_subscriber')->setClass(AttendeeRequestsRedirect::class);
    }
  }

}
