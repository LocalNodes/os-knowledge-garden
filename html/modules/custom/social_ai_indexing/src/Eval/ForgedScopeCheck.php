<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Eval;

/**
 * E2's sameness rule for the replies to forged scopes.
 *
 * Every forged scope must get the same reply. One exemption: a reply that is
 * exactly the fallback text, with no sources and the fallback outcome, means
 * the model call did not finish, not that the scope changed the answer. Such
 * a reply is asked again once. Any other difference is a critical failure.
 *
 * Pure and static. Eval tooling only: nothing on a live request path calls
 * it.
 */
final class ForgedScopeCheck {

  public const SAME = 'same';
  public const RETRY = 'retry';
  public const PASS_AFTER_RETRY = 'pass_after_retry';
  public const INCOMPLETE = 'incomplete';
  public const CRITICAL = 'critical';

  /**
   * Whether one reply is the exempt fallback.
   *
   * @param array $reply
   *   'answer' (string), 'sources' (int) and 'outcome' (string).
   * @param string $fallback
   *   The fallback text.
   */
  public static function isFallback(array $reply, string $fallback): bool {
    return ($reply['answer'] ?? NULL) === $fallback
      && (int) ($reply['sources'] ?? -1) === 0
      && ($reply['outcome'] ?? NULL) === 'fallback';
  }

  /**
   * Judges the first replies.
   *
   * Fallbacks are picked out before sameness is judged, so replies that are
   * all the fallback are retried too, never accepted as the same.
   *
   * @param array[] $replies
   *   One reply per forged scope, keyed by any id.
   * @param string $fallback
   *   The fallback text.
   *
   * @return array
   *   'result' (SAME, RETRY or CRITICAL) and 'retry' (the keys to ask again).
   */
  public static function first(array $replies, string $fallback): array {
    [$retry, $others] = self::split($replies, $fallback);
    if (count(array_unique($others)) > 1) {
      return ['result' => self::CRITICAL, 'retry' => []];
    }
    if ($retry) {
      return ['result' => self::RETRY, 'retry' => $retry];
    }
    return ['result' => self::SAME, 'retry' => []];
  }

  /**
   * Judges the replies after the one retry.
   *
   * @param array[] $replies
   *   The first replies with each retried key replaced by its new reply.
   * @param array $retried
   *   The keys that were asked again.
   * @param string $fallback
   *   The fallback text.
   *
   * @return string
   *   PASS_AFTER_RETRY, INCOMPLETE or CRITICAL. A retried reply with sources
   *   is critical even when its text matches. Different replies are critical
   *   even when a fallback is left; only then does a fallback that is left
   *   make the run incomplete.
   */
  public static function afterRetry(array $replies, array $retried, string $fallback): string {
    foreach ($retried as $key) {
      if ((int) ($replies[$key]['sources'] ?? 0) > 0) {
        return self::CRITICAL;
      }
    }
    [$left, $others] = self::split($replies, $fallback);
    if (count(array_unique($others)) > 1) {
      return self::CRITICAL;
    }
    return $left ? self::INCOMPLETE : self::PASS_AFTER_RETRY;
  }

  /**
   * The whole rule: the first judgement, the one retry, the final judgement.
   *
   * @param array[] $replies
   *   One reply per forged scope, keyed by any id.
   * @param string $fallback
   *   The fallback text.
   * @param callable $retry
   *   Called once per key to ask again; returns the new reply, or NULL when
   *   the retry did not complete. Every key is asked even after a NULL, so
   *   a different reply from another retry is still seen; a key whose retry
   *   did not complete keeps its fallback, which leaves the run INCOMPLETE
   *   unless the replies differ.
   *
   * @return string
   *   SAME, PASS_AFTER_RETRY, INCOMPLETE or CRITICAL.
   */
  public static function resolve(array $replies, string $fallback, callable $retry): string {
    $first = self::first($replies, $fallback);
    if ($first['result'] !== self::RETRY) {
      return $first['result'];
    }
    foreach ($first['retry'] as $key) {
      $again = $retry($key);
      if ($again !== NULL) {
        $replies[$key] = $again;
      }
    }
    return self::afterRetry($replies, $first['retry'], $fallback);
  }

  /**
   * Splits replies into the fallback keys and the other replies' texts.
   */
  private static function split(array $replies, string $fallback): array {
    $fallbacks = [];
    $others = [];
    foreach ($replies as $key => $reply) {
      if (self::isFallback($reply, $fallback)) {
        // PHP turns numeric string keys into integers; ids stay strings.
        $fallbacks[] = (string) $key;
      }
      else {
        $others[] = $reply['answer'] ?? NULL;
      }
    }
    return [$fallbacks, $others];
  }

}
