<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs;

use Drupal\localnodes_demo_packs\PackText;

/**
 * Where the phrases of an assistant row come from, and where they must not be.
 *
 * Two rules, kept apart. Provenance: a phrase a reply must not say is held by
 * a page the row forbids. The left-out sentence rule: a phrase may instead be
 * held by a sentence the pack never loads (expect.yml left_out). Exclusivity:
 * whichever way it is accepted, the phrase is on no page the row allows or
 * cites, or a correct, cited answer would trip the leak detector. A page is
 * read whole (title and body), not by its recorded passages.
 *
 * No Drupal calls, and no message holds a phrase.
 */
final class PackProvenanceChecks {

  /**
   * The normalized, lower-cased text of a phrase or passage.
   */
  private static function fold(string $text): string {
    return mb_strtolower(PackText::normalize(PackText::untoken($text)));
  }

  /**
   * The folded prose of every page, by uuid: its title, body and the like.
   *
   * Every string under a prose key (PackChecks::PROSE_KEYS), at any depth, is
   * stripped of its markup, joined by its blocks and folded as a phrase is.
   *
   * @param array<string, array> $files
   *   Parsed files by file name (the pack's, and the shared how-to file).
   *
   * @return array<string, string[]>
   *   The folded strings of each entry, by the entry's uuid.
   */
  public static function pageTexts(array $files): array {
    $pages = [];
    foreach ($files as $file) {
      foreach (PackChecks::entries($file) as [$uuid, $entry]) {
        foreach (PackChecks::stringsOf($entry) as $string) {
          if (!in_array($string['key'], PackChecks::PROSE_KEYS, TRUE)) {
            continue;
          }
          $text = self::fold(implode(' ', PackText::blocks(PackText::untoken($string['value']))));
          if ($text !== '') {
            $pages[$uuid][] = $text;
          }
        }
      }
    }
    return $pages;
  }

  /**
   * Whether a left-out sentence of the pack holds the phrase.
   *
   * @param string $phrase
   *   The phrase, already folded.
   * @param array<int, array> $left_out
   *   The left_out rows of expect.yml.
   */
  public static function heldByLeftOut(string $phrase, array $left_out): bool {
    foreach ($left_out as $row) {
      if (str_contains(self::fold((string) ($row['text'] ?? '')), $phrase)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether any passage of the pages holds the phrase.
   *
   * @param string[] $uuids
   *   Page uuids.
   * @param array<string, string[]> $passages
   *   Folded passage texts by page uuid.
   */
  private static function heldByPages(string $phrase, array $uuids, array $passages): bool {
    foreach ($uuids as $uuid) {
      foreach ($passages[(string) $uuid] ?? [] as $text) {
        if (str_contains($text, $phrase)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Problems with the must_not_say phrases of assistant rows.
   *
   * @param array<int, array> $rows
   *   The rows of assistant.yml.
   * @param array<int, array> $passages
   *   The rows of passages.yml (uuid, text).
   * @param array<int, array> $left_out
   *   The left_out rows of expect.yml.
   * @param array<string, string[]> $pages
   *   The whole pages, folded, by uuid (pageTexts()).
   *
   * @return string[]
   *   One message for each phrase that fails, and for each allowed uuid that
   *   is no page, by its position in the row's list (#1 is the first).
   */
  public static function mustNotSayProblems(string $pack, array $rows, array $passages, array $left_out, array $pages): array {
    $by_page = [];
    foreach ($passages as $passage) {
      $by_page[(string) ($passage['uuid'] ?? '')][] = self::fold((string) ($passage['text'] ?? ''));
    }
    $problems = [];
    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '?');
      $allowed = array_merge($row['cites'] ?? [], $row['may_cite'] ?? []);
      foreach (array_values($allowed) as $position => $uuid) {
        if (!isset($pages[(string) $uuid])) {
          $problems[] = "Pack $pack, row $id: allowed page #" . ($position + 1) . ' (cites, then may_cite) is no page of the pack or the how-to file.';
        }
      }
      foreach (array_values($row['must_not_say'] ?? []) as $index => $phrase) {
        $n = $index + 1;
        $phrase = self::fold((string) $phrase);
        if ($phrase === '') {
          $problems[] = "Pack $pack, row $id: must_not_say phrase #$n is empty.";
          continue;
        }
        if (!self::heldByPages($phrase, $row['forbidden'] ?? [], $by_page) && !self::heldByLeftOut($phrase, $left_out)) {
          $problems[] = "Pack $pack, row $id: must_not_say phrase #$n is in no forbidden page's passage and no left-out sentence.";
        }
        if (self::heldByPages($phrase, $allowed, $by_page) || self::heldByPages($phrase, $allowed, $pages)) {
          $problems[] = "Pack $pack, row $id: must_not_say phrase #$n is also on a page the row allows or cites (a passage, or the page's title or body); an allowed answer would trip it.";
        }
      }
    }
    return $problems;
  }

}
