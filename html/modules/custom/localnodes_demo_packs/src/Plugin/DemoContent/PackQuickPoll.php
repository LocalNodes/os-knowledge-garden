<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\localnodes_quick_poll\DemoContent\QuickPollDemoContent;

/**
 * Pack Quick Poll: the same loader as social_demo's, reading the chosen pack.
 *
 * A poll marked `closed: true` loads closed.
 *
 * @DemoContent(
 *   id = "pack_quick_poll",
 *   label = @Translation("Pack Quick Poll"),
 *   source = "content/entity/quick-poll.yml",
 *   entity_type = "poll"
 * )
 */
class PackQuickPoll extends QuickPollDemoContent {

  use ResolvesPackTokens;

  /**
   * {@inheritdoc}
   *
   * The base loads every poll open (status 1). A pack marks a finished poll
   * `closed: true`, and it loads closed, with its answers.
   */
  protected function getEntry(array $item) {
    $entry = parent::getEntry($item);
    $entry['status'] = empty($item['closed']) ? 1 : 0;
    return $entry;
  }

}
