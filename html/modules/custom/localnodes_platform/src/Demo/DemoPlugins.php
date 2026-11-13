<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Demo;

/**
 * The demo content plugins a demo module loads, in load order.
 *
 * One list for every loader: docker/entrypoint.sh passes it to
 * drush social-demo:add (through drush localnodes-demo:plugins), and
 * drush localnodes-demo:install loads it directly.
 */
final class DemoPlugins {

  /**
   * Content every demo module has, in load order: files and terms first,
   * then people and groups, then what they post, comment and like.
   */
  public const ORDER = [
    'file',
    'user_terms',
    'event_type',
    'user',
    'group',
    'topic',
    'event',
    'event_enrollment',
    'post',
    'comment',
    'like',
  ];

  /**
   * Each demo module's plugin id prefix.
   */
  public const PREFIXES = [
    'localnodes_demo' => 'localnodes',
    'boulder_demo' => 'boulder',
    'portland_demo' => 'portland',
  ];

  /**
   * Content only some demo modules have. It loads after the rest, since a
   * quick poll needs its group and the people who answer it.
   */
  public const EXTRAS = [
    'localnodes_demo' => ['quick_poll'],
    'boulder_demo' => ['quick_poll'],
  ];

  /**
   * The plugin ids for a demo module, in load order.
   *
   * A module without a prefix gets social_demo's own unprefixed plugins.
   *
   * @return string[]
   */
  public static function ids(string $module): array {
    $prefix = self::PREFIXES[$module] ?? NULL;
    if ($prefix === NULL) {
      return self::ORDER;
    }
    return array_map(
      static fn (string $name): string => $prefix . '_' . $name,
      array_merge(self::ORDER, self::EXTRAS[$module] ?? []),
    );
  }

}
