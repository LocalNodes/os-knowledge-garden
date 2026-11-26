<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * The Decisions and set-up pages of a group show as ordinary group pages.
 *
 * Open Social shows a group's header (its name and buttons) and the card
 * with its member count only on the group paths each block lists. The Fund
 * tab adds its path to those lists
 * once, when it is installed. These pages add theirs at read time instead,
 * so a site where this module was already on gets them on its next start
 * as well as a new one, with nothing written to its configuration.
 *
 * Only paths are appended; a block a site has removed stays removed, and
 * a path already listed is not added again.
 */
final class GroupPageBlocksOverride implements ConfigFactoryOverrideInterface {

  /**
   * The paths of these pages, as block path patterns.
   */
  public const PATHS = [
    '/group/*/decisions',
    '/group/*/decisions/*',
    '/group/*/setup',
  ];

  /**
   * Blocks shown only on listed group paths: the header and the card.
   *
   * The theme's page titles are left out on these pages by route, not by
   * path (localnodes_consensus_preprocess_page()).
   */
  public const SHOWN_ON = [
    'block.block.groupheroblock',
    'block.block.socialblue_groupheroblock',
    'block.block.socialblue_group_statistic_block',
  ];

  public function __construct(
    private readonly StorageInterface $storage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names): array {
    $overrides = [];
    foreach ($names as $name) {
      if (!in_array($name, self::SHOWN_ON, TRUE)) {
        continue;
      }
      $data = $this->storage->read($name);
      $pages = is_array($data) ? ($data['visibility']['request_path']['pages'] ?? NULL) : NULL;
      $negate = is_array($data) ? (bool) ($data['visibility']['request_path']['negate'] ?? FALSE) : FALSE;
      // Only a block that is still shown on listed paths.
      if (!is_string($pages) || $pages === '' || $negate) {
        continue;
      }
      $lines = preg_split('/\r\n|\r|\n/', $pages);
      $missing = array_diff(self::PATHS, $lines);
      if ($missing) {
        $overrides[$name]['visibility']['request_path']['pages'] = implode("\r\n", [...$lines, ...$missing]);
      }
    }
    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'localnodes_consensus_group_pages';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    return new CacheableMetadata();
  }

}
