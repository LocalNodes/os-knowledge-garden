<?php

/**
 * @file
 * Currency check of a pack's redacted source copy, run on a development site
 * that holds the working files.
 *
 * Usage: php check_pack_source.php <pack> <pack-source-file> <walk-file>
 *
 * The PHPUnit tests cannot read the working files a pack is written from, so
 * this script is what notices a change to them. It takes the paths as
 * arguments. It reads tests/fixtures/expect/<pack>/walk.yml, which records
 * the sha256 of the pack source (source_sha256), the sha256 of the walk
 * section (walk_section_sha256, from the line "## <walk_heading>" up to, not
 * including, the next line that is exactly "---"; the section's lines joined
 * by a newline, with one newline at the end) and the redactions: a map of
 * line number to the line that replaces the source's line in the copy.
 *
 * It checks both hashes, applies the redactions to the source and compares
 * the result with tests/fixtures/expect/<pack>/source.md line by line.
 *
 * It prints only OK, or FAIL and the reason: "source hash", "walk hash",
 * "line count", "line <N>" (the first line that differs), "walk.yml does not
 * parse (line <N>)" or "walk.yml shape". It never prints text from any of
 * the files. The exit status is 0 for OK and 1 for FAIL.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$autoload = NULL;
for ($directory = __DIR__; $directory !== dirname($directory); $directory = dirname($directory)) {
  if (is_file($directory . '/vendor/autoload.php')) {
    $autoload = $directory . '/vendor/autoload.php';
    break;
  }
}
if ($autoload === NULL) {
  fwrite(STDERR, "FAIL: no vendor/autoload.php above this script\n");
  exit(2);
}
require $autoload;

if ($argc !== 4) {
  fwrite(STDERR, "usage: php check_pack_source.php <pack> <pack-source-file> <walk-file>\n");
  exit(2);
}
[, $pack, $source_path, $walk_path] = $argv;
$module = dirname(__DIR__);
$walk_yml = $module . "/tests/fixtures/expect/$pack/walk.yml";
$copy_path = $module . "/tests/fixtures/expect/$pack/source.md";
foreach ([$walk_yml, $copy_path, $source_path, $walk_path] as $path) {
  if (!is_file($path)) {
    echo "FAIL: a file is missing\n";
    exit(1);
  }
}

// A parse error's message quotes the offending line, so only its line
// number is printed.
try {
  $record = Yaml::parseFile($walk_yml);
}
catch (\Throwable $e) {
  $line = $e instanceof ParseException ? $e->getParsedLine() : 0;
  echo "FAIL: walk.yml does not parse" . ($line > 0 ? " (line $line)" : '') . "\n";
  exit(1);
}
if (!is_array($record) || !is_array($record['redactions'] ?? [])) {
  echo "FAIL: walk.yml shape\n";
  exit(1);
}
foreach (['source_sha256', 'walk_heading', 'walk_section_sha256'] as $key) {
  if (!is_string($record[$key] ?? NULL)) {
    echo "FAIL: walk.yml shape\n";
    exit(1);
  }
}
foreach (($record['redactions'] ?? []) as $replacement) {
  if (!is_scalar($replacement)) {
    echo "FAIL: walk.yml shape\n";
    exit(1);
  }
}
$source = (string) file_get_contents($source_path);
if (hash('sha256', $source) !== (string) ($record['source_sha256'] ?? '')) {
  echo "FAIL: source hash\n";
  exit(1);
}

$walk_lines = explode("\n", (string) file_get_contents($walk_path));
$heading = '## ' . (string) ($record['walk_heading'] ?? '');
$start = array_search($heading, $walk_lines, TRUE);
$end = NULL;
if ($start !== FALSE) {
  for ($index = $start + 1; $index < count($walk_lines); $index++) {
    if ($walk_lines[$index] === '---') {
      $end = $index;
      break;
    }
  }
}
if ($start === FALSE || $end === NULL) {
  echo "FAIL: walk hash\n";
  exit(1);
}
$section = implode("\n", array_slice($walk_lines, $start, $end - $start)) . "\n";
if (hash('sha256', $section) !== (string) ($record['walk_section_sha256'] ?? '')) {
  echo "FAIL: walk hash\n";
  exit(1);
}

$expected = explode("\n", $source);
foreach (($record['redactions'] ?? []) as $number => $replacement) {
  if (!isset($expected[(int) $number - 1])) {
    echo "FAIL: line " . (int) $number . "\n";
    exit(1);
  }
  $expected[(int) $number - 1] = (string) $replacement;
}
$copy = explode("\n", (string) file_get_contents($copy_path));
if (count($copy) !== count($expected)) {
  echo "FAIL: line count\n";
  exit(1);
}
foreach ($expected as $index => $line) {
  if ($copy[$index] !== $line) {
    echo "FAIL: line " . ($index + 1) . "\n";
    exit(1);
  }
}
echo "OK\n";
exit(0);
