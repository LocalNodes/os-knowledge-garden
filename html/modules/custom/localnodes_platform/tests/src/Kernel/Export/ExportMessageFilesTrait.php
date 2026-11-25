<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\Entity\File;
use Symfony\Component\Process\Process;

/**
 * Message files on a real private folder, a stand-in dumper and the text
 * of the archive the exporter builds from them.
 *
 * For kernel tests that list system, user, file, key and localnodes_platform
 * in their modules.
 */
trait ExportMessageFilesTrait {

  /**
   * Real folder holding public, private and exports.
   */
  private string $base;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->base = sys_get_temp_dir() . '/ln-export-msgfiles-' . bin2hex(random_bytes(6));
    mkdir($this->base . '/public', 0775, TRUE);
    mkdir($this->base . '/private/inline-images', 0775, TRUE);
    $settings = Settings::getAll();
    $settings['file_public_path'] = $this->base . '/public';
    $settings['file_private_path'] = $this->base . '/private';
    new Settings($settings);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('user', ['users_data']);
    // Key-value rows in the database, as on a site, so a file's extracted
    // text is a row the copy can hold or leave out.
    $this->container->set('keyvalue', $this->container->get('keyvalue.database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->base) && is_dir($this->base)) {
      (new Process(['rm', '-rf', $this->base]))->run();
    }
    parent::tearDown();
  }

  /**
   * A file put into message $message_id, stored under $uri, holding $word.
   */
  private function messageFile(string $uri, string $word, int $message_id): File {
    // Where Drupal reads it: the target without its leading or trailing
    // slashes and backslashes.
    $path = $this->base . '/private/' . StreamWrapperManager::getTarget($uri);
    file_put_contents($path, "Contents $word\n");
    $file = File::create(['uri' => $uri, 'filename' => "$word.txt", 'uid' => 0]);
    $file->setPermanent();
    $file->save();
    $this->container->get('file.usage')->add($file, 'editor', 'private_message', (string) $message_id);
    return $file;
  }

  /**
   * A stand-in dumper: every row of every table not structure-only, read
   * when it runs; $during runs first, as a write made while the dump runs,
   * and $after once the rows are read, as work done before the export ends.
   */
  private function dumper(?callable $during = NULL, ?callable $after = NULL): callable {
    return function (string $file, array $structure_only) use ($during, $after): void {
      if ($during) {
        $during();
      }
      $database = $this->container->get('database');
      $prefix = $database->getPrefix();
      $text = '';
      foreach ($database->schema()->findTables('%') as $table) {
        if (in_array($prefix . $table, $structure_only, TRUE)) {
          continue;
        }
        foreach ($database->select($table, 't')->fields('t')->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
          $text .= "INSERT INTO `$table` " . json_encode(array_map(fn ($v) => is_string($v) && !mb_check_encoding($v, 'UTF-8') ? base64_encode($v) : $v, $row)) . "\n";
        }
      }
      file_put_contents($file, $text);
      if ($after) {
        $after();
      }
    };
  }

  /**
   * Builds the archive and returns all its text: rows (hex decoded), file
   * names and file contents.
   */
  private function archiveText(callable $dumper): string {
    /** @var \Drupal\localnodes_platform\Export\SiteExporter $exporter */
    $exporter = $this->container->get('localnodes_platform.site_exporter');
    $result = $exporter->export($this->base . '/exports', $dumper);
    return $this->archiveTextAt($result['path']);
  }

  /**
   * All the text of the archive at $path: rows (hex decoded), file names
   * and file contents.
   */
  private function archiveTextAt(string $path): string {
    $out = $this->base . '/unpacked-' . bin2hex(random_bytes(3));
    mkdir($out);
    (new Process(['tar', '-xf', $path, '-C', $out]))->mustRun();
    $text = '';
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out, \FilesystemIterator::SKIP_DOTS)) as $file) {
      $path = (string) $file;
      if (str_ends_with($path, '.tar.gz')) {
        $text .= (new Process(['tar', '-tzf', $path]))->mustRun()->getOutput();
        $text .= (new Process(['tar', '-xzOf', $path]))->mustRun()->getOutput();
      }
      elseif (str_ends_with($path, '.gz')) {
        $text .= (string) gzdecode((string) file_get_contents($path));
      }
      else {
        $text .= (string) file_get_contents($path);
      }
    }
    preg_match_all('/0x([0-9a-f]+)/', $text, $matches);
    foreach ($matches[1] as $hex) {
      $text .= "\n" . hex2bin($hex);
    }
    return $text;
  }

}
