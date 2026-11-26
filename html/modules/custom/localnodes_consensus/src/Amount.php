<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus;

/**
 * A money decision's "Up to $" text, read as cents with no float arithmetic.
 */
final class Amount {

  /**
   * Dollars with up to two decimals as cents, or NULL when refused.
   *
   * Refused: blank, zero, a sign, a comma, a symbol, an exponent, three or
   * more decimals, a bare point at either end and more than nine dollar
   * digits.
   */
  public static function cents(string $text): ?int {
    if (preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/', trim($text), $match) !== 1) {
      return NULL;
    }
    $cents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    return $cents > 0 ? $cents : NULL;
  }

  /**
   * Cents as a form shows them back: 250 for whole dollars, else 249.99.
   */
  public static function text(?int $cents): string {
    if ($cents === NULL) {
      return '';
    }
    $dollars = intdiv($cents, 100);
    $rest = $cents % 100;
    return $rest === 0 ? (string) $dollars : sprintf('%d.%02d', $dollars, $rest);
  }

}
