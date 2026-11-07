<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Component\Utility\Xss;

/**
 * Cleans third-party HTML before it is stored as a Topic body (D-13, E8).
 *
 * Both import paths (a MediaWiki's action=parse HTML and readability.php's
 * main content) pass through here, and the result is stored in the
 * imported_html text format, so a stored body never carries markup that
 * format wouldn't render (threat T-22-51). In order:
 * 1. Html::load() parses the fragment into a DOM;
 * 2. elements whose content is code, media or chrome are removed with their
 *    content (script, style, iframe, form, img, ..., the wiki's navboxes,
 *    edit links, table of contents and print footer), and so are comments;
 * 3. i and b become em and strong, so quoted words keep their emphasis;
 * 4. every attribute is removed except the few the format allows (href and
 *    hreflang on links, cite on blockquotes, id on headings, list types,
 *    table header scope), which drops class, style and every on* handler;
 * 5. href and cite are made absolute against the source address, and any
 *    that isn't http, https, mailto or an in-page anchor is removed;
 * 6. Html::serialize(), then Xss::filter() with the format's tag list.
 *
 * Blockquotes and the paragraphs around them are kept as they are, so a
 * quoted writer's words stay a quote with their credit line, never the
 * community's or the wiki's own words (D-14).
 */
final class HtmlSanitizer {

  /**
   * Tags kept in a stored body: the imported_html format's filter_html list.
   *
   * RestrictedFormatTest checks that the shipped format lists exactly these.
   */
  public const ALLOWED_TAGS = [
    'p', 'br', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'em', 'strong', 'cite', 'q',
    'blockquote', 'code', 'pre', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'sup',
    'sub', 'table', 'caption', 'thead', 'tbody', 'tr', 'th', 'td',
  ];

  /**
   * Elements removed together with everything inside them.
   *
   * Xss::filter() would drop these tags but keep their text, which would
   * leave CSS rules, script code or button labels in the body.
   */
  private const REMOVED_ELEMENTS = [
    'script', 'style', 'iframe', 'frame', 'frameset', 'form', 'noscript',
    'object', 'embed', 'applet', 'img', 'picture', 'svg', 'math', 'template',
    'button', 'input', 'select', 'textarea', 'link', 'meta', 'base', 'audio',
    'video', 'source', 'track', 'canvas', 'map', 'nav',
  ];

  /**
   * Wiki chrome removed by class.
   *
   * The references list (.mw-references-wrap) stays: it is the page's own
   * citations.
   */
  private const REMOVED_CLASSES = ['navbox', 'mw-editsection', 'printfooter', 'mw-jump-link', 'catlinks', 'toc'];

  /**
   * Wiki chrome removed by id.
   */
  private const REMOVED_IDS = ['toc', 'catlinks', 'jump-to-nav'];

  /**
   * Elements renamed to the format's equivalent.
   */
  private const RENAMED = ['i' => 'em', 'b' => 'strong'];

  /**
   * Attributes kept, by element. Everything else is removed.
   */
  private const ALLOWED_ATTRIBUTES = [
    'a' => ['href', 'hreflang'],
    'blockquote' => ['cite'],
    'h2' => ['id'],
    'h3' => ['id'],
    'h4' => ['id'],
    'h5' => ['id'],
    'h6' => ['id'],
    'ul' => ['type'],
    'ol' => ['start', 'type'],
    'th' => ['scope'],
  ];

  /**
   * Attributes that hold an address, made absolute against the source.
   */
  private const URL_ATTRIBUTES = ['href', 'cite'];

  /**
   * Link schemes kept after resolution.
   */
  private const URL_SCHEMES = ['http', 'https', 'mailto'];

  /**
   * Cleans an HTML fragment for storage in the imported_html format.
   *
   * @param string $html
   *   Untrusted HTML: a page, or a fragment of one.
   * @param string $base_url
   *   The address the HTML came from; relative links resolve against it.
   *
   * @return string
   *   HTML that holds only ALLOWED_TAGS and their allowed attributes.
   */
  public function sanitize(string $html, string $base_url): string {
    if (trim($html) === '') {
      return '';
    }
    $document = Html::load($html);
    $xpath = new \DOMXPath($document);

    $doomed = [];
    foreach (self::REMOVED_ELEMENTS as $tag) {
      foreach ($document->getElementsByTagName($tag) as $node) {
        $doomed[] = $node;
      }
    }
    foreach (self::REMOVED_CLASSES as $class) {
      foreach ($xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]") as $node) {
        $doomed[] = $node;
      }
    }
    foreach (self::REMOVED_IDS as $id) {
      foreach ($xpath->query("//*[@id='$id']") as $node) {
        $doomed[] = $node;
      }
    }
    foreach ($xpath->query('//comment()') as $node) {
      $doomed[] = $node;
    }
    // Collected first: removing while a live list is iterated skips nodes.
    foreach ($doomed as $node) {
      $node->parentNode?->removeChild($node);
    }

    foreach (self::RENAMED as $from => $to) {
      $nodes = iterator_to_array($document->getElementsByTagName($from));
      foreach ($nodes as $node) {
        $this->rename($node, $to);
      }
    }

    foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
      $this->cleanAttributes($element, $base_url);
    }

    return Xss::filter(Html::serialize($document), self::ALLOWED_TAGS);
  }

  /**
   * Replaces an element with a new one of another name, keeping its content.
   */
  private function rename(\DOMElement $element, string $name): void {
    $replacement = $element->ownerDocument->createElement($name);
    while ($element->firstChild !== NULL) {
      $replacement->appendChild($element->firstChild);
    }
    $element->parentNode?->replaceChild($replacement, $element);
  }

  /**
   * Removes every attribute the format doesn't allow on this element.
   */
  private function cleanAttributes(\DOMElement $element, string $base_url): void {
    $allowed = self::ALLOWED_ATTRIBUTES[strtolower($element->tagName)] ?? [];
    $names = [];
    foreach ($element->attributes as $attribute) {
      $names[] = $attribute->nodeName;
    }
    foreach ($names as $name) {
      $lower = strtolower($name);
      if (!in_array($lower, $allowed, TRUE)) {
        $element->removeAttribute($name);
        continue;
      }
      if (in_array($lower, self::URL_ATTRIBUTES, TRUE)) {
        $url = self::absolute($element->getAttribute($name), $base_url);
        if ($url === NULL) {
          $element->removeAttribute($name);
        }
        else {
          $element->setAttribute($name, $url);
        }
      }
    }
  }

  /**
   * Parses a whole page (or a fragment) into a DOM for reading.
   *
   * For detection and licence lookups only; nothing read this way is
   * stored. Errors in broken markup are ignored, and no network is used.
   */
  public static function loadDocument(string $html): \DOMDocument {
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $document;
  }

  /**
   * Whether an element's rel attribute holds a token, in any case.
   */
  public static function hasRelToken(\DOMElement $element, string $token): bool {
    $tokens = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel')))) ?: [];
    return in_array(strtolower($token), $tokens, TRUE);
  }

  /**
   * Resolves a link against the source address (RFC 3986, section 5.2).
   *
   * @return string|null
   *   The absolute address, an in-page anchor as given, or NULL when the
   *   link can't be resolved or uses a scheme other than http(s)/mailto.
   */
  public static function absolute(string $reference, string $base_url): ?string {
    $reference = trim($reference);
    if ($reference === '') {
      return NULL;
    }
    if ($reference[0] === '#') {
      return $reference;
    }
    if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $reference, $match)) {
      return in_array(strtolower($match[1]), self::URL_SCHEMES, TRUE) ? UrlHelper::filterBadProtocol($reference) : NULL;
    }

    $base = parse_url($base_url);
    if (!isset($base['scheme'], $base['host']) || !in_array(strtolower($base['scheme']), ['http', 'https'], TRUE)) {
      return NULL;
    }
    $scheme = strtolower($base['scheme']);
    if (str_starts_with($reference, '//')) {
      return $scheme . ':' . $reference;
    }
    $origin = $scheme . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    $base_path = $base['path'] ?? '/';
    if ($reference[0] === '/') {
      return $origin . self::removeDotSegments($reference);
    }
    if ($reference[0] === '?') {
      return $origin . $base_path . $reference;
    }
    $directory = substr($base_path, 0, (int) strrpos($base_path, '/') + 1) ?: '/';
    return $origin . self::removeDotSegments($directory . $reference);
  }

  /**
   * Removes "." and ".." path segments (RFC 3986, section 5.2.4).
   *
   * A query or fragment after the path is kept as it is.
   */
  private static function removeDotSegments(string $reference): string {
    $split = strcspn($reference, '?#');
    $path = substr($reference, 0, $split);
    $rest = substr($reference, $split);
    $output = [];
    foreach (explode('/', $path) as $segment) {
      if ($segment === '..') {
        if (count($output) > 1) {
          array_pop($output);
        }
      }
      elseif ($segment !== '.') {
        $output[] = $segment;
      }
    }
    $last = basename($path);
    $resolved = implode('/', $output);
    if (($last === '.' || $last === '..') && !str_ends_with($resolved, '/')) {
      $resolved .= '/';
    }
    return ($resolved === '' ? '/' : $resolved) . $rest;
  }

}
