<?php

declare(strict_types=1);

namespace Drupal\neighbors_demo;

use Drupal\localnodes_platform\CommonsName;

/**
 * The neighbors demo site's name: the one place it is written.
 *
 * The install names the site with it, and the demo content says
 * "{{site_name}}" wherever the name appears (profiles, posts and topics),
 * so renaming the demo is a change to NAME alone. The main group is the
 * site's Commons, the first group everyone is in: "{{commons_name}}", filled
 * by the platform's rule (CommonsName), never written out here.
 */
final class SiteName {

  /**
   * The site's name; the main group is its Commons.
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
   * The placeholder the content files use for the main group's name.
   */
  public const COMMONS_PLACEHOLDER = '{{commons_name}}';

  /**
   * Puts the site name and the Commons name in place of their placeholders.
   *
   * @param array $data
   *   Parsed demo content.
   *
   * @return array
   *   The same content with the name filled in.
   */
  public static function fill(array $data): array {
    $names = [
      self::COMMONS_PLACEHOLDER => CommonsName::of(self::NAME),
      self::PLACEHOLDER => self::NAME,
    ];
    array_walk_recursive($data, static function (&$value) use ($names): void {
      if (is_string($value)) {
        $value = strtr($value, $names);
      }
    });
    return $data;
  }

}
