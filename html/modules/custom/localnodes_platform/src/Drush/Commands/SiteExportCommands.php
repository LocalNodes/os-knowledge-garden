<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Drush\Commands;

use Drupal\localnodes_platform\Export\SiteExporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Drush;

/**
 * drush localnodes:export: one archive a community can move to its own server.
 *
 * The host runs it and hands the archive to the community's organizers. It
 * prints the archive's path on the server, never a URL: the archive holds
 * every member's account and all private content. SiteExporter says what is
 * in it; RESTORE.md inside it says how to bring the site up elsewhere.
 */
final class SiteExportCommands extends DrushCommands {

  /**
   * Write the site's export archive and print its path.
   */
  #[CLI\Command(name: 'localnodes:export', aliases: ['ln-export'])]
  #[CLI\Option(name: 'destination', description: 'Absolute path of the folder for the archive. Without it, the archive goes to the exports folder next to the Drupal root (never under it).')]
  #[CLI\Usage(name: 'drush localnodes:export', description: 'Write the archive to the default folder and print its path.')]
  #[CLI\Usage(name: 'drush localnodes:export --destination=/srv/exports', description: 'Write it somewhere else the web server does not serve.')]
  public function export(array $options = ['destination' => self::REQ]): int {
    /** @var \Drupal\localnodes_platform\Export\SiteExporter $exporter */
    $exporter = \Drupal::service('localnodes_platform.site_exporter');
    $destination = (string) ($options['destination'] ?: $exporter->defaultDestination());

    if (\Drupal::state()->get('localnodes.install_complete') === NULL) {
      $this->logger()->warning('This site has no install-complete marker (it was not installed by the LocalNodes image). On restore, the image will run its post-install steps once before the site starts.');
    }

    $dumper = function (string $sql_file, array $structure_only): void {
      $process = Drush::drush(Drush::aliasManager()->getSelf(), 'sql:dump', [], [
        'result-file' => $sql_file,
        'structure-tables-list' => implode(',', $structure_only),
      ]);
      $process->setTimeout(3600);
      // mustRun(): the exporter turns its failure into the command and the
      // exit code, without the tool's stderr.
      $process->mustRun();
    };

    try {
      $result = $exporter->export($destination, $dumper);
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }

    $manifest = $result['manifest'];
    $this->logger()->success(sprintf(
      'Export written: %d left-out tables, %d public and %d private files, %d config objects scrubbed.',
      count($manifest['database']['left_out_tables']),
      $manifest['files']['public_count'],
      $manifest['files']['private_count'],
      count($manifest['database']['config_scrubbed']),
    ));
    $this->output()->writeln($result['path']);
    return self::EXIT_SUCCESS;
  }

}
