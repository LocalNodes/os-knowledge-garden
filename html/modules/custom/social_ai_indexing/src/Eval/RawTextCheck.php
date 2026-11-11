<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Eval;

use Drupal\social_ai_indexing\Citation\CitedAnswer;

/**
 * E2's content check: an answer must not be a tool output or the question.
 *
 * The eval's E2 used to look at the reply's role and at the rewritten flag.
 * Both can be wrong when contrib code changes a message in place (the Gemini
 * provider relabelled a tool result as a user message, and RQ-05 came back
 * as the raw search output on gemini-3.8-flash). So E2 also compares content:
 * the answer against what the run's tools returned and what was asked.
 *
 * Pure and static, codes only: a finding never carries the answer, the tool
 * output or the question, so nothing sensitive can reach a log or a record.
 * Eval tooling only: nothing on a live request path calls it.
 */
final class RawTextCheck {

  /**
   * Tool outputs and questions shorter than this (normalised) are ignored.
   *
   * A tool output of "none" inside an ordinary answer is not a leak.
   */
  private const MIN_LENGTH = 12;

  /**
   * Finds the ways an answer repeats what it was built from.
   *
   * @param string $answer
   *   The answer text the asker would see.
   * @param string[] $toolOutputs
   *   The readable outputs of the run's tool calls.
   * @param string[] $questions
   *   The questions asked in the run.
   *
   * @return string[]
   *   Finding codes, deduplicated, in a fixed order: answer_is_tool_output,
   *   answer_contains_tool_output, answer_is_question. Empty when clean.
   */
  public static function findings(string $answer, array $toolOutputs, array $questions): array {
    $normalised = self::normalise($answer);
    foreach (CitedAnswer::FIXED_REPLIES as $copy) {
      if ($normalised === self::normalise($copy)) {
        return [];
      }
    }

    $found = [];
    foreach ($toolOutputs as $output) {
      $output = self::normalise($output);
      if (mb_strlen($output) < self::MIN_LENGTH) {
        continue;
      }
      if ($normalised === $output) {
        $found['answer_is_tool_output'] = TRUE;
      }
      elseif (str_contains($normalised, $output)) {
        $found['answer_contains_tool_output'] = TRUE;
      }
    }
    foreach ($questions as $question) {
      $question = self::normalise($question);
      if (mb_strlen($question) >= self::MIN_LENGTH && $normalised === $question) {
        $found['answer_is_question'] = TRUE;
      }
    }

    $order = ['answer_is_tool_output', 'answer_contains_tool_output', 'answer_is_question'];
    return array_values(array_filter($order, static fn(string $code): bool => isset($found[$code])));
  }

  /**
   * Drops markup, lower-cases, collapses whitespace runs and trims.
   *
   * HTML tags and the Markdown emphasis and code marks (* _ ` ~) are removed
   * from both sides, so emphasis around an echoed tool output cannot hide it.
   * Only tag-shaped text goes: the search tool's <<<SOURCE_TEXT fences stay.
   * Spacing around punctuation is evened out on both sides, so a tag beside a
   * full stop (or a stray space before one) cannot hide an echo either.
   */
  private static function normalise(string $text): string {
    $text = (string) preg_replace('#</?[a-z][a-z0-9]*(?:\s[^<>]*)?/?>#iu', ' ', $text);
    $text = str_replace(['*', '_', '`', '~'], '', $text);
    $text = (string) preg_replace('/\s+/u', ' ', mb_strtolower($text));
    // A removed tag leaves a space; drop spaces before closing punctuation and
    // after opening brackets so the rendered-identical text compares equal.
    $text = (string) preg_replace('/ (?=[.,;:!?)\]])|(?<=[(\[]) /u', '', $text);
    return trim($text);
  }

}
