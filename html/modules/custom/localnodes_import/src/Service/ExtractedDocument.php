<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

/**
 * What one parse of a document gave: its text, its own title, or a failure.
 *
 * Returned by DocumentTextExtractor::extract(), which never throws.
 */
final readonly class ExtractedDocument {

  /**
   * The file could not be opened or parsed (encrypted, damaged, hostile).
   */
  public const FAILURE_UNREADABLE = 'unreadable';

  /**
   * The file type is not one the importer reads; no parser saw it.
   */
  public const FAILURE_UNSUPPORTED = 'unsupported_type';

  /**
   * Builds the result. Use failed() for a failure.
   *
   * @param string $text
   *   The plain text ('' when there is none, or on failure).
   * @param string|null $title
   *   The document's own metadata title (PDF Title, DOCX/ODT dc:title),
   *   cleaned to one line, or NULL when it has none (D-16).
   * @param string|null $failure
   *   NULL, or one of the FAILURE_* codes.
   * @param bool $looksScanned
   *   TRUE for a PDF that has pages but (almost) no text layer.
   */
  public function __construct(
    public string $text,
    public ?string $title = NULL,
    public ?string $failure = NULL,
    public bool $looksScanned = FALSE,
  ) {}

  /**
   * A document nothing could be read from.
   */
  public static function failed(string $failure): self {
    return new self('', NULL, $failure, FALSE);
  }

}
