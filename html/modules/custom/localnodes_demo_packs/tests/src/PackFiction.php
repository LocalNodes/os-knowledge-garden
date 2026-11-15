<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs;

use Symfony\Component\Yaml\Yaml;

/**
 * Finds the names in pack text and holds them to a reviewed inventory.
 *
 * A pack and the how-to pages may name only people, groups, places and streets
 * that are made up. Each pack lists those in invented.yml; this finds every
 * proper-noun run in the text (capitalised words in a row, with the numbers
 * that lead or follow them) and every structured name field, and reports what
 * neither the inventory nor the short list of ordinary words covers.
 *
 * A capitalised word that starts a sentence is ordinary when the same file
 * writes it in lower case somewhere other than the start of a sentence. A run
 * beside words of the list, such as a screen label, is cut at those words; a
 * month or weekday word is cut only beside a number, a day token or another
 * calendar word. Web addresses must be on a reserved host, and the words of a
 * mail address or a username must be words of an inventory person. A
 * capitalisation scan cannot see a real name written in lower case, so only
 * REAL_WORDS' short list guards that case.
 *
 * No Drupal calls.
 */
final class PackFiction {

  /**
   * The inventory's groups, each a list of whole names.
   */
  public const GROUPS = ['people', 'organisations', 'places', 'streets'];

  /**
   * The inventory's optional list of plain words for its own pack.
   */
  public const ORDINARY_KEY = 'ordinary';

  /**
   * Fields of a pack entity that hold a name, a place or an address line.
   */
  public const NAME_KEYS = [
    'label', 'field_event_location', 'organization', 'locality', 'address_line1',
    'address_line2', 'administrative_area', 'address',
  ];

  /**
   * Month and weekday words, in full and short: ordinary only in a calendar
   * context (see unknownRuns()).
   */
  public const CALENDAR = [
    'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
    'September', 'October', 'November', 'December',
    'Jan', 'Feb', 'Mar', 'Apr', 'Jun', 'Jul', 'Aug', 'Sep', 'Sept', 'Oct', 'Nov', 'Dec',
    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
    'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
  ];

  /**
   * Words that are not names: the product, common marks and screen words.
   */
  public const ORDINARY = [
    'LocalNodes', 'UTC', 'AI', 'OK', 'PM', 'AM', 'URL', 'PDF', 'FAQ',
    // The demo fund's words, and the answers of a yes or no poll.
    'Practice', 'PRACTICE', 'Money', 'Yes', 'No',
    // Words that head a document, a table or a step and start a sentence.
    'Part', 'Phase', 'How-to', 'First-visit', 'Other-group', 'Title', 'Target',
    'We', 'What', 'How', 'Some', 'For', 'Against', 'Abstain', 'Votes', 'Fund',
    'Balances', 'Topics', 'Groups', 'Walk',
    'Add', 'Ask', 'Avoid', 'Cast', 'Change', 'Describe', 'Find', 'Need', 'Open',
    'Preparing', 'Refresh', 'Respond', 'Tick', 'Titles', 'Write',
    // Plain words that start a sentence, a heading or a table cell.
    'A', 'The', 'This', 'These', 'When', 'Once', 'If', 'Only', 'Leaving',
    'Shown', 'None', 'Nothing', 'Finally', 'Reference', 'People', 'Library',
    'Posts', 'Group', 'Read-only', 'Publish', 'Vote', 'Exact', 'Native',
    'Citation', 'Recipients', 'Agree', 'Stand', 'Remaining', 'Dynamic',
    'Supporting', 'Body', 'Resolution', 'Start', 'Keep', 'Point', 'Hide',
    'Bring', 'Load', 'Respect', 'Checks', 'Run', 'Uncovered',
    // Plain words that start a sentence or a table cell in a pack written as
    // ordinary prose (meeting minutes, a repair log, house rules, posts). Each
    // is a common English word, none a name. A month or a weekday is not here
    // (see CALENDAR), and a made-up name belongs in the pack's inventory.
    'Welcome', 'Welcomes', 'Everyone', 'Anyone', 'Membership', 'Replacing',
    'Replace', 'Sign', 'Each', 'Present', 'Budget', 'Agreed', 'Any', 'Thanks',
    'Thank', 'Fridge', 'Shelves', 'Chores', 'Next', 'She', 'Reminder',
    'Apologies', 'Back', 'Washing', 'Repair', 'Bylaws', 'Handbook', 'Treasurer',
    'Longest-standing', 'Chairs', 'Runs', 'Looks', 'Works', 'Newest',
    'Organizes', 'Wrote', 'Has', 'Version', 'Audience', 'About',
    'Garden', 'Status', 'Graphite', 'Temporary', 'Cleared', 'Paint', 'Officers',
    'Most', 'Deadlines', 'Guests', 'Smoking', 'Mortgage', 'Property', 'Paying',
    'Recycling', 'Bigger', 'Trade', 'Review', 'Shared', 'Author', 'Moving',
    'Cited', "I've", "I'll", "I'd", "I'm", "We're", "Haven't", 'Mind', 'Logged',
    'Hello', 'Tool-share', 'Seed', 'Tea', 'Agenda', 'Opened', 'Choice', 'Type',
    'Happy', 'Result', 'One-time', 'Decision', 'Consistency', 'Work',
    'Requires', 'Neither', 'Minimum', 'Annual', 'Demo-loader', 'Still-open',
    'Later', 'Source', 'Using', 'IDs', 'Unrelated', 'Preserve',
    // More plain words that start a sentence in the Lantern Row pack's house
    // rules, handbook and repair log.
    'Longer', 'Bins', 'Put', 'Set', 'Use',
  ];

  /**
   * Words of a markdown source's sections after its preface, ordinary there
   * and nowhere else: the builder's source aliases, tool and language names,
   * and the directory words of the source paths its tables quote.
   */
  public const BUILDER_WORDS = [
    'H', 'OS', 'C', 'Core', 'SG', 'GT', 'Code', 'Drupal',
    'PHP', 'CSS', 'UI', 'Ui', 'README', 'English', 'Plugin',
    'Form', 'Hooks', 'Controller', 'Entity', 'Service', 'Block',
  ];

  /**
   * Process words of a markdown source's preface, ordinary there and nowhere
   * else: the note to the builder before the first second-level heading.
   */
  public const PREFACE_WORDS = ['HOLD', 'Job'];

  /**
   * Words of a mail local part or a username that are roles, not people.
   */
  public const MAIL_WORDS = ['info', 'admin', 'hello', 'team', 'contact', 'help', 'office', 'mail', 'noreply', 'test', 'user'];

  /**
   * The marker that stands for a day token in plain text.
   */
  public const DAY = 'DAYTOKEN';

  /**
   * Keys that hold an ISO code, whatever the file.
   */
  public const ISO_KEYS = ['country_code'];

  /**
   * Keys of expectation rows that hold ids, paths and settings, not prose.
   */
  public const CODE_KEYS = ['id', 'uuid', 'page', 'check', 'as', 'control', 'within', 'root', 'path', 'line', 'in_copy', 'source_line', 'kind', 'status'];

  /**
   * Plain text of a piece of markup, a markdown line or a YAML string.
   *
   * Day tokens become the DAY marker, links and addresses are dropped (see
   * linkProblems() and mailProblems() for those), block tags and markdown
   * marks become breaks, entities are decoded, and a possessive loses its 's.
   */
  public static function plain(string $text): string {
    $text = (string) preg_replace('/\{\{day:[^}]*\}\}/', ' ' . self::DAY . ' ', $text);
    $text = (string) preg_replace('#</?(?:p|div|li|ul|ol|br|h[1-6]|tr|td|th|table|blockquote|pre|section|article)\b[^>]*>#i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/\]\([^)\s]*(?:\s+"[^"]*")?\)/u', ']', $text);
    $text = (string) preg_replace('#https?://\S+#', ' ', $text);
    $text = (string) preg_replace('/\S+@\S+/', ' ', $text);
    $text = strtr($text, ['|' => "\n"]);
    $text = (string) preg_replace('/[*`>\[\]{}]/u', ' ', $text);
    $text = (string) preg_replace('/\p{Pd}{2,}/u', ' ', $text);
    $text = strtr($text, ["\u{2018}" => "'", "\u{2019}" => "'", "\u{00A0}" => ' ']);
    return (string) preg_replace("/(?<=\\p{L})'s\\b/u", '', $text);
  }

  /**
   * Every string of parsed YAML, in order, whatever its depth.
   *
   * @param string[] $skip_keys
   *   Keys whose values are left out.
   *
   * @return string[]
   */
  public static function strings(mixed $data, array $skip_keys = []): array {
    if (is_string($data)) {
      return [$data];
    }
    $found = [];
    if (is_array($data)) {
      foreach ($data as $key => $value) {
        if (is_string($key) && in_array($key, $skip_keys, TRUE)) {
          continue;
        }
        $found = array_merge($found, self::strings($value, $skip_keys));
      }
    }
    return $found;
  }

  /**
   * The words a file writes in lower case away from the start of a sentence.
   *
   * A lower-case word that starts a sentence is no evidence: it may be a
   * name typed without its capital.
   *
   * @param string[] $texts
   *   The plain texts of one file.
   *
   * @return array<string, true>
   *   The words, lower case, as keys.
   */
  public static function lowerWords(array $texts): array {
    $known = [];
    foreach ($texts as $text) {
      foreach (self::tokens($text) as [$word, $type, $starts]) {
        if ($type === 'lower' && !$starts) {
          $known[mb_strtolower($word)] = TRUE;
        }
      }
    }
    return $known;
  }

  /**
   * The words of a text as [word, kind, starts a sentence, joins the last].
   *
   * Kinds: cap (capitalised), num (digits), day (the DAY marker), lower
   * (everything else, which includes codes such as P01 and identifiers such as
   * QuickPollWording).
   *
   * @return array<int, array{0: string, 1: string, 2: bool, 3: bool}>
   */
  private static function tokens(string $text): array {
    $tokens = [];
    preg_match_all("/[\\p{L}\\p{N}]+(?:['\\-][\\p{L}\\p{N}]+)*/u", $text, $matches, PREG_OFFSET_CAPTURE);
    $end = 0;
    $first = TRUE;
    foreach ($matches[0] as [$word, $offset]) {
      $gap = substr($text, $end, $offset - $end);
      $starts = $first || preg_match('/[.!?:;\n]/u', $gap) === 1;
      $joins = !$first && preg_match('/^[ ]+$/', $gap) === 1;
      if ($word === self::DAY) {
        $kind = 'day';
      }
      elseif (preg_match('/^\p{N}+$/u', $word) === 1) {
        $kind = 'num';
      }
      elseif (preg_match('/^\p{Lu}/u', $word) === 1 && preg_match('/^\p{Lu}+\p{N}/u', $word) !== 1 && preg_match('/\p{Ll}\p{Lu}/u', $word) !== 1) {
        $kind = 'cap';
      }
      else {
        $kind = 'lower';
      }
      $tokens[] = [$word, $kind, $starts, $joins];
      $end = $offset + strlen($word);
      $first = FALSE;
    }
    return $tokens;
  }

  /**
   * The proper-noun runs of a plain text, each a list of words.
   *
   * A run is capitalised words, with numbers and day markers among them, one
   * space apart. The first word of a sentence is dropped from its run when the
   * same word is written in lower case in $known.
   *
   * @param array<string, true> $known
   *   From lowerWords() on the same file.
   *
   * @return string[][]
   *   The runs, each a list of words.
   */
  public static function runs(string $text, array $known): array {
    $runs = [];
    $current = [];
    $flush = static function () use (&$runs, &$current, $known): void {
      if ($current !== []) {
        $kinds = array_column($current, 1);
        if (in_array('cap', $kinds, TRUE)) {
          [$word, $kind, $starts] = $current[0];
          if ($kind === 'cap' && $starts && isset($known[mb_strtolower($word)])) {
            array_shift($current);
          }
          $words = array_column($current, 0);
          if ($words !== [] && in_array('cap', array_column($current, 1), TRUE)) {
            $runs[] = $words;
          }
        }
      }
      $current = [];
    };
    foreach (self::tokens($text) as $token) {
      [, $kind, , $joins] = $token;
      $member = $kind === 'cap' || $kind === 'num' || $kind === 'day';
      if (!$member) {
        $flush();
        continue;
      }
      if (!$joins) {
        $flush();
      }
      $current[] = $token;
    }
    $flush();
    return $runs;
  }

  /**
   * The runs of a text that the ordinary words and the inventory do not cover.
   *
   * A run is cut at ordinary words. A month or weekday word (CALENDAR) is
   * cut only when the next or the previous word of the run is a number, the
   * DAY marker or another calendar word; alone it is a name like any other. A
   * DAY marker is a cut too. What is left, if it has a capitalised word, must
   * be a whole entry of the inventory or a stretch of words of one.
   *
   * @param array<string, true> $known
   *   From lowerWords() on the same file.
   * @param string[] $inventory
   *   The whole names of the inventory.
   * @param string[] $ordinary
   *   The ordinary words.
   *
   * @return string[]
   *   Each uncovered run, as written.
   */
  public static function unknownRuns(string $text, array $known, array $inventory, array $ordinary): array {
    $skip = array_flip($ordinary);
    $calendar = array_flip(self::CALENDAR);
    $dated = static fn (string $word): bool => $word === self::DAY || isset($calendar[$word]) || preg_match('/^\p{N}+$/u', $word) === 1;
    $unknown = [];
    foreach (self::runs($text, $known) as $words) {
      $piece = [];
      $pieces = [];
      foreach ($words as $index => $word) {
        $in_context = isset($calendar[$word]) && (($index > 0 && $dated($words[$index - 1])) || ($index + 1 < count($words) && $dated($words[$index + 1])));
        if ($word === self::DAY || isset($skip[$word]) || $in_context) {
          $pieces[] = $piece;
          $piece = [];
          continue;
        }
        $piece[] = $word;
      }
      $pieces[] = $piece;
      foreach ($pieces as $piece) {
        if ($piece === [] || preg_match('/^\p{N}+$/u', implode('', $piece)) === 1 || !self::hasCapital($piece)) {
          continue;
        }
        if (!self::covered($piece, $inventory) && !self::coveredBesideNumbers($piece, $inventory)) {
          $unknown[] = implode(' ', $piece);
        }
      }
    }
    return array_values(array_unique($unknown));
  }

  /**
   * Whether any word of a piece is capitalised.
   *
   * @param string[] $piece
   */
  private static function hasCapital(array $piece): bool {
    foreach ($piece as $word) {
      if (preg_match('/^\p{Lu}/u', $word) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a piece is covered once the numbers at its edges are set aside.
   *
   * A count written beside a name ("Smoke 12" for the twelve of Smoke) is not
   * part of the name. Only an entry with no number in it can cover a piece
   * this way, so "10 Smoke Lane" is not covered by "1 Smoke Lane".
   *
   * @param string[] $piece
   * @param string[] $inventory
   */
  private static function coveredBesideNumbers(array $piece, array $inventory): bool {
    $trimmed = $piece;
    while ($trimmed !== [] && preg_match('/^\p{N}+$/u', $trimmed[0]) === 1) {
      array_shift($trimmed);
    }
    while ($trimmed !== [] && preg_match('/^\p{N}+$/u', (string) end($trimmed)) === 1) {
      array_pop($trimmed);
    }
    if ($trimmed === [] || $trimmed === $piece) {
      return FALSE;
    }
    $plain = array_filter($inventory, static fn (string $entry): bool => preg_match('/(?<![\p{L}\p{N}])\p{N}+(?![\p{L}\p{N}])/u', $entry) !== 1);
    return self::covered($trimmed, array_values($plain));
  }

  /**
   * Whether the words are a whole inventory name or a stretch of words of one.
   *
   * @param string[] $piece
   * @param string[] $inventory
   */
  private static function covered(array $piece, array $inventory): bool {
    $count = count($piece);
    foreach ($inventory as $entry) {
      $words = preg_split('/\s+/u', trim($entry), -1, PREG_SPLIT_NO_EMPTY) ?: [];
      for ($start = 0; $start + $count <= count($words); $start++) {
        if (array_slice($words, $start, $count) === $piece) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * The whole names a pack's entities state in name fields.
   *
   * A person's display name is the first and last name together; the other
   * fields are those of NAME_KEYS, at any depth.
   *
   * @param array<string, array> $files
   *   The pack's parsed YAML files by file name.
   *
   * @return string[]
   *   The names, in order and without repeats.
   */
  public static function structuredNames(array $files): array {
    $names = [];
    $walk = static function (mixed $data) use (&$walk, &$names): void {
      if (!is_array($data)) {
        return;
      }
      $first = trim((string) ($data['first_name'] ?? ''));
      $last = trim((string) ($data['last_name'] ?? ''));
      if ($first !== '' || $last !== '') {
        $names[] = trim("$first $last");
      }
      foreach ($data as $key => $value) {
        if (in_array($key, self::NAME_KEYS, TRUE)) {
          foreach (self::strings($value) as $string) {
            if (trim($string) !== '') {
              $names[] = trim($string);
            }
          }
        }
        else {
          $walk($value);
        }
      }
    };
    foreach ($files as $file) {
      $walk($file);
    }
    return array_values(array_unique($names));
  }

  /**
   * The words of every screen label, as ordinary words.
   *
   * @param array<int, array> $rows
   *   The rows of labels.yml.
   *
   * @return string[]
   */
  public static function labelWords(array $rows): array {
    $words = [];
    foreach ($rows as $row) {
      $label = is_array($row) ? (string) ($row['label'] ?? '') : '';
      preg_match_all("/[\\p{L}\\p{N}]+(?:['\\-][\\p{L}\\p{N}]+)*/u", $label, $found);
      $words = array_merge($words, $found[0]);
    }
    return array_values(array_unique($words));
  }

  /**
   * The strings of a file, as written, each with the extra ordinary words
   * that hold for it.
   *
   * A YAML file gives every string in it; a file of the expectation
   * directory leaves out the keys of CODE_KEYS, which hold ids and settings.
   * Any other file is markdown, kept whole: its preface (the lines before the
   * first second-level heading) is one segment, with PREFACE_WORDS as its extra
   * ordinary words, and the rest, builder sections included, is another with
   * BUILDER_WORDS. Any YAML file leaves out the keys of ISO_KEYS.
   *
   * @return array<int, array{0: string, 1: string[]}>
   *   Each segment as [raw text, extra ordinary words].
   */
  public static function fileSegments(string $path): array {
    $raw = (string) file_get_contents($path);
    if (!str_ends_with($path, '.yml')) {
      $preface = [];
      $rest = [];
      $in_preface = TRUE;
      foreach (explode("\n", $raw) as $line) {
        if ($in_preface && preg_match('/^##\s/u', $line) === 1) {
          $in_preface = FALSE;
        }
        if ($in_preface) {
          $preface[] = $line;
        }
        else {
          $rest[] = $line;
        }
      }
      $segments = [[implode("\n", $preface), self::PREFACE_WORDS]];
      if ($rest !== []) {
        $segments[] = [implode("\n", $rest), self::BUILDER_WORDS];
      }
      return $segments;
    }
    $skip = array_merge(self::ISO_KEYS, str_contains($path, '/expect/') ? self::CODE_KEYS : []);
    return array_map(static fn (string $string): array => [$string, []], self::strings(Yaml::parse($raw) ?? [], $skip));
  }

  /**
   * The plain strings of a file, as a reader meets them.
   *
   * @return string[]
   */
  public static function fileTexts(string $path): array {
    return array_map(static fn (array $segment): string => self::plain($segment[0]), self::fileSegments($path));
  }

  /**
   * What the scan finds wrong in each file's text.
   *
   * Per file: every web address on a host that is not reserved ("link: host"),
   * every mail address word that is no word of an inventory person ("mail:
   * word"), and every proper-noun run no inventory entry covers. A word
   * that starts a sentence is ordinary only on the evidence of its own file.
   *
   * @param array<string, array<int, array{0: string, 1: string[]}>> $files
   *   Segments by file name, from fileSegments().
   * @param string[] $inventory
   *   The whole names of the inventory.
   * @param string[] $people
   *   The inventory's people (peopleNames()).
   * @param string[] $ordinary
   *   The ordinary words.
   *
   * @return array<string, string[]>
   *   The faults of each file that has any.
   */
  public static function scan(array $files, array $inventory, array $people, array $ordinary): array {
    $result = [];
    foreach ($files as $file => $segments) {
      $plains = array_map(static fn (array $segment): string => self::plain($segment[0]), $segments);
      $known = self::lowerWords($plains);
      $found = [];
      foreach ($segments as $index => [$raw, $extra]) {
        foreach (self::linkProblems($raw) as $host) {
          $found[] = "link: $host";
        }
        foreach (self::mailProblems($raw, $people) as $word) {
          $found[] = "mail: $word";
        }
        $found = array_merge($found, self::unknownRuns($plains[$index], $known, $inventory, array_merge($ordinary, $extra)));
      }
      $found = array_values(array_unique($found));
      if ($found !== []) {
        $result[(string) $file] = $found;
      }
    }
    return $result;
  }

  /**
   * Whether a host is reserved for documentation and tests.
   */
  private static function hostIsReserved(string $host): bool {
    $host = rtrim(mb_strtolower($host), '.');
    if ($host === 'localhost') {
      return TRUE;
    }
    foreach (['example', 'test', 'invalid', 'example.com', 'example.net', 'example.org'] as $reserved) {
      if ($host === $reserved || str_ends_with($host, ".$reserved")) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The hosts of web addresses in a raw text that are not reserved.
   *
   * Finds scheme://host, protocol-relative //host and bare www. hosts, in
   * markup too (an href or src value). A path without a host is the site's own.
   * A host is reserved when it is localhost, or is, or ends in a dot and, one
   * of example, test, invalid, example.com, example.net or example.org.
   *
   * @return string[]
   *   The hosts, lower case, without repeats.
   */
  public static function linkProblems(string $raw): array {
    $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $stop = '[^/\s:?#"\'<>)\]]+';
    $hosts = [];
    foreach ([
      '~\b[a-z][a-z0-9+.\-]*://(?:[^/\s"\'<>@]*@)?(' . $stop . ')~i',
      '~(?<![\w:/.\-])//(' . $stop . ')~',
      '~(?<![\w.@/\-])(www\.' . $stop . ')~i',
    ] as $pattern) {
      preg_match_all($pattern, $text, $found);
      foreach ($found[1] as $host) {
        $hosts[] = rtrim(mb_strtolower($host), '.,;!');
      }
    }
    $bad = [];
    foreach (array_unique($hosts) as $host) {
      if (!self::hostIsReserved($host)) {
        $bad[] = $host;
      }
    }
    return $bad;
  }

  /**
   * The lower-case words of a person-like string: mail local part or username.
   *
   * Split on everything that is not a letter, so a dot, an underscore, a
   * hyphen, a digit, a plus and a space all separate. A one-letter part is
   * dropped.
   *
   * @return string[]
   */
  private static function localWords(string $value): array {
    $words = preg_split('/[^\p{L}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($words, static fn (string $word): bool => mb_strlen($word) > 1));
  }

  /**
   * The words of a person-like string that are neither a role word nor a word
   * of an inventory person.
   *
   * @param string[] $people
   *   The inventory's people.
   *
   * @return string[]
   */
  private static function uncoveredWords(string $value, array $people): array {
    $known = array_flip(self::MAIL_WORDS);
    foreach ($people as $name) {
      // A name written without its apostrophe ("obrien") is a word of the
      // person too, as well as the words either side of it.
      foreach (array_merge(self::localWords($name), self::localWords((string) preg_replace("/['\u{2019}]/u", '', $name))) as $word) {
        $known[$word] = TRUE;
      }
    }
    return array_values(array_unique(array_filter(self::localWords($value), static fn (string $word): bool => !isset($known[$word]))));
  }

  /**
   * The words of mail local parts in a raw text that cover no inventory person.
   *
   * Each local part is split on dots, underscores, hyphens, digits and the
   * like; a part must be a role word (MAIL_WORDS) or, in any case, a word of an
   * inventory person.
   *
   * @param string[] $people
   *   The inventory's people (peopleNames()).
   *
   * @return string[]
   *   The words, lower case, without repeats.
   */
  public static function mailProblems(string $raw, array $people): array {
    $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('/([A-Za-z0-9._%+\-]+)@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+/', $text, $found);
    $bad = [];
    foreach ($found[1] as $local) {
      $bad = array_merge($bad, self::uncoveredWords($local, $people));
    }
    return array_values(array_unique($bad));
  }

  /**
   * The names of an inventory's people, trimmed.
   *
   * @return string[]
   */
  public static function peopleNames(array $inventory): array {
    $names = [];
    foreach ($inventory['people'] ?? [] as $entry) {
      if (is_string($entry) && trim($entry) !== '') {
        $names[] = trim($entry);
      }
    }
    return $names;
  }

  /**
   * The name and mail fields of parsed data, at any depth, as [key, value].
   *
   * @return array<int, array{0: string, 1: string}>
   */
  public static function accountFields(mixed $data): array {
    $fields = [];
    if (!is_array($data)) {
      return $fields;
    }
    foreach ($data as $key => $value) {
      if (is_string($value) && ($key === 'name' || $key === 'mail')) {
        $fields[] = [$key, $value];
      }
      else {
        $fields = array_merge($fields, self::accountFields($value));
      }
    }
    return $fields;
  }

  /**
   * The words of username and mail fields that cover no inventory person.
   *
   * A name field is read as a mail local part is; a mail field is read as mail.
   *
   * @param string[] $people
   *   The inventory's people (peopleNames()).
   *
   * @return string[]
   *   "name: word" and "mail: word", in order, without repeats.
   */
  public static function accountProblems(mixed $data, array $people): array {
    $found = [];
    foreach (self::accountFields($data) as [$key, $value]) {
      $words = $key === 'mail' && str_contains($value, '@') ? self::mailProblems($value, $people) : self::uncoveredWords($value, $people);
      foreach ($words as $word) {
        $found[] = "$key: $word";
      }
    }
    return array_values(array_unique($found));
  }

  /**
   * The entries of a short list of real words that a text states, in any case.
   *
   * @param string[] $words
   *
   * @return string[]
   *   The entries found, as written in the list.
   */
  public static function realWordProblems(string $text, array $words): array {
    $found = [];
    foreach ($words as $word) {
      if (preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $text) === 1) {
        $found[] = $word;
      }
    }
    return $found;
  }

  /**
   * Inventory entries that none of the texts states.
   *
   * @param string[] $names
   *   The inventory's names.
   * @param string[] $texts
   *   Plain texts.
   *
   * @return string[]
   *   The names no text holds, written as in the inventory.
   */
  public static function staleEntries(array $names, array $texts): array {
    $stale = [];
    foreach ($names as $name) {
      $found = FALSE;
      foreach ($texts as $text) {
        if (str_contains($text, $name)) {
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        $stale[] = $name;
      }
    }
    return $stale;
  }

  /**
   * What is wrong with an inventory file's form.
   *
   * @param array $inventory
   *   The parsed invented.yml.
   *
   * @return string[]
   *   One message for each fault.
   */
  public static function inventoryProblems(array $inventory): array {
    $problems = [];
    foreach (array_keys($inventory) as $group) {
      if (!in_array($group, self::GROUPS, TRUE) && $group !== self::ORDINARY_KEY) {
        $problems[] = "The inventory has a group that is not one of " . implode(', ', self::GROUPS) . ' (or ' . self::ORDINARY_KEY . ').';
      }
    }
    $problems = array_merge($problems, self::ordinaryProblems($inventory));
    $seen = [];
    foreach (self::GROUPS as $group) {
      if (!array_key_exists($group, $inventory) || !is_array($inventory[$group])) {
        $problems[] = "The inventory has no list for $group.";
        continue;
      }
      foreach (array_values($inventory[$group]) as $index => $entry) {
        $name = is_string($entry) ? trim($entry) : '';
        if ($name === '') {
          $problems[] = "The inventory's $group entry #" . ($index + 1) . ' is not a name.';
          continue;
        }
        if (isset($seen[$name])) {
          $problems[] = "The inventory names '$name' twice.";
        }
        $seen[$name] = TRUE;
      }
    }
    return $problems;
  }

  /**
   * The ordinary words an inventory adds for its own pack.
   *
   * A pack written as prose may use a word that the scan reads as a possible
   * name (a month or a weekday standing alone, as in "in March") or that is a
   * word of another pack's made-up names. It lists that word here, once, as
   * the plain word it is in this pack's text.
   *
   * @return string[]
   */
  public static function ordinaryWords(array $inventory): array {
    $words = [];
    foreach ($inventory[self::ORDINARY_KEY] ?? [] as $entry) {
      if (is_string($entry) && trim($entry) !== '') {
        $words[] = trim($entry);
      }
    }
    return $words;
  }

  /**
   * What is wrong with an inventory's ordinary words.
   *
   * Each is one word, none twice, and none is a word of a name the inventory
   * lists, so a made-up name cannot be hidden among them.
   *
   * @return string[]
   */
  private static function ordinaryProblems(array $inventory): array {
    if (!array_key_exists(self::ORDINARY_KEY, $inventory)) {
      return [];
    }
    if (!is_array($inventory[self::ORDINARY_KEY])) {
      return ['The inventory\'s ' . self::ORDINARY_KEY . ' is not a list.'];
    }
    $name_words = [];
    foreach (self::names($inventory) as $name) {
      foreach (preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        $name_words[$word] = TRUE;
      }
    }
    $problems = [];
    $seen = [];
    foreach (array_values($inventory[self::ORDINARY_KEY]) as $index => $entry) {
      $word = is_string($entry) ? trim($entry) : '';
      if ($word === '' || preg_match('/\s/u', $word) === 1) {
        $problems[] = 'The inventory\'s ' . self::ORDINARY_KEY . ' entry #' . ($index + 1) . ' is not one word.';
        continue;
      }
      if (isset($seen[$word])) {
        $problems[] = "The inventory lists the ordinary word '$word' twice.";
      }
      if (isset($name_words[$word])) {
        $problems[] = "The ordinary word '$word' is a word of a name in the inventory.";
      }
      $seen[$word] = TRUE;
    }
    return $problems;
  }

  /**
   * The whole names of an inventory, every group together.
   *
   * @return string[]
   */
  public static function names(array $inventory): array {
    $names = [];
    foreach (self::GROUPS as $group) {
      foreach ($inventory[$group] ?? [] as $entry) {
        if (is_string($entry) && trim($entry) !== '') {
          $names[] = trim($entry);
        }
      }
    }
    return $names;
  }

}
