<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Usage;

use Drupal\ai\OperationType\OutputInterface;

/**
 * Token counts over the chat calls behind one answer (22-29, cost metric).
 *
 * The answer_summary log line carries these numbers, so the health report
 * can work out tokens per answer and a monthly cost (AI-SPEC §7). Counts
 * only: nothing here reads or keeps message text (E10).
 *
 * A call's counts come from the output's TokenUsageDto, which the provider
 * fills in. Gemini's provider leaves it empty when the call runs inside a
 * PHP Fiber, so the raw response's usageMetadata is read as a fallback. A
 * call with neither counts as uncounted, and its tokens are missing from
 * the sums; the health report leaves such answers out of the mean.
 */
final class TokenTally {

  /**
   * The message template part that goes with logContext().
   */
  public const LOG_TEMPLATE = 'chat_calls=@chat_calls input_tokens=@input_tokens output_tokens=@output_tokens reasoning_tokens=@reasoning_tokens uncounted_calls=@uncounted_calls';

  /**
   * Chat calls seen.
   */
  public int $calls = 0;

  /**
   * Input (prompt) tokens over the counted calls.
   */
  public int $input = 0;

  /**
   * Output tokens over the counted calls, reasoning not included.
   */
  public int $output = 0;

  /**
   * Reasoning (thinking) tokens over the counted calls.
   *
   * Gemini bills these at the output price but reports them apart from
   * candidatesTokenCount.
   */
  public int $reasoning = 0;

  /**
   * Calls that reported no token counts.
   */
  public int $uncounted = 0;

  /**
   * Adds one chat call's output.
   */
  public function add(OutputInterface $output): void {
    $this->calls++;
    $counts = self::read($output);
    if ($counts === NULL) {
      $this->uncounted++;
      return;
    }
    $this->input += $counts[0];
    $this->output += $counts[1];
    $this->reasoning += $counts[2];
  }

  /**
   * The log context fields for answer_summary.
   *
   * @return array{'@chat_calls': int, '@input_tokens': int, '@output_tokens': int, '@reasoning_tokens': int, '@uncounted_calls': int}
   *   Integers only.
   */
  public function logContext(): array {
    return [
      '@chat_calls' => $this->calls,
      '@input_tokens' => $this->input,
      '@output_tokens' => $this->output,
      '@reasoning_tokens' => $this->reasoning,
      '@uncounted_calls' => $this->uncounted,
    ];
  }

  /**
   * Input, output and reasoning tokens of one call, or NULL if it has none.
   *
   * @return array{0: int, 1: int, 2: int}|null
   *   [input, output, reasoning].
   */
  public static function read(OutputInterface $output): ?array {
    if (method_exists($output, 'getTokenUsage')) {
      $usage = $output->getTokenUsage();
      if ($usage !== NULL && ($usage->input !== NULL || $usage->output !== NULL)) {
        return [max(0, (int) $usage->input), max(0, (int) $usage->output), max(0, (int) $usage->reasoning)];
      }
    }
    $raw = $output->getRawOutput();
    if (is_object($raw) && method_exists($raw, 'toArray')) {
      $raw = $raw->toArray();
    }
    $meta = is_array($raw) ? ($raw['usageMetadata'] ?? NULL) : NULL;
    if (!is_array($meta) || (!isset($meta['promptTokenCount']) && !isset($meta['candidatesTokenCount']))) {
      return NULL;
    }
    return [
      max(0, (int) ($meta['promptTokenCount'] ?? 0)),
      max(0, (int) ($meta['candidatesTokenCount'] ?? 0)),
      max(0, (int) ($meta['thoughtsTokenCount'] ?? 0)),
    ];
  }

}
