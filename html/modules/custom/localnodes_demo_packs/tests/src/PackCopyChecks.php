<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs;

use Drupal\localnodes_demo_packs\PackAnswerCheck;
use Drupal\localnodes_demo_packs\PackText;

/**
 * The copy rules of a pack, as plain functions that return problems.
 *
 * A source file and an expected answer are members-only text, so no message
 * here holds a phrase, a passage or an excerpt of one. Each message names the
 * pack, the row, passage or page, and the position of the phrase in its list.
 *
 * No Drupal calls.
 */
final class PackCopyChecks {

  /**
   * Passages that are not in the source word for word near their source line.
   *
   * @param array<int, array> $passages
   *   The rows of passages.yml.
   * @param string $raw
   *   The source file as written.
   *
   * @return string[]
   *   One message for each fault.
   */
  public static function passageProblems(string $pack, array $passages, string $raw): array {
    $problems = [];
    $source = PackText::markdownBlocks($raw);
    $lines = explode("\n", $raw);
    foreach ($passages as $passage) {
      $id = (string) ($passage['id'] ?? '?');
      $text = PackText::normalize(PackText::untoken((string) ($passage['text'] ?? '')));
      if ($text === '') {
        $problems[] = "Pack $pack, passage $id has no text.";
        continue;
      }
      if (!PackText::inBlocks($text, $source)) {
        $problems[] = "Pack $pack, passage $id is not in source.md word for word, inside one block.";
      }
      $line = (int) ($passage['source_line'] ?? 0);
      if ($line < 1) {
        $problems[] = "Pack $pack, passage $id does not name its source line.";
        continue;
      }
      if ($line > count($lines)) {
        $problems[] = "Pack $pack, passage $id: source_line is not inside source.md.";
        continue;
      }
      $near = PackText::markdownBlocks(implode("\n", array_slice($lines, $line - 1, 20)));
      if (!PackText::inBlocks($text, $near)) {
        $problems[] = "Pack $pack, passage $id is not within 20 lines of its source line.";
      }
    }
    return $problems;
  }

  /**
   * Left-out sentences that are not in the source, or are in the pack's YAML.
   *
   * @param array<int, array> $rows
   *   The left_out rows of expect.yml.
   * @param string $raw
   *   The source file as written.
   * @param string $content
   *   The pack's loaded text, normalized and lower-cased.
   *
   * @return string[]
   *   One message for each fault.
   */
  public static function leftOutProblems(string $pack, array $rows, string $raw, string $content): array {
    $problems = [];
    $source = PackText::markdownBlocks($raw);
    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '?');
      $text = mb_strtolower(PackText::normalize((string) ($row['text'] ?? '')));
      if ($text === '') {
        $problems[] = "Pack $pack, left-out row $id has no text.";
        continue;
      }
      if (!PackText::inBlocks($text, $source, TRUE)) {
        $problems[] = "Pack $pack, left-out row $id is not in source.md, inside one block.";
      }
      if (str_contains($content, $text)) {
        $problems[] = "Pack $pack, left-out row $id is in the pack's YAML.";
      }
    }
    return $problems;
  }

  /**
   * Phrases an assistant row must say that are not in the source.
   *
   * @param array<int, array> $rows
   *   The rows of assistant.yml.
   * @param string[] $source
   *   The source's blocks (PackText::markdownBlocks()).
   *
   * @return string[]
   *   One message for each phrase that is missing, by its position (#1 is the
   *   first) in the row's must_say list.
   */
  public static function mustSayProblems(string $pack, array $rows, array $source): array {
    $problems = [];
    foreach ($rows as $row) {
      $id = (string) ($row['id'] ?? '?');
      foreach (array_values($row['must_say'] ?? []) as $index => $phrase) {
        if (!PackText::inBlocks(PackText::untoken(PackAnswerCheck::sourceWording($phrase)), $source, TRUE)) {
          $problems[] = "Pack $pack, row $id: must_say phrase #" . ($index + 1) . ' is not in source.md, inside one block.';
        }
      }
    }
    return $problems;
  }

  /**
   * Blocks of a how-to page that are not in Part 1 of the source.
   *
   * @param string[] $blocks
   *   The page's body blocks, in order.
   * @param string[] $part_one
   *   Part 1's blocks.
   *
   * @return string[]
   *   One message for each block, by its position (#1 is the first).
   */
  public static function howtoBlockProblems(string $uuid, array $blocks, array $part_one): array {
    $problems = [];
    foreach (array_values($blocks) as $index => $block) {
      if (!PackText::inBlocks((string) $block, $part_one)) {
        $problems[] = "How-to page $uuid: block #" . ($index + 1) . ' is not in Part 1 word for word, inside one block.';
      }
    }
    return $problems;
  }

  /**
   * Withheld phrases that are not in the source, or are in the how-to text.
   *
   * @param string[] $phrases
   *   The phrases, in order.
   * @param string[] $source
   *   The full source's blocks.
   * @param string $content
   *   The how-to file's text, normalized and lower-cased.
   *
   * @return string[]
   *   One message for each fault, by position (#1 is the first).
   */
  public static function withheldProblems(array $phrases, array $source, string $content): array {
    $problems = [];
    foreach (array_values($phrases) as $index => $phrase) {
      $n = $index + 1;
      $text = mb_strtolower(PackText::normalize((string) $phrase));
      if ($text === '') {
        $problems[] = "Withheld phrase #$n is empty.";
        continue;
      }
      if (!PackText::inBlocks($text, $source, TRUE)) {
        $problems[] = "Withheld phrase #$n is not in the source, inside one block.";
      }
      if (str_contains($content, $text)) {
        $problems[] = "Withheld phrase #$n is in the how-to file.";
      }
    }
    return $problems;
  }

}
