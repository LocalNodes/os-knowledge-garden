<?php

/**
 * @file
 * Measures how the installed extractors read every import fixture.
 *
 * Plain PHP, no Drupal bootstrap. For each manifest.yml row it extracts text
 * with the same libraries ai_file_to_text uses (smalot/pdfparser for PDF,
 * PhpWord IOFactory with the Word2007, MsDoc and ODText readers) and prints
 * one line per file:
 *
 *   file=... length=... phrase=found|missing title=... ai_file_to_text=...
 *
 * - phrase: found when the manifest phrase is in the text. Rows whose
 *   manifest phrase is null (scan, encrypted, truncated, spreadsheet) report
 *   found when any "fixture-phrase heron-NN" string came out at all, which
 *   would mean the fixture leaks text it should not.
 * - title: smalot getDetails()['Title'] for PDFs, PhpWord
 *   getDocInfo()->getTitle() for Word and ODT (D-16 metadata title).
 * - ai_file_to_text: the same check through the contrib module's own
 *   extractor classes (PdfExtractor, WordExtractor, OdtExtractor), which have
 *   no Drupal dependencies. E7 is judged against these.
 *
 * PHP notices and warnings go to stderr so stdout stays one line per file.
 *
 * Run (from the repository root, in the DDEV webserver image):
 *   docker run --rm -v "$(git rev-parse --show-toplevel)":/var/www/html \
 *     -w /var/www/html --entrypoint "" \
 *     ddev/ddev-webserver:v1.24.6-fresh3-built php8.3 \
 *     html/modules/custom/localnodes_import/tests/fixtures/import/probe_extraction.php
 */

declare(strict_types=1);

use Drupal\ai_file_to_text\Extractor\OdtExtractor;
use Drupal\ai_file_to_text\Extractor\PdfExtractor;
use Drupal\ai_file_to_text\Extractor\WordExtractor;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;
use Symfony\Component\Yaml\Yaml;

$repo_root = dirname(__DIR__, 7);
$loader = require $repo_root . '/vendor/autoload.php';
$loader->addPsr4('Drupal\\ai_file_to_text\\', $repo_root . '/html/modules/contrib/ai_file_to_text/src');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
  fwrite(STDERR, sprintf("php-notice: %s (%s:%d)\n", $message, basename($file), $line));
  return TRUE;
});

/**
 * Collects the text of a PhpWord element tree.
 */
function probe_collect_text(iterable $elements): string {
  $text = '';
  foreach ($elements as $element) {
    if (method_exists($element, 'getRows')) {
      foreach ($element->getRows() as $row) {
        foreach ($row->getCells() as $cell) {
          $text .= probe_collect_text($cell->getElements()) . ' ';
        }
      }
      continue;
    }
    if (method_exists($element, 'getElements')) {
      $text .= probe_collect_text($element->getElements()) . "\n";
      continue;
    }
    if (method_exists($element, 'getText')) {
      $value = $element->getText();
      if (is_string($value)) {
        $text .= $value . ' ';
      }
      elseif (is_object($value) && method_exists($value, 'getElements')) {
        $text .= probe_collect_text($value->getElements()) . ' ';
      }
    }
  }
  return $text;
}

/**
 * Reports whether the expected phrase (or any fixture phrase) is present.
 */
function probe_phrase(string $text, ?string $phrase): string {
  if ($phrase !== NULL) {
    return str_contains($text, $phrase) ? 'found' : 'missing';
  }
  return preg_match('/fixture-phrase heron-\d\d/', $text) ? 'found' : 'missing';
}

/**
 * Flattens a value for a one-line report field.
 */
function probe_flat(?string $value): string {
  if ($value === NULL || $value === '') {
    return '-';
  }
  return '"' . str_replace(["\r", "\n", '"'], [' ', ' ', "'"], mb_substr($value, 0, 120)) . '"';
}

$manifest = Yaml::parseFile(__DIR__ . '/manifest.yml');
$readers = ['docx' => 'Word2007', 'doc' => 'MsDoc', 'odt' => 'ODText'];

foreach ($manifest['files'] as $row) {
  $path = __DIR__ . '/files/' . $row['file'];
  $kind = $row['kind'];
  $phrase = $row['phrase'] ?? NULL;
  $text = '';
  $title = NULL;
  $error = NULL;

  // Library-level extraction (what ai_file_to_text calls underneath).
  try {
    if ($kind === 'pdf') {
      $pdf = (new Parser())->parseFile($path);
      $text = trim($pdf->getText());
      $details = $pdf->getDetails();
      $title = isset($details['Title']) ? (string) $details['Title'] : NULL;
    }
    elseif (isset($readers[$kind])) {
      $doc = IOFactory::load($path, $readers[$kind]);
      foreach ($doc->getSections() as $section) {
        $text .= probe_collect_text($section->getElements());
      }
      $text = trim($text);
      $title = $doc->getDocInfo()->getTitle();
    }
    else {
      $error = 'skipped: no extractor for .' . $kind;
    }
  }
  catch (\Throwable $e) {
    $error = get_class($e) . ': ' . $e->getMessage();
    $text = '';
  }

  // The contrib module's own extractor classes.
  $contrib = '-';
  try {
    $extractor = match ($kind) {
      'pdf' => new PdfExtractor(),
      'docx', 'doc' => new WordExtractor(),
      'odt' => new OdtExtractor(),
      default => NULL,
    };
    if ($extractor !== NULL) {
      $contrib_text = (string) $extractor->extractText($path, $kind);
      $contrib = probe_phrase($contrib_text, $phrase) . '/' . mb_strlen(trim($contrib_text));
    }
  }
  catch (\Throwable $e) {
    $contrib = 'error(' . (new \ReflectionClass($e))->getShortName() . ')';
  }

  printf(
    "file=%s length=%d phrase=%s title=%s ai_file_to_text=%s expect=%s%s\n",
    $row['file'],
    mb_strlen($text),
    probe_phrase($text, $phrase),
    probe_flat($title),
    $contrib,
    $row['expect'],
    $error !== NULL ? ' error=' . probe_flat($error) : '',
  );
}
