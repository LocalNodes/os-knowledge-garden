<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

use Drupal\social_ai_indexing\Citation\CitedAnswer;

/**
 * Judges one assistant answer against its expectation row.
 *
 * A row in a pack's assistant.yml asks one question as one member and says
 * what a good reply holds. This class holds only the rules, so a Unit test
 * can prove each one; the script that asks the assistant is
 * scripts/check_demo_pack.php.
 *
 * Rules, for every row:
 * - a reply that is one of the assistant's own trouble replies (search
 *   trouble, unavailable, fallback) fails with the reason "infrastructure",
 *   because nothing was judged;
 * - a reply that says a phrase from `must_not_say` fails;
 * - a reply that says a phrase from `must_not_claim` fails. These are claims
 *   no page makes (that the assistant can pay, say), so unlike `must_not_say`
 *   they are not drawn from a forbidden page;
 * - a reply that cites a page listed in `forbidden` fails.
 * A row with `refusal: true` passes only on one of the fixed no-answer
 * replies, citing nothing outside `may_cite`. With `may_answer: true` as
 * well, it also passes on a reply that cites at least one page and only
 * pages in `may_cite`. Any other row needs every page
 * in `cites` among the cited pages and every phrase in `must_say` in the
 * reply body. A `must_say` entry may be a list of wordings of one fact: the
 * first is the source's own wording (the one the provenance checks find in
 * the cited passage) and the others are wordings the assistant was seen to
 * use; any one of them counts. The body is the reply above its last Sources heading, so a page
 * title in the Sources list never counts as something the answer said.
 * Text is compared as PackText::normalize() folds it, ignoring case.
 *
 * No Drupal calls, so Unit tests use it directly.
 */
final class PackAnswerCheck {

  /**
   * The assistant's replies that mean no answer was produced at all.
   */
  private const INFRASTRUCTURE = [
    CitedAnswer::SEARCH_TROUBLE,
    CitedAnswer::UNAVAILABLE,
    CitedAnswer::FALLBACK,
  ];

  /**
   * The assistant's replies that say it found nothing to answer from.
   */
  private const NO_ANSWER = [
    CitedAnswer::NO_ANSWER_GROUP,
    CitedAnswer::NO_ANSWER_SITE,
    CitedAnswer::NO_SOURCED_ANSWER_GROUP,
    CitedAnswer::NO_SOURCED_ANSWER_SITE,
  ];

  /**
   * Whether an answer meets its row.
   *
   * @param string $answer
   *   The assistant's reply text.
   * @param array<int, array{uuid: string, title: string}> $sources
   *   The pages the reply cites.
   * @param array $row
   *   The expectation row.
   *
   * @return array{pass: bool, reasons: string[]}
   *   The verdict and, on a fail, why. A reason is a fixed code: a phrase
   *   is named by its index in the row (must_not_say[2], must_say[0]
   *   missing) and a page by its uuid, never by text from the row or the
   *   reply, because reasons are printed.
   */
  public static function judge(string $answer, array $sources, array $row): array {
    $text = self::fold($answer);
    if ($text === '') {
      return self::verdict(['the reply is empty']);
    }
    if (in_array($text, array_map([self::class, 'fold'], self::INFRASTRUCTURE), TRUE)) {
      return self::verdict(['infrastructure']);
    }
    $reasons = [];
    $cited = array_map(static fn (array $source): string => (string) $source['uuid'], $sources);
    // The Sources link text is encoded for markdown ("_" is "&#95;"), so a
    // phrase that must not be shown is looked for in the shown text too.
    $shown = self::fold(CitedAnswer::displayText($answer));

    foreach (array_values($row['must_not_say'] ?? []) as $index => $phrase) {
      if (self::says($text, (string) $phrase) || self::says($shown, (string) $phrase)) {
        $reasons[] = "must_not_say[$index]";
      }
    }
    foreach (array_values($row['must_not_claim'] ?? []) as $index => $phrase) {
      if (self::says($text, (string) $phrase) || self::says($shown, (string) $phrase)) {
        $reasons[] = "must_not_claim[$index]";
      }
    }
    foreach ($row['forbidden'] ?? [] as $uuid) {
      if (in_array((string) $uuid, $cited, TRUE)) {
        $reasons[] = "cites a forbidden page: $uuid";
      }
    }

    if (!empty($row['refusal'])) {
      if (!in_array($text, array_map([self::class, 'fold'], self::NO_ANSWER), TRUE)) {
        if (empty($row['may_answer'])) {
          $reasons[] = 'is not a no-answer reply';
        }
        elseif ($cited === []) {
          $reasons[] = 'answers without citing a page';
        }
      }
      $allowed = array_map('strval', $row['may_cite'] ?? []);
      foreach ($cited as $uuid) {
        if (!in_array($uuid, $allowed, TRUE)) {
          $reasons[] = "cites a page a refusal may not cite: $uuid";
        }
      }
      return self::verdict($reasons);
    }

    if (in_array($text, array_map([self::class, 'fold'], self::NO_ANSWER), TRUE)) {
      $reasons[] = 'refuses a question it should answer';
    }
    foreach ($row['cites'] ?? [] as $uuid) {
      if (!in_array((string) $uuid, $cited, TRUE)) {
        $reasons[] = "cite missing: $uuid";
      }
    }
    $bodyText = self::fold(self::body($answer));
    foreach (array_values($row['must_say'] ?? []) as $index => $phrase) {
      $said = FALSE;
      foreach (self::wordings($phrase) as $wording) {
        $said = $said || self::says($bodyText, $wording);
      }
      if (!$said) {
        $reasons[] = "must_say[$index] missing";
      }
    }
    return self::verdict($reasons);
  }

  /**
   * The reply without its Sources list.
   *
   * Cut at the last Sources heading line: the assistant appends that heading
   * and the list after the body, so an earlier line that quotes the heading
   * stays in the body.
   */
  public static function body(string $answer): string {
    $heading = "\n" . CitedAnswer::SOURCES_HEADING . "\n";
    $position = strrpos("\n" . $answer, $heading);
    if ($position === FALSE) {
      return $answer;
    }
    return substr($answer, 0, max(0, $position - 1));
  }

  /**
   * Text as the comparison reads it: typography folded, lower case.
   */
  private static function fold(string $text): string {
    return mb_strtolower(PackText::normalize($text));
  }

  /**
   * The wordings of a must_say entry: a string, or a list of strings.
   *
   * @return string[]
   *   The wordings, in order; anything that is not a string is dropped.
   */
  public static function wordings(mixed $phrase): array {
    $list = is_array($phrase) ? array_values($phrase) : [$phrase];
    return array_values(array_map('strval', array_filter($list, 'is_string')));
  }

  /**
   * The source's own wording of a must_say entry: its first wording.
   *
   * Empty when the entry holds no string, so a provenance check fails it.
   */
  public static function sourceWording(mixed $phrase): string {
    return self::wordings($phrase)[0] ?? '';
  }

  /**
   * Whether the folded reply holds a phrase.
   */
  private static function says(string $text, string $phrase): bool {
    $needle = self::fold($phrase);
    return $needle !== '' && str_contains($text, $needle);
  }

  /**
   * The verdict for a list of reasons.
   *
   * @param string[] $reasons
   *   Why the reply fails; none when it passes.
   *
   * @return array{pass: bool, reasons: string[]}
   *   The verdict.
   */
  private static function verdict(array $reasons): array {
    return ['pass' => $reasons === [], 'reasons' => $reasons];
  }

}
