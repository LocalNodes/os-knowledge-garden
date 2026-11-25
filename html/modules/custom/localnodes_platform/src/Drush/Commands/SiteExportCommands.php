<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Drush\Commands;

use Drupal\Core\Site\Settings;
use Drupal\localnodes_platform\Backup\BackupCapture;
use Drupal\localnodes_platform\Backup\BackupPause;
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
 *
 * The site takes no writes while the copy is made: the command closes it
 * with the same pause as the web export and the nightly backup, and opens
 * it again when the copy is done or has failed.
 */
class SiteExportCommands extends DrushCommands {

  /**
   * Seconds of the pause kept back for finishing the archive.
   */
  private const MARGIN = 60;

  /**
   * Unix time by which the copy must be done, while the command runs.
   */
  private ?float $deadline = NULL;

  /**
   * Write the site's export archive and print its path.
   */
  #[CLI\Command(name: 'localnodes:export', aliases: ['ln-export'])]
  #[CLI\Option(name: 'destination', description: 'Absolute path of the folder for the archive. Without it, the archive goes to the exports folder next to the Drupal root (never under it).')]
  #[CLI\Option(name: 'no-library', description: 'Leave out library/, the readable Markdown copy of every published topic, event and page.')]
  #[CLI\Option(name: 'drain-seconds', description: 'Seconds to wait, once the site is closed, for work already under way to finish before the copy starts.')]
  #[CLI\Option(name: 'max-pause', description: 'Longest time, in seconds, the site stays closed for the copy. The run stops and the site opens again at this limit.')]
  #[CLI\Usage(name: 'drush localnodes:export', description: 'Write the archive to the default folder and print its path.')]
  #[CLI\Usage(name: 'drush localnodes:export --destination=/srv/exports', description: 'Write it somewhere else the web server does not serve.')]
  public function export(array $options = ['destination' => self::REQ, 'no-library' => FALSE, 'drain-seconds' => 20, 'max-pause' => 5400]): int {
    /** @var \Drupal\localnodes_platform\Export\SiteExporter $exporter */
    $exporter = \Drupal::service('localnodes_platform.site_exporter');
    $destination = (string) ($options['destination'] ?: $exporter->defaultDestination());

    if (\Drupal::state()->get('localnodes.install_complete') === NULL) {
      $this->logger()->warning('This site has no install-complete marker (it was not installed by the LocalNodes image). On restore, the image will run its post-install steps once before the site starts.');
    }

    $library = $options['no-library'] ? NULL : $this->library();
    $drain = (int) ($options['drain-seconds'] ?? 20);
    $max_pause = (int) ($options['max-pause'] ?? 5400);
    $private = (string) Settings::get('file_private_path', '');
    $why = match (TRUE) {
      $private === '' => 'this site has no private files folder, so there is nowhere to keep the pause',
      $drain < 0 => '--drain-seconds cannot be negative',
      $max_pause <= $this->margin() + $drain => '--max-pause must be more than ' . ($this->margin() + $drain) . ' seconds',
      default => NULL,
    };
    if ($why !== NULL) {
      $this->logger()->error("Export refused: $why. Nothing was written.");
      return self::EXIT_FAILURE;
    }

    try {
      $result = (new BackupCapture())->run(new BackupPause($private), 'export', $max_pause, $drain, $this->margin(), function (float $deadline) use ($exporter, $destination, $library): array {
        $this->deadline = $deadline;
        return $exporter->export($destination, $this->dumper(), $library, $deadline);
      });
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    finally {
      $this->deadline = NULL;
    }

    $manifest = $result['manifest'];
    $library_line = $manifest['library'] === NULL
      ? 'no library'
      : sprintf('library of %d pages and %d files', $manifest['library']['pages'], $manifest['library']['files']);
    $this->io()->success(sprintf(
      'Export written: %d left-out tables, %d public and %d private files, %d config objects scrubbed, %s.',
      count($manifest['database']['left_out_tables']),
      $manifest['files']['public_count'],
      $manifest['files']['private_count'],
      count($manifest['database']['config_scrubbed']),
      $library_line,
    ));
    $this->output()->writeln($result['path']);
    return self::EXIT_SUCCESS;
  }

  /**
   * The database dumper the exporter calls: drush sql:dump on this site.
   *
   * Protected so a test can run the command with a fake dump.
   */
  protected function dumper(): callable {
    return function (string $sql_file, array $structure_only): void {
      $process = Drush::drush(Drush::aliasManager()->getSelf(), 'sql:dump', [], [
        'result-file' => $sql_file,
        'structure-tables-list' => implode(',', $structure_only),
      ]);
      $process->setTimeout($this->deadline === NULL ? 3600 : SiteBackupCommands::timeLeft($this->deadline));
      // mustRun(): the exporter turns its failure into the command and the
      // exit code, without the tool's stderr.
      $process->mustRun();
    };
  }

  /**
   * Seconds held back from --max-pause for finishing the archive.
   */
  protected function margin(): int {
    return self::MARGIN;
  }

  /**
   * The library writer (library/ in the archive).
   */
  private function library(): callable {
    return \Drupal::service('localnodes_platform.library_writer');
  }

}
