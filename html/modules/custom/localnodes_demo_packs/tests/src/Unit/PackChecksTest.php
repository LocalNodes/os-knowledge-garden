<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\localnodes_demo_packs\PackText;
use Drupal\localnodes_demo_packs\PackTokens;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\UnitTestCase;

/**
 * The pack checks themselves, run against made-up packs that break each rule.
 *
 * A check that cannot fail proves nothing, so each rule here is shown a
 * pack that breaks it and must report it, and a pack that keeps it and must
 * report nothing. Every name, event and address is invented.
 *
 * @group localnodes_demo_packs
 */
final class PackChecksTest extends UnitTestCase {

  private const U1 = '00000000-0000-4000-8000-000000000001';

  private const U2 = '00000000-0000-4000-8000-000000000002';

  private const E1 = '00000000-0000-4000-8000-0000000000e1';

  /**
   * The source blocks the prose checks compare against.
   */
  private static function source(): array {
    return PackText::markdownBlocks("## Library\n\n### Quiet page\n\nThe lamp stays on until nine.\n\n- Invented Org\n");
  }

  /**
   * A nested body value is prose under the name of its field.
   */
  public function testNestedValueTakesItsFieldName(): void {
    $found = PackChecks::stringsOf(['body' => ['value' => '<p>x</p>', 'format' => 'basic_html'], 'title' => 'T']);
    $this->assertContains(['key' => 'body', 'value' => '<p>x</p>'], $found);
    $this->assertContains(['key' => 'title', 'value' => 'T'], $found);
  }

  /**
   * The user fields a member reads are prose keys.
   */
  public function testUserFieldsAreProseKeys(): void {
    foreach (['organization', 'function', 'address', 'image_alt', 'self_introduction', 'body'] as $key) {
      $this->assertContains($key, PackChecks::PROSE_KEYS);
    }
  }

  /**
   * Prose that is not in the source is reported, in every supported field.
   */
  public function testProseOutsideTheSourceIsReported(): void {
    $kept = ['user.yml' => [self::U1 => ['uuid' => self::U1, 'organization' => 'Invented Org', 'function' => '', 'image_alt' => '']]];
    $result = PackChecks::proseProblems('x', $kept, self::source());
    $this->assertSame([], $result['problems']);
    $this->assertSame(1, $result['checked']);

    foreach (['organization', 'function', 'address', 'image_alt'] as $key) {
      $files = ['user.yml' => [self::U1 => ['uuid' => self::U1, $key => 'Words nobody wrote']]];
      $this->assertNotSame([], PackChecks::proseProblems('x', $files, self::source())['problems'], "$key is checked.");
    }
    $nested = ['topic.yml' => [self::U1 => ['uuid' => self::U1, 'body' => ['value' => '<p>Words nobody wrote</p>', 'format' => 'basic_html']]]];
    $this->assertNotSame([], PackChecks::proseProblems('x', $nested, self::source())['problems'], 'A nested body value is checked as a body.');
    $nested_ok = ['topic.yml' => [self::U1 => ['uuid' => self::U1, 'body' => ['value' => '<p>The lamp stays on until nine.</p>', 'format' => 'basic_html']]]];
    $this->assertSame([], PackChecks::proseProblems('x', $nested_ok, self::source())['problems']);
  }

  /**
   * Prose after a closing tag that ends the reader's wrapper is reported.
   *
   * A body that is valid up to "</div>" and carries a paragraph after it
   * once passed, because the paragraph sat outside the wrapper.
   */
  public function testProseAfterAStrayClosingDivIsReported(): void {
    $ok = '<p>The lamp stays on until nine.</p>';
    $files = fn (string $body): array => ['topic.yml' => [self::U1 => ['uuid' => self::U1, 'body' => ['value' => $body, 'format' => 'basic_html']]]];
    $this->assertSame([], PackChecks::proseProblems('x', $files($ok), self::source())['problems']);
    foreach (['</div><p>Words nobody wrote</p>', '</div>Words nobody wrote', '</div></body></html><p>Words nobody wrote</p>'] as $tail) {
      $problems = PackChecks::proseProblems('x', $files($ok . $tail), self::source())['problems'];
      $this->assertNotSame([], $problems, "Text after $tail is checked.");
    }
  }

  /**
   * The text of an entry, as the left-out checks search it, holds every string.
   */
  public function testTextOfAnEntryHoldsAllItsText(): void {
    $entry = ['uuid' => self::U1, 'title' => 'Quiet page', 'body' => ['value' => '<p>The lamp stays on</p></div><p>until nine on {{day:+7}}.</p>', 'format' => 'basic_html']];
    $text = PackChecks::textOfEntry($entry);
    $this->assertStringContainsString('Quiet page', $text);
    $this->assertStringContainsString('The lamp stays on', $text);
    $this->assertStringContainsString('until nine on day +7.', $text, 'Text after a stray closing tag is searched too.');
  }

  /**
   * An enrollment title must be the member's name and the event's title.
   */
  public function testEnrollmentTitleIsItsComposition(): void {
    $files = [
      'user.yml' => [self::U1 => ['uuid' => self::U1, 'name' => 'lamp_one']],
      'event.yml' => [self::E1 => ['uuid' => self::E1, 'title' => 'Quiet evening']],
      'event-enrollment.yml' => [
        self::U2 => ['uuid' => self::U2, 'title' => 'lamp_one @ Quiet evening', 'uid' => self::U1, 'field_event' => self::E1],
      ],
    ];
    $this->assertSame([], PackChecks::enrollmentProblems($files));
    $files['event-enrollment.yml'][self::U2]['title'] = 'lamp_one @ A made-up other event';
    $this->assertNotSame([], PackChecks::enrollmentProblems($files));
    $files['event-enrollment.yml'][self::U2]['title'] = 'someone_else @ Quiet evening';
    $this->assertNotSame([], PackChecks::enrollmentProblems($files));
    $files['event-enrollment.yml'][self::U2]['title'] = 'lamp_one @ Quiet evening';
    $files['event-enrollment.yml'][self::U2]['field_event'] = self::U2;
    $this->assertNotSame([], PackChecks::enrollmentProblems($files), 'An event the pack does not hold is reported.');
  }

  /**
   * Each entry's key is its uuid, and no uuid is used twice.
   */
  public function testEntryKeysAndUuids(): void {
    $clean = ['poll.yml' => [
      self::U1 => ['uuid' => self::U1, 'question' => 'A'],
      self::U2 => ['uuid' => self::U2, 'question' => 'B'],
    ]];
    $this->assertSame([], PackChecks::entryProblems($clean));

    $mismatch = ['poll.yml' => [
      self::U1 => ['uuid' => self::U1, 'question' => 'A'],
      self::U2 => ['uuid' => self::U1, 'question' => 'B'],
    ]];
    $problems = PackChecks::entryProblems($mismatch);
    $this->assertNotSame([], $problems, 'Poll B carrying poll A\'s uuid is reported.');

    // A key that is not its entry's uuid is reported on its own, with every
    // uuid still used once, so the duplicate rule cannot be what reports it.
    $wrongKey = ['poll.yml' => [
      self::U1 => ['uuid' => self::U1, 'question' => 'A'],
      self::U2 => ['uuid' => self::E1, 'question' => 'B'],
    ]];
    $keyProblems = PackChecks::entryProblems($wrongKey);
    $this->assertCount(1, $keyProblems);
    $this->assertStringContainsString('not its key', $keyProblems[0]);

    $twice = ['poll.yml' => [self::U1 => ['uuid' => self::U1]], 'topic.yml' => [self::U1 => ['uuid' => self::U1]]];
    $this->assertNotSame([], PackChecks::entryProblems($twice), 'The same uuid in two files is reported.');

    $none = ['poll.yml' => [self::U1 => ['question' => 'A']]];
    $this->assertNotSame([], PackChecks::entryProblems($none), 'An entry without a uuid field is reported.');
  }

  /**
   * The source of a made-up pack with a People section.
   */
  private static function people(): string {
    return "# Lamp pack\n\n## People\n\n- lamp_one (Lamp One): Meets every group.\n- lamp_two (Lamp Two): A second account.\n\n## Groups\n\nEveryone is welcome.\n";
  }

  /**
   * A made-up account.
   */
  private static function user(string $name, string $first, string $last): array {
    return [self::U1 => ['uuid' => self::U1, 'name' => $name, 'first_name' => $first, 'last_name' => $last]];
  }

  /**
   * Names are whole names of the People section's records.
   */
  public function testNamesAreWholeNamesFromThePeopleRecords(): void {
    $this->assertSame([], PackChecks::nameProblems(self::people(), self::user('lamp_one', 'Lamp', 'One')));
    $this->assertNotSame([], PackChecks::nameProblems(self::people(), self::user('lamp_one', 'Eve', 'One')), 'Eve is a word of "every", not a listed name.');
    $this->assertNotSame([], PackChecks::nameProblems(self::people(), self::user('lamp_on', 'Lamp', 'One')), 'Half a username is not a username.');
    $this->assertNotSame([], PackChecks::nameProblems(self::people(), self::user('lamp_one', 'Lamp', 'Two')), 'Lamp Two is listed, but not as lamp_one.');
    $this->assertNotSame([], PackChecks::nameProblems(self::people(), self::user('lamp_three', 'Lamp', 'Three')));
  }

  /**
   * Only a heading that is exactly "People" opens the section.
   */
  public function testPeopleHeadingIsExact(): void {
    $source = "# The people of the lamp pack\n\n## Groups\n\n- lamp_one (Lamp One): Listed under Groups.\n";
    $this->assertNotSame([], PackChecks::nameProblems($source, self::user('lamp_one', 'Lamp', 'One')), 'A longer heading is not the People section.');
    $this->assertSame(['lamp_one' => 'Lamp One', 'lamp_two' => 'Lamp Two'], PackChecks::peopleRecords(self::people()));
    $this->assertSame([], PackChecks::peopleRecords($source));
    // A heading that only contains the word does not open it either.
    $longer = "# Lamp pack\n\n## People and places\n\n- lamp_one (Lamp One): Listed under a longer heading.\n";
    $this->assertSame([], PackChecks::peopleRecords($longer));
    $this->assertNotSame([], PackChecks::nameProblems($longer, self::user('lamp_one', 'Lamp', 'One')));
  }

  /**
   * The source of a made-up pack whose People section is a table.
   */
  private static function peopleTable(): string {
    return "# Lamp pack\n\n## 1. People\n\nTwo people live here.\n\n| Name | Initials | Role |\n|---|---|---|\n| Lamp One | LO | Meets every group |\n| Mo O'Lamp | MO | Keeps the lamp |\n\n**Second house**\n\n| Name | Initials | Role |\n|---|---|---|\n| Lamp Two | LT | A second account |\n\n## 2. Groups\n\n| Name | Initials | Role |\n|---|---|---|\n| Lamp Three | L3 | Listed under Groups, not People |\n";
  }

  /**
   * A People section written as tables lists its people by display name.
   *
   * The username is the display name lowercase, spaces as underscores and
   * apostrophes dropped; the header and separator rows are not people, and a
   * table under another heading is not read.
   */
  public function testPeopleTablesListPeopleByDisplayName(): void {
    $this->assertSame(
      ['lamp_one' => 'Lamp One', 'mo_olamp' => "Mo O'Lamp", 'lamp_two' => 'Lamp Two'],
      PackChecks::peopleRecords(self::peopleTable())
    );
    $this->assertSame([], PackChecks::nameProblems(self::peopleTable(), self::user('lamp_one', 'Lamp', 'One')));
    $this->assertSame([], PackChecks::nameProblems(self::peopleTable(), self::user('mo_olamp', 'Mo', "O'Lamp")));
    $this->assertSame([], PackChecks::nameProblems(self::peopleTable(), self::user('lamp_two', 'Lamp', 'Two')));
  }

  /**
   * An account missing from the table, or named otherwise, fails.
   */
  public function testPeopleTablesRejectAccountsTheyDoNotList(): void {
    $table = self::peopleTable();
    $this->assertNotSame([], PackChecks::nameProblems($table, self::user('lamp_three', 'Lamp', 'Three')), 'Lamp Three is in a table under Groups, not People.');
    $this->assertNotSame([], PackChecks::nameProblems($table, self::user('name', 'Name', '')), 'The header row is not a person.');
    $this->assertNotSame([], PackChecks::nameProblems($table, self::user('lamp_one', 'Lamp', 'Two')), 'Lamp Two is listed, but not as lamp_one.');
    $this->assertNotSame([], PackChecks::nameProblems($table, self::user('lamp_on', 'Lamp', 'One')), 'Half a username is not a username.');
    $longer = str_replace('## 1. People', '## 1. People and places', $table);
    $this->assertSame([], PackChecks::peopleRecords($longer), 'A longer heading is not the People section.');
    $this->assertNotSame([], PackChecks::nameProblems($longer, self::user('lamp_one', 'Lamp', 'One')));
  }

  /**
   * Any address, plain or written with entities, must be on a reserved domain.
   */
  public function testAddressesAreReservedWhateverTheirEncoding(): void {
    $this->assertSame(0, PackChecks::addressProblems('Say hello at nine, no mail.')['count']);
    $kept = PackChecks::addressProblems('Mail lamp-one@lamp.example or lamp&#64;lamp.example or lamp&#x40;lamp.example.');
    $this->assertSame(3, $kept['count']);
    $this->assertSame([], $kept['problems']);
    foreach (['bad@gmail.com', 'bad&#64;gmail.com', 'bad&#x40;gmail.com', 'bad&amp;#64;gmail.com', 'bad&commat;gmail.com', '<a href="mailto:bad&#64;gmail.com">x</a>'] as $text) {
      $this->assertNotSame([], PackChecks::addressProblems($text)['problems'], "$text is not on a reserved domain.");
    }
    $this->assertStringNotContainsString('bad', implode(' ', PackChecks::addressProblems('bad@gmail.com')['problems']), 'The message names the domain, not the address.');
  }

  /**
   * An address written with a YAML escape is read as the address it is.
   *
   * The raw text of "bad\u0040gmail.com" holds no "@", so only the parsed
   * value shows the address. Single-quoted PHP strings keep the backslash for
   * the YAML parser.
   */
  public function testYamlEscapesCannotHideAnAddress(): void {
    foreach ([
      'must_not_say: ["bad\u0040gmail.com"]',
      'must_not_say: ["bad\x40gmail.com"]',
      'must_not_say: ["bad\u0040gmail.com"]' . "\n" . 'other: fine',
      "rows:\n  - id: a\n    must_say:\n      - \"bad\\u0040gmail.com\"",
      '"bad\u0040gmail.com": value',
      'body: {value: "<p>bad\x40gmail.com</p>", format: basic_html}',
      'mail: bad@gmail.com',
      "mail: 'bad&#64;gmail.com'",
    ] as $yaml) {
      $result = PackChecks::yamlAddressProblems($yaml);
      $this->assertNotSame([], $result['problems'], "$yaml holds an address that is not reserved.");
      $this->assertStringNotContainsString('bad', implode(' ', $result['problems']), 'The message names the domain, not the address.');
    }
    $kept = PackChecks::yamlAddressProblems('must_say: ["lamp\u0040lamp.example", "no address here"]');
    $this->assertSame([], $kept['problems']);
    $this->assertGreaterThan(0, $kept['count'], 'The escaped address is counted.');
    $this->assertSame(0, PackChecks::yamlAddressProblems('title: Say hello at nine')['count']);
  }

  /**
   * The timezone the date oracle tests run in.
   */
  private const ZONE = 'America/Denver';

  /**
   * Runs a callback with the default timezone set, then puts it back.
   */
  private static function inZone(string $zone, callable $callback): mixed {
    $before = date_default_timezone_get();
    date_default_timezone_set($zone);
    try {
      return $callback();
    }
    finally {
      date_default_timezone_set($before);
    }
  }

  /**
   * The date oracle gives the weekday and date a calendar gives, by hand.
   *
   * The expected strings are written out here, not computed, so the oracle is
   * checked against something that does not share its code.
   *
   * @dataProvider dateProvider
   */
  public function testExpectedDateMatchesTheCalendar(string $text, string $expected): void {
    $got = self::inZone(self::ZONE, static fn (): string => PackChecks::expectedDate($text, mktime(12, 0, 0, 11, 12, 2026)));
    $this->assertSame($expected, $got);
  }

  /**
   * Offsets from noon on Thursday 12 November 2026, across a year change.
   */
  public static function dateProvider(): array {
    return [
      'a week on' => ['We meet on {{day:+7}}.', 'We meet on Thursday 19 November.'],
      'today' => ['{{day:+0}}', 'Thursday 12 November'],
      'two weeks back' => ['{{day:-14}}', 'Thursday 29 October'],
      'into the new year' => ['{{day:+50}}', 'Friday 1 January'],
      'two tokens' => ['{{day:+1}} to {{day:+2}}', 'Friday 13 November to Saturday 14 November'],
      'no token' => ['Plain text.', 'Plain text.'],
    ];
  }

  /**
   * A late evening in the site's zone is still that day, not the UTC day.
   */
  public function testExpectedDateCountsInTheSiteTimezone(): void {
    $got = self::inZone(self::ZONE, static fn (): string => PackChecks::expectedDate('{{day:+7}}', mktime(23, 30, 0, 11, 12, 2026)));
    $this->assertSame('Thursday 19 November', $got);
  }

  /**
   * A token the oracle cannot read is an error, never left in the text.
   */
  public function testExpectedDateRejectsAMalformedToken(): void {
    $this->expectException(\InvalidArgumentException::class);
    PackChecks::expectedDate('{{day:soon}}', 1_800_000_000);
  }

  /**
   * The loader's resolver agrees with the oracle for every offset and anchor.
   *
   * Anchors sit on both sides of the clock changes, at noon and just before
   * midnight, in a zone that has them and in UTC.
   */
  public function testResolverAgreesWithTheOracle(): void {
    foreach (['UTC', self::ZONE] as $zone) {
      self::inZone($zone, function () use ($zone): void {
        foreach ([[2026, 3, 7], [2026, 3, 8], [2026, 10, 31], [2026, 11, 1], [2026, 11, 12], [2026, 12, 31]] as [$y, $m, $d]) {
          foreach ([[12, 0], [23, 59], [0, 1]] as [$h, $i]) {
            $anchor = mktime($h, $i, 0, $m, $d, $y);
            for ($n = -400; $n <= 400; $n += 7) {
              $token = '{{day:' . ($n < 0 ? '' : '+') . $n . '}}';
              $this->assertSame(
                PackChecks::expectedDate($token, $anchor),
                PackTokens::resolve($token, $anchor),
                "$zone, $y-$m-$d $h:$i, $token"
              );
            }
          }
        }
      });
    }
  }

}
