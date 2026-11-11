<?php

declare(strict_types=1);

// Pure PHP/filesystem test. No Drupal bootstrap, Docker or real site storage.
require_once __DIR__ . '/../../../html/sites/default/hash-salt.php';

$root = sys_get_temp_dir() . '/localnodes-salt-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$old = getenv('DRUPAL_HASH_SALT');
$passed = 0;
function check(bool $condition, string $name): void {
  global $passed;
  if (!$condition) {
    throw new RuntimeException('FAIL ' . $name);
  }
  $passed++;
  echo 'PASS ' . $name . PHP_EOL;
}
function refuses(callable $read): bool {
  try { $read(); }
  catch (RuntimeException $e) { return TRUE; }
  return FALSE;
}
try {
  putenv('DRUPAL_HASH_SALT');
  check(refuses(fn() => localnodes_hash_salt($root)), 'missing salt fails without creating one');
  ob_start();
  $first = localnodes_hash_salt($root, TRUE);
  $output = ob_get_clean();
  $file = $root . '/.localnodes/hash-salt';
  check(preg_match('/^[a-f0-9]{64}$/D', $first) === 1, 'safe random salt made');
  check($output === '', 'salt never printed');
  check((fileperms($file) & 0777) === 0600, 'salt mode 600');
  check((fileperms(dirname($file)) & 0777) === 0700, 'secret directory mode 700');
  $bytes = file_get_contents($file);
  check(localnodes_hash_salt($root, TRUE) === $first && file_get_contents($file) === $bytes, 'second start keeps the first salt');
  check(localnodes_hash_salt($root) === $first, 'exec without environment reads the saved salt');
  putenv('DRUPAL_HASH_SALT=generate-random');
  check(localnodes_hash_salt($root) === $first, 'compose sentinel uses the saved salt');
  putenv('DRUPAL_HASH_SALT=explicit-test-salt');
  check(localnodes_hash_salt($root, TRUE) === 'explicit-test-salt' && file_get_contents($file) === $bytes, 'explicit variable wins without changing storage');
  check(localnodes_hash_salt($root . '/unused', TRUE) === 'explicit-test-salt' && !file_exists($root . '/unused'), 'explicit variable needs no file');
  putenv('DRUPAL_HASH_SALT=');
  unlink($file);
  file_put_contents($file, '');
  check(refuses(fn() => localnodes_hash_salt($root)), 'empty file fails closed');
  file_put_contents($file, 'change-me-in-production');
  check(refuses(fn() => localnodes_hash_salt($root)), 'constant file fails closed');
  unlink($file);
  symlink($root . '/missing', $file);
  check(refuses(fn() => localnodes_hash_salt($root, TRUE)), 'symlink is refused');
  echo 'passed: ' . $passed . ' failed: 0' . PHP_EOL;
}
finally {
  putenv($old === FALSE ? 'DRUPAL_HASH_SALT' : 'DRUPAL_HASH_SALT=' . $old);
  if (is_link($root . '/.localnodes/hash-salt') || file_exists($root . '/.localnodes/hash-salt')) { unlink($root . '/.localnodes/hash-salt'); }
  if (is_dir($root . '/.localnodes')) { rmdir($root . '/.localnodes'); }
  rmdir($root);
}
