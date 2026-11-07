<?php

declare(strict_types=1);

namespace Drupal\localnodes_import;

use Drupal\localnodes_import\Entity\ImportItem;

/**
 * What became of one imported source.
 *
 * Returned by the factories instead of throwing, so a batch operation always
 * has an outcome to write to the item row.
 */
final class ImportOutcome {

  /**
   * Builds an outcome. Use the named constructors.
   *
   * @param string $code
   *   One of the ImportItem::OUTCOME_* codes.
   * @param string|null $reason
   *   A machine reason code (no_text, unreadable, ...), or NULL.
   * @param int|null $nid
   *   The Topic the source became, or NULL when none was created.
   */
  private function __construct(
    public readonly string $code,
    public readonly ?string $reason = NULL,
    public readonly ?int $nid = NULL,
  ) {}

  /**
   * A Topic was created and its text can be searched.
   */
  public static function imported(int $nid): self {
    return new self(ImportItem::OUTCOME_IMPORTED, NULL, $nid);
  }

  /**
   * A Topic was created, but no searchable text came out of the source.
   */
  public static function notSearchable(int $nid, string $reason): self {
    return new self(ImportItem::OUTCOME_NOT_SEARCHABLE, $reason, $nid);
  }

  /**
   * The source is already in the group as this Topic.
   *
   * No reason code: the outcome itself is the "Already here" message, and
   * the nid links the existing Topic (22-04 manifest).
   */
  public static function duplicate(int $nid): self {
    return new self(ImportItem::OUTCOME_DUPLICATE, NULL, $nid);
  }

  /**
   * The source couldn't be imported.
   */
  public static function failed(string $reason): self {
    return new self(ImportItem::OUTCOME_FAILED, $reason);
  }

  /**
   * The source was refused before any work (type, size, address).
   */
  public static function refused(string $reason): self {
    return new self(ImportItem::OUTCOME_REFUSED, $reason);
  }

}
