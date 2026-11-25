<?php

namespace Drupal\localnodes_platform;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\localnodes_platform\Activity\HouseActivityFactory;

/** Keeps the optional activity service's own dependencies and lifetime. */
final class LocalnodesPlatformServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('activity_creator.activity_factory')) {
      $container->getDefinition('activity_creator.activity_factory')->setClass(HouseActivityFactory::class);
    }
  }

}
