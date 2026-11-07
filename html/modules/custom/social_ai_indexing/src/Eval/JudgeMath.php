<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Eval;

/**
 * The citation-support judge's deterministic parts (22-29 Task 1, E4).
 *
 * AI-SPEC §5 "Citation-Support Judge": one model call per cited sentence
 * says supported, partial or not_supported, with a quote per source. Code
 * here does everything that must not depend on the model:
 * - splitting a cited answer into sentences and their [n] citations;
 * - the quote cross-check: a quote must appear verbatim (whitespace-
 *   normalised) in its source's excerpt, or the source adds no support,
 *   and a verdict with no checked support falls to not_supported;
 * - the metrics (citation recall and precision, uncited factual
 *   sentences) and the calibration against labels.
 *
 * Eval tooling only: nothing on a live request path calls it.
 */
final class JudgeMath {

  public const SUPPORTED = 'supported';
  public const PARTIAL = 'partial';
  public const NOT_SUPPORTED = 'not_supported';

  /**
   * Label-file verdicts mapped to the judge's.
   */
  public const LABEL_MAP = [
    'supported' => self::SUPPORTED,
    'partly' => self::PARTIAL,
    'partial' => self::PARTIAL,
    'not' => self::NOT_SUPPORTED,
    'not_supported' => self::NOT_SUPPORTED,
  ];

  /**
   * The structured-output schema the judge call requests.
   */
  public static function schema(): array {
    return [
      'name' => 'citation_support',
      'schema' => [
        'type' => 'object',
        'properties' => [
          'verdict' => ['type' => 'string', 'enum' => [self::SUPPORTED, self::PARTIAL, self::NOT_SUPPORTED]],
          'per_source' => [
            'type' => 'array',
            'items' => [
              'type' => 'object',
              'properties' => [
                'label' => ['type' => 'string'],
                'adds_support' => ['type' => 'boolean'],
                'quote' => ['type' => 'string'],
              ],
              'required' => ['label', 'adds_support', 'quote'],
            ],
          ],
        ],
        'required' => ['verdict', 'per_source'],
      ],
    ];
  }

  /**
   * Cited answer markdown to sentences with their citation numbers.
   *
   * The body is everything before the "Sources" heading. List items and
   * paragraphs are split into sentences; [n] markers attach to the
   * sentence they end.
   *
   * @return list<array{text: string, labels: list<int>}>
   *   Sentences in order; text has markers and markdown removed.
   */
  public static function sentences(string $answer): array {
    $body = preg_split('/^\s*\*{0,2}Sources\*{0,2}\s*$/mu', $answer)[0] ?? $answer;
    $out = [];
    foreach (preg_split('/\R+/u', $body) as $line) {
      $line = trim((string) preg_replace('/^\s*(?:[-*+]|\d+\.)\s+/u', '', $line));
      if ($line === '') {
        continue;
      }
      // Split after sentence punctuation plus any citation markers.
      $parts = preg_split('/(?<=[.!?])((?:\s*\[\d+\])*)\s+(?=\S)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
      $pieces = [];
      for ($i = 0; $i < count($parts); $i += 2) {
        $pieces[] = $parts[$i] . ($parts[$i + 1] ?? '');
      }
      foreach ($pieces as $piece) {
        preg_match_all('/\[(\d+)\]/u', $piece, $m);
        $text = self::plain((string) preg_replace('/\s*\[\d+\]/u', '', $piece));
        if ($text === '') {
          continue;
        }
        $out[] = ['text' => $text, 'labels' => array_values(array_unique(array_map('intval', $m[1])))];
      }
    }
    return $out;
  }

  /**
   * Text without markdown emphasis, whitespace collapsed.
   */
  public static function plain(string $text): string {
    $text = str_replace(['**', '__'], '', $text);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * Whether a quote is in an excerpt, whitespace-normalised.
   */
  public static function quoteIn(string $quote, string $excerpt): bool {
    $quote = self::comparable($quote);
    if (mb_strlen($quote) < 8) {
      return FALSE;
    }
    return str_contains(self::comparable($excerpt), $quote);
  }

  /**
   * Text as the quote check compares it.
   *
   * Markdown links keep only their text, emphasis marks go, typographic
   * quotes and dashes fold to ASCII and whitespace collapses. The model
   * quotes what the passage says, not its markup: "the work of Jose Ramos"
   * for "the work of [Jose Ramos](https://…)" (seen live, 2026-11-07).
   */
  public static function comparable(string $text): string {
    $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
    $text = str_replace(['**', '__', '`'], '', $text);
    $text = strtr($text, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ']);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * A source label as the excerpts are keyed: '1', '[1]', ' [ 1 ] ' -> '[1]'.
   *
   * The model is asked for the label it was shown ('[1]') but sometimes
   * returns the bare number; without this the quote is never found and a
   * supported sentence falls to not_supported (seen live, 2026-11-07).
   */
  public static function label(string $label): string {
    return preg_match('/^\s*\[?\s*(\d+)\s*\]?\s*$/u', $label, $m) ? '[' . $m[1] . ']' : trim($label);
  }

  /**
   * Applies the quote cross-check to one judgement.
   *
   * @param array $judgement
   *   Decoded model output: verdict and per_source.
   * @param array<string, string> $excerpts
   *   Excerpt per source label ('[1]').
   *
   * @return array{verdict: string, model_verdict: string, per_source: list<array{label: string, adds_support: bool, quote: string, quote_found: bool}>, downgraded: bool}
   *   The checked judgement.
   */
  public static function crossCheck(array $judgement, array $excerpts): array {
    $model = (string) ($judgement['verdict'] ?? '');
    if (!in_array($model, [self::SUPPORTED, self::PARTIAL, self::NOT_SUPPORTED], TRUE)) {
      $model = self::NOT_SUPPORTED;
    }
    $checked = [];
    $any = FALSE;
    foreach ((array) ($judgement['per_source'] ?? []) as $source) {
      $label = self::label((string) ($source['label'] ?? ''));
      $quote = (string) ($source['quote'] ?? '');
      $found = isset($excerpts[$label]) && self::quoteIn($quote, $excerpts[$label]);
      $adds = !empty($source['adds_support']) && $found;
      $any = $any || $adds;
      $checked[] = ['label' => $label, 'adds_support' => $adds, 'quote' => $quote, 'quote_found' => $found];
    }
    $verdict = $model;
    if ($model !== self::NOT_SUPPORTED && !$any) {
      // Support the excerpts don't hold isn't support.
      $verdict = self::NOT_SUPPORTED;
    }
    return ['verdict' => $verdict, 'model_verdict' => $model, 'per_source' => $checked, 'downgraded' => $verdict !== $model];
  }

  /**
   * Recall, precision and the uncited count over judged sentences.
   *
   * @param list<array{verdict: string, per_source: list<array{adds_support: bool}>}> $judged
   *   Checked judgements of cited sentences.
   * @param int $uncited
   *   Sentences with no citation in cited answers.
   *
   * @return array{sentences: int, supported: int, partial: int, not_supported: int, recall: float, citations: int, adding: int, precision: float, uncited: int}
   *   Partial counts as not supported for recall (AI-SPEC §5).
   */
  public static function metrics(array $judged, int $uncited): array {
    $counts = [self::SUPPORTED => 0, self::PARTIAL => 0, self::NOT_SUPPORTED => 0];
    $citations = 0;
    $adding = 0;
    foreach ($judged as $j) {
      $counts[$j['verdict']]++;
      foreach ($j['per_source'] as $s) {
        $citations++;
        $adding += $s['adds_support'] ? 1 : 0;
      }
    }
    $n = count($judged);
    return [
      'sentences' => $n,
      'supported' => $counts[self::SUPPORTED],
      'partial' => $counts[self::PARTIAL],
      'not_supported' => $counts[self::NOT_SUPPORTED],
      'recall' => $n ? $counts[self::SUPPORTED] / $n : 0.0,
      'citations' => $citations,
      'adding' => $adding,
      'precision' => $citations ? $adding / $citations : 0.0,
      'uncited' => $uncited,
    ];
  }

  /**
   * Compares judge verdicts with labels (AI-SPEC §5 calibration).
   *
   * A false pass is the costly error: the judge says supported where the
   * label says not supported. Passes on partly-labelled pairs are counted
   * apart, as the stricter check. Cohen's kappa only with at least 10
   * not-supported labels; below that a coefficient is noise.
   *
   * @param array<string, string> $judge
   *   Judge verdict per pair id.
   * @param array<string, string> $labels
   *   Label verdict per pair id (label-file words are mapped).
   *
   * @return array{total: int, agree: int, false_passes: int, partial_passes: int, disagreements: list<array{id: string, label: string, judge: string}>, kappa: float|null, not_supported_labels: int, trusted: bool}
   *   Trusted needs 0 false passes, 0 partial passes and >= 90% agreement.
   */
  public static function calibrate(array $judge, array $labels): array {
    $agree = 0;
    $false = 0;
    $partial_pass = 0;
    $disagreements = [];
    $pairs = [];
    foreach ($labels as $id => $label) {
      $label = self::LABEL_MAP[$label] ?? $label;
      if (!isset($judge[$id])) {
        continue;
      }
      $pairs[] = [$label, $judge[$id]];
      if ($label === $judge[$id]) {
        $agree++;
        continue;
      }
      $disagreements[] = ['id' => (string) $id, 'label' => $label, 'judge' => $judge[$id]];
      if ($judge[$id] === self::SUPPORTED && $label === self::NOT_SUPPORTED) {
        $false++;
      }
      if ($judge[$id] === self::SUPPORTED && $label === self::PARTIAL) {
        $partial_pass++;
      }
    }
    $total = count($pairs);
    $not = count(array_filter($pairs, static fn (array $p): bool => $p[0] === self::NOT_SUPPORTED));
    return [
      'total' => $total,
      'agree' => $agree,
      'false_passes' => $false,
      'partial_passes' => $partial_pass,
      'disagreements' => $disagreements,
      'kappa' => $not >= 10 ? self::kappa($pairs) : NULL,
      'not_supported_labels' => $not,
      'trusted' => $total > 0 && $false === 0 && $partial_pass === 0 && $agree / $total >= 0.9,
    ];
  }

  /**
   * Cohen's kappa over [label, judge] pairs.
   */
  public static function kappa(array $pairs): float {
    $n = count($pairs);
    if ($n === 0) {
      return 0.0;
    }
    $classes = [self::SUPPORTED, self::PARTIAL, self::NOT_SUPPORTED];
    $observed = count(array_filter($pairs, static fn (array $p): bool => $p[0] === $p[1])) / $n;
    $expected = 0.0;
    foreach ($classes as $c) {
      $a = count(array_filter($pairs, static fn (array $p): bool => $p[0] === $c)) / $n;
      $b = count(array_filter($pairs, static fn (array $p): bool => $p[1] === $c)) / $n;
      $expected += $a * $b;
    }
    return $expected >= 1.0 ? 1.0 : ($observed - $expected) / (1 - $expected);
  }

}
