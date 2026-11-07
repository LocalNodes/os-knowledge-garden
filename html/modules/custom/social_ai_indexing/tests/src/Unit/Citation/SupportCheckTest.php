<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Citation;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\social_ai_indexing\Citation\SupportCheck;
use Drupal\Tests\UnitTestCase;

/**
 * The runtime support check keeps, trims by deletion, or drops (22-27 B).
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class SupportCheckTest extends UnitTestCase {

  private const EXCERPTS = [
    1 => ['Fictional: the seed vault key is kept at the old mill. Members mostly meet on Tuesdays.'],
    2 => ['The skill-share circle meets in the round room on the last evening of each month.'],
  ];

  private const ANSWER = "The seed vault key is kept at the old mill [R1]. Members meet on Tuesdays in the barn [R1].\n\n- The circle meets in the round room [R2]. It was founded in 1999 [R2].\nAsk a steward for details.";

  /**
   * Units: sentences per line, labels read, list markers kept.
   */
  public function testUnits(): void {
    $units = SupportCheck::units(self::ANSWER . "\nBoth groups share tools [R1, R2].");
    $this->assertCount(6, $units);
    $this->assertSame([1], $units[0]['labels']);
    $this->assertSame('The seed vault key is kept at the old mill.', $units[0]['body']);
    $this->assertSame('- ', $units[2]['prefix']);
    $this->assertSame([], $units[4]['labels'], 'An uncited sentence is a unit without labels.');
    $this->assertSame([1, 2], $units[5]['labels'], 'A grouped label counts both sources.');
  }

  /**
   * Kept, trimmed, dropped; uncited text and structure stay.
   */
  public function testApply(): void {
    $units = SupportCheck::units(self::ANSWER);
    $result = SupportCheck::apply(self::ANSWER, $units, [
      ['verdict' => 'supported', 'quote' => 'the seed vault key is kept at the old mill', 'keep' => 'The seed vault key is kept at the old mill.'],
      ['verdict' => 'partial', 'quote' => 'Members mostly meet on Tuesdays', 'keep' => 'Members meet on Tuesdays.'],
      ['verdict' => 'supported', 'quote' => 'meets in the round room', 'keep' => 'The circle meets in the round room.'],
      ['verdict' => 'not_supported', 'quote' => '', 'keep' => ''],
    ], self::EXCERPTS);
    $this->assertSame("The seed vault key is kept at the old mill [R1]. Members meet on Tuesdays [R1].\n\n- The circle meets in the round room [R2].\nAsk a steward for details.", $result['text']);
    $this->assertSame(['cited' => 4, 'kept' => 3, 'trimmed' => 1, 'dropped' => 1], array_intersect_key($result, array_flip(['cited', 'kept', 'trimmed', 'dropped'])));
  }

  /**
   * Supported needs its quote in the cited excerpts; partial needs it too.
   */
  public function testQuoteMustBeInTheCitedExcerpts(): void {
    $text = 'The seed vault key is kept at the old mill [R1].';
    $units = SupportCheck::units($text);
    $invented = SupportCheck::apply($text, $units, [['verdict' => 'supported', 'quote' => 'kept in the green tin', 'keep' => 'x']], self::EXCERPTS);
    $this->assertSame('NO_RELEVANT_CONTENT', $invented['text'], 'An invented quote drops the sentence; nothing left means no answer.');
    $wrong_source = SupportCheck::apply('The circle meets in the round room [R1].', SupportCheck::units('The circle meets in the round room [R1].'), [['verdict' => 'supported', 'quote' => 'meets in the round room', 'keep' => '']], self::EXCERPTS);
    $this->assertSame(0, $wrong_source['kept'], 'A quote from a source the sentence does not cite counts for nothing.');
  }

  /**
   * A trim may only delete; never add, reorder or remove a negation or hedge.
   */
  public function testTrimIsDeletionOnly(): void {
    $body = 'Members do not meet on Tuesdays in the barn.';
    $this->assertTrue(SupportCheck::isDeletionOf('Members do not meet on Tuesdays.', $body));
    $this->assertFalse(SupportCheck::isDeletionOf('Members do not meet on Mondays.', $body), 'A changed word is not a deletion.');
    $this->assertFalse(SupportCheck::isDeletionOf('On Tuesdays members do not meet.', $body), 'Reordering is not a deletion.');
    $this->assertFalse(SupportCheck::isDeletionOf('Members do meet on Tuesdays.', $body), 'Deleting a negation flips the claim.');
    $this->assertFalse(SupportCheck::isDeletionOf('Members meet on Tuesdays.', 'Members mostly meet on Tuesdays.'), 'Deleting a hedge strengthens the claim.');
    $this->assertFalse(SupportCheck::isDeletionOf('Members meet.', 'Members meet on Tuesdays.'), 'Fewer than three words is not kept.');
    $this->assertTrue(SupportCheck::isDeletionOf("Members don't meet.", "Members don't meet on Tuesdays."), 'Three words, negation kept.');
    $this->assertFalse(SupportCheck::isDeletionOf('Members meet on Tuesdays here.', "Members don't meet on Tuesdays here."), "Deleting n't flips the claim.");

    $text = 'Members meet on Tuesdays in the barn [R1].';
    $added = SupportCheck::apply($text, SupportCheck::units($text), [['verdict' => 'partial', 'quote' => 'Members mostly meet on Tuesdays', 'keep' => 'Members always meet on Tuesdays.']], self::EXCERPTS);
    $this->assertSame('NO_RELEVANT_CONTENT', $added['text'], 'A trim that adds a word drops the sentence.');
  }

  /**
   * Off, failed or incomplete checks leave the answer unchanged.
   */
  public function testServiceFailsOpenToUnchangedText(): void {
    $registry = new SourceRegistry();
    $providers = $this->createMock(PluginManagerInterface::class);
    $providers->method('createInstance')->willThrowException(new \RuntimeException('timeout'));

    $off = new SupportCheck($providers, $this->getConfigFactoryStub(['social_ai_indexing.settings' => ['support_check' => ['assistant' => FALSE, 'overview' => TRUE]]]));
    $result = $off->check(self::ANSWER, $registry, 'assistant');
    $this->assertSame('skipped', $result['status']);
    $this->assertSame(self::ANSWER, $result['text']);

    $on = new SupportCheck($providers, $this->getConfigFactoryStub(['social_ai_indexing.settings' => ['support_check' => ['assistant' => TRUE, 'overview' => TRUE, 'timeout' => 8]]]));
    $failed = $on->check(self::ANSWER, $registry, 'assistant');
    $this->assertSame('unchecked', $failed['status'], 'A failed call is logged as unchecked.');
    $this->assertSame(self::ANSWER, $failed['text'], 'A failed call sends the answer unchanged.');

    $this->assertSame('none', $on->check('NO_RELEVANT_CONTENT', $registry, 'assistant')['status'], 'No call for a no-answer.');
    $this->assertSame('none', $on->check('Nothing cited here.', $registry, 'overview')['status'], 'No call when nothing is cited.');
  }

  /**
   * No key: no provider call at all, so the CI egress guard sees nothing.
   */
  public function testNoKeyMeansNoCall(): void {
    $text = 'The seed vault key is kept at the old mill [R1].';
    $registry = new SourceRegistry();
    $config = [
      'social_ai_indexing.settings' => ['support_check' => ['assistant' => TRUE, 'overview' => TRUE, 'provider' => 'gemini', 'timeout' => 8]],
      'gemini_provider.settings' => ['api_key' => 'gemini'],
    ];
    foreach (['provider not usable' => [FALSE, 'ignored'], 'key id set, key value empty' => [TRUE, ''], 'key value present (control)' => [TRUE, 'fictional-key']] as $case => [$usable, $value]) {
      $provider = new class($usable) {

        public int $calls = 0;

        public function __construct(private bool $usable) {}

        public function isUsable(?string $operation_type = NULL): bool {
          return $this->usable;
        }

        public function getPluginDefinition(): array {
          return ['provider' => 'gemini_provider'];
        }

        public function chat(...$arguments): object {
          $this->calls++;
          throw new \RuntimeException('no network in unit tests');
        }

      };
      $providers = $this->createMock(PluginManagerInterface::class);
      $providers->method('createInstance')->willReturn($provider);
      $key = $this->createMock(KeyInterface::class);
      $key->method('getKeyValue')->willReturn($value);
      $keys = $this->createMock(KeyRepositoryInterface::class);
      $keys->method('getKey')->willReturn($key);
      $check = new SupportCheck($providers, $this->getConfigFactoryStub($config), $keys);
      $result = $check->check($text, $registry, 'assistant');
      $this->assertSame('unchecked', $result['status'], "$case: unchecked.");
      $this->assertSame($text, $result['text'], "$case: the answer goes out unchanged.");
      $this->assertSame($value === 'fictional-key' ? 1 : 0, $provider->calls, "$case: provider chat() calls.");
    }
  }

  /**
   * Names the cited excerpts never mention drop the sentence.
   */
  public function testUnsupportedNamesAreDropped(): void {
    $evidence = 'A bioregion may cross the Missouri and Oklahoma state lines. Rivers shape the watershed. The work of Jose Ramos.';
    $this->assertSame(['Ozarks'], SupportCheck::unsupportedNames('Examples include the Ozarks, across several state lines.', $evidence));
    $this->assertSame(['Cascadia'], SupportCheck::unsupportedNames('One example is Cascadia [R1].', $evidence));
    $this->assertSame([], SupportCheck::unsupportedNames('It crosses the Missouri and Oklahoma lines [R1].', $evidence), 'Names in the excerpt are fine.');
    $this->assertSame([], SupportCheck::unsupportedNames("Ramos's work is cited.", $evidence), 'A possessive of a name in the excerpt is fine.');
    $this->assertSame([], SupportCheck::unsupportedNames('Rivers shape the watershed.', $evidence), 'A sentence-initial capital is not a name.');
    $this->assertSame([], SupportCheck::unsupportedNames('Watersheds matter.', $evidence), 'A sentence-initial common word is not a name either.');
    $this->assertSame(['Kostakis'], SupportCheck::unsupportedNames('Vasilis Kostakis wrote it.', $evidence), 'A name at the start is caught by its next word.');
    // Seen in rerun 3b (S05, S11): both supported, neither may be dropped.
    $this->assertSame([], SupportCheck::unsupportedNames('It is linked to the Cosmo-Local Reader.', 'Read the cosmo local reader.'), 'Hyphens fold like the excerpt.');
    $this->assertSame([], SupportCheck::unsupportedNames('Market Production: Unlike CBPP, markets use prices.', 'market production uses prices unlike cbpp'), 'A capital after a colon starts a clause.');

    $text = 'Examples include the Ozarks across state lines [R1]. The bioregion crosses the Missouri and Oklahoma lines [R1].';
    $excerpts = [1 => [$evidence]];
    $result = SupportCheck::apply($text, SupportCheck::units($text), [
      ['verdict' => 'supported', 'quote' => 'cross the Missouri and Oklahoma state lines', 'keep' => ''],
      ['verdict' => 'supported', 'quote' => 'cross the Missouri and Oklahoma state lines', 'keep' => ''],
    ], $excerpts);
    $this->assertStringNotContainsString('Ozarks', $result['text'], 'Judged supported, but the name is not in the excerpt: dropped.');
    $this->assertStringContainsString('Missouri and Oklahoma', $result['text']);
    $this->assertSame([1, 1], [$result['kept'], $result['dropped']]);
  }

}
