<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Writes one archive a community can take to its own server.
 *
 * The archive is an uncompressed tar holding one folder:
 * - database.sql.gz: the whole database. Tables that only hold caches, logs,
 *   sessions or short-lived data keep their structure and lose their rows
 *   (leftOutTables()). The config table's rows are written by this class, one
 *   by one, after the dump, with secrets taken out (writeScrubbedConfig()).
 * - public-files.tar.gz and private-files.tar.gz, without files Drupal makes
 *   again on its own (compiled templates, image derivatives, aggregates).
 * - library/: only when export() is given a library writer (LibraryWriter;
 *   the Drush command passes it unless --no-library): every published topic,
 *   event and page as Markdown with its files, split by who may read it.
 * - manifest.json, SHA256SUMS and RESTORE.md.
 *
 * Secrets never leave the site. Keys come from the environment, so a fresh
 * install stores none; the scrub covers a key switched to the "config"
 * provider and a password typed into a DSN or URL. Before anything is
 * packed, every part, file name, link target and generated document is
 * scanned for each known secret value (knownSecrets()) in each form it can
 * take in a dump (scanForms()); one hit deletes the archive and fails the
 * run. A secret too short to scan for, or a key that cannot be read, refuses
 * the run before it starts.
 *
 * The archive is built in a private staging folder (mode 0700, umask 077)
 * next to its destination and moved into place only when it is complete.
 *
 * Not included, and RESTORE.md says so: the Solr index and the vector store
 * (both rebuilt by reindexing) and every key.
 */
final class SiteExporter {

  /**
   * Format of manifest.json; raise it when a part changes shape.
   */
  public const FORMAT_VERSION = 2;

  /**
   * Tables whose rows are left out (structure kept), as LIKE patterns.
   *
   * Caches, cache tags, sessions, flood control, logs, locks, batches and
   * the expiring key-value store (temp stores, form drafts). Pending work in
   * queue and advancedqueue is kept.
   */
  public const LEFT_OUT_TABLE_PATTERNS = [
    'cache%',
    'sessions',
    'flood',
    'watchdog',
    'semaphore',
    'batch',
    'key_value_expire',
    'ultimate_cron_log',
    'ultimate_cron_lock',
    'ultimate_cron_signal',
  ];

  /**
   * Environment variables whose values must never be in an archive.
   */
  public const SECRET_ENV = [
    'GEMINI_API_KEY',
    'OPENROUTER_API_KEY',
    'RESEND_API_KEY',
    'PROVISION_CALLBACK_TOKEN',
    'STRIPE_SESSION_ID',
    'SPONSOR_RELAYER_TOKEN',
    'SPONSOR_RELAYER_PRIVATE_KEY',
    'WALLETCONNECT_PROJECT_ID',
    'DB_PASSWORD',
    'DB_ROOT_PASSWORD',
    'DRUPAL_HASH_SALT',
    'DRUPAL_ADMIN_PASS',
  ];

  /**
   * Published defaults and placeholders, which are no secret.
   *
   * "db" is DDEV's database password and "admin" the old admin default,
   * which the image replaces with a random password.
   */
  private const NOT_SECRETS = ['changeme', 'rootpassword', 'generate-random', 'placeholder', 'change-me-in-production', 'db', 'admin'];

  /**
   * A secret shorter than this cannot be scanned for: it would match
   * ordinary text. The export refuses to run while one is set.
   */
  private const MIN_SECRET_LENGTH = 12;

  /**
   * Top-level folders of the public files left out: Drupal makes them again.
   */
  public const PUBLIC_SKIP = ['php', 'styles', 'css', 'js', 'simpletest', 'sync', 'tmp'];

  /**
   * Top-level folders of the private files left out.
   */
  public const PRIVATE_SKIP = ['styles', 'php', 'tmp', '.localnodes'];

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleExtensionList $moduleList,
    private readonly ?object $keyRepository = NULL,
  ) {}

  /**
   * The default folder for archives: next to the Drupal root, never under it.
   */
  public function defaultDestination(): string {
    return dirname(DRUPAL_ROOT) . '/exports';
  }

  /**
   * Refuses a destination the web server could serve or the archive would include.
   *
   * @return string|null
   *   Why the folder is refused, or NULL when it is fine.
   */
  public function refuseDestination(string $destination): ?string {
    if (!str_starts_with($destination, '/')) {
      return 'it is not an absolute path';
    }
    // The folder may not exist yet: resolve its nearest existing parent.
    $real = $destination;
    $missing = '';
    while (!file_exists($real) && $real !== '/' && $real !== '') {
      $missing = '/' . basename($real) . $missing;
      $real = dirname($real);
    }
    $real = rtrim(realpath($real) ?: $real, '/') . $missing;
    if (preg_match('#(^|/)\.\.?(/|$)#', $missing)) {
      return 'it contains "." or ".."';
    }
    $roots = ['the Drupal root' => DRUPAL_ROOT];
    foreach (['public://' => 'the public files', 'private://' => 'the private files'] as $scheme => $label) {
      $path = $this->fileSystem->realpath($scheme);
      if ($path) {
        $roots[$label] = $path;
      }
    }
    foreach ($roots as $label => $root) {
      $root = rtrim(realpath($root) ?: $root, '/');
      if ($real === $root || str_starts_with($real . '/', $root . '/')) {
        return "it is inside $label ($root)";
      }
    }
    return NULL;
  }

  /**
   * The tables whose rows are left out, by their unprefixed names.
   *
   * @return string[]
   */
  public function leftOutTables(): array {
    $tables = [];
    foreach (self::LEFT_OUT_TABLE_PATTERNS as $pattern) {
      foreach ($this->database->schema()->findTables($pattern) as $table) {
        $tables[$table] = $table;
      }
    }
    sort($tables);
    return array_values($tables);
  }

  /**
   * Every secret value this site knows, by where it comes from.
   *
   * @return array<string, string>
   *   Values to keep out of the archive, keyed by a label that names the
   *   source (never the value).
   *
   * @throws \RuntimeException
   *   When a key cannot be read, or a secret is too short to scan for. The
   *   message names the source only.
   */
  public function knownSecrets(): array {
    $secrets = [];
    if ($this->keyRepository && method_exists($this->keyRepository, 'getKeys')) {
      foreach ($this->keyRepository->getKeys() as $id => $key) {
        try {
          $secrets['key ' . $id] = (string) $key->getKeyValue();
        }
        catch (\Throwable) {
          throw new \RuntimeException("Secret check failed: the value of key $id cannot be read here, so the archive cannot be checked for it. Nothing was written.");
        }
      }
    }
    // The database passwords this site connects with, wherever they are set.
    foreach (Database::getAllConnectionInfo() as $key => $targets) {
      foreach ($targets as $target => $info) {
        $secrets["database $key.$target password"] = (string) ($info['password'] ?? '');
      }
    }
    foreach (self::SECRET_ENV as $name) {
      $secrets['env ' . $name] = (string) getenv($name);
    }
    $secrets['setting hash_salt'] = (string) Settings::get('hash_salt', '');
    $provision = Settings::get('localnodes_provision', []);
    if (is_array($provision)) {
      $secrets['setting localnodes_provision.callback_token'] = (string) ($provision['callback_token'] ?? '');
    }
    // A mail transport password, as the site uses it (with settings.php's override).
    foreach ($this->configFactory->listAll('symfony_mailer.mailer_transport.') as $name) {
      $dsn = $this->configFactory->get($name)->get('configuration.dsn');
      if (is_string($dsn) && ($password = parse_url($dsn, PHP_URL_PASS))) {
        $secrets['mail ' . $name] = rawurldecode($password);
        $secrets['mail ' . $name . ' (encoded)'] = $password;
      }
    }
    $secrets = array_filter($secrets, fn (string $value): bool =>
      $value !== '' && !in_array($value, self::NOT_SECRETS, TRUE));
    $short = array_keys(array_filter($secrets, fn (string $value): bool => strlen($value) < self::MIN_SECRET_LENGTH));
    if ($short) {
      throw new \RuntimeException(sprintf(
        'Secret check failed: %s %s shorter than %d characters, too short to check the archive for. Set a longer value, then run the export again. Nothing was written.',
        implode(', ', $short), count($short) === 1 ? 'is' : 'are', self::MIN_SECRET_LENGTH
      ));
    }
    return $secrets;
  }

  /**
   * Each secret in every form it can take inside the archive.
   *
   * A dump escapes quotes and backslashes and may write a value as a hex
   * literal; manifest.json escapes it as JSON; a URL encodes it.
   *
   * @param array<string, string> $secrets
   *   From knownSecrets().
   *
   * @return array<string, string>
   *   The forms to scan for, keyed "<label>" or "<label> (<form>)".
   */
  public static function scanForms(array $secrets): array {
    $forms = [];
    foreach ($secrets as $label => $secret) {
      $json = json_encode($secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      $candidates = [
        '' => $secret,
        'SQL-escaped' => strtr($secret, ['\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\0" => '\\0', "\x1a" => '\\Z']),
        'hex' => bin2hex($secret),
        'HEX' => strtoupper(bin2hex($secret)),
        'JSON-escaped' => is_string($json) ? substr($json, 1, -1) : $secret,
        'URL-encoded' => rawurlencode($secret),
        'form-encoded' => urlencode($secret),
      ];
      foreach ($candidates as $form => $value) {
        if (!in_array($value, $forms, TRUE)) {
          $forms[$form === '' ? $label : "$label ($form)"] = $value;
        }
      }
    }
    return $forms;
  }

  /**
   * A site name as the archive's folder name uses it.
   */
  private static function slug(string $text): string {
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(Html::decodeEntities($text))), '-');
  }

  /**
   * Looks for any known secret in a piece of text, such as a name.
   *
   * @return string|null
   *   The label of the first secret found (never the value), or NULL.
   */
  public function findSecretInText(string $text, array $forms): ?string {
    foreach ($forms as $label => $form) {
      if (str_contains($text, $form)) {
        return $label;
      }
    }
    return NULL;
  }

  /**
   * Writes every config row as SQL, with secrets taken out.
   *
   * Config data is serialized PHP, so a secret cannot be cut out of the dump
   * text (the string lengths would no longer match). Each row is read,
   * unserialized, cleaned and serialized again here. Values go in as hex
   * literals, so no quoting or character set can change them.
   *
   * @param resource $handle
   *   Where the SQL goes.
   * @param array<string, string> $secrets
   *   From knownSecrets().
   *
   * @return string[]
   *   Names of the config objects that were changed.
   */
  public function writeScrubbedConfig($handle, array $secrets): array {
    $table = $this->database->getPrefix() . 'config';
    $changed = [];
    fwrite($handle, "\n-- Config rows, written by localnodes:export with secrets removed.\nSET autocommit=0;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $rows = $this->database->select('config', 'c')->fields('c', ['collection', 'name', 'data'])->orderBy('collection')->orderBy('name')->execute();
    foreach ($rows as $row) {
      $data = (string) $row->data;
      $value = @unserialize($data, ['allowed_classes' => FALSE]);
      if (is_array($value)) {
        $clean = $this->scrubConfig((string) $row->name, $value, $secrets);
        if ($clean !== $value) {
          $changed[] = ($row->collection !== '' ? $row->collection . ':' : '') . $row->name;
          $data = serialize($clean);
        }
      }
      // The rows go in as hex, which the final scan cannot read, so check
      // each one here (an unreadable row included).
      foreach ($secrets as $label => $secret) {
        if (str_contains($data, $secret)) {
          throw new \RuntimeException("Secret check failed: $label is still in config {$row->name} after the scrub. Nothing was written.");
        }
      }
      fwrite($handle, sprintf(
        "INSERT INTO `%s` (`collection`, `name`, `data`) VALUES (%s, %s, %s);\n",
        $table, self::sqlText((string) $row->collection), self::sqlText((string) $row->name), self::sqlBinary($data)
      ));
    }
    fwrite($handle, "COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\nSET autocommit=1;\n");
    return $changed;
  }

  /**
   * Takes the secrets out of one config object.
   *
   * - A key entity on the "config" provider loses its stored value.
   * - A URL or DSN with a password gets "placeholder" in its place.
   * - Any string holding a known secret value becomes empty.
   */
  public function scrubConfig(string $name, array $value, array $secrets): array {
    if (str_starts_with($name, 'key.key.') && ($value['key_provider'] ?? '') === 'config') {
      if (isset($value['key_provider_settings']['key_value']) && $value['key_provider_settings']['key_value'] !== '') {
        $value['key_provider_settings']['key_value'] = '';
      }
    }
    array_walk_recursive($value, function (&$item) use ($secrets): void {
      if (!is_string($item) || $item === '') {
        return;
      }
      $url = preg_replace_callback(
        '#^([a-z][a-z0-9+.-]*://[^:/@\s]+):([^@/\s]+)@#i',
        fn (array $m): string => $m[2] === 'placeholder' ? $m[0] : $m[1] . ':placeholder@',
        $item
      );
      if ($url !== NULL) {
        $item = $url;
      }
      foreach ($secrets as $secret) {
        if (str_contains($item, $secret)) {
          $item = '';
          return;
        }
      }
    });
    return $value;
  }

  /**
   * Looks for any known secret in one file.
   *
   * @return string|null
   *   The label of the first secret found (never the value), or NULL.
   */
  public function findSecretInFile(string $path, array $secrets): ?string {
    if (!$secrets) {
      return NULL;
    }
    $overlap = max(array_map('strlen', $secrets));
    $read = str_ends_with($path, '.gz') ? 'gzopen' : 'fopen';
    $handle = @$read($path, 'rb');
    if (!$handle) {
      throw new \RuntimeException('Cannot read a file of the archive to check it for secrets. The archive was deleted.');
    }
    $tail = '';
    try {
      while (!(str_ends_with($path, '.gz') ? gzeof($handle) : feof($handle))) {
        $chunk = str_ends_with($path, '.gz') ? gzread($handle, 1 << 20) : fread($handle, 1 << 20);
        if ($chunk === FALSE) {
          throw new \RuntimeException('Cannot read a file of the archive to check it for secrets. The archive was deleted.');
        }
        $window = $tail . $chunk;
        foreach ($secrets as $label => $secret) {
          if (str_contains($window, $secret)) {
            return $label;
          }
        }
        $tail = substr($window, -$overlap);
      }
    }
    finally {
      str_ends_with($path, '.gz') ? gzclose($handle) : fclose($handle);
    }
    return NULL;
  }

  /**
   * Looks for any known secret under a folder: names, link targets, contents.
   *
   * Folders are visited before what they hold, and a name is checked before
   * the file's contents, so a path in the answer never holds a secret.
   *
   * @return string|null
   *   "<label> in the name of a file or folder", "<label> in a link target",
   *   "<label> in <relative path>" for the first hit, or NULL.
   */
  public function findSecretInTree(string $dir, array $secrets): ?string {
    if (!is_dir($dir)) {
      return NULL;
    }
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
      $relative = substr($file->getPathname(), strlen($dir) + 1);
      // The whole relative path too: a secret holding "/" can span names.
      if (($label = $this->findSecretInText($file->getFilename(), $secrets)) || ($label = $this->findSecretInText($relative, $secrets))) {
        return $label . ' in the name of a file or folder';
      }
      if ($file->isLink()) {
        if ($label = $this->findSecretInText((string) readlink($file->getPathname()), $secrets)) {
          return $label . ' in a link target';
        }
        continue;
      }
      if ($file->isFile() && ($label = $this->findSecretInFile($file->getPathname(), $secrets))) {
        return $label . ' in ' . $relative;
      }
    }
    return NULL;
  }

  /**
   * Builds the archive.
   *
   * @param string $destination
   *   Folder for the archive (made if missing, mode 0700).
   * @param callable $dumper
   *   function (string $sqlFile, string[] $structureOnlyTables): void. Writes
   *   the database, with the config table and the listed tables as structure
   *   only, to $sqlFile. The Drush command passes drush sql:dump.
   * @param callable|null $library
   *   function (string $dir): array. Writes the library folder and returns
   *   its counts; NULL leaves the library out.
   *
   * @return array
   *   'path' => the archive's path, 'manifest' => the manifest.
   */
  public function export(string $destination, callable $dumper, ?callable $library = NULL): array {
    if ($why = $this->refuseDestination($destination)) {
      throw new \RuntimeException("Refusing to write the archive to $destination: $why.");
    }
    // Before anything is written: the secrets, and the site name that goes
    // into the folder name, manifest.json and RESTORE.md.
    $secrets = $this->knownSecrets();
    $forms = self::scanForms($secrets);
    $site_name = (string) $this->configFactory->get('system.site')->get('name');
    $slug = self::slug($site_name) ?: 'site';
    $slug_forms = array_filter(array_map([self::class, 'slug'], $secrets), fn (string $value): bool => strlen($value) >= self::MIN_SECRET_LENGTH);
    if (($label = $this->findSecretInText($site_name, $forms)) || ($label = $this->findSecretInText(Html::decodeEntities($site_name), $forms)) || ($label = $this->findSecretInText($slug, $slug_forms))) {
      throw new \RuntimeException("Secret check failed: $label is in the site's name. Change the name, then run the export again. Nothing was written.");
    }

    $old_umask = umask(0077);
    try {
      return $this->build($destination, $dumper, $library, $secrets, $forms, $site_name, $slug);
    }
    catch (ProcessFailedException $e) {
      // A tool the dumper ran: its stderr can quote a file name in an escaped
      // form no secret scan matches, so only the command and the exit code.
      throw self::toolFailed($e->getProcess()->getCommandLine(), (int) $e->getProcess()->getExitCode());
    }
    catch (\Throwable $e) {
      // Any other message can still quote a secret: take every known form
      // out before the message reaches a log or a terminal.
      $message = $e->getMessage();
      $clean = strtr($message, array_fill_keys(array_values($forms), '[secret]'));
      if ($clean !== $message) {
        throw new \RuntimeException($clean . ' (A secret was removed from this message.)', 0);
      }
      throw $e;
    }
    finally {
      umask($old_umask);
    }
  }

  /**
   * The body of export(), run under umask 077.
   */
  private function build(string $destination, callable $dumper, ?callable $library, array $secrets, array $forms, string $site_name, string $slug): array {
    if (!is_dir($destination) && !mkdir($destination, 0700, TRUE) && !is_dir($destination)) {
      throw new \RuntimeException("Cannot make $destination.");
    }
    $destination = realpath($destination);
    if ($why = $this->refuseDestination($destination)) {
      throw new \RuntimeException("Refusing to write the archive to $destination: $why.");
    }
    $created = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $name = 'localnodes-export-' . $slug . '-' . $created->format('Ymd-His');
    $archive = $destination . '/' . $name . '.tar';
    if (file_exists($archive)) {
      throw new \RuntimeException("$archive already exists; run the command again in a second.");
    }
    // Everything is built in a staging folder only this user can enter.
    $staging = $destination . '/.localnodes-export-' . bin2hex(random_bytes(8));
    if (!mkdir($staging, 0700) || !chmod($staging, 0700)) {
      throw new \RuntimeException("Cannot make a private staging folder in $destination.");
    }
    try {
      $work = $staging . '/' . $name;
      mkdir($work, 0700);
      $left_out = $this->leftOutTables();

      // Database: the dump, then the cleaned config rows, then gzip. The
      // dumper gets the tables' real names, with the database prefix.
      $prefix = $this->database->getPrefix();
      $sql = $work . '/database.sql';
      $dumper($sql, array_map(fn (string $table): string => $prefix . $table, array_merge($left_out, ['config'])));
      $handle = fopen($sql, 'ab');
      $scrubbed = $this->writeScrubbedConfig($handle, $secrets);
      fclose($handle);
      $this->run(['gzip', '-n', $sql]);

      // Files.
      $public = (string) $this->fileSystem->realpath('public://');
      $private = (string) $this->fileSystem->realpath('private://');
      $file_counts = [
        'public' => $this->packFiles($public, $work . '/public-files.tar.gz', self::PUBLIC_SKIP),
        'private' => $private !== '' ? $this->packFiles($private, $work . '/private-files.tar.gz', self::PRIVATE_SKIP, $destination) : 0,
      ];

      $library_counts = NULL;
      if ($library) {
        mkdir($work . '/library', 0700);
        $library_counts = $library($work . '/library');
      }

      // The gate, part one: no known secret in any part.
      foreach (['database.sql.gz', 'public-files.tar.gz', 'private-files.tar.gz'] as $part) {
        if (is_file("$work/$part") && ($label = $this->findSecretInPart("$work/$part", $forms))) {
          throw new \RuntimeException("Secret check failed: $label was found in $part. The archive was deleted. Remove that value from the site, then run the export again.");
        }
      }

      $manifest = $this->manifest($created, $site_name, $left_out, $scrubbed, $file_counts, $library_counts, $work);
      file_put_contents($work . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
      file_put_contents($work . '/RESTORE.md', $this->restoreText($manifest, $name));
      $sums = '';
      foreach ($manifest['parts'] as $part => $info) {
        $sums .= $info['sha256'] . '  ' . $part . "\n";
      }
      file_put_contents($work . '/SHA256SUMS', $sums);

      // The gate, part two: the library and the generated documents, by
      // name and contents.
      if ($library && ($label = $this->findSecretInTree($work . '/library', $forms))) {
        throw new \RuntimeException("Secret check failed: $label (library). The archive was deleted.");
      }
      foreach (['manifest.json', 'RESTORE.md', 'SHA256SUMS'] as $doc) {
        if ($label = $this->findSecretInFile("$work/$doc", $forms)) {
          throw new \RuntimeException("Secret check failed: $label was found in $doc. The archive was deleted.");
        }
      }

      $staged = $staging . '/' . $name . '.tar';
      $this->run(['tar', '-C', $staging, '-cf', $staged, $name]);
      if (!chmod($staged, 0600)) {
        throw new \RuntimeException('Cannot make the archive private (chmod 0600). The archive was deleted.');
      }
      if (file_exists($archive) || !rename($staged, $archive)) {
        throw new \RuntimeException("Cannot move the archive to $archive. The archive was deleted.");
      }
      return ['path' => $archive, 'manifest' => $manifest];
    }
    finally {
      $this->fileSystem->deleteRecursive($staging);
    }
  }

  /**
   * Scans one part: a gzipped dump directly, a files tarball by its contents.
   */
  private function findSecretInPart(string $path, array $secrets): ?string {
    if (str_ends_with($path, '.tar.gz')) {
      $tmp = $path . '.check';
      mkdir($tmp, 0700);
      try {
        $this->run(['tar', '-xzf', $path, '-C', $tmp]);
        return $this->findSecretInTree($tmp, $secrets);
      }
      finally {
        $this->fileSystem->deleteRecursive($tmp);
      }
    }
    return $this->findSecretInFile($path, $secrets);
  }

  /**
   * Packs a files folder, leaving out what Drupal makes again.
   *
   * @return int
   *   The number of files packed.
   */
  private function packFiles(string $dir, string $tarball, array $skip, ?string $also_skip = NULL): int {
    $args = ['tar', '-C', $dir, '-czf', $tarball];
    foreach ($skip as $folder) {
      $args[] = '--exclude=./' . $folder;
    }
    $real_skip = $also_skip ? (realpath($also_skip) ?: $also_skip) : NULL;
    if ($real_skip && str_starts_with($real_skip . '/', rtrim(realpath($dir) ?: $dir, '/') . '/')) {
      $args[] = '--exclude=./' . substr($real_skip, strlen(rtrim(realpath($dir) ?: $dir, '/')) + 1);
    }
    $args[] = '.';
    $this->run($args);
    $list = $this->run(['tar', '-tzf', $tarball]);
    return count(array_filter(explode("\n", $list->getOutput()), fn (string $line): bool => $line !== '' && !str_ends_with($line, '/')));
  }

  /**
   * The manifest: what is in the archive and what built the site.
   */
  private function manifest(\DateTimeImmutable $created, string $site_name, array $left_out, array $scrubbed, array $file_counts, ?array $library_counts, string $work): array {
    $commit = self::buildCommit();
    $parts = [];
    foreach (['database.sql.gz', 'public-files.tar.gz', 'private-files.tar.gz'] as $part) {
      if (is_file("$work/$part")) {
        $parts[$part] = ['bytes' => filesize("$work/$part"), 'sha256' => hash_file('sha256', "$work/$part")];
      }
    }
    if (is_dir("$work/library")) {
      $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$work/library", \FilesystemIterator::SKIP_DOTS));
      $paths = [];
      foreach ($files as $file) {
        if ($file->isFile()) {
          $paths[] = substr($file->getPathname(), strlen($work) + 1);
        }
      }
      sort($paths);
      foreach ($paths as $path) {
        $parts[$path] = ['bytes' => filesize("$work/$path"), 'sha256' => hash_file('sha256', "$work/$path")];
      }
    }
    $custom = [];
    foreach (['localnodes_platform', 'social_ai_indexing', 'localnodes_import', 'localnodes_web3', 'localnodes_openrouter', 'boulder_demo', 'portland_demo', 'localnodes_demo', 'siwe_login', 'safe_smart_accounts', 'group_treasury', 'safe_governance', 'gas_sponsor'] as $module) {
      if ($this->moduleHandler->moduleExists($module)) {
        $info = $this->moduleList->getExtensionInfo($module);
        $custom[$module] = $info['version'] ?? self::composerVersion('drupal/' . $module) ?? ($commit ? 'commit ' . substr($commit, 0, 7) : 'unknown');
      }
    }
    $demo_modules = array_values(array_filter(['localnodes_demo', 'boulder_demo', 'portland_demo', 'social_demo'], [$this->moduleHandler, 'moduleExists']));
    return [
      'format' => 'localnodes-export',
      'format_version' => self::FORMAT_VERSION,
      'created' => $created->format(\DateTimeInterface::ATOM),
      'site_name' => $site_name,
      'build' => [
        'commit' => $commit,
        'image' => 'ghcr.io/localnodes/os-knowledge-garden',
        'image_tag' => $commit ? substr($commit, 0, 7) : NULL,
      ],
      'versions' => [
        'drupal' => \Drupal::VERSION,
        'open_social' => self::composerVersion('goalgorilla/open_social'),
        'modules' => $custom,
      ],
      'settings_to_match' => [
        // Always none on a restore: a demo module name would let the image
        // load demo content into the restored site.
        'DEMO_MODULE' => 'none',
        'WEB3_ENABLED' => $this->moduleHandler->moduleExists('localnodes_web3') ? 'true' : 'false',
        'AI_PROVIDER' => $this->moduleHandler->moduleExists('localnodes_openrouter') ? 'openrouter' : 'gemini',
      ],
      'demo_modules_enabled' => $demo_modules,
      'install_complete_marker' => \Drupal::state()->get('localnodes.install_complete') !== NULL,
      'database' => [
        'left_out_tables' => $left_out,
        'left_out_note' => 'These tables keep their structure; their rows (caches, logs, sessions, flood control, locks, batches, temporary stores) are not included.',
        'config_scrubbed' => $scrubbed,
      ],
      'files' => [
        'public_count' => $file_counts['public'],
        'private_count' => $file_counts['private'],
        'public_left_out' => self::PUBLIC_SKIP,
        'private_left_out' => self::PRIVATE_SKIP,
      ],
      'library' => $library_counts,
      'not_included' => [
        'keys' => 'No API key, mail key, token or password from this server. Supply your own (RESTORE.md).',
        'solr_index' => 'Rebuilt by reindexing (RESTORE.md step 6).',
        'vector_store' => 'Rebuilt by reindexing once you set your own AI key.',
      ],
      'parts' => $parts,
    ];
  }

  /**
   * RESTORE.md, filled in for this archive.
   */
  private function restoreText(array $manifest, string $folder): string {
    $template = file_get_contents($this->moduleList->getPath('localnodes_platform') . '/export/RESTORE.md');
    $tag = $manifest['build']['image_tag'] ?? NULL;
    return strtr($template, [
      '{{site_name}}' => $manifest['site_name'],
      '{{created}}' => $manifest['created'],
      '{{folder}}' => $folder,
      '{{commit}}' => $manifest['build']['commit'] ?? 'unknown (see manifest.json)',
      '{{image_tag}}' => $tag ?? 'latest',
      '{{demo_module}}' => $manifest['settings_to_match']['DEMO_MODULE'],
      '{{web3_enabled}}' => $manifest['settings_to_match']['WEB3_ENABLED'],
      '{{ai_provider}}' => $manifest['settings_to_match']['AI_PROVIDER'],
    ]);
  }

  /**
   * The commit the running code was built from, or NULL.
   *
   * The image writes it to BUILD_COMMIT at build time (docker/Dockerfile); a
   * development checkout answers from git.
   */
  public static function buildCommit(): ?string {
    $root = dirname(DRUPAL_ROOT);
    $file = $root . '/BUILD_COMMIT';
    if (is_readable($file) && preg_match('/^[0-9a-f]{40}$/', $commit = trim((string) file_get_contents($file)))) {
      return $commit;
    }
    if (is_dir($root . '/.git') || is_file($root . '/.git')) {
      $git = new Process(['git', '-C', $root, 'rev-parse', 'HEAD']);
      $git->run();
      if ($git->isSuccessful() && preg_match('/^[0-9a-f]{40}$/', $commit = trim($git->getOutput()))) {
        return $commit;
      }
    }
    return NULL;
  }

  /**
   * A Composer package's installed version, or NULL.
   */
  private static function composerVersion(string $package): ?string {
    try {
      return \Composer\InstalledVersions::isInstalled($package) ? \Composer\InstalledVersions::getPrettyVersion($package) : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Runs a command and fails on a non-zero exit.
   *
   * The failure names the command and its exit code only. A tool's stderr is
   * never passed on: tar, for one, quotes file names with C escapes, so a
   * secret in a name reaches stderr in a form the secret scan does not match.
   */
  private function run(array $command): Process {
    $process = new Process($command);
    $process->setTimeout(3600);
    $process->run();
    if (!$process->isSuccessful()) {
      throw self::toolFailed($command[0], (int) $process->getExitCode());
    }
    return $process;
  }

  /**
   * The error for a failed tool: its program name and exit code, nothing else.
   *
   * @param string $command
   *   The program, or a whole command line whose first word is the program.
   */
  private static function toolFailed(string $command, int $code): \RuntimeException {
    $program = preg_match('/^\s*\'?([^\'\s]+)/', $command, $m) ? basename($m[1]) : 'A command';
    return new \RuntimeException(sprintf('%s failed with exit code %d. The archive was deleted.', $program, $code));
  }

  /**
   * A string as a SQL literal that no character set can change.
   */
  private static function sqlText(string $value): string {
    return $value === '' ? "''" : 'CONVERT(0x' . bin2hex($value) . ' USING utf8mb4)';
  }

  /**
   * Bytes as a SQL hex literal.
   */
  private static function sqlBinary(string $value): string {
    return $value === '' ? "''" : '0x' . bin2hex($value);
  }

}
