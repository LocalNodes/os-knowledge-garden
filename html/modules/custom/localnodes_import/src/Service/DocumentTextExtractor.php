<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\ai_file_to_text\Extractor\FileExtractorManager;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Opens a document once and returns its text and its own title (D-16).
 *
 * RESEARCH Pattern 4:
 * - PDF: one smalot parse with a memory-safe config (no image content kept,
 *   a cap on each decompressed stream) gives both the text and the Title
 *   detail. ai_file_to_text's own PDF path uses a default Parser with no
 *   such limits, so PDFs don't go through it here.
 * - DOCX and ODT: the title is read from docProps/core.xml or meta.xml
 *   through ZipArchive (cheap, no second parse); the text comes from
 *   ai_file_to_text.extractor_manager.
 * - DOC: text through the extractor manager (PhpWord MsDoc). MsDoc loads the
 *   SummaryInformation stream but never parses it into DocInfo, so a .doc
 *   has no metadata title and the file name is used.
 * - TXT and MD: the file's contents, no metadata title.
 *
 * Every parse runs under a local error handler: PhpWord's MsDoc reader
 * raises tens of thousands of warnings per .doc (22-04), which are counted
 * and logged once. Any \Throwable becomes the 'unreadable' failure. Neither
 * the text nor parser messages (which can quote file bytes) are logged.
 */
class DocumentTextExtractor {

  /**
   * File types the importer reads. Anything else is refused unparsed.
   */
  public const SUPPORTED_EXTENSIONS = ['pdf', 'docx', 'doc', 'odt', 'txt', 'md'];

  /**
   * Fewer characters than this and a document counts as having no text.
   */
  public const MIN_TEXT_LENGTH = 50;

  /**
   * Cap, in bytes, on one decompressed PDF stream (Pitfall 3, T-22-32).
   */
  public const PDF_DECODE_MEMORY_LIMIT = 32 * 1024 * 1024;

  /**
   * Longest metadata part read from an Office zip (a title fits easily).
   */
  protected const META_PART_MAX_BYTES = 65536;

  /**
   * Dublin Core, the namespace of dc:title in DOCX and ODT metadata.
   */
  protected const DC_NAMESPACE = 'http://purl.org/dc/elements/1.1/';

  public function __construct(
    protected FileSystemInterface $fileSystem,
    protected FileExtractorManager $extractorManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether the importer reads files with this extension.
   */
  public function supports(string $extension): bool {
    return in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS, TRUE);
  }

  /**
   * The text and metadata title of a file, from one parse. Never throws.
   */
  public function extract(FileInterface $file): ExtractedDocument {
    $extension = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
    if (!$this->supports($extension)) {
      return ExtractedDocument::failed(ExtractedDocument::FAILURE_UNSUPPORTED);
    }
    $path = $this->fileSystem->realpath($file->getFileUri());
    if ($path === FALSE || $path === '' || !is_file($path) || !is_readable($path)) {
      return ExtractedDocument::failed(ExtractedDocument::FAILURE_UNREADABLE);
    }

    $warnings = 0;
    set_error_handler(static function () use (&$warnings): bool {
      $warnings++;
      return TRUE;
    }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);
    try {
      return match ($extension) {
        'pdf' => $this->extractPdf($path),
        'docx' => $this->extractOffice($path, $extension, 'docProps/core.xml'),
        'odt' => $this->extractOffice($path, $extension, 'meta.xml'),
        'doc' => new ExtractedDocument($this->managerText($path, $extension)),
        default => new ExtractedDocument($this->plainText($path)),
      };
    }
    catch (\Throwable $e) {
      $this->logger->notice('File @fid: the @ext file could not be read (@class).', [
        '@fid' => $file->id(),
        '@ext' => $extension,
        '@class' => get_class($e),
      ]);
      return ExtractedDocument::failed(ExtractedDocument::FAILURE_UNREADABLE);
    }
    finally {
      restore_error_handler();
      if ($warnings > 0) {
        $this->logger->info('File @fid: the @ext parser raised @count PHP warnings.', [
          '@fid' => $file->id(),
          '@ext' => $extension,
          '@count' => $warnings,
        ]);
      }
    }
  }

  /**
   * Text length after collapsing whitespace: the "not searchable" measure.
   */
  public static function textLength(string $text): int {
    return mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $text)));
  }

  /**
   * One smalot parse: text, Title detail and whether it looks scanned.
   */
  protected function extractPdf(string $path): ExtractedDocument {
    $config = new Config();
    $config->setRetainImageContent(FALSE);
    $config->setDecodeMemoryLimit(self::PDF_DECODE_MEMORY_LIMIT);
    $pdf = (new Parser([], $config))->parseFile($path);

    $text = trim($pdf->getText());
    $details = $pdf->getDetails();
    $pages = count($pdf->getPages());
    return new ExtractedDocument(
      $text,
      $this->cleanMetadataTitle($details['Title'] ?? NULL),
      NULL,
      $pages > 0 && self::textLength($text) < self::MIN_TEXT_LENGTH,
    );
  }

  /**
   * DOCX or ODT: dc:title from the zip's metadata part, text from the manager.
   */
  protected function extractOffice(string $path, string $extension, string $meta_part): ExtractedDocument {
    $title = NULL;
    $zip = new \ZipArchive();
    if ($zip->open($path, \ZipArchive::RDONLY) === TRUE) {
      try {
        $xml = $zip->getFromName($meta_part, self::META_PART_MAX_BYTES);
        if (is_string($xml) && $xml !== '') {
          $title = $this->dublinCoreTitle($xml);
        }
      }
      finally {
        $zip->close();
      }
    }
    return new ExtractedDocument($this->managerText($path, $extension), $title);
  }

  /**
   * Text through ai_file_to_text's extractor for this extension.
   */
  protected function managerText(string $path, string $extension): string {
    return trim((string) $this->extractorManager->extract($path, $extension, 'text'));
  }

  /**
   * A plain-text file's contents, with invalid UTF-8 replaced.
   */
  protected function plainText(string $path): string {
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException('The file could not be read.');
    }
    return trim(mb_scrub($contents, 'UTF-8'));
  }

  /**
   * The first dc:title in a metadata XML part, or NULL.
   */
  protected function dublinCoreTitle(string $xml): ?string {
    $dom = new \DOMDocument();
    // No network access; entities are not substituted (libxml default).
    if (!$dom->loadXML($xml, LIBXML_NONET)) {
      return NULL;
    }
    $nodes = $dom->getElementsByTagNameNS(self::DC_NAMESPACE, 'title');
    return $nodes->length > 0 ? $this->cleanMetadataTitle($nodes->item(0)->textContent) : NULL;
  }

  /**
   * A metadata title as one line of display text, or NULL when empty.
   */
  protected function cleanMetadataTitle(mixed $title): ?string {
    if (is_array($title)) {
      $title = reset($title);
    }
    if (!is_string($title)) {
      return NULL;
    }
    $title = mb_scrub($title, 'UTF-8');
    $title = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title);
    $title = trim((string) preg_replace('/\s+/u', ' ', $title));
    return $title === '' ? NULL : mb_substr($title, 0, 255);
  }

}
