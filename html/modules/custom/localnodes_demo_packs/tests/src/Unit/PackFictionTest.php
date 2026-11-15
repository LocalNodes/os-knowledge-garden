<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Tests\localnodes_demo_packs\PackFiction;
use Drupal\Tests\UnitTestCase;

/**
 * The name scan finds a real-looking name wherever it sits, and only then.
 *
 * Each case feeds the scan a short text. A real person, company, city or street
 * must be reported against an inventory of made-up names; the made-up names,
 * the ordinary words of a screen and the calendar must pass.
 *
 * @group localnodes_demo_packs
 */
final class PackFictionTest extends UnitTestCase {

  private const INVENTORY = ['Smoke One', 'Smoke hall', '1 Smoke Lane'];

  private const PEOPLE = ['Smoke One'];

  /**
   * The runs a text leaves uncovered, against the test inventory.
   *
   * @return string[]
   */
  private static function unknown(string $text, array $lower_texts = []): array {
    $plain = PackFiction::plain($text);
    $known = PackFiction::lowerWords(array_merge([$plain], array_map([PackFiction::class, 'plain'], $lower_texts)));
    return PackFiction::unknownRuns($plain, $known, self::INVENTORY, PackFiction::ORDINARY);
  }

  /**
   * A real person, company, city and street are each reported.
   *
   * @dataProvider realNames
   */
  public function testRealLookingNamesFail(string $text, string $name): void {
    $this->assertContains($name, self::unknown($text));
  }

  /**
   * Each name sits in the middle of a sentence.
   */
  public static function realNames(): array {
    return [
      'person' => ['The club asked Ada Lovelace to speak.', 'Ada Lovelace'],
      'company' => ['Members can search with Google at the hall.', 'Google'],
      'city' => ['The next meeting is in London on Tuesday.', 'London'],
      'street' => ['Send the keys to 10 Downing Street by noon.', '10 Downing Street'],
    ];
  }

  /**
   * A real name at the start of a sentence is reported too.
   */
  public function testASentenceStartNameFails(): void {
    $this->assertContains('Google', self::unknown('Google is where the notice went.'));
    $this->assertContains('London', self::unknown('One day. London is big.'));
  }

  /**
   * A name an inventory entry does not hold, even in part, is reported.
   */
  public function testAPartialMatchIsNotACover(): void {
    $this->assertContains('Smoke Lake', self::unknown('We met at Smoke Lake today.'));
    $this->assertContains('Smoke One Two', self::unknown('We met Smoke One Two today.'));
  }

  /**
   * The made-up names, in whole or in a stretch of their words, pass.
   */
  public function testInventoryNamesPass(): void {
    $this->assertSame([], self::unknown('We met Smoke One in the Smoke hall at 1 Smoke Lane.'));
    $this->assertSame([], self::unknown('We asked Smoke about the hall; One came.'));
  }

  /**
   * Calendar words and screen words cut a run and are not reported.
   */
  public function testOrdinaryWordsPass(): void {
    $this->assertSame([], self::unknown('We start on Saturday 21 November at 7:00 PM in UTC.'));
    $this->assertSame([], self::unknown('We sign in to LocalNodes and open the page.'));
    $this->assertSame(['Ada Lovelace'], self::unknown('Saturday 21 November: Ada Lovelace joined.'));
  }

  /**
   * A month or weekday word is ordinary only beside a number, a day token or
   * another calendar word; alone in a run it is a name like any other.
   */
  public function testCalendarWordsNeedACalendarContext(): void {
    $this->assertSame([], self::unknown('We meet May 3 at noon.'));
    $this->assertSame([], self::unknown('We meet on 3 May at noon.'));
    $this->assertSame([], self::unknown('We meet {{day:+1}} May at noon.'));
    $this->assertSame([], self::unknown('We meet May {{day:+1}} at noon.'));
    $this->assertSame([], self::unknown('We meet Friday May at noon.'));
    $this->assertSame([], self::unknown('We meet Mon 3 Jun at noon.'));
    $this->assertContains('May', self::unknown('Ask May about it.'));
    $this->assertContains('June', self::unknown('We asked June about it.'));
    $this->assertContains('Sun', self::unknown('We asked Sun about it.'));
    $this->assertContains('Ada May', self::unknown('We asked Ada May about it.'));
    $this->assertSame(['Ada Lovelace'], self::unknown('We met Ada Lovelace May 3 at noon.'), 'A name beside a dated month still fails.');
  }

  /**
   * Plain words that start a sentence of ordinary prose pass; a name beside
   * them still fails.
   */
  public function testPlainProseWordsPassAndNamesBesideThemFail(): void {
    $this->assertSame([], self::unknown('Welcome to the hall. Thanks Smoke. Agreed. Present: Smoke One.'));
    $this->assertSame(['Ada Lovelace'], self::unknown('Welcome. Thanks Ada Lovelace. Agreed.'));
    $this->assertContains('Google', self::unknown('Present at the meeting: Google.'));
  }

  /**
   * A count beside a name is not part of the name, unless the entry has one.
   */
  public function testANumberBesideACoveredNameIsNotAName(): void {
    $this->assertSame([], self::unknown('Smoke 12 and 3 Smoke came; Smoke hall 9.'));
    $this->assertContains('Ada Lovelace 12', self::unknown('We met Ada Lovelace 12 times.'));
    $this->assertContains('10 Smoke Lane', self::unknown('We met at 10 Smoke Lane today.'), 'A street number must match the entry.');
    $this->assertSame([], self::unknown('We met at 1 Smoke Lane today.'));
    $this->assertContains('Smoke Lake 12', self::unknown('We met at Smoke Lake 12 today.'), 'A number does not make a stretch of words a cover.');
  }

  /**
   * A username or address written without an apostrophe is the same person.
   */
  public function testAnApostropheMayBeDroppedFromAMailWord(): void {
    $people = ["Smoke O'One"];
    $this->assertSame([], PackFiction::mailProblems('smoke.oone@smoke.example', $people));
    $this->assertSame([], PackFiction::mailProblems("smoke.o.one@smoke.example", $people));
    $this->assertSame(['otwo'], PackFiction::mailProblems('smoke.otwo@smoke.example', $people));
    $this->assertSame(['obrien'], PackFiction::mailProblems('smoke.obrien@smoke.example', $people));
  }

  /**
   * US is not an ordinary word: it is the name of a country.
   */
  public function testUsIsNotOrdinary(): void {
    $this->assertContains('US', self::unknown('We asked the US office about it.'));
    $this->assertNotContains('US', PackFiction::ORDINARY);
  }

  /**
   * A web address is fine on a reserved host or as the site's own path only.
   *
   * @dataProvider addresses
   */
  public function testWebAddresses(string $text, int $bad): void {
    $this->assertCount($bad, PackFiction::linkProblems($text), $text);
  }

  /**
   * Address text and how many hosts it names that are not reserved.
   */
  public static function addresses(): array {
    return [
      'real site' => ['See https://www.google.com/ for it.', 1],
      'real site in a link' => ['<a href="https://www.google.com/">here</a>', 1],
      'real site in markdown' => ['[here](http://news.ycombinator.com/item?id=1)', 1],
      'bare www' => ['Go to www.google.com today.', 1],
      'protocol relative' => ['<script src="//cdn.real.net/x.js"></script>', 1],
      'userinfo' => ['https://example.com@evil.net/', 1],
      'lookalike' => ['https://example.com.evil.org/', 1],
      'suffix lookalike' => ['https://notexample.com/', 1],
      'two hosts' => ['https://a.example/ and https://b.google.com/', 1],
      'example.com' => ['https://example.com/a', 0],
      'subdomain of example.net' => ['https://www.example.net/a', 0],
      'example.org' => ['http://example.org', 0],
      'example tld' => ['https://smoke.example/page', 0],
      'localhost' => ['http://localhost:8080/page', 0],
      'test tld' => ['https://site.test/page', 0],
      'invalid tld' => ['https://site.invalid/page', 0],
      'own path' => ['Open /group/1/stream or <a href="/node/2">this</a>.', 0],
      'a time is no address' => ['At 7:30 we start at 10:15.', 0],
    ];
  }

  /**
   * A mail address names an inventory person, a role, or fails.
   *
   * @dataProvider mails
   */
  public function testMailLocalParts(string $text, int $bad): void {
    $this->assertCount($bad, PackFiction::mailProblems($text, self::PEOPLE), $text);
  }

  /**
   * Text with addresses and how many local-part words it leaves uncovered.
   */
  public static function mails(): array {
    return [
      'real person' => ['Write to ada.lovelace@smoke.example.', 2],
      'real person, underscore and digits' => ['ada_lovelace42@smoke.example', 2],
      'one real word' => ['smoke.lovelace@smoke.example', 1],
      'in a link' => ['<a href="mailto:grace-hopper@smoke.example">x</a>', 2],
      'made-up person' => ['smoke-one@smoke.example', 0],
      'made-up with digits' => ['smoke_one7@smoke.example', 0],
      'case does not matter' => ['Smoke.One@smoke.example', 0],
      'a role' => ['info@smoke.example', 0],
      'a UI placeholder is no address' => ['Import @count files and smoke_two @ Smoke meetup.', 0],
    ];
  }

  /**
   * A username and a mail field are checked like a mail local part.
   */
  public function testAccountFieldsAreScanned(): void {
    $good = ['u1' => ['uuid' => 'x', 'name' => 'smoke_one', 'mail' => 'smoke-one@smoke.example']];
    $this->assertSame([], PackFiction::accountProblems($good, self::PEOPLE));
    $name = ['u1' => ['uuid' => 'x', 'name' => 'ada_lovelace', 'mail' => 'smoke-one@smoke.example']];
    $this->assertSame(['name: ada', 'name: lovelace'], PackFiction::accountProblems($name, self::PEOPLE));
    $mail = ['u1' => ['uuid' => 'x', 'name' => 'smoke_one', 'mail' => 'ada.lovelace@smoke.example']];
    $this->assertSame(['mail: ada', 'mail: lovelace'], PackFiction::accountProblems($mail, self::PEOPLE));
    $nested = ['f' => ['a' => ['b' => ['name' => 'Grace Hopper']]]];
    $this->assertSame(['name: grace', 'name: hopper'], PackFiction::accountProblems($nested, self::PEOPLE));
    $this->assertNotContains('name', PackFiction::CODE_KEYS);
    $this->assertNotContains('mail', PackFiction::CODE_KEYS);
  }

  /**
   * A web address or a mail address in text goes through the whole scan.
   */
  public function testScanReportsAddressesInText(): void {
    $files = ['a.yml' => [
      ['Visit <a href="https://www.google.com/">the site</a> or mail ada.lovelace@smoke.example.', []],
      ['Mail smoke-one@smoke.example or see https://smoke.example/page.', []],
    ]];
    $found = PackFiction::scan($files, self::INVENTORY, self::PEOPLE, PackFiction::ORDINARY)['a.yml'] ?? [];
    $this->assertContains('link: www.google.com', $found);
    $this->assertContains('mail: ada', $found);
    $this->assertContains('mail: lovelace', $found);
    $this->assertNotContains('link: smoke.example', $found);
    $this->assertNotContains('mail: smoke', $found);
  }

  /**
   * A name in lower case is beyond the capital-letter scan; the short list of
   * real words catches it whatever its case.
   */
  public function testRealWordsAreFoundInAnyCase(): void {
    $words = ['Ada Lovelace', 'gmail'];
    $this->assertSame(['Ada Lovelace'], PackFiction::realWordProblems('We asked ada lovelace to speak.', $words));
    $this->assertSame(['Ada Lovelace'], PackFiction::realWordProblems('ADA LOVELACE spoke.', $words));
    $this->assertSame(['gmail'], PackFiction::realWordProblems('Use Gmail.', $words));
    $this->assertSame([], PackFiction::realWordProblems('We asked Smoke One to speak. The gmailer is out.', $words));
  }

  /**
   * A sentence-start word is ordinary when the same file has it in lower case
   * somewhere other than the start of a sentence.
   */
  public function testASentenceStartWordWrittenInLowerCasePasses(): void {
    $this->assertSame([], self::unknown('Meet the group at noon.', ['You can meet the group.']));
    $this->assertSame(['Meet'], self::unknown('Meet the group at noon.'));
  }

  /**
   * Lower case at the start of a sentence is no evidence of an ordinary word.
   */
  public function testALowerCaseWordThatStartsASentenceIsNoEvidence(): void {
    $this->assertSame(['Google'], self::unknown('Google is where the notice went.', ['google is the other word.']));
    $this->assertSame(['Google'], self::unknown('Google is where. google is big.'));
    $this->assertSame([], self::unknown('Google is where. we said google.'));
  }

  /**
   * The lower-case evidence must come from the same file.
   */
  public function testSentenceStartEvidenceIsPerFile(): void {
    $files = [
      'a.md' => [['Google is where the notice went.', []]],
      'b.md' => [['We say google here.', []]],
    ];
    $found = PackFiction::scan($files, self::INVENTORY, self::PEOPLE, PackFiction::ORDINARY);
    $this->assertSame(['Google'], $found['a.md'] ?? []);
    $this->assertSame([], $found['b.md'] ?? []);
    // The file that holds the lower-case word may come first.
    $swapped = ['b.md' => $files['b.md'], 'a.md' => $files['a.md']];
    $found = PackFiction::scan($swapped, self::INVENTORY, self::PEOPLE, PackFiction::ORDINARY);
    $this->assertSame(['Google'], $found['a.md'] ?? []);
    $same = ['a.md' => [['Google is where the notice went. We say google here.', []]]];
    $this->assertSame([], PackFiction::scan($same, self::INVENTORY, self::PEOPLE, PackFiction::ORDINARY)['a.md'] ?? []);
  }

  /**
   * An identifier or a code is not a name.
   */
  public function testIdentifiersAreNotRuns(): void {
    $this->assertSame([], self::unknown('We see page P01-join and QuickPollWording and P07 hold it.'));
  }

  /**
   * A possessive and a markup tag do not hide or make a name.
   */
  public function testPlainTextHandlesPossessivesAndMarkup(): void {
    $this->assertContains('Ada Lovelace', self::unknown("<p>It is Ada Lovelace's desk.</p>"));
    $this->assertSame([], self::unknown('<p>Smoke One&#039;s desk</p>'));
  }

  /**
   * A table bar splits runs, so neighbouring cells do not join.
   */
  public function testATableBarIsABoundary(): void {
    $this->assertContains('Ada', self::unknown('| Smoke One | Ada |'));
    $this->assertNotContains('One Ada', self::unknown('| Smoke One | Ada |'));
  }

  /**
   * Display names, group labels, places and address lines are collected whole.
   */
  public function testStructuredNamesAreCollected(): void {
    $files = [
      'user.yml' => ['a' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'organization' => '']],
      'group.yml' => ['b' => ['label' => 'Smoke back room', 'nested' => ['label' => 'Inner']]],
      'event.yml' => ['c' => [
        'field_event_location' => 'Smoke hall',
        'field_event_address' => ['locality' => 'Smoke Town', 'address_line1' => '1 Smoke Lane'],
        'body' => 'Not a name field.',
      ]],
    ];
    $names = PackFiction::structuredNames($files);
    foreach (['Ada Lovelace', 'Smoke back room', 'Inner', 'Smoke hall', 'Smoke Town', '1 Smoke Lane'] as $name) {
      $this->assertContains($name, $names);
    }
    $this->assertNotContains('Not a name field.', $names);
    $this->assertNotContains('', $names);
  }

  /**
   * A markdown source is scanned whole: its preface and builder sections too.
   */
  public function testMarkdownPrefaceAndBuilderSectionsAreScanned(): void {
    $markdown = "# Title\n\nNote to Ada Lovelace.\n\n## People\n\nSmoke One stays.\n\n## Sources and publication conditions\n\nGoogle says so.\n\n### Deeper\n\nLondon too.\n";
    $path = tempnam(sys_get_temp_dir(), 'fiction');
    file_put_contents($path . '.md', $markdown);
    try {
      $segments = PackFiction::fileSegments($path . '.md');
    }
    finally {
      unlink($path . '.md');
      unlink($path);
    }
    $found = PackFiction::scan(['s.md' => $segments], self::INVENTORY, self::PEOPLE, PackFiction::ORDINARY)['s.md'] ?? [];
    foreach (['Ada Lovelace', 'Google', 'London'] as $name) {
      $this->assertContains($name, $found);
    }
    $this->assertNotContains('Smoke One', $found);
  }

  /**
   * The preface's process words are ordinary in the preface and nowhere else.
   */
  public function testPrefaceWordsAreOrdinaryOnlyInThePreface(): void {
    $word = PackFiction::PREFACE_WORDS[0];
    $markdown = "# Title\n\nnote: $word was said.\n\n## people\n\nSmoke One stays; then $word came.\n";
    $path = tempnam(sys_get_temp_dir(), 'fiction');
    file_put_contents($path . '.md', $markdown);
    try {
      $segments = PackFiction::fileSegments($path . '.md');
    }
    finally {
      unlink($path . '.md');
      unlink($path);
    }
    $this->assertSame(PackFiction::PREFACE_WORDS, $segments[0][1], 'The preface carries the preface words.');
    $this->assertSame(PackFiction::BUILDER_WORDS, $segments[1][1], 'The rest carries the builder words, not the preface words.');
    $this->assertNotContains($word, PackFiction::BUILDER_WORDS);
    $found = PackFiction::scan(['s.md' => $segments], self::INVENTORY, self::PEOPLE, PackFiction::ORDINARY)['s.md'] ?? [];
    $this->assertSame([$word], $found, 'The word passes in the preface and fails in the body.');
  }

  /**
   * The builder's words are ordinary in a markdown source after its preface.
   */
  public function testBuilderWordsAreOrdinaryOnlyInMarkdownSections(): void {
    $word = PackFiction::BUILDER_WORDS[0];
    $markdown = "# title\n\nsee also $word here.\n\n## tools\n\nsee also $word here.\n";
    $path = tempnam(sys_get_temp_dir(), 'fiction');
    file_put_contents($path . '.md', $markdown);
    file_put_contents($path . '.yml', "note: 'see also $word here.'\n");
    try {
      $files = ['s.md' => PackFiction::fileSegments($path . '.md'), 's.yml' => PackFiction::fileSegments($path . '.yml')];
    }
    finally {
      unlink($path . '.md');
      unlink($path . '.yml');
      unlink($path);
    }
    $found = PackFiction::scan($files, self::INVENTORY, self::PEOPLE, PackFiction::ORDINARY);
    $this->assertSame([$word], $found['s.md'] ?? [], 'Passes after the preface, fails in it.');
    $this->assertSame([$word], $found['s.yml'] ?? [], 'Fails in a YAML file.');
  }

  /**
   * A markdown link's destination is an address, not words.
   */
  public function testALinkDestinationIsNotText(): void {
    $plain = PackFiction::plain('See the [promises table](../NOTES-TABLE-1111.md).');
    $this->assertStringContainsString('promises table', $plain);
    $this->assertStringNotContainsString('NOTES', $plain);
    $found = PackFiction::scan(['s.md' => [['then [Google](https://www.google.com/) and [notes](../NOTES-1111.md).', []]]], self::INVENTORY, self::PEOPLE, PackFiction::ORDINARY);
    $this->assertSame(['link: www.google.com', 'Google'], $found['s.md'] ?? []);
  }

  /**
   * A country code field is a code; the same letters in prose are not.
   */
  public function testACountryCodeFieldIsACodeNotProse(): void {
    $path = tempnam(sys_get_temp_dir(), 'fiction');
    file_put_contents($path . '.yml', "address:\n  country_code: US\n  note: 'The US office.'\n");
    try {
      $segments = PackFiction::fileSegments($path . '.yml');
    }
    finally {
      unlink($path . '.yml');
      unlink($path);
    }
    $this->assertSame([['The US office.', []]], $segments);
  }

  /**
   * The words of the screen labels are ordinary words.
   */
  public function testLabelWordsAreSplitFromLabels(): void {
    $words = PackFiction::labelWords([['label' => 'Send request'], ['label' => "What's new"], ['id' => 'x']]);
    $this->assertContains('Send', $words);
    $this->assertContains('request', $words);
    $this->assertContains("What's", $words);
    $this->assertSame([], self::unknownWith('Open Send request', $words));
  }

  /**
   * The runs of a text against extra ordinary words.
   *
   * @param string[] $extra
   *
   * @return string[]
   */
  private static function unknownWith(string $text, array $extra): array {
    $plain = PackFiction::plain($text);
    return PackFiction::unknownRuns($plain, PackFiction::lowerWords([$plain]), self::INVENTORY, array_merge(PackFiction::ORDINARY, $extra));
  }

  /**
   * A pack's own ordinary words cut a run; a name stays a name.
   */
  public function testAPacksOrdinaryWordsAreScopedAndChecked(): void {
    $this->assertContains('March', self::unknown('We met in March.'), 'Alone, a month is a name like any other.');
    $this->assertSame([], self::unknownWith('We met in March.', ['March']));
    $this->assertContains('Ada Lovelace', self::unknownWith('In March Ada Lovelace came.', ['March']));
    $inventory = ['people' => ['Smoke One'], 'organisations' => [], 'places' => [], 'streets' => []];
    $this->assertSame([], PackFiction::inventoryProblems($inventory + ['ordinary' => ['March']]));
    $this->assertSame(['March'], PackFiction::ordinaryWords($inventory + ['ordinary' => ['March']]));
    $this->assertSame([], PackFiction::ordinaryWords($inventory));
    $this->assertNotSame([], PackFiction::inventoryProblems($inventory + ['ordinary' => ['Smoke']]), 'A word of a listed name cannot hide as an ordinary word.');
    $this->assertNotSame([], PackFiction::inventoryProblems($inventory + ['ordinary' => ['March', 'March']]), 'Twice is a fault.');
    $this->assertNotSame([], PackFiction::inventoryProblems($inventory + ['ordinary' => ['March April']]), 'One word each.');
    $this->assertNotSame([], PackFiction::inventoryProblems($inventory + ['ordinary' => 'March']), 'A list.');
    $this->assertNotSame([], PackFiction::inventoryProblems($inventory + ['colours' => []]), 'Another key is still refused.');
  }

  /**
   * An inventory file must list each group once, with whole distinct names.
   */
  public function testInventoryFormProblems(): void {
    $good = ['people' => ['Smoke One'], 'organisations' => [], 'places' => ['Smoke hall'], 'streets' => ['1 Smoke Lane']];
    $this->assertSame([], PackFiction::inventoryProblems($good));
    $this->assertNotSame([], PackFiction::inventoryProblems(['people' => ['Smoke One']] + ['organisations' => [], 'places' => []]), 'A missing group is a fault.');
    $this->assertNotSame([], PackFiction::inventoryProblems($good + ['planets' => ['Mars']]), 'An unknown group is a fault.');
    $this->assertNotSame([], PackFiction::inventoryProblems(['people' => ['Smoke One', 'Smoke One']] + $good), 'A repeat is a fault.');
    $this->assertNotSame([], PackFiction::inventoryProblems(['places' => ['Smoke One']] + $good), 'A name in two groups is a fault.');
    $this->assertNotSame([], PackFiction::inventoryProblems(['people' => ['  ']] + $good), 'A blank is a fault.');
    $this->assertNotSame([], PackFiction::inventoryProblems(['people' => [['x']]] + $good), 'A non-string is a fault.');
    $this->assertNotSame([], PackFiction::inventoryProblems(['people' => 'Smoke One'] + $good), 'A group that is not a list is a fault.');
  }

  /**
   * The names of an inventory are every group's entries, trimmed.
   */
  public function testNamesJoinTheGroups(): void {
    $names = PackFiction::names(['people' => [' Smoke One '], 'places' => ['Smoke hall'], 'streets' => [], 'organisations' => []]);
    $this->assertSame(['Smoke One', 'Smoke hall'], $names);
  }

  /**
   * An entry the scanned text never states is stale.
   */
  public function testStaleEntriesAreFound(): void {
    $texts = ['We met Smoke One in the Smoke hall.', 'Another line.'];
    $this->assertSame([], PackFiction::staleEntries(['Smoke One', 'Smoke hall'], $texts));
    $this->assertSame(['Smoke Town'], PackFiction::staleEntries(['Smoke One', 'Smoke Town'], $texts));
  }

}
