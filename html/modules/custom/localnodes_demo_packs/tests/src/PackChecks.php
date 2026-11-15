<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs;

use Drupal\localnodes_demo_packs\PackText;
use Drupal\localnodes_platform\Mail\ReservedRecipientFilter;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * The rules the pack tests apply, as plain functions of parsed pack data.
 *
 * Each rule takes the parsed YAML of a pack (file name, then entries keyed by
 * uuid) or a source file and returns the problems it finds, so a Unit test can
 * show every rule a made-up pack that breaks it. The pack tests then call the
 * same functions on the real packs and expect no problems.
 *
 * No Drupal calls.
 */
final class PackChecks {

  /**
   * The YAML keys whose values are prose a member reads.
   *
   * A nested value (body: {value: ..., format: ...}) counts under the name of
   * its field. Add a key here when a pack file uses another one.
   */
  public const PROSE_KEYS = [
    'title', 'label', 'body', 'description', 'field_post', 'message',
    'question', 'choices', 'field_event_address', 'field_event_location',
    'field_group_description', 'self_introduction', 'organization',
    'function', 'address', 'image_alt',
  ];

  /**
   * Every string in parsed data, at any depth, with its nearest named key.
   *
   * List items (poll choices, member lists) take the key of their list, and a
   * "value" under a named field takes that field's name.
   *
   * @return array<int, array{key: string, value: string}>
   */
  public static function stringsOf(array $data, string $key = ''): array {
    $found = [];
    foreach ($data as $name => $value) {
      $named = is_int($name) || ($name === 'value' && $key !== '') ? $key : (string) $name;
      if (is_array($value)) {
        $found = array_merge($found, self::stringsOf($value, $named));
      }
      elseif (is_string($value)) {
        $found[] = ['key' => $named, 'value' => $value];
      }
    }
    return $found;
  }

  /**
   * Entries of a parsed pack file as [uuid, data] pairs.
   *
   * @return array<int, array{0: string, 1: array}>
   *   The uuid is the entry's uuid field, or its key when it has none.
   */
  public static function entries(array $file): array {
    $entries = [];
    foreach ($file as $key => $data) {
      if (!is_array($data)) {
        continue;
      }
      $entries[] = [(string) ($data['uuid'] ?? $key), $data];
    }
    return $entries;
  }

  /**
   * The text of one entry as a member reads it: every string, by its blocks.
   *
   * Day tokens read as "day +N". The result is normalized, not case-folded.
   * The left-out checks search this for text a pack must not load.
   */
  public static function textOfEntry(array $entry): string {
    $parts = [];
    foreach (self::stringsOf($entry) as $string) {
      $parts[] = implode(' ', PackText::blocks(PackText::untoken($string['value'])));
    }
    return PackText::normalize(implode(' ', $parts));
  }

  /**
   * Prose in a pack's YAML that is not in the source, inside one block.
   *
   * Reads every string under a prose key (PROSE_KEYS), at any depth, by its
   * HTML blocks. An enrollment's title is built from two names that other
   * rules check (see enrollmentProblems()).
   *
   * @param string $pack
   *   The pack name, for the messages.
   * @param array<string, array> $files
   *   The pack's parsed files by file name.
   * @param string[] $source
   *   The source's blocks (PackText::markdownBlocks()).
   *
   * @return array{checked: int, problems: string[]}
   *   The number of blocks compared, and one message for each that fails.
   */
  public static function proseProblems(string $pack, array $files, array $source): array {
    $checked = 0;
    $problems = [];
    foreach ($files as $file => $entries) {
      foreach (self::entries($entries) as [$uuid, $entry]) {
        foreach (self::stringsOf($entry) as $string) {
          if (!in_array($string['key'], self::PROSE_KEYS, TRUE)) {
            continue;
          }
          if ($file === 'event-enrollment.yml' && $string['key'] === 'title') {
            continue;
          }
          $value = PackText::untoken($string['value']);
          if (trim($value) === '') {
            continue;
          }
          $blocks = PackText::blocks($value);
          if ($blocks === []) {
            $problems[] = "Pack $pack, $file, $uuid: {$string['key']} has markup and no text.";
          }
          foreach ($blocks as $index => $block) {
            $checked++;
            if (!PackText::inBlocks($block, $source)) {
              $problems[] = "Pack $pack, $file, $uuid: {$string['key']}, block " . ($index + 1) . ' is not in source.md word for word, inside one block.';
            }
          }
        }
      }
    }
    return ['checked' => $checked, 'problems' => $problems];
  }

  /**
   * Enrollment titles that are not "<member name> @ <event title>".
   *
   * @param array<string, array> $files
   *   The pack's parsed files by file name.
   *
   * @return string[]
   *   One message for each title that is not that composition of the member
   *   and the event the enrollment names.
   */
  public static function enrollmentProblems(array $files): array {
    $names = [];
    foreach (self::entries($files['user.yml'] ?? []) as [$uuid, $user]) {
      $names[$uuid] = PackText::normalize((string) ($user['name'] ?? ''));
    }
    $titles = [];
    foreach (self::entries($files['event.yml'] ?? []) as [$uuid, $event]) {
      $titles[$uuid] = PackText::normalize((string) ($event['title'] ?? ''));
    }
    $problems = [];
    foreach (self::entries($files['event-enrollment.yml'] ?? []) as [$uuid, $enrollment]) {
      $member = (string) ($enrollment['uid'] ?? '');
      $event = (string) ($enrollment['field_event'] ?? '');
      if (!isset($names[$member]) || !isset($titles[$event])) {
        $problems[] = "Enrollment $uuid names a member or event the pack does not hold.";
        continue;
      }
      $expected = $names[$member] . ' @ ' . $titles[$event];
      if (PackText::normalize((string) ($enrollment['title'] ?? '')) !== $expected) {
        $problems[] = "Enrollment $uuid: its title is not '<member> @ <event>' from the pack's own names.";
      }
    }
    return $problems;
  }

  /**
   * Entries whose key is not their uuid, and uuids used twice.
   *
   * @param array<string, array> $files
   *   The pack's parsed files by file name.
   *
   * @return string[]
   *   One message for each fault.
   */
  public static function entryProblems(array $files): array {
    $problems = [];
    $seen = [];
    foreach ($files as $file => $entries) {
      foreach ($entries as $key => $data) {
        if (!is_array($data)) {
          continue;
        }
        $uuid = (string) ($data['uuid'] ?? '');
        if ($uuid === '') {
          $problems[] = "$file, entry $key: has no uuid field.";
          continue;
        }
        if ($uuid !== (string) $key) {
          $problems[] = "$file, entry $key: its uuid field is $uuid, not its key.";
        }
        if (isset($seen[$uuid])) {
          $problems[] = "$file: the uuid $uuid is already used in {$seen[$uuid]}.";
        }
        $seen[$uuid] = $file;
      }
    }
    return $problems;
  }

  /**
   * The person records of the source's People section.
   *
   * The section opens at a heading that is exactly "People", with or without a
   * number before it ("1. People"), and runs to the next heading of the same
   * or a higher level. A record is a list item "username (Display Name): text"
   * or a table row whose first cell is the display name; its username is the
   * display name in lower case, with spaces as underscores and without
   * apostrophes ("Liam O'Brien" is liam_obrien). The header row and the
   * separator row of a table are not records.
   *
   * @return array<string, string>
   *   Display name by username.
   */
  public static function peopleRecords(string $source): array {
    $records = [];
    $level = 0;
    foreach (preg_split('/\R/u', $source) ?: [] as $line) {
      $line = trim($line);
      if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/u', $line, $heading)) {
        if ($level > 0 && strlen($heading[1]) <= $level) {
          break;
        }
        if ($level === 0 && preg_match('/^(?:\d+\.\s+)?People$/u', trim($heading[2])) === 1) {
          $level = strlen($heading[1]);
        }
        continue;
      }
      if ($level === 0) {
        continue;
      }
      if (preg_match('/^[-*+]\s+(\S+)\s+\(([^)]+)\)\s*:/u', $line, $record)) {
        $records[$record[1]] = PackText::normalize($record[2]);
      }
      elseif (str_starts_with($line, '|')) {
        $cells = array_map('trim', explode('|', trim($line, '|')));
        $display = PackText::normalize($cells[0] ?? '');
        if ($display === '' || preg_match('/^:?-+:?$/', $display) === 1 || mb_strtolower($display) === 'name') {
          continue;
        }
        $username = preg_replace('/\s+/u', '_', str_replace(["'", "\u{2019}"], '', mb_strtolower($display)));
        $records[(string) $username] = $display;
      }
    }
    return $records;
  }

  /**
   * Accounts whose name is not a whole name the source's People lists.
   *
   * Each user's name must be a username of a person record, and the first and
   * last name together must be that record's display name.
   *
   * @param string $source
   *   The raw source.md.
   * @param array $users
   *   The parsed user.yml.
   *
   * @return string[]
   *   One message for each fault.
   */
  public static function nameProblems(string $source, array $users): array {
    $records = self::peopleRecords($source);
    if ($records === []) {
      return ["The source has no People section with person records ('username (Display Name): ...')."];
    }
    $problems = [];
    foreach (self::entries($users) as [$uuid, $user]) {
      $name = PackText::normalize((string) ($user['name'] ?? ''));
      if (!isset($records[$name])) {
        $problems[] = "User $uuid: the name is not a username in the source's People section.";
        continue;
      }
      $display = PackText::normalize(trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')));
      if ($display !== '' && $display !== $records[$name]) {
        $problems[] = "User $uuid: the first and last name are not the display name the source gives that account.";
      }
    }
    return $problems;
  }

  /**
   * Addresses in a text that are not on a reserved domain.
   *
   * Entities are decoded first (also a doubly encoded one), so an address
   * written as "name&#64;domain" is read as the address it is. The messages
   * name the domain, never the address, which could be real.
   *
   * @return array{count: int, problems: string[]}
   *   The addresses found, and one message for each that is not reserved.
   */
  public static function addressProblems(string $text): array {
    for ($pass = 0; $pass < 3; $pass++) {
      $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      if ($decoded === $text) {
        break;
      }
      $text = $decoded;
    }
    $count = 0;
    $problems = [];
    if (preg_match_all('/[^\s<>"\',;:()]+@[^\s<>"\',;:()]+/u', $text, $found)) {
      foreach ($found[0] as $address) {
        $count++;
        if (!ReservedRecipientFilter::isReserved($address)) {
          $problems[] = 'An address on ' . strtolower(substr((string) strrchr($address, '@'), 1)) . ' is not on a reserved domain.';
        }
      }
    }
    return ['count' => $count, 'problems' => $problems];
  }

  /**
   * Addresses in the text of a YAML file that are not on a reserved domain.
   *
   * Reads the file twice: as written, through addressProblems(), and as
   * parsed, every string (mapping keys too) at any depth through the same
   * check. A YAML escape such as "\u0040" or "\x40" is an "@" only after
   * parsing, so the raw text alone would let it by.
   *
   * @return array{count: int, problems: string[]}
   *   The most addresses either reading found, and one message for each
   *   kind of problem found by either.
   *
   * @throws \Symfony\Component\Yaml\Exception\ParseException
   *   When the text is not YAML.
   */
  public static function yamlAddressProblems(string $yaml): array {
    $result = self::addressProblems($yaml);
    $count = $result['count'];
    $problems = $result['problems'];
    $data = Yaml::parse($yaml);
    $parsed_count = 0;
    foreach (self::scalarsOf($data) as $scalar) {
      $found = self::addressProblems($scalar);
      $parsed_count += $found['count'];
      $problems = array_merge($problems, $found['problems']);
    }
    return ['count' => max($count, $parsed_count), 'problems' => array_values(array_unique($problems))];
  }

  /**
   * Every string in parsed YAML, keys and values, at any depth.
   *
   * @return string[]
   */
  private static function scalarsOf(mixed $data): array {
    if (is_string($data)) {
      return [$data];
    }
    if (!is_array($data)) {
      return [];
    }
    $found = [];
    foreach ($data as $key => $value) {
      if (is_string($key)) {
        $found[] = $key;
      }
      $found = array_merge($found, self::scalarsOf($value));
    }
    return $found;
  }

  /**
   * The text with each day token replaced by the date a member should read.
   *
   * A second implementation, apart from PackTokens: the date is worked out
   * with DateTimeImmutable in the site's timezone, so a check that compares a
   * loaded page with this does not agree with the loader by sharing its code.
   * The format is the weekday and date, "Thursday 19 November".
   *
   * @throws \InvalidArgumentException
   *   When "{{" is still in the text after the tokens are replaced.
   */
  public static function expectedDate(string $text, int $anchor): string {
    $zone = new \DateTimeZone(date_default_timezone_get());
    $start = (new \DateTimeImmutable('@' . $anchor))->setTimezone($zone);
    $resolved = preg_replace_callback(
      '/\{\{day:([+-])(\d+)\}\}/',
      static fn (array $m): string => $start->modify($m[1] . $m[2] . ' days')->format('l j F'),
      $text
    );
    if ($resolved === NULL || str_contains($resolved, '{{')) {
      throw new \InvalidArgumentException('A day token is not +N or -N.');
    }
    return $resolved;
  }

  /**
   * The calls whose string arguments a member reads.
   */
  private const EMITTING_CALLS = ['t', 'formatPlural', 'setTitle', 'TranslatableMarkup'];

  /**
   * The render-array keys whose string value a member reads.
   */
  private const EMITTING_KEYS = ['#title', '#value', '#markup', '#placeholder', '#text'];

  /**
   * The YAML keys whose value a member reads as a name.
   */
  private const EMITTING_YAML_KEYS = ['title', 'label', 'link_title'];

  /**
   * Whether a source file emits a label, ignoring its comments.
   *
   * A label counts only where the code or the template puts it on a screen:
   * - php: a string that is a direct argument of t(), $this->t(), formatPlural(),
   *   ->setTitle() or new TranslatableMarkup(); the value of a render key
   *   (#title, #value, #markup, #placeholder, #text), in an array or set by
   *   assignment; inline HTML; or a string held by a class constant that the
   *   same file reads through self::, static:: or its own class name. A string
   *   in a variable, an unread constant, another call or a nested option array
   *   does not count. The comments (`//`, `#`, block and doc comments) are
   *   dropped by the tokenizer first, and a heredoc is not read.
   * - twig: template text outside `{% if false %}` blocks, with the `{{ }}` and
   *   `{% %}` tags removed, or the string of a `|t` filter, after the `{# #}`
   *   and `<!-- -->` comments are removed.
   * - yml: the value of a `title`, `label` or `link_title` key, after the `#`
   *   comments are removed by the parser.
   * The label must stand alone in the string, or between markup tags, or
   * inside the asterisks of markdown bold, or beside the \x03 that separates
   * the singular and the plural of a translated pair.
   *
   * @throws \InvalidArgumentException
   *   When the file kind is not php, twig or yml, so no row passes unread.
   */
  public static function labelInFile(string $path, string $label): bool {
    $code = (string) file_get_contents($path);
    if (preg_match('/\.php$/', $path)) {
      $strings = self::phpStrings($code);
    }
    elseif (preg_match('/\.twig$/', $path)) {
      $strings = self::twigStrings($code);
    }
    elseif (preg_match('/\.ya?ml$/', $path)) {
      $strings = self::yamlStrings($code);
    }
    else {
      throw new \InvalidArgumentException('The file kind is not read.');
    }
    $pattern = '/(?<![^\x03>*{}\'"`\n])' . preg_quote($label, '/') . '(?![^\x03<*{}\'"`\n])/u';
    foreach ($strings as $string) {
      if (preg_match($pattern, $string) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * A quoted PHP string token decoded.
   */
  private static function decodePhpString(string $text): string {
    $inner = substr($text, 1, -1);
    return $text[0] === "'"
      ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
      : stripcslashes($inner);
  }

  /**
   * The strings a PHP file puts on a screen, apart from its comments.
   *
   * @return string[]
   *   Each quoted string that is a direct argument of an emitting call or the
   *   value of an emitting render key, each string of a class constant the
   *   file reads, and each inline HTML block.
   */
  private static function phpStrings(string $code): array {
    $tokens = array_values(array_filter(
      token_get_all($code),
      static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)
    ));
    $count = count($tokens);
    $kind = static fn (int $i): mixed => $tokens[$i] ?? NULL;
    $is_string = static fn (mixed $token): bool => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING;
    $name_of = static function (mixed $token): ?string {
      if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], TRUE)) {
        $parts = explode('\\', $token[1]);
        return end($parts);
      }
      return NULL;
    };
    // A string stands alone as an argument, an array value or a right side.
    $standalone = static fn (int $i): bool => $is_string($kind($i)) && in_array($kind($i + 1), [',', ')', ']', ';'], TRUE);

    // The class constants: their strings, and the names the file reads.
    $class = NULL;
    $constants = [];
    $reads = [];
    for ($i = 0; $i < $count; $i++) {
      $token = $tokens[$i];
      if (is_array($token) && $token[0] === T_CLASS && $class === NULL && ($name = $name_of($kind($i + 1))) !== NULL) {
        $class = $name;
      }
      if (is_array($token) && $token[0] === T_CONST) {
        $constant = NULL;
        for ($j = $i + 1; $j < $count && $tokens[$j] !== '=' && $tokens[$j] !== ';'; $j++) {
          $constant = $name_of($tokens[$j]) ?? $constant;
        }
        $depth = 0;
        for ($k = $j + 1; $k < $count; $k++) {
          if (in_array($tokens[$k], ['(', '[', '{'], TRUE)) {
            $depth++;
          }
          elseif (in_array($tokens[$k], [')', ']', '}'], TRUE)) {
            $depth--;
          }
          elseif ($tokens[$k] === ';' && $depth <= 0) {
            break;
          }
          elseif ($is_string($tokens[$k]) && $constant !== NULL) {
            $constants[$constant][] = self::decodePhpString($tokens[$k][1]);
          }
        }
      }
      $next = $kind($i + 1);
      if (is_array($token) && is_array($next) && $next[0] === T_DOUBLE_COLON) {
        $holder = $token[0] === T_STATIC ? 'static' : $name_of($token);
        $member = $kind($i + 2);
        if ($holder !== NULL && in_array($holder, array_filter(['self', 'static', $class]), TRUE) && is_array($member) && $member[0] === T_STRING && $kind($i + 3) !== '(') {
          $reads[$member[1]] = TRUE;
        }
      }
    }

    $strings = [];
    // The open brackets, each with the name of the call it belongs to, if any.
    $stack = [];
    for ($i = 0; $i < $count; $i++) {
      $token = $tokens[$i];
      if ($token === '(') {
        $stack[] = $name_of($kind($i - 1)) ?? '';
      }
      elseif ($token === '[' || $token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], TRUE))) {
        $stack[] = NULL;
      }
      elseif ($token === ')' || $token === ']' || $token === '}') {
        array_pop($stack);
      }
      elseif (is_array($token) && $token[0] === T_INLINE_HTML) {
        $strings[] = $token[1];
      }
      elseif ($is_string($token)) {
        $text = self::decodePhpString($token[1]);
        $top = end($stack);
        $previous = $kind($i - 1);
        if ($top !== FALSE && $top !== NULL && in_array($top, self::EMITTING_CALLS, TRUE) && ($previous === '(' || $previous === ',') && $standalone($i)) {
          $strings[] = $text;
        }
        // A render key and its value: ['#title' => 'x'] or $form['#title'] = 'x'.
        if (in_array($text, self::EMITTING_KEYS, TRUE)) {
          if (is_array($kind($i + 1)) && $kind($i + 1)[0] === T_DOUBLE_ARROW && $standalone($i + 2)) {
            $strings[] = self::decodePhpString($tokens[$i + 2][1]);
          }
          elseif ($kind($i + 1) === ']' && $kind($i + 2) === '=' && $standalone($i + 3)) {
            $strings[] = self::decodePhpString($tokens[$i + 3][1]);
          }
        }
      }
    }
    foreach ($constants as $name => $values) {
      if (isset($reads[$name])) {
        array_push($strings, ...$values);
      }
    }
    return $strings;
  }

  /**
   * The strings a Twig template puts on a screen.
   *
   * @return string[]
   *   The string of each `|t` filter and the template text, without its
   *   comments, its `{% if false %}` blocks and its tags.
   */
  private static function twigStrings(string $code): array {
    $code = preg_replace('/\{#.*?#\}/s', '', $code) ?? '';
    $code = preg_replace('/<!--.*?-->/s', '', $code) ?? '';
    $code = preg_replace('/\{%-?\s*if\s+(?:false|0|null)\s*-?%\}.*?\{%-?\s*endif\s*-?%\}/s', '', $code) ?? '';
    $strings = [];
    if (preg_match_all('/([\'"])((?:\\\\.|(?!\1).)*)\1\s*\|\s*t\b/s', $code, $matches) > 0) {
      foreach ($matches[2] as $translated) {
        $strings[] = stripcslashes($translated);
      }
    }
    $strings[] = preg_replace('/\{\{.*?\}\}|\{%.*?%\}/s', '', $code) ?? '';
    return $strings;
  }

  /**
   * The names a YAML file shows, apart from its comments.
   *
   * @return string[]
   *   The scalar value of every `title`, `label` and `link_title` key, at any
   *   depth, as a string.
   */
  private static function yamlStrings(string $code): array {
    $values = [];
    $walk = static function ($node) use (&$walk, &$values): void {
      if (!is_array($node)) {
        return;
      }
      foreach ($node as $key => $value) {
        if (is_scalar($value) && in_array($key, self::EMITTING_YAML_KEYS, TRUE)) {
          $values[] = (string) $value;
        }
        $walk($value);
      }
    };
    $walk(Yaml::parse($code));
    return $values;
  }

  /**
   * The label rows whose label is not in the text of their page.
   *
   * @param array<int, array> $rows
   *   The rows of labels.yml; only rows with in_copy true are read.
   * @param array<string, string> $texts
   *   The text of each page, keyed P01 to P10, as a member reads it.
   *
   * @return string[]
   *   One problem per missing label, naming the row id and no text.
   */
  public static function copyLabelProblems(array $rows, array $texts): array {
    $problems = [];
    foreach ($rows as $row) {
      if (empty($row['in_copy'])) {
        continue;
      }
      $page = (string) ($row['page'] ?? '');
      $label = PackText::normalize((string) ($row['label'] ?? ''));
      if ($label === '' || !isset($texts[$page]) || !str_contains(PackText::normalize($texts[$page]), $label)) {
        $problems[] = (string) ($row['id'] ?? '?');
      }
    }
    return $problems;
  }

  /**
   * The control kinds a screen row may name.
   */
  public const CONTROL_KINDS = ['link', 'tab', 'button', 'submit', 'field', 'option', 'heading', 'placeholder'];

  /**
   * Whether a page has a visible, enabled control of one kind with this name.
   *
   * The kind picks the elements that are looked at, so a heading or a field
   * label with the name does not stand in for a link, and a link does not
   * stand in for a button. A node never counts when it, or an ancestor, is
   * hidden (the hidden attribute, aria-hidden, the visually-hidden or hidden
   * class, an inline display:none or visibility:hidden, a template) or
   * aria-disabled, or sits in the admin toolbar, or in a disabled fieldset,
   * select or optgroup, or when it is a disabled control. A placeholder counts
   * only on an input or a text area. A field label counts only when the
   * control it names (by `for` and id, or nested in the label) exists and is
   * itself neither hidden nor disabled.
   *
   * @param string $html
   *   The page.
   * @param string $label
   *   The name exactly as a member sees it.
   * @param string $control
   *   One of CONTROL_KINDS.
   * @param string|null $within
   *   A CSS selector for the region that holds the control (main content, a
   *   form id, a block). A match counts only inside a node the selector finds;
   *   a selector that finds nothing fails. NULL reads the whole page.
   *
   * @throws \InvalidArgumentException
   *   When the kind is not one of CONTROL_KINDS; the message never repeats it.
   */
  public static function controlOnScreen(string $html, string $label, string $control, ?string $within = NULL): bool {
    $name = self::xpathLiteral(trim($label));
    $text = "normalize-space(.)=$name";
    $value = "normalize-space(@value)=$name";
    // A field name may be followed by its required marker, which is not part of the name.
    $marked = "normalize-space(.)=concat($name, '*') or normalize-space(.)=concat($name, ' *')";
    $tabs = "ancestor::*[contains(@id, 'local-tasks') or contains(concat(' ', normalize-space(@class), ' '), ' tabs ') or contains(concat(' ', normalize-space(@class), ' '), ' nav-tabs ')]";
    $query = match ($control) {
      'link' => "//a[@href][$text]",
      'tab' => "//a[@href][$text][$tabs]",
      'button' => "//button[@type='button'][$text] | //input[@type='button'][$value]",
      'submit' => "//button[not(@type) or @type='submit'][$text] | //input[@type='submit' or @type='image'][$value]",
      'field' => "//label[$text or $marked] | //legend[$text or $marked]",
      'option' => "//option[$text]",
      'heading' => "//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6][$text]",
      'placeholder' => "//*[self::input or self::textarea][@placeholder=$name]",
      default => throw new \InvalidArgumentException('Unknown control kind.'),
    };
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new \DOMXPath($document);
    $regions = [];
    if ($within !== NULL) {
      foreach ($xpath->query((new CssSelectorConverter())->toXPath($within)) ?: [] as $region) {
        $regions[] = $region;
      }
      if ($regions === []) {
        return FALSE;
      }
    }
    foreach ($xpath->query($query) as $node) {
      if (!$node instanceof \DOMElement || self::nodeIsShut($node)) {
        continue;
      }
      if ($within !== NULL && !self::isInside($node, $regions)) {
        continue;
      }
      if ($node->tagName === 'label' && !self::labelControlIsAvailable($xpath, $node)) {
        continue;
      }
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Whether a node is one of the regions or inside one.
   *
   * @param \DOMElement $node
   *   The node.
   * @param \DOMNode[] $regions
   *   The nodes a selector found.
   */
  private static function isInside(\DOMElement $node, array $regions): bool {
    for ($ancestor = $node; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode) {
      foreach ($regions as $region) {
        if ($ancestor->isSameNode($region)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Whether a label's control exists and is neither hidden nor disabled.
   *
   * The control is the labelable element the label's `for` names by id, or
   * the first one nested in the label.
   */
  private static function labelControlIsAvailable(\DOMXPath $xpath, \DOMElement $label): bool {
    $labelable = "self::input[not(@type='hidden')] or self::select or self::textarea or self::button or self::meter or self::output or self::progress";
    if ($label->hasAttribute('for')) {
      $id = trim($label->getAttribute('for'));
      $query = $id === '' ? NULL : "//*[@id=" . self::xpathLiteral($id) . "][$labelable]";
    }
    else {
      $query = ".//*[$labelable]";
    }
    if ($query === NULL) {
      return FALSE;
    }
    foreach ($label->hasAttribute('for') ? $xpath->query($query) : $xpath->query($query, $label) as $control) {
      if ($control instanceof \DOMElement && !self::nodeIsShut($control)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether an element is hidden, in the admin toolbar, or a disabled control.
   */
  private static function nodeIsShut(\DOMElement $element): bool {
    $hidden_classes = ['visually-hidden', 'hidden', 'toolbar', 'toolbar-bar', 'toolbar-tray', 'gin-secondary-toolbar'];
    for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
      if ($node->hasAttribute('hidden') || $node->getAttribute('aria-hidden') === 'true' || $node->getAttribute('id') === 'toolbar-administration') {
        return TRUE;
      }
      if ($node->getAttribute('aria-disabled') === 'true' || $node->tagName === 'template') {
        return TRUE;
      }
      // A disabled fieldset, select or optgroup shuts what it holds, not itself.
      if ($node !== $element && in_array($node->tagName, ['fieldset', 'select', 'optgroup'], TRUE) && $node->hasAttribute('disabled')) {
        return TRUE;
      }
      if (array_intersect($hidden_classes, preg_split('/\s+/', trim($node->getAttribute('class'))) ?: []) !== []) {
        return TRUE;
      }
      $style = $node->getAttribute('style');
      if (preg_match('/display\s*:\s*none/i', $style) === 1 || preg_match('/visibility\s*:\s*hidden/i', $style) === 1) {
        return TRUE;
      }
    }
    return $element->hasAttribute('disabled');
  }

  /**
   * An XPath string literal for any text.
   */
  private static function xpathLiteral(string $text): string {
    if (!str_contains($text, "'")) {
      return "'$text'";
    }
    return 'concat(' . implode(', "\'", ', array_map(static fn (string $part): string => "'$part'", explode("'", $text))) . ')';
  }

}
