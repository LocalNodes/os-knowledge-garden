<?php

declare(strict_types=1);

namespace Drupal\neighbors_demo;

/**
 * Fills the site name into every parsed content file.
 *
 * Used by every neighbors demo plugin, so the name in the content is the
 * one SiteName holds.
 */
trait FillsSiteName {

  /**
   * {@inheritdoc}
   */
  protected function fetchData() {
    if (!$this->data) {
      $data = $this->parser->parseFileFromModule($this->getSource(), $this->getModule(), $this->getProfile());
      $this->data = SiteName::fill(is_array($data) ? $data : []);
    }

    return $this->data;
  }

}
