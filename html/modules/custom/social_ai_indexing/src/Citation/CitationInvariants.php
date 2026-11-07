<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Citation;

/**
 * The single citation invariant check (AI-SPEC §5, E3).
 *
 * Unit tests and the live verification script both call check(), so the
 * rules live in one place. Each violation is "code: detail"; the codes are
 * numbering, dangling, orphan, duplicate_link_text, leftover_label,
 * absolute_url_in_body, link_in_body, url_not_root_relative, not_in_registry,
 * duplicate_entity, malformed_sources_line and parse_failure.
 */
final class CitationInvariants {

  /**
   * Checks a rendered answer against the request's registry.
   *
   * @param string $markdown
   *   A CitedAnswer::toMarkdown() result, or the fixed copy.
   * @param \Drupal\social_ai_indexing\Citation\SourceRegistry $registry
   *   The registry the answer was built from.
   *
   * @return list<string>
   *   Violations; empty when the answer is valid.
   */
  public static function check(string $markdown, SourceRegistry $registry): array {
    $violations = [];

    // Split the body from the Sources block before scanning [n] markers.
    $heading = "\n" . CitedAnswer::SOURCES_HEADING . "\n";
    $position = strrpos($markdown, $heading);
    if ($position === FALSE) {
      $body = $markdown;
      $lines = [];
    }
    else {
      $body = substr($markdown, 0, $position);
      $lines = array_values(array_filter(
        explode("\n", substr($markdown, $position + strlen($heading))),
        static fn (string $line): bool => trim($line) !== '',
      ));
    }

    // Body: no raw labels, no absolute URLs, no links.
    $count = preg_match_all('/\[R\d+\]/i', $body, $leftover);
    if ($count === FALSE) {
      return ['parse_failure: body labels'];
    }
    foreach (array_unique($leftover[0]) as $label) {
      $violations[] = 'leftover_label: ' . $label . ' in body';
    }
    if (preg_match('#https?://#i', $body) !== 0) {
      $violations[] = 'absolute_url_in_body: an http(s) URL remains in the body';
    }
    if (preg_match('/\]\([^)]*\)|<a\b/i', $body) !== 0) {
      $violations[] = 'link_in_body: a link remains in the body';
    }

    // Body numbers: first uses must run 1, 2, ..., k.
    if (preg_match_all('/\[(\d+)\]/', $body, $matches) === FALSE) {
      return ['parse_failure: body numbers'];
    }
    $first_uses = array_values(array_unique(array_map('intval', $matches[1])));
    if ($first_uses !== ($first_uses === [] ? [] : range(1, count($first_uses)))) {
      $violations[] = 'numbering: first uses run ' . implode(', ', $first_uses) . ', expected 1..' . count($first_uses) . ' in order';
    }

    // Sources entries.
    $by_url = [];
    foreach ($registry->all() as $source) {
      $by_url[$source->url] = $source;
    }
    $entry_numbers = [];
    $texts = [];
    $keys = [];
    foreach ($lines as $index => $line) {
      if (preg_match('/^- \[(\d+)\] \[(.+)\]\((\S+)\)$/', $line, $entry) !== 1) {
        $violations[] = 'malformed_sources_line: ' . $line;
        continue;
      }
      [, $number, $text, $url] = $entry;
      $number = (int) $number;
      $entry_numbers[] = $number;
      if ($number !== $index + 1) {
        $violations[] = 'numbering: Sources entry ' . ($index + 1) . ' is numbered [' . $number . ']';
      }
      if (isset($texts[$text])) {
        $violations[] = 'duplicate_link_text: "' . $text . '" in [' . $texts[$text] . '] and [' . $number . ']';
      }
      $texts[$text] ??= $number;
      if (!RetrievedSource::isRootRelative($url)) {
        $violations[] = 'url_not_root_relative: [' . $number . '] ' . $url;
        continue;
      }
      $source = $by_url[$url] ?? NULL;
      if ($source === NULL) {
        $violations[] = 'not_in_registry: [' . $number . '] ' . $url;
        continue;
      }
      if (isset($keys[$source->key()])) {
        $violations[] = 'duplicate_entity: ' . $source->key() . ' is [' . $keys[$source->key()] . '] and [' . $number . ']';
      }
      $keys[$source->key()] ??= $number;
    }

    // Every body number has an entry, and every entry is cited.
    foreach (array_diff($first_uses, $entry_numbers) as $number) {
      $violations[] = 'dangling: [' . $number . '] has no Sources entry';
    }
    foreach (array_diff($entry_numbers, $first_uses) as $number) {
      $violations[] = 'orphan: Sources entry [' . $number . '] is not cited in the body';
    }

    return $violations;
  }

}
