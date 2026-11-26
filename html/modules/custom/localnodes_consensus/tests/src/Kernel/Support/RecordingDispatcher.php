<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel\Support;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;

/**
 * The site's event dispatcher, noting the name of every event it sends.
 */
class RecordingDispatcher extends ContainerAwareEventDispatcher {

  /**
   * Event names dispatched, in order.
   *
   * @var string[]
   */
  public static array $names = [];

  /**
   * {@inheritdoc}
   */
  public function dispatch(object $event, ?string $eventName = NULL): object {
    static::$names[] = $eventName ?? get_class($event);
    return parent::dispatch($event, $eventName);
  }

}
