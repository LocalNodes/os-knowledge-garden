<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Citation;

/**
 * Turns the model's final text into a validated CitedAnswer (D-19).
 *
 * Model text is untrusted input. Nothing it wrote about sources survives: its
 * Sources list, links, URLs, plain [n] markers and labels that map to no
 * retrieved source are removed. The remaining [R#] labels are renumbered
 * [1], [2], [3] in order of first use, and the Sources list is rebuilt from
 * the registry only (T-22-18). Every regex failure fails closed.
 *
 * The rewriter does not mark the registry; its caller (the 22-16 subscriber)
 * calls SourceRegistry::markRewritten().
 */
final class CitationRewriter {

  /**
   * Rewrites model text into a cited answer.
   *
   * @param string $llm_text
   *   The model's final text.
   * @param \Drupal\social_ai_indexing\Citation\SourceRegistry $registry
   *   Sources retrieved during this request.
   * @param bool $in_group
   *   Whether the question was asked in a group (selects the no-answer copy).
   *
   * @throws \Drupal\social_ai_indexing\Citation\UncitableAnswerException
   *   When nothing citable remains; callers show the fallback copy.
   */
  public function rewrite(string $llm_text, SourceRegistry $registry, bool $in_group = TRUE): CitedAnswer {
    $text = trim($llm_text);
    if ($text === '') {
      throw new UncitableAnswerException('empty_output');
    }
    if (str_contains($text, 'NO_RELEVANT_CONTENT')) {
      return CitedAnswer::noRelevantContent($in_group);
    }
    if ($registry->count() === 0) {
      // Nothing was retrieved, so nothing can be cited: whatever the model
      // wrote is the no-answer case. gemini-3.8-flash sometimes says so in
      // its own words instead of NO_RELEVANT_CONTENT, and the fallback copy
      // then broke the rule that every no-result reply is identical (forged
      // scopes, door 4; rerun 5, 2026-11-07).
      return CitedAnswer::noRelevantContent($in_group);
    }

    $text = "\n" . $text;
    // The model's own Sources list: a "Sources" heading line and all after it.
    $text = self::replace('/\n[ \t]*(?:#{1,6}[ \t]*)?(?:\*\*|__)?[ \t]*sources[ \t]*:?[ \t]*(?:\*\*|__)?[ \t]*:?[ \t]*(?:\n.*)?\z/is', '', $text);
    // HTML anchors become their text.
    $text = self::replace('/<a\b[^>]*>(.*?)<\/a\s*>/is', '$1', $text);
    // Markdown links and images become their text; keep wrapped labels.
    $text = self::replaceCallback('/!?\[([^\]]*)\]\([^)]*\)/', static function (array $m): string {
      return preg_match('/^R\d+$/i', trim($m[1])) === 1 ? '[' . trim($m[1]) . ']' : $m[1];
    }, $text);
    // Autolinks and bare URLs are removed.
    $text = self::replace('/<(?:https?:)?\/\/[^>]*>/i', '', $text);
    $text = self::replace('#(?:\bhttps?://|\bwww\.)\S+#i', '', $text);
    // Plain [n] and [n, m]: stale history or invented numbers.
    $text = self::replace('/\[\d+(?:[ \t]*,[ \t]*\d+)*\]/', '', $text);
    // "[R1, R3]" -> "[R1][R3]".
    $text = self::replaceCallback('/\[(R\d+(?:[ \t]*,[ \t]*R\d+)+)\]/i', static function (array $m): string {
      return implode('', array_map(static fn (string $label): string => '[' . trim($label) . ']', explode(',', $m[1])));
    }, $text);

    // Every claim must carry a source: sentences and headings with no valid
    // [R#] go (Codex merge review rounds 1-2). This runs BEFORE numbering,
    // so a removed line can't leave its number behind (round 3: a dropped
    // cited heading left an orphan Sources entry and numbering began at
    // [2]). Only removed, never reworded.
    $valid = static fn (int $n): bool => $n < 1000000 && $registry->byLabelNumber($n) !== NULL;
    $text = self::dropUncited($text, $valid);
    if (trim($text) === '') {
      // Nothing cited survived: the same reason as no valid labels at all.
      throw new UncitableAnswerException('no_valid_citations');
    }

    // Renumber by first use; drop labels that map to no retrieved source.
    $display = [];
    $citations = [];
    $text = self::replaceCallback('/\[R(\d+)\]/i', static function (array $m) use ($registry, &$display, &$citations): string {
      $source = strlen($m[1]) <= 6 ? $registry->byLabelNumber((int) $m[1]) : NULL;
      if ($source === NULL) {
        return '';
      }
      if (!isset($display[$source->key()])) {
        $citations[] = $source;
        $display[$source->key()] = count($citations);
      }
      return '[' . $display[$source->key()] . ']';
    }, $text);

    // "[1][1]" -> "[1]".
    $text = self::replace('/(\[\d+\])(?:[ \t]*\1)+/', '$1', $text);
    // Nothing cited at all keeps its own reason (the overview retries once
    // on it).
    if ($citations === []) {
      throw new UncitableAnswerException('no_valid_citations');
    }
    // Tidy the gaps left by dropped text.
    $text = self::replace('/(?<=\S)[ \t]{2,}(?=\S)/', ' ', $text);
    $text = self::replace('/[ \t]+(?=[.,;:!?])/', '', $text);
    $text = self::replace('/[ \t]+$/m', '', $text);
    $text = self::replace('/\n{3,}/', "\n\n", $text);

    $text = trim($text);
    if ($text === '') {
      throw new UncitableAnswerException('empty_body');
    }
    // Throws if nothing citable remains or a raw label survived.
    return CitedAnswer::cited($text, $citations);
  }

  /**
   * Removes sentences with no citation marker, keeping explicit boilerplate.
   *
   * The support check only judges cited sentences, so "Supported fact [1].
   * Fabricated fact." kept its second sentence, and the citation gate never
   * measured it. Here, after the labels are renumbered, a sentence without
   * any [n] is deleted unless it is one of:
   * - a list intro or heading that is one of the fixed non-claim intros
   *   (FIXED_INTROS, six words or fewer);
   * - a plain statement that the pages don't say something, which the
   *   support rules ask for ("say the pages don't spell out the link"), in
   *   its known forms only (see isBoilerplate()).
   * Lines left empty are removed; the markdown otherwise stays as written.
   */
  public static function dropUncited(string $text, ?callable $valid = NULL): string {
    // A line or sentence is cited when it holds a label ([R#] before
    // numbering, [n] after) that $valid accepts; with no $valid, any label.
    $cited = static function (string $text) use ($valid): bool {
      preg_match_all('/\[R?(\d+)\]/i', $text, $m);
      foreach ($m[1] as $n) {
        if ($valid === NULL || $valid((int) $n)) {
          return TRUE;
        }
      }
      return FALSE;
    };
    $out = [];
    foreach (preg_split('/\n/', $text) as $line) {
      if (trim($line) === '') {
        $out[] = $line;
        continue;
      }
      if (preg_match('/^(\s*#{1,6}\s+)(.*)$/u', $line, $heading) && self::isFixedIntro($heading[2])) {
        // A fixed intro heading stays whole.
        $out[] = $line;
        continue;
      }
      // A heading's sentences are filtered like any line's (Codex merge
      // review R4: "## Fact [R1]. Founded on the moon in 1850." kept both),
      // keeping its "#" prefix; a list marker likewise.
      $prefix = preg_match('/^\s*(?:#{1,6}|[-*+]|\d+\.)\s+/u', $line, $m) ? $m[0] : '';
      $rest = substr($line, strlen($prefix));
      $parts = preg_split('/(?<=[.!?])((?:[ \t]*\[R?\d+\])*)[ \t]+(?=\S)/iu', $rest, -1, PREG_SPLIT_DELIM_CAPTURE);
      $kept = [];
      for ($i = 0; $i < count($parts); $i += 2) {
        $sentence = trim($parts[$i] . ($parts[$i + 1] ?? ''));
        if ($sentence !== '' && ($cited($sentence) || self::isBoilerplate($sentence))) {
          $kept[] = $sentence;
        }
      }
      if ($kept !== []) {
        $out[] = $prefix . implode(' ', $kept);
      }
    }
    return implode("\n", $out);
  }

  /**
   * Whether an uncited sentence is allowed boilerplate (see dropUncited()).
   */
  public static function isBoilerplate(string $sentence): bool {
    $sentence = trim($sentence);
    if (preg_match('/:$/u', $sentence)) {
      return self::isFixedIntro($sentence);
    }
    // The absence statement our support rules ask for ("say the pages don't
    // spell out the link"), pinned to its known forms (orchestrator,
    // 2026-11-07): "The [provided] pages/sources/texts do not/don't
    // [explicitly] say/state/spell out/mention" + what is missing, which must
    // be a relation or an indirect question ("the link between ...", "what
    // ...", "how ..."), never a statement of its own ("the meeting moved to
    // Friday"). One clause: no comma, semicolon, "but" or digit.
    if (preg_match('/[,;0-9]|\bbut\b/iu', $sentence)) {
      return FALSE;
    }
    return (bool) preg_match('/^the\s+(?:provided\s+)?(?:pages|sources|texts)\s+(?:do\s+not|don[\'’]t)\s+(?:(?:explicitly|directly|clearly)\s+)?(?:say|state|spell\s+out|mention)\s+(?:the\s+(?:link|links|connection|connections|relationship|relation)\b|how\b|what\b|whether\b|why\b|which\b|who\b|when\b)[^.!?]*\.$/iu', $sentence);
  }

  /**
   * Intros that state nothing: the only uncited headings and colon lines.
   *
   * Compared without case, apostrophe style, a trailing colon or extra
   * spaces. Codex merge review round 2: any heading, and any sentence ending
   * in a colon, used to pass, so "## The group was founded on the moon in
   * 1850" survived uncited.
   */
  public const FIXED_INTROS = [
    "here's what the pages say",
    "here's what the group's pages say",
    "here's what the sources say",
    "here's what i found",
    'key points',
    'in short',
    'in brief',
    'summary',
    'sources',
  ];

  /**
   * Whether a heading or colon intro is a fixed, non-claim intro.
   *
   * At most six words, and one of FIXED_INTROS ("here is" counts as
   * "here's").
   */
  public static function isFixedIntro(string $text): bool {
    $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    $text = rtrim(trim(str_replace(['’', '*', '_'], ["'", '', ''], $text)), ': ');
    $text = (string) preg_replace("/^here is /u", "here's ", $text);
    if ($text === '' || count(explode(' ', $text)) > 6) {
      return FALSE;
    }
    return in_array($text, self::FIXED_INTROS, TRUE);
  }

  /**
   * Runs preg_replace() and fails closed: NULL becomes '' and a throw.
   */
  private static function replace(string $pattern, string $replacement, string $subject): string {
    return self::ensure(preg_replace($pattern, $replacement, $subject));
  }

  /**
   * Runs preg_replace_callback() and fails closed.
   */
  private static function replaceCallback(string $pattern, callable $callback, string $subject): string {
    return self::ensure(preg_replace_callback($pattern, $callback, $subject));
  }

  /**
   * Turns a failed regex result into a throw.
   */
  private static function ensure(?string $result): string {
    $result ??= '';
    if ($result === '') {
      throw new UncitableAnswerException('empty_body');
    }
    return $result;
  }

}
