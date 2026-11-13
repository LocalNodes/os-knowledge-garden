<?php

namespace Drupal\boulder_demo\Plugin\DemoContent;

use Drupal\localnodes_quick_poll\DemoContent\QuickPollDemoContent;

/**
 * Quick polls in a demo group, with a few answers from its members.
 *
 * @DemoContent(
 *   id = "boulder_quick_poll",
 *   label = @Translation("Quick poll"),
 *   source = "content/entity/quick-poll.yml",
 *   entity_type = "poll"
 * )
 */
class BoulderQuickPoll extends QuickPollDemoContent {

}
