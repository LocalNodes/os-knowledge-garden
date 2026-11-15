<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

/**
 * Stamps a loaded node's changed time with its created time.
 *
 * The home stream orders each item by its changed time. social_demo loads a
 * post with changed equal to created, but a page or an event keeps the time of
 * the save, so every one of them would sit above every post whatever date the
 * pack gives it. With the two equal, a pack's dates decide the order.
 */
trait StampsChangedTime {

  /**
   * {@inheritdoc}
   */
  protected function getEntry(array $item) {
    $entry = parent::getEntry($item);
    $entry['changed'] = $entry['created'];
    return $entry;
  }

}
