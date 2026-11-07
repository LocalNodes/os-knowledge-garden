<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Controller;

use Drupal\localnodes_import\Entity\ImportItem;

/**
 * The breakdown lines of a run: counts by outcome, in the copy-table order.
 *
 * Shared by the S4 report and the S1 Past imports list (D-21), so both say
 * the same thing about a run ("18 imported", "1 refreshed from its
 * source"...). The class using it provides t().
 */
trait RunBreakdownTrait {

  /**
   * The non-zero breakdown lines for counts by outcome code.
   *
   * @param array<string, int> $counts
   *   Items per outcome code; missing codes count as zero.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   One line per non-zero outcome group.
   */
  protected function breakdownLines(array $counts): array {
    $count = static fn(string ...$outcomes): int => array_sum(array_map(static fn(string $outcome): int => (int) ($counts[$outcome] ?? 0), $outcomes));
    $imported = $count(ImportItem::OUTCOME_IMPORTED);
    $not_searchable = $count(ImportItem::OUTCOME_NOT_SEARCHABLE);
    $duplicate = $count(ImportItem::OUTCOME_DUPLICATE);
    $failed = $count(ImportItem::OUTCOME_FAILED, ImportItem::OUTCOME_REFUSED);
    $not_uploaded = $count(ImportItem::OUTCOME_NOT_UPLOADED);
    $refreshed = $count(ImportItem::OUTCOME_REFRESHED);
    $lines = [
      [$imported, $this->t('@count imported', ['@count' => $imported])],
      [$not_searchable, $this->t('@count imported, not searchable', ['@count' => $not_searchable])],
      [$duplicate, $this->t('@count already in this group', ['@count' => $duplicate])],
      [$failed, $this->t("@count couldn't be imported", ['@count' => $failed])],
      [$not_uploaded, $this->t('@count not uploaded', ['@count' => $not_uploaded])],
      [$refreshed, $this->t('@count refreshed from its source', ['@count' => $refreshed])],
    ];
    $breakdown = [];
    foreach ($lines as [$number, $line]) {
      if ($number > 0) {
        $breakdown[] = $line;
      }
    }
    return $breakdown;
  }

}
