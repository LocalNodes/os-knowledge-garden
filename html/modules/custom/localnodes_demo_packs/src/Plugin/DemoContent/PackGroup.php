<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_demo_packs\PackCommunityGroup;
use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\DemoGroup;

/**
 * Pack Group: social_demo's loader reading the chosen pack.
 *
 * After the groups are loaded it records the pack's community-wide group
 * (see PackCommunityGroup), so a new member's first sign-in joins it and
 * starts the group tour, as on a site an organizer set up from a template.
 *
 * @DemoContent(
 *   id = "pack_group",
 *   label = @Translation("Pack Group"),
 *   source = "content/entity/group.yml",
 *   entity_type = "group"
 * )
 */
class PackGroup extends DemoGroup {

  use ResolvesPackTokens;

  /**
   * {@inheritdoc}
   */
  public function createContent($generate = FALSE, $max = NULL) {
    $content = parent::createContent($generate, $max);
    PackCommunityGroup::record($this->fetchData(), \Drupal::entityTypeManager(), \Drupal::configFactory(), \Drupal::state());
    return $content;
  }

}
