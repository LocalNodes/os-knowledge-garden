<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Citation;

/**
 * A validated answer: body with [n] markers plus its ordered citations.
 *
 * Only constructible through cited() and noRelevantContent(), so an invalid
 * answer cannot exist (AI-SPEC §4b). The fixed copy is the UI-SPEC
 * Copywriting Contract text, byte-identical for every person in the same
 * scope, so it never hints at pages the asker cannot see (D-04, T-22-20).
 */
final readonly class CitedAnswer {

  /**
   * No-answer copy inside a group.
   */
  public const NO_ANSWER_GROUP = "I couldn't find anything about that in this group's pages. Try asking with different words, or look through the group's pages yourself.";

  /**
   * No-answer copy outside a group.
   */
  public const NO_ANSWER_SITE = "I couldn't find anything about that on this site. Try asking with different words, or use search.";

  /**
   * Fallback copy for an answer that cannot be cited.
   */
  public const FALLBACK = "I couldn't find a source I can point you to for that, so I won't guess. Try asking with different words, or look through the group's pages yourself.";

  /**
   * The Sources heading line.
   */
  public const SOURCES_HEADING = '**Sources**';

  /**
   * Constructs a CitedAnswer.
   *
   * @param string $body
   *   The answer body with [n] markers.
   * @param list<\Drupal\social_ai_indexing\Citation\RetrievedSource> $citations
   *   First-use order; display number = index + 1.
   * @param bool $noAnswer
   *   TRUE for the fixed no-answer reply.
   */
  private function __construct(
    public string $body,
    public array $citations,
    public bool $noAnswer,
  ) {}

  /**
   * Builds a cited answer.
   *
   * @param string $body
   *   Rewritten body; only [n] markers, no raw [R#] labels.
   * @param array $citations
   *   RetrievedSource objects in first-use order.
   *
   * @throws \Drupal\social_ai_indexing\Citation\UncitableAnswerException
   */
  public static function cited(string $body, array $citations): self {
    if (trim($body) === '') {
      throw new UncitableAnswerException('empty_body');
    }
    if ($citations === []) {
      throw new UncitableAnswerException('no_valid_citations');
    }
    // Never leak raw markers. A preg failure (FALSE) also fails closed.
    if (preg_match('/\[R\d+\]/i', $body) !== 0) {
      throw new UncitableAnswerException('unrewritten_label');
    }
    foreach ($citations as $citation) {
      if (!$citation instanceof RetrievedSource) {
        throw new UncitableAnswerException('bad_citation_type');
      }
    }
    return new self(rtrim($body), array_values($citations), FALSE);
  }

  /**
   * The fixed no-answer reply for the scope; no Sources list.
   */
  public static function noRelevantContent(bool $in_group = TRUE): self {
    return new self($in_group ? self::NO_ANSWER_GROUP : self::NO_ANSWER_SITE, [], TRUE);
  }

  /**
   * The fixed reply used when an answer cannot be cited; no Sources list.
   */
  public static function fallbackMarkdown(): string {
    return self::FALLBACK;
  }

  /**
   * Renders body, blank line, "**Sources**", then "- [i] [Title](url)" lines.
   */
  public function toMarkdown(): string {
    if ($this->noAnswer) {
      return $this->body;
    }
    $lines = [];
    foreach (self::linkTexts($this->citations) as $i => $text) {
      $lines[] = sprintf('- [%d] [%s](%s)', $i + 1, $text, $this->citations[$i]->url);
    }
    return $this->body . "\n\n" . self::SOURCES_HEADING . "\n" . implode("\n", $lines);
  }

  /**
   * Link texts for a Sources list, one per citation, all distinct.
   *
   * Brackets become parentheses and whitespace collapses, so a title can't
   * break the "- [n] [Title](url)" line. A title already used by an earlier
   * entry gets " (2)", " (3)" and so on, so no two entries share link text.
   *
   * @param list<\Drupal\social_ai_indexing\Citation\RetrievedSource> $citations
   *   Citations in display order.
   *
   * @return list<string>
   *   Link texts in the same order.
   */
  public static function linkTexts(array $citations): array {
    $texts = [];
    $used = [];
    foreach (array_values($citations) as $citation) {
      $base = trim((string) preg_replace('/\s+/u', ' ', str_replace(['[', ']'], ['(', ')'], $citation->title)));
      if ($base === '') {
        $base = 'Untitled';
      }
      $text = $base;
      for ($i = 2; isset($used[$text]); $i++) {
        $text = $base . ' (' . $i . ')';
      }
      $used[$text] = TRUE;
      $texts[] = $text;
    }
    return $texts;
  }

}
