<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\localnodes_platform\Export\SiteExporter;
use Symfony\Component\Process\Process;

/**
 * drush localnodes:export's archive: secrets, manifest and left-out tables.
 *
 * 22.2 export and restore job. The archive goes to a community's organizers,
 * so no key of ours may be in it. The test seeds secrets the ways a site can
 * hold one (a key on the "config" provider, a password in a DSN, an
 * environment value) and checks every part of the archive for them, the
 * config rows included after decoding their hex literals.
 *
 * The files live on real disk (not vfs), because the exporter packs them
 * with tar. The dumper is a fake that writes SQL text, so the test runs on
 * SQLite as well as MySQL.
 *
 * @group localnodes_platform
 * @group phase22
 */
final class SiteExportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'key', 'localnodes_platform'];

  /**
   * The DSN test config has no schema.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  private const KEY_SECRET = 'seeded-config-key-value-7f3a91c2';
  private const DSN_SECRET = 'seeded-dsn-password-55e0b1';
  private const ENV_SECRET = 'seeded-env-gemini-key-a8d4e6f0c2';

  /**
   * Real folder holding public, private and exports.
   */
  private string $base;

  /**
   * Permissions the fake dumper saw: its file, the work and staging folders.
   *
   * @var int[]
   */
  private array $dumpPerms = [];

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $this->base = sys_get_temp_dir() . '/ln-export-test-' . bin2hex(random_bytes(6));
    mkdir($this->base . '/public', 0775, TRUE);
    mkdir($this->base . '/private', 0775, TRUE);
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
    $this->config('system.site')->set('name', 'Juniper Test House')->save();

    file_put_contents($this->base . '/public/picture.txt', 'a public picture');
    mkdir($this->base . '/public/styles/thumb', 0775, TRUE);
    file_put_contents($this->base . '/public/styles/thumb/picture.txt', 'derivative');
    mkdir($this->base . '/private/localnodes_import/12', 0775, TRUE);
    file_put_contents($this->base . '/private/localnodes_import/12/01-bylaws.txt', 'bylaws text');

    // A cache table and an ordinary table, for the left-out list.
    $spec = ['fields' => ['id' => ['type' => 'int', 'not null' => TRUE]], 'primary key' => ['id']];
    $this->container->get('database')->schema()->createTable('cache_exporttest', $spec);
    $this->container->get('database')->schema()->createTable('watchdog', $spec);
    $this->container->get('database')->schema()->createTable('exporttest_content', $spec);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('GEMINI_API_KEY');
    putenv('DRUPAL_ADMIN_PASS');
    Database::removeConnection('exporttest');
    if (isset($this->base) && is_dir($this->base)) {
      (new Process(['rm', '-rf', $this->base]))->run();
    }
    parent::tearDown();
  }

  /**
   * The exporter service.
   */
  private function exporter(): SiteExporter {
    return $this->container->get('localnodes_platform.site_exporter');
  }

  /**
   * Seeds a key on the config provider and a password in a DSN.
   */
  private function seedConfigSecrets(): void {
    Key::create([
      'id' => 'seeded_key',
      'label' => 'Seeded',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => self::KEY_SECRET],
    ])->save();
    $this->config('localnodes_exporttest.mail')
      ->set('dsn', 'smtps://resend:' . self::DSN_SECRET . '@smtp.example.org:465')
      ->set('note', 'ordinary text stays')
      ->save();
  }

  /**
   * A fake dumper: SQL text for the ordinary tables, with an optional extra.
   *
   * @param array $seen
   *   Gets the structure-only list the exporter passed.
   */
  private function dumper(array &$seen, string $extra = ''): callable {
    return function (string $file, array $structure_only) use (&$seen, $extra): void {
      $seen = $structure_only;
      file_put_contents($file, "CREATE TABLE `exporttest_content` (`id` int);\nINSERT INTO `exporttest_content` VALUES (1);\n" . $extra);
      clearstatcache();
      $this->dumpPerms = [
        'file' => fileperms($file) & 0777,
        'work' => fileperms(dirname($file)) & 0777,
        'staging' => fileperms(dirname($file, 2)) & 0777,
      ];
    };
  }

  /**
   * Unpacks an archive and returns the export folder.
   */
  private function unpack(string $archive): string {
    $out = $this->base . '/unpacked-' . bin2hex(random_bytes(3));
    mkdir($out);
    (new Process(['tar', '-xf', $archive, '-C', $out]))->mustRun();
    $folders = glob($out . '/localnodes-export-*', GLOB_ONLYDIR);
    $this->assertCount(1, $folders);
    return $folders[0];
  }

  /**
   * Every byte of the archive's text, with hex literals decoded too.
   */
  private function everything(string $folder): string {
    $text = gzdecode(file_get_contents($folder . '/database.sql.gz'));
    preg_match_all('/0x([0-9a-f]+)/', $text, $matches);
    foreach ($matches[1] as $hex) {
      $text .= "\n" . hex2bin($hex);
    }
    foreach (['public-files.tar.gz', 'private-files.tar.gz'] as $part) {
      $dir = $folder . '/' . $part . '.x';
      mkdir($dir);
      (new Process(['tar', '-xzf', $folder . '/' . $part, '-C', $dir]))->mustRun();
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        $text .= "\n" . file_get_contents($file->getPathname());
      }
    }
    foreach (['manifest.json', 'RESTORE.md', 'SHA256SUMS'] as $file) {
      $text .= "\n" . file_get_contents($folder . '/' . $file);
    }
    return $text;
  }

  /**
   * Seeded secrets are absent from every part, and the scrub is recorded.
   */
  public function testSeededSecretsAreAbsent(): void {
    $this->seedConfigSecrets();
    putenv('GEMINI_API_KEY=' . self::ENV_SECRET);
    // The secrets are really on the site.
    $this->assertSame(self::KEY_SECRET, Key::load('seeded_key')->getKeyValue());
    $secrets = $this->exporter()->knownSecrets();
    $this->assertContains(self::KEY_SECRET, $secrets);
    $this->assertContains(self::ENV_SECRET, $secrets);

    // An existing destination open to everyone, and a permissive umask: the
    // parts are still private from the moment they are written.
    mkdir($this->base . '/exports', 0777);
    chmod($this->base . '/exports', 0777);
    $umask = umask(0022);
    $seen = [];
    try {
      $result = $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
      $this->assertSame(0022, umask(), 'The umask is restored.');
    }
    finally {
      umask($umask);
    }
    $this->assertSame(['file' => 0600, 'work' => 0700, 'staging' => 0700], $this->dumpPerms);
    $this->assertSame([basename($result['path'])], array_values(array_diff(scandir($this->base . '/exports'), ['.', '..'])), 'Only the archive is left; no staging folder.');
    $folder = $this->unpack($result['path']);
    $all = $this->everything($folder);

    $this->assertStringContainsString('ordinary text stays', $all, 'The decoded config rows are in the scan.');
    $this->assertStringContainsString('smtps://resend:placeholder@smtp.example.org:465', $all);
    foreach ([self::KEY_SECRET, self::DSN_SECRET, self::ENV_SECRET] as $secret) {
      $this->assertStringNotContainsString($secret, $all);
    }
    $scrubbed = $result['manifest']['database']['config_scrubbed'];
    $this->assertContains('key.key.seeded_key', $scrubbed);
    $this->assertContains('localnodes_exporttest.mail', $scrubbed);

    // The site itself still has its key: only the archive is cleaned.
    $this->assertSame(self::KEY_SECRET, Key::load('seeded_key')->getKeyValue());
    $this->assertSame(0600, fileperms($result['path']) & 0777);
  }

  /**
   * A secret that reaches the dump or the files fails the run, archive gone.
   */
  public function testGateDeletesArchiveOnHit(): void {
    putenv('GEMINI_API_KEY=' . self::ENV_SECRET);
    $seen = [];
    try {
      $this->exporter()->export($this->base . '/exports', $this->dumper($seen, "INSERT INTO `key_value` VALUES ('state','x','" . self::ENV_SECRET . "');\n"));
      $this->fail('A secret in the dump must fail the export.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('env GEMINI_API_KEY was found in database.sql.gz', $e->getMessage());
      $this->assertStringNotContainsString(self::ENV_SECRET, $e->getMessage());
    }
    $this->assertSame([], glob($this->base . '/exports/*'));

    file_put_contents($this->base . '/private/leak.txt', 'key=' . self::ENV_SECRET);
    try {
      $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
      $this->fail('A secret in the private files must fail the export.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('in private-files.tar.gz', $e->getMessage());
    }
    $this->assertSame([], glob($this->base . '/exports/*'));
  }

  /**
   * The manifest's contents, the left-out tables and the parts' checksums.
   */
  public function testManifestAndLeftOutTables(): void {
    $seen = [];
    $result = $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
    $manifest = $result['manifest'];
    $folder = $this->unpack($result['path']);

    // Left-out tables: listed, and passed to the dumper as structure only,
    // with config (written separately, scrubbed).
    $left_out = $manifest['database']['left_out_tables'];
    $this->assertContains('cache_exporttest', $left_out);
    $this->assertContains('watchdog', $left_out);
    $this->assertNotContains('exporttest_content', $left_out);
    $this->assertNotContains('config', $left_out);
    // The dumper gets the real table names, with the database prefix.
    $prefix = $this->container->get('database')->getPrefix();
    $this->assertNotSame('', $prefix);
    $this->assertEqualsCanonicalizing(array_map(fn (string $table): string => $prefix . $table, array_merge($left_out, ['config'])), $seen);
    $sql = gzdecode(file_get_contents($folder . '/database.sql.gz'));
    $this->assertStringContainsString('INSERT INTO `exporttest_content`', $sql);
    // On SQLite the test prefix ends with a dot, so the table name may hold one.
    $this->assertMatchesRegularExpression('/INSERT INTO `[a-z0-9_.]*config` .*VALUES/', $sql);
    $this->assertStringEndsWith("COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\nSET autocommit=1;\n", $sql);

    // The manifest names what the job asks for.
    $this->assertSame('localnodes-export', $manifest['format']);
    $this->assertSame('Juniper Test House', $manifest['site_name']);
    $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $manifest['created']));
    $this->assertSame(\Drupal::VERSION, $manifest['versions']['drupal']);
    $this->assertArrayHasKey('open_social', $manifest['versions']);
    $this->assertArrayHasKey('localnodes_platform', $manifest['versions']['modules']);
    $this->assertArrayHasKey('commit', $manifest['build']);
    $this->assertSame('none', $manifest['settings_to_match']['DEMO_MODULE']);
    $this->assertSame(1, $manifest['files']['public_count'], 'Image derivatives are left out.');
    $this->assertSame(1, $manifest['files']['private_count']);
    $this->assertArrayHasKey('solr_index', $manifest['not_included']);
    $this->assertArrayHasKey('vector_store', $manifest['not_included']);
    $this->assertSame($manifest, json_decode(file_get_contents($folder . '/manifest.json'), TRUE));

    // A checksum per part, matching the files and SHA256SUMS.
    $this->assertEqualsCanonicalizing(['database.sql.gz', 'public-files.tar.gz', 'private-files.tar.gz'], array_keys($manifest['parts']));
    foreach ($manifest['parts'] as $part => $info) {
      $this->assertSame(hash_file('sha256', "$folder/$part"), $info['sha256']);
      $this->assertStringContainsString($info['sha256'] . '  ' . $part, file_get_contents("$folder/SHA256SUMS"));
    }

    // RESTORE.md is filled in, says keys are not included, never config:import.
    $restore = file_get_contents($folder . '/RESTORE.md');
    $this->assertStringNotContainsString('{{', $restore);
    $this->assertStringContainsString('Juniper Test House', $restore);
    $this->assertStringContainsString('No keys or passwords from our server', $restore);
    $this->assertStringContainsString('docs/SELF-HOST.md', $restore);
    $this->assertStringContainsString('Never run `drush config:import`', $restore);
    $this->assertStringNotContainsString('drush cim', $restore);
  }

  /**
   * A secret in a dump's escaped or hex form, a file name or a link target.
   */
  public function testEncodedSecretsAndNamesAreCaught(): void {
    $seen = [];
    $quoted = "seeded-env'quoted\\key-a8d4e6";
    putenv('GEMINI_API_KEY=' . $quoted);
    $forms = SiteExporter::scanForms($this->exporter()->knownSecrets());
    $this->assertContains("seeded-env\\'quoted\\\\key-a8d4e6", $forms);

    $cases = [
      'SQL-escaped' => [$this->dumper($seen, "INSERT INTO `x` VALUES ('seeded-env\\'quoted\\\\key-a8d4e6');\n"), 'env GEMINI_API_KEY (SQL-escaped) was found in database.sql.gz'],
      'hex' => [$this->dumper($seen, 'INSERT INTO `x` VALUES (0x00' . strtoupper(bin2hex($quoted)) . ");\n"), 'env GEMINI_API_KEY (HEX) was found in database.sql.gz'],
    ];
    foreach ($cases as $case => [$dumper, $expected]) {
      try {
        $this->exporter()->export($this->base . '/exports', $dumper);
        $this->fail("A secret in $case form must fail the export.");
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString($expected, $e->getMessage());
        $this->assertStringNotContainsString('a8d4e6', $e->getMessage());
      }
      $this->assertSame([], glob($this->base . '/exports/{,.}[!.]*', GLOB_BRACE), "Nothing is left after the $case hit.");
    }

    putenv('GEMINI_API_KEY=' . self::ENV_SECRET);
    // A file named with the secret, then a folder (its file's own name clean).
    $named = $this->base . '/private/notes-' . self::ENV_SECRET . '.txt';
    file_put_contents($named, 'ordinary');
    $folder = $this->base . '/private/folder-' . self::ENV_SECRET;
    foreach ([[$named, NULL], [$folder, $folder . '/a.txt']] as [$path, $inner]) {
      if ($inner) {
        mkdir($path);
        file_put_contents($inner, 'ordinary');
      }
      try {
        $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
        $this->fail('A secret in a file or folder name must fail the export.');
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString('env GEMINI_API_KEY in the name of a file or folder was found in private-files.tar.gz', $e->getMessage());
        $this->assertStringNotContainsString(self::ENV_SECRET, $e->getMessage());
      }
      $inner ? (new Process(['rm', '-rf', $path]))->mustRun() : unlink($path);
    }

    symlink('/nowhere/' . self::ENV_SECRET, $this->base . '/private/link');
    try {
      $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
      $this->fail('A secret in a link target must fail the export.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('env GEMINI_API_KEY in a link target was found in private-files.tar.gz', $e->getMessage());
      $this->assertStringNotContainsString(self::ENV_SECRET, $e->getMessage());
    }
    $this->assertSame([], glob($this->base . '/exports/{,.}[!.]*', GLOB_BRACE));
    unlink($this->base . '/private/link');

    // A secret holding "/" that spans a folder name and a file name.
    putenv('GEMINI_API_KEY=seeded-dir/env-key-a8d4e6f0');
    mkdir($this->base . '/private/x-seeded-dir');
    file_put_contents($this->base . '/private/x-seeded-dir/env-key-a8d4e6f0.txt', 'ordinary');
    try {
      $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
      $this->fail('A secret spanning a path must fail the export.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('env GEMINI_API_KEY in the name of a file or folder was found in private-files.tar.gz', $e->getMessage());
      $this->assertStringNotContainsString('a8d4e6f0', $e->getMessage());
    }
    (new Process(['rm', '-rf', $this->base . '/private/x-seeded-dir']))->mustRun();

    // A failing tool whose message quotes the secret: the message is cleaned.
    putenv('GEMINI_API_KEY=' . self::ENV_SECRET);
    $failing = function (): void {
      throw new \RuntimeException('tar: ./notes-' . self::ENV_SECRET . '.txt: Cannot open');
    };
    try {
      $this->exporter()->export($this->base . '/exports', $failing);
      $this->fail('The failing dumper must fail the export.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('tar: ./notes-[secret].txt: Cannot open', $e->getMessage());
      $this->assertStringNotContainsString(self::ENV_SECRET, $e->getMessage());
    }
    $this->assertSame([], glob($this->base . '/exports/{,.}[!.]*', GLOB_BRACE));
  }

  /**
   * A failed tool is reported by command and exit code, never by its stderr.
   *
   * tar quotes a file name with C escapes, so a secret holding a backslash
   * reaches stderr as "\\" and slips past the [secret] replacement. The
   * message must hold nothing from stderr at all.
   */
  public function testFailedToolShowsNoStderr(): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
      $this->markTestSkipped('root reads an unreadable file, so tar does not fail.');
    }
    $secret = 'seeded-env\\key-a8d4e6f0c2';
    putenv('GEMINI_API_KEY=' . $secret);
    $file = $this->base . '/private/notes-' . $secret . '.txt';
    file_put_contents($file, 'ordinary');
    chmod($file, 0000);
    $seen = [];
    try {
      $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
      $this->fail('An unreadable file must fail the export.');
    }
    catch (\RuntimeException $e) {
      $message = $e->getMessage();
      $this->assertMatchesRegularExpression('/^tar failed with exit code [1-9]\d*\. The archive was deleted\.$/', $message);
      $this->assertStringNotContainsString('a8d4e6f0', $message);
      $this->assertStringNotContainsString('Cannot open', $message);
      $this->assertStringNotContainsString('notes-', $message);
    }
    finally {
      chmod($file, 0600);
    }
    $this->assertSame([], glob($this->base . '/exports/{,.}[!.]*', GLOB_BRACE));
    unlink($file);

    // The dumper's tool (drush sql:dump in the command) fails the same way.
    $failing = function () use ($secret): void {
      (new Process(['sh', '-c', 'printf "%s\n" "$1" >&2; exit 3', 'sh', 'dump of ' . $secret]))->mustRun();
    };
    try {
      $this->exporter()->export($this->base . '/exports', $failing);
      $this->fail('A failing dump tool must fail the export.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('sh failed with exit code 3. The archive was deleted.', $e->getMessage());
    }
    $this->assertSame([], glob($this->base . '/exports/{,.}[!.]*', GLOB_BRACE));
  }

  /**
   * A secret in the site name refuses the run before anything is written.
   */
  public function testSecretInSiteNameRefused(): void {
    $seen = [];
    putenv('GEMINI_API_KEY=' . self::ENV_SECRET);
    foreach (['House ' . self::ENV_SECRET, 'House ' . strtoupper(self::ENV_SECRET)] as $site_name) {
      $this->config('system.site')->set('name', $site_name)->save();
      try {
        $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
        $this->fail('A secret in the site name must fail the export.');
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString("env GEMINI_API_KEY is in the site's name", $e->getMessage());
        $this->assertStringNotContainsString('a8d4e6f0c2', strtolower($e->getMessage()));
      }
      $this->assertDirectoryDoesNotExist($this->base . '/exports');
    }
  }

  /**
   * Every nonempty secret is kept; one too short to scan for refuses the run.
   */
  public function testSecretInventory(): void {
    $seen = [];
    putenv('DRUPAL_ADMIN_PASS=seeded-admin-pass-31c9');
    Database::addConnectionInfo('exporttest', 'default', [
      'driver' => 'mysql',
      'database' => 'exporttest',
      'username' => 'exporttest',
      'password' => 'seeded-db-password-0b9c7e1d',
      'host' => 'localhost',
    ]);
    $secrets = $this->exporter()->knownSecrets();
    $this->assertSame('seeded-admin-pass-31c9', $secrets['env DRUPAL_ADMIN_PASS']);
    $this->assertSame('seeded-db-password-0b9c7e1d', $secrets['database exporttest.default password']);

    // The published defaults are no secret.
    putenv('DRUPAL_ADMIN_PASS=admin');
    $this->assertArrayNotHasKey('env DRUPAL_ADMIN_PASS', $this->exporter()->knownSecrets());

    putenv('GEMINI_API_KEY=short-7k');
    try {
      $this->exporter()->export($this->base . '/exports', $this->dumper($seen));
      $this->fail('A secret too short to scan for must refuse the export.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('env GEMINI_API_KEY is shorter than 12 characters', $e->getMessage());
      $this->assertStringNotContainsString('short-7k', $e->getMessage());
    }
    $this->assertDirectoryDoesNotExist($this->base . '/exports');
  }

  /**
   * Places the web server could serve, or the archive would pack, are refused.
   */
  public function testDestinationRefused(): void {
    $exporter = $this->exporter();
    $this->assertNotNull($exporter->refuseDestination('exports'));
    $this->assertNotNull($exporter->refuseDestination(DRUPAL_ROOT . '/sites/default/exports'));
    $this->assertNotNull($exporter->refuseDestination($this->base . '/public/exports'));
    $this->assertNotNull($exporter->refuseDestination($this->base . '/private'));
    $this->assertNotNull($exporter->refuseDestination($this->base . '/x/../public/y'));
    $this->assertNull($exporter->refuseDestination($this->base . '/exports'));
    $this->assertStringEndsWith('/exports', $exporter->defaultDestination());
    $this->assertNotNull($exporter->refuseDestination($this->base . '/x/../public/y'));
  }

}
