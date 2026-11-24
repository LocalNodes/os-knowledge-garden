<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The switch that turns the whole-site export on or off.
 *
 * It is export_enabled in localnodes_platform.settings and is off on a fresh
 * install: the export carries member and private-group data that the scoped
 * export is being built to leave out, so it stays off until that version
 * passes its review. While it is off, the export pages and the wallet
 * challenge are not registered at all (a 404, not a 403), and are refused as
 * not found before routing too, for a route table built while the switch was
 * on. The "Export this site" menu entry is gone, nothing can be requested or
 * downloaded, and the worker prepares nothing: a worker already running stops
 * at its next step and keeps nothing it made.
 *
 * A host turns it on with config (drush cset localnodes_platform.settings
 * export_enabled 1) or a settings.php override
 * ($config['localnodes_platform.settings']['export_enabled'] = TRUE), then
 * rebuilds caches so the routes and the menu entry come back.
 *
 * The host-only drush localnodes:export command is not covered: it needs a
 * shell on the server, which already has the database.
 */
final class ExportSwitch {

  public const CONFIG = 'localnodes_platform.settings';
  public const KEY = 'export_enabled';

  /**
   * Every route of the export, by name.
   */
  public const ROUTES = [
    'localnodes_platform.site_export',
    'localnodes_platform.site_export_confirm',
    'localnodes_web3.export_challenge',
  ];

  /**
   * The path every export route lives under.
   */
  public const PATH = '/admin/config/system/site-export';

  public static function enabled(ConfigFactoryInterface $config): bool {
    // A missing setting counts as off.
    return $config->get(self::CONFIG)->get(self::KEY) === TRUE;
  }

  /**
   * The switch as stored now, not as this process first loaded it.
   *
   * The export worker is a long-running process, and the host turns the
   * switch off from another one. This process keeps its own loaded copy of
   * the setting, so the worker asks again before each step. A settings.php
   * override is read only when a process starts, so turning the export off
   * there takes effect at the worker's next run.
   */
  public static function live(ConfigFactoryInterface $config): bool {
    $config->reset(self::CONFIG);
    return self::enabled($config);
  }

}
