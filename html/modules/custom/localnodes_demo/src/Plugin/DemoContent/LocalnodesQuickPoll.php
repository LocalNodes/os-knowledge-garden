<?php

namespace Drupal\localnodes_demo\Plugin\DemoContent;

use Drupal\localnodes_quick_poll\DemoContent\QuickPollDemoContent;

/**
 * Quick polls in a demo group, with a few answers from its members.
 *
 * @DemoContent(
 *   id = "localnodes_quick_poll",
 *   label = @Translation("Quick poll"),
 *   source = "content/entity/quick-poll.yml",
 *   entity_type = "poll"
 * )
 */
class LocalnodesQuickPoll extends QuickPollDemoContent {

}
