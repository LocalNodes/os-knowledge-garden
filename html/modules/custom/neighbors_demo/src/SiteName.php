<?php

declare(strict_types=1);

namespace Drupal\neighbors_demo;

/**
 * The neighbors demo site's name: the one place it is written.
 *
 * The install names the site with it, and the demo content says
 * "{{site_name}}" wherever the name appears (the main group, profiles,
 * posts and topics), so renaming the demo is a change to NAME alone.
 */
final class SiteName {

  /**
   * The site's name, also the main group's name.
   */
  public const NAME = 'Creekside Neighbors';

  /**
   * The site's slogan.
   */
  public const SLOGAN = 'Events, notes and a shared fund for our neighborhood';

  /**
   * The placeholder the content files use for the name.
   */
  public const PLACEHOLDER = '{{site_name}}';

  /**
   * Puts the site name in place of the placeholder in every string.
   *
   * @param array $data
   *   Parsed demo content.
   *
   * @return array
   *   The same content with the name filled in.
   */
  public static function fill(array $data): array {
    array_walk_recursive($data, static function (&$value): void {
      if (is_string($value)) {
        $value = str_replace(self::PLACEHOLDER, self::NAME, $value);
      }
    });
    return $data;
  }

}
