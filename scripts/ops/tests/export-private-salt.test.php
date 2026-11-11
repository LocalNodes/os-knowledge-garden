<?php

declare(strict_types=1);

// Real packer and scanner, owned filesystem fixtures, no Drupal database.
require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../../html/modules/custom/localnodes_platform/src/Export/SiteExporter.php';
require __DIR__ . '/../../../html/sites/default/hash-salt.php';

use Drupal\localnodes_platform\Export\SiteExporter;
use Symfony\Component\Process\Process;

$root = sys_get_temp_dir() . '/localnodes-export-salt-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/private', 0700);
$old = getenv('DRUPAL_HASH_SALT');
$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void {
  if (!$condition) { throw new RuntimeException('FAIL ' . $name); }
  $checks++;
  echo 'PASS ' . $name . PHP_EOL;
};
try {
  putenv('DRUPAL_HASH_SALT');
  $salt = localnodes_hash_salt($root . '/private', TRUE);
  file_put_contents($root . '/private/document.txt', 'community record');
  $exporter = (new ReflectionClass(SiteExporter::class))->newInstanceWithoutConstructor();
  $packer = new ReflectionMethod(SiteExporter::class, 'packFiles');
  $forms = SiteExporter::scanForms(['setting hash_salt' => $salt]);
  $archive = $root . '/private-files.tar.gz';
  $count = $packer->invoke($exporter, $root . '/private', $archive, SiteExporter::PRIVATE_SKIP);
  $listing = (new Process(['tar', '-tzf', $archive]))->mustRun()->getOutput();
  $check(!str_contains($listing, '.localnodes'), 'private archive excludes the salt directory');
  $check($count === 1 && str_contains($listing, 'document.txt'), 'ordinary private document remains');
  $check(in_array('.localnodes', SiteExporter::PRIVATE_SKIP, TRUE), 'manifest exclusion source names the directory');
  mkdir($root . '/unpacked', 0700);
  (new Process(['tar', '-xzf', $archive, '-C', $root . '/unpacked']))->mustRun();
  $check($exporter->findSecretInTree($root . '/unpacked', $forms) === NULL, 'clean private archive passes the unchanged scanner');
  file_put_contents($root . '/private/leaked-copy.txt', $salt);
  $packer->invoke($exporter, $root . '/private', $root . '/leaked.tar.gz', SiteExporter::PRIVATE_SKIP);
  mkdir($root . '/leaked', 0700);
  (new Process(['tar', '-xzf', $root . '/leaked.tar.gz', '-C', $root . '/leaked']))->mustRun();
  $check($exporter->findSecretInTree($root . '/leaked', $forms) === 'setting hash_salt in leaked-copy.txt', 'salt anywhere else still triggers the scanner');
  echo 'passed: ' . $checks . ' failed: 0' . PHP_EOL;
}
finally {
  putenv($old === FALSE ? 'DRUPAL_HASH_SALT' : 'DRUPAL_HASH_SALT=' . $old);
  (new Process(['rm', '-rf', '--', $root]))->mustRun();
}
