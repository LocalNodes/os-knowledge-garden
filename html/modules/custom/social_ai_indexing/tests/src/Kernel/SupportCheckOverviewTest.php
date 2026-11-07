<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\social_ai_indexing\Citation\SupportCheck;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\OverviewTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * The runtime support check in the overview door (22-27 option B).
 *
 * The overview's answer and the check's judgment are both scripted on
 * the echo provider, so this runs the real path: retrieval, the overview
 * call, the check call (its own tags, the cited excerpts fenced), the
 * code-side keep/trim/drop, then the citation rewrite and Sources list.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class SupportCheckOverviewTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use OverviewTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['social_ai_indexing_test', 'ai_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpOverviewSearch();
    $this->installConfig(['social_ai_indexing']);
    $this->config('social_ai_indexing.settings')
      ->set('support_check', ['assistant' => TRUE, 'overview' => TRUE, 'provider' => 'echoai', 'model' => 'gpt-test', 'timeout' => 8])
      ->save();
  }

  /**
   * A supported sentence stays; an invented one goes, and so does its source.
   */
  public function testUnsupportedSentenceIsDropped(): void {
    $quote = '';
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text('The members keep fictional notes [R1]. The group was founded on the moon in 1850 [R2].'),
      static function (ChatInput $input) use (&$quote) {
        // The check sees the excerpt shown for [R1]; quote its first words.
        $text = ScriptedLlmSubscriber::snapshot($input);
        preg_match('/\[R1\]\n<<<SOURCE_TEXT\n(.*?)\nSOURCE_TEXT>>>/s', $text, $m);
        $quote = mb_substr(trim($m[1] ?? ''), 0, 24);
        return ScriptedLlmSubscriber::text(json_encode(['sentences' => [
          ['i' => 1, 'verdict' => 'supported', 'quote' => $quote, 'keep' => 'The members keep fictional notes.'],
          ['i' => 2, 'verdict' => 'not_supported', 'quote' => '', 'keep' => ''],
        ]]));
      },
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);

    $calls = $this->allCalls();
    $this->assertCount(2, $calls, 'The overview call and one check call.');
    foreach (SupportCheck::TAGS as $tag) {
      $this->assertContains($tag, $calls[1]['tags'], "The check call is tagged $tag.");
    }
    $this->assertNotContains('social_ai_overview', $calls[1]['tags'], 'The check call is not tagged as an overview call.');
    $this->assertStringContainsString('<<<SOURCE_TEXT', $calls[1]['text'], 'Excerpts reach the check fenced as data.');
    $this->assertNotSame('', $quote, 'The check saw an excerpt for [R1].');

    $this->assertNotNull($response['summary_html'] ?? NULL);
    $this->assertStringContainsString('The members keep fictional notes', $response['summary_html']);
    $this->assertStringNotContainsString('moon', $response['summary_html'], 'The unsupported sentence is gone.');
    $this->assertCount(1, $response['sources'], 'The source only the dropped sentence cited is gone from Sources.');
    $this->assertLogged('support_check=checked sc_cited=2 sc_kept=1 sc_trimmed=0 sc_dropped=1');
  }

  /**
   * An uncited sentence never reaches the overview (merge review R1, item 3).
   *
   * The check judges only cited sentences; the rewriter then removes any
   * sentence without a source, so "Supported [R1]. Fabricated." loses its
   * second sentence even when the first passes.
   */
  public function testUncitedSentenceIsRemoved(): void {
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text('The members keep fictional notes [R1]. The group was founded on the moon in 1850.'),
      static function (ChatInput $input) {
        $text = ScriptedLlmSubscriber::snapshot($input);
        preg_match('/\[R1\]\n<<<SOURCE_TEXT\n(.*?)\nSOURCE_TEXT>>>/s', $text, $m);
        return ScriptedLlmSubscriber::text(json_encode(['sentences' => [
          ['i' => 1, 'verdict' => 'supported', 'quote' => mb_substr(trim($m[1] ?? ''), 0, 24), 'keep' => 'The members keep fictional notes.'],
        ]]));
      },
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);
    $this->assertStringContainsString('The members keep fictional notes', $response['summary_html'] ?? '');
    $this->assertStringNotContainsString('moon', $response['summary_html'] ?? '', 'The uncited sentence is removed.');
  }

  /**
   * A broken check answer leaves the overview unchanged, logged unchecked.
   */
  public function testFailedCheckLeavesAnswerUnchanged(): void {
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text('The members keep fictional notes [R1]. The group was founded on the moon in 1850 [R2].'),
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text('not json'),
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);
    $this->assertStringContainsString('moon', $response['summary_html'] ?? '', 'Unchecked: the text goes out as written.');
    $this->assertStringNotContainsString('unchecked', $response['summary_html'] ?? '', 'Members never see the check status.');
    $this->assertLogged('support_check=unchecked');
  }

  /**
   * Nothing supported: no overview, never the raw text.
   */
  public function testNothingSupportedHidesTheOverview(): void {
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text('The group was founded on the moon in 1850 [R1].'),
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text(json_encode(['sentences' => [['i' => 1, 'verdict' => 'not_supported', 'quote' => '', 'keep' => '']]])),
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);
    $this->assertNull($response['summary_html'] ?? NULL, 'No overview when nothing cited is supported.');
  }

  /**
   * Asserts an answer_summary line containing $fragment was logged.
   */
  private function assertLogged(string $fragment): void {
    $lines = array_map([SpyLogger::class, 'flatten'], $this->container->get('social_ai_indexing_test.spy_logger')->records());
    $this->assertNotEmpty(array_filter($lines, static fn (string $line): bool => str_contains($line, 'answer_summary') && str_contains($line, $fragment)), "answer_summary holds '$fragment'.");
  }

  /**
   * Codex merge review R2: an uncited claim as a heading or colon line.
   *
   * @dataProvider moonCases
   */
  public function testUncitedHeadingOrColonClaimIsRemoved(string $tail): void {
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text("The members keep fictional notes [R1].\n" . $tail),
      static function (ChatInput $input) {
        $text = ScriptedLlmSubscriber::snapshot($input);
        preg_match('/\\[R1\\]\\n<<<SOURCE_TEXT\\n(.*?)\\nSOURCE_TEXT>>>/s', $text, $m);
        return ScriptedLlmSubscriber::text(json_encode(['sentences' => [
          ['i' => 1, 'verdict' => 'supported', 'quote' => mb_substr(trim($m[1] ?? ''), 0, 24), 'keep' => 'The members keep fictional notes.'],
        ]]));
      },
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);
    $this->assertStringContainsString('The members keep fictional notes', $response['summary_html'] ?? '');
    $this->assertStringNotContainsString('moon', $response['summary_html'] ?? '', 'The uncited claim is removed.');
  }

  /**
   * Codex's two cases.
   */
  public static function moonCases(): array {
    return [
      'heading' => ['## The group was founded on the moon in 1850'],
      'colon line' => ['The group was founded on the moon in 1850:'],
    ];
  }

  /**
   * Codex merge review R4: a cited heading keeps no uncited sentence.
   */
  public function testCitedHeadingDropsUncitedSentence(): void {
    $this->testUncitedHeadingOrColonClaimIsRemoved('## Also cited [R1]. The group was founded on the moon in 1850.');
  }

  /**
   * Codex merge review R3: a cited heading doesn't leave an orphan source.
   */
  public function testCitedHeadingKeepsNumberingAndSources(): void {
    $this->scriptedLlm()->reset();
    $this->scriptedLlm()->script([
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text("## The members keep fictional notes [R1].\nThe group also keeps a public notice [R2]."),
      static function (ChatInput $input) {
        $text = ScriptedLlmSubscriber::snapshot($input);
        $quotes = [];
        foreach ([1, 2] as $n) {
          preg_match('/\\[R' . $n . '\\]\\n<<<SOURCE_TEXT\\n(.*?)\\nSOURCE_TEXT>>>/s', $text, $m);
          $quotes[$n] = mb_substr(trim($m[1] ?? ''), 0, 20);
        }
        return ScriptedLlmSubscriber::text(json_encode(['sentences' => [
          ['i' => 1, 'verdict' => 'supported', 'quote' => $quotes[1], 'keep' => 'The members keep fictional notes.'],
          ['i' => 2, 'verdict' => 'supported', 'quote' => $quotes[2], 'keep' => 'The group also keeps a public notice.'],
        ]]));
      },
    ]);
    $response = $this->askOverview($this->personas['P-MEM'], self::KEYWORD_ONLY_TERM);
    $html = $response['summary_html'] ?? '';
    preg_match_all('/\\[(\\d+)\\]/', strip_tags($html), $m);
    $markers = array_values(array_unique(array_map('intval', $m[1])));
    sort($markers);
    $this->assertSame([1], array_slice($markers, 0, 1), 'Numbering starts at [1].');
    $this->assertCount(count($markers), $response['sources'], 'Every Sources entry is cited in the text, and every marker has an entry.');
  }

}
