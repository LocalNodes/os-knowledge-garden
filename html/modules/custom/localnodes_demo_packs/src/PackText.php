<?php

declare(strict_types=1);

namespace Drupal\localnodes_demo_packs;

/**
 * Text helpers for the word-for-word check of pack copy.
 *
 * A pack keeps its copy in a markdown source file and loads it as HTML. The
 * check compares the two as plain text. It is a word-for-word check, so it
 * folds only typography and markup, never words: markdown markers, HTML
 * tags and entities, white space, straight versus curly quotes and
 * non-breaking spaces. A changed, added or dropped word is a difference.
 *
 * No Drupal calls, so Unit tests use it directly.
 */
final class PackText {

  /**
   * Folds typography both sides of a comparison share.
   *
   * Curly quotes and apostrophes become straight ones, non-breaking and
   * narrow spaces become spaces, runs of white space become one space, and
   * the ends are trimmed. Case is kept.
   */
  public static function normalize(string $text): string {
    $text = strtr($text, [
      "\u{2018}" => "'",
      "\u{2019}" => "'",
      "\u{201B}" => "'",
      "\u{201C}" => '"',
      "\u{201D}" => '"',
      "\u{201F}" => '"',
    ]);
    return trim((string) preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $text));
  }

  /**
   * Turns each day token back into the "day +N" the source file says.
   *
   * Pack text holds "{{day:+7}}" where the source says "day +7".
   */
  public static function untoken(string $text): string {
    return (string) preg_replace('/\{\{day:([+-]\d+)\}\}/', 'day $1', $text);
  }

  /**
   * Plain text of a markdown source file, normalized.
   *
   * The blocks of markdownBlocks() joined with single spaces.
   */
  public static function normalizeMarkdown(string $markdown): string {
    return self::normalize(implode(' ', self::markdownBlocks($markdown)));
  }

  /**
   * The text blocks of a markdown source file, each normalized.
   *
   * A heading, a list item, a block quote and a table row are each a block
   * (consecutive quote lines are one); any other run of lines up to a blank
   * line is one block, so a wrapped paragraph stays whole. Inside a block,
   * emphasis markers are dropped (`**`, `*`, and `_` where it acts as
   * emphasis, so a name such as smoke_one keeps its underscore), as are
   * heading marks, bullets and numbers, quote marks, table pipes,
   * backticks and link syntax (the link text stays). Table separator rows
   * and horizontal rules carry no words and make no block.
   *
   * @return string[]
   */
  public static function markdownBlocks(string $markdown): array {
    $blocks = [];
    $current = [];
    $kind = '';
    $flush = static function () use (&$blocks, &$current, &$kind): void {
      if ($current && ($text = self::normalize(implode(' ', $current))) !== '') {
        $blocks[] = $text;
      }
      $current = [];
      $kind = '';
    };
    foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || preg_match('/^[|\s:\-=_*]+$/', $line)) {
        $flush();
        continue;
      }
      $marker = preg_match('/^(?:#{1,6}\s+|>+\s*|[-*+]\s+|\d+[.)]\s+|\|)/u', $line, $found) === 1 ? $found[0] : '';
      $line_kind = $marker === '' ? '' : ($marker[0] === '>' ? 'quote' : ($marker[0] === '#' ? 'heading' : 'item'));
      if ($line_kind === 'quote' && $kind === 'quote') {
        // The next line of one block quote.
      }
      elseif ($line_kind !== '' || $kind === 'heading' || $kind === 'row') {
        $flush();
      }
      if ($line_kind !== '') {
        $kind = $line_kind;
      }
      elseif ($current === []) {
        $kind = 'paragraph';
      }
      if ($marker !== '' && $marker === '|') {
        $kind = 'row';
      }
      $current[] = self::cleanMarkdownLine($line);
    }
    $flush();
    return $blocks;
  }

  /**
   * One markdown line without its markers.
   */
  private static function cleanMarkdownLine(string $line): string {
    $line = (string) preg_replace('/^(?:#{1,6}\s+|>+\s*|[-*+]\s+|\d+[.)]\s+)+/u', '', $line);
    $line = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $line);
    $line = str_replace(['`', '|', '**', '*'], ['', ' ', '', ''], $line);
    return (string) preg_replace('/(?<![\p{L}\p{N}])_+(?=[\p{L}\p{N}])|(?<=[\p{L}\p{N}])_+(?![\p{L}\p{N}])/u', '', $line);
  }

  /**
   * Whether the text is inside one of the blocks, on whole words.
   *
   * The match stays inside a single block, so text from two blocks (a heading
   * and the line below it) is not one match, and the ends sit on word
   * boundaries, so half a word is not a match. The blocks come normalized
   * (markdownBlocks() or blocks()).
   *
   * @param string[] $blocks
   *   The blocks to search.
   */
  public static function inBlocks(string $text, array $blocks, bool $ignoreCase = FALSE): bool {
    $needle = self::normalize($text);
    if ($needle === '') {
      return FALSE;
    }
    $word = '[\p{L}\p{N}_]';
    $pattern = '/'
      . (preg_match("/^$word/u", $needle) ? "(?<!$word)" : '')
      . preg_quote($needle, '/')
      . (preg_match("/$word\$/u", $needle) ? "(?!$word)" : '')
      . '/u' . ($ignoreCase ? 'i' : '');
    foreach ($blocks as $block) {
      if (preg_match($pattern, (string) $block) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Elements that start a new block of text.
   */
  private const BLOCK_ELEMENTS = [
    'address', 'article', 'aside', 'blockquote', 'body', 'caption', 'dd',
    'details', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer',
    'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hr', 'html',
    'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td',
    'tfoot', 'th', 'thead', 'tr', 'ul',
  ];

  /**
   * The text blocks of an HTML value, entities decoded, normalized.
   *
   * Every text node of the value comes out once: the text between two block
   * element boundaries (paragraph, list item, table cell, heading, quote,
   * division and the like) is one block, and inline markup such as a link or
   * emphasis stays inside it. A line break is a word break. A value with no
   * markup comes back as one block. Empty input has none. Text the parser
   * places outside the wrapper it was given, after a stray closing tag, is
   * read too.
   *
   * @return string[]
   */
  public static function blocks(string $html): array {
    if (trim($html) === '') {
      return [];
    }
    $previous = libxml_use_internal_errors(TRUE);
    $document = new \DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8"><div id="pack-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    // The whole document is read, not only the wrapper above: a stray closing
    // tag in the value ends the wrapper, and what follows it sits beside it
    // (or beside the body) in the tree.
    $blocks = [];
    $current = '';
    self::collect($document, $blocks, $current);
    self::flush($blocks, $current);
    return $blocks;
  }

  /**
   * Adds the text under a node to the blocks, starting one at each block edge.
   *
   * @param string[] $blocks
   *   The finished blocks.
   * @param string $current
   *   The text of the block being read.
   */
  private static function collect(\DOMNode $node, array &$blocks, string &$current): void {
    foreach ($node->childNodes as $child) {
      if ($child instanceof \DOMText) {
        $current .= $child->data;
      }
      elseif ($child instanceof \DOMElement) {
        $name = strtolower($child->nodeName);
        if ($name === 'br') {
          $current .= ' ';
          continue;
        }
        $block = in_array($name, self::BLOCK_ELEMENTS, TRUE);
        if ($block) {
          self::flush($blocks, $current);
        }
        self::collect($child, $blocks, $current);
        if ($block) {
          self::flush($blocks, $current);
        }
      }
    }
  }

  /**
   * Ends the block being read, if it holds text.
   *
   * @param string[] $blocks
   *   The finished blocks.
   * @param string $current
   *   The text of the block being read; emptied.
   */
  private static function flush(array &$blocks, string &$current): void {
    if (($text = self::normalize($current)) !== '') {
      $blocks[] = $text;
    }
    $current = '';
  }

}
