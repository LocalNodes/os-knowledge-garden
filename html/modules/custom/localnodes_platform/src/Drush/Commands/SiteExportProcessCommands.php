<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/** The export consumer is separate from HTTP and ordinary cron queues. */
final class SiteExportProcessCommands extends DrushCommands {

  #[CLI\Command(name: 'localnodes:site-export-process')]
  public function process(): int {
    if (PHP_SAPI !== 'cli') return self::EXIT_FAILURE;
    try {
      \Drupal::service('localnodes_platform.export_manager')->work();
      return self::EXIT_SUCCESS;
    }
    catch (\Throwable) {
      $this->logger()->warning('Site export processing is unavailable.');
      return self::EXIT_FAILURE;
    }
  }

}
