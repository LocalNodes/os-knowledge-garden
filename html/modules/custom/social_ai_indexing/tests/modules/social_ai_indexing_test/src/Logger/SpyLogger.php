<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing_test\Logger;

use Drupal\Core\Logger\RfcLoggerTrait;
use Psr\Log\LoggerInterface;

/**
 * Records every log call on every channel (AI-SPEC §5 leak door 6, E10).
 *
 * Tagged 'logger', so the logger factory attaches it to every channel,
 * including channels created before it. Tests read records() and check
 * each message and its whole context.
 */
final class SpyLogger implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * Recorded calls.
   *
   * @var list<array{level: mixed, channel: string, message: string, context: array}>
   */
  private array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => $level,
      'channel' => (string) ($context['channel'] ?? ''),
      'message' => (string) $message,
      'context' => $context,
    ];
  }

  /**
   * Every call recorded since the last reset().
   *
   * @return list<array{level: mixed, channel: string, message: string, context: array}>
   *   The records, in call order.
   */
  public function records(): array {
    return $this->records;
  }

  /**
   * Forgets every record.
   */
  public function reset(): void {
    $this->records = [];
  }

  /**
   * A record as one string: message, placeholders filled, and all context.
   *
   * Objects in the context are included by class, and a Throwable by its
   * message too (an exception message can carry content).
   */
  public static function flatten(array $record): string {
    $placeholders = [];
    foreach ($record['context'] as $key => $value) {
      if (is_string($key) && ($key[0] ?? '') !== '' && in_array($key[0], ['@', '%', ':'], TRUE) && is_scalar($value)) {
        $placeholders[$key] = (string) $value;
      }
    }
    return strtr($record['message'], $placeholders) . "\n" . $record['message'] . "\n" . self::stringify($record['context']);
  }

  /**
   * Any value as text, recursively, depth-limited.
   */
  private static function stringify(mixed $value, int $depth = 0): string {
    if ($depth > 6) {
      return '';
    }
    if (is_scalar($value) || $value === NULL) {
      return (string) $value;
    }
    if ($value instanceof \Throwable) {
      return get_class($value) . ': ' . $value->getMessage() . "\n" . self::stringify($value->getPrevious(), $depth + 1);
    }
    if ($value instanceof \Stringable) {
      return (string) $value;
    }
    if (is_object($value)) {
      return get_class($value);
    }
    $parts = [];
    foreach ($value as $key => $item) {
      $parts[] = $key . '=' . self::stringify($item, $depth + 1);
    }
    return implode("\n", $parts);
  }

}
