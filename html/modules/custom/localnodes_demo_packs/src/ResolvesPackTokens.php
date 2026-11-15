<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

/**
 * Passes every parsed pack file through the day-token resolver.
 *
 * Used by every pack plugin, so a post's "{{day:+7}}" and an event's date
 * count from the same moment.
 */
trait ResolvesPackTokens {

  /**
   * The profile (directory) the plugin reads its file from.
   */
  protected function packProfile(): string {
    return $this->getProfile();
  }

  /**
   * {@inheritdoc}
   */
  protected function fetchData() {
    if (!$this->data) {
      $data = $this->parser->parseFileFromModule($this->getSource(), $this->getModule(), $this->packProfile());
      $this->data = PackTokens::walk(is_array($data) ? $data : [], $this->packAnchor());
    }

    return $this->data;
  }

  /**
   * The time every day token on this site counts from.
   *
   * Set once per site, at the first load, so loading again on the same site
   * resolves to the same dates.
   */
  protected function packAnchor(): int {
    $state = \Drupal::state();
    $anchor = $state->get('localnodes_demo_packs.anchor');
    if (!$anchor) {
      $anchor = \Drupal::time()->getRequestTime();
      $state->set('localnodes_demo_packs.anchor', $anchor);
    }
    return (int) $anchor;
  }

}
