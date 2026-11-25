<?php

namespace Drupal\neighbors_demo\Plugin\DemoContent;

use Drupal\localnodes_quick_poll\DemoContent\QuickPollDemoContent;
use Drupal\neighbors_demo\FillsSiteName;

/**
 * Quick polls in a demo group, with a few answers from its members.
 *
 * @DemoContent(
 *   id = "neighbors_quick_poll",
 *   label = @Translation("Quick poll"),
 *   source = "content/entity/quick-poll.yml",
 *   entity_type = "poll"
 * )
 */
class NeighborsQuickPoll extends QuickPollDemoContent {

  use FillsSiteName;

}
