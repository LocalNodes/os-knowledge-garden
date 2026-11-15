<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

use Drupal\localnodes_events_calendar\DemoContent\DemoDate;

/**
 * Resolves day tokens in pack text to the date a member reads.
 *
 * Pack text says "{{day:+7}}" or "{{day:-14}}": N days from the load. Every
 * pack uses one format, the weekday and date as a post would write it
 * ("Thursday 19 November"). The pack's question passages hold the same
 * tokens, and their check resolves them with the same anchor, so a question
 * and the page it quotes agree.
 */
final class PackTokens {

  /**
   * The date format for every resolved token.
   */
  public const FORMAT = 'l j F';

  /**
   * Replaces each day token in the text with its date.
   *
   * @param string $text
   *   Pack text.
   * @param int $anchor
   *   The time the offsets count from.
   *
   * @throws \InvalidArgumentException
   *   When "{{" is still in the text after the tokens are replaced. The
   *   message never quotes the text.
   */
  public static function resolve(string $text, int $anchor): string {
    if (!str_contains($text, '{{')) {
      return $text;
    }
    $resolved = preg_replace_callback(
      '/\{\{day:([+-]\d+)\}\}/',
      static fn (array $m): string => date(self::FORMAT, (int) strtotime(DemoDate::day($m[1] . ' day', $anchor))),
      $text
    );
    if ($resolved === NULL || str_contains($resolved, '{{')) {
      throw new \InvalidArgumentException('Pack text has a malformed token.');
    }
    return $resolved;
  }

  /**
   * Resolves every string value in parsed pack data, at any depth.
   *
   * Keys and values that are not strings are left as they are.
   *
   * @throws \InvalidArgumentException
   *   When a value has a malformed token; the message names its keys, never
   *   its text.
   */
  public static function walk(array $data, int $anchor, string $path = ''): array {
    foreach ($data as $key => $value) {
      $at = $path === '' ? (string) $key : $path . '.' . $key;
      if (is_array($value)) {
        $data[$key] = self::walk($value, $anchor, $at);
      }
      elseif (is_string($value)) {
        try {
          $data[$key] = self::resolve($value, $anchor);
        }
        catch (\InvalidArgumentException) {
          throw new \InvalidArgumentException('Pack text has a malformed token at ' . $at . '.');
        }
      }
    }
    return $data;
  }

}
