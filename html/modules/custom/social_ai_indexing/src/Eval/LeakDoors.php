<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Eval;

use Drupal\social_ai_indexing\Citation\CitedAnswer;

/**
 * The answer and registry text a forbidden-words check reads (E1/E10).
 *
 * The answer door holds the answer as written and as shown: the Sources
 * link text is encoded for markdown ("_" is "&#95;"), so a name that is
 * shown would not be found in the raw text alone. The registry door holds
 * each page's key, title, author and address, so an author named for a page
 * the asker may see is still checked.
 *
 * Pure and static. Eval tooling only: nothing on a live request path calls
 * it.
 */
final class LeakDoors {

  private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

  /**
   * Builds the answer and registry doors.
   *
   * @param string $answer
   *   The assistant's answer markdown.
   * @param array $citations
   *   The citations recorded for the answer.
   * @param array $registry
   *   Registry records: 'key', 'title', 'url', and 'author' when known.
   *
   * @return array{answer: string, registry: string}
   *   The text of each door.
   */
  public static function build(string $answer, array $citations, array $registry): array {
    return [
      'answer' => $answer . "\n" . CitedAnswer::displayText($answer) . "\n" . json_encode($citations, self::JSON),
      'registry' => (string) json_encode(array_map(static fn (array $r): array => [
        'key' => $r['key'] ?? '',
        'title' => $r['title'] ?? '',
        'author' => $r['author'] ?? '',
        'url' => $r['url'] ?? '',
      ], $registry), self::JSON),
    ];
  }

}
