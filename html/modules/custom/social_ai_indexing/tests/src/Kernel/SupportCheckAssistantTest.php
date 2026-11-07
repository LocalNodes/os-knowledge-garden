<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\social_ai_indexing\Citation\SupportCheck;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * The runtime support check in the group assistant door (22-27 option B).
 *
 * The real agent loop on the echo provider: two searches, a scripted
 * answer with one supported and one invented sentence, then the scripted
 * check. Only what the check keeps reaches the member, before the
 * citation rewrite.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class SupportCheckAssistantTest extends GroupAccessKernelTestBase {

  use AgentLoopTrait;
  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'modeler_api',
    'ai_agents',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAgentLoop();
    $this->setStubMode('honour');
    $this->installConfig(['social_ai_indexing']);
    $this->config('social_ai_indexing.settings')
      ->set('support_check', ['assistant' => TRUE, 'overview' => TRUE, 'provider' => 'echoai', 'model' => 'gpt-test', 'timeout' => 8])
      ->save();
  }

  /**
   * The invented sentence is dropped; the supported one stays.
   */
  public function testUnsupportedSentenceIsDropped(): void {
    $check_tags = [];
    $script = [
      self::searchTurn('social_posts', 'notes'),
      self::searchTurn('social_comments', 'notes'),
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text('The group keeps fictional notes [R1]. The group was founded on the moon in 1850 [R1].'),
      static function (ChatInput $input) {
        $text = ScriptedLlmSubscriber::snapshot($input);
        preg_match('/\[R1\]\n<<<SOURCE_TEXT\n(.*?)\nSOURCE_TEXT>>>/s', $text, $m);
        return ScriptedLlmSubscriber::text(json_encode(['sentences' => [
          ['i' => 1, 'verdict' => 'supported', 'quote' => mb_substr(trim($m[1] ?? ''), 0, 24), 'keep' => 'The group keeps fictional notes.'],
          ['i' => 2, 'verdict' => 'not_supported', 'quote' => '', 'keep' => ''],
        ]]));
      },
    ];
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', $script);

    $this->assertSame(0, $run['unused_turns'], 'The check call was made.');
    foreach ($this->scriptedLlm()->inputs() as $call) {
      if (in_array('social_ai_support_check', $call['tags'], TRUE)) {
        $check_tags = $call['tags'];
      }
    }
    $this->assertContains(SupportCheck::TAGS[1], $check_tags, 'The check call carries its tag.');
    $this->assertStringContainsString('The group keeps fictional notes', $run['answer']);
    $this->assertStringNotContainsString('moon', $run['answer'], 'The unsupported sentence never reaches the member.');
    $this->assertSame([], $run['violations'], 'The rewritten answer keeps the citation invariants.');
  }

  /**
   * Codex merge review R2: an uncited claim as a heading or colon line.
   *
   * @dataProvider moonCases
   */
  public function testUncitedHeadingOrColonClaimIsRemoved(string $tail): void {
    $script = [
      self::searchTurn('social_posts', 'notes'),
      self::searchTurn('social_comments', 'notes'),
      static fn (ChatInput $input) => ScriptedLlmSubscriber::text("The group keeps fictional notes [R1].\n" . $tail),
      static function (ChatInput $input) {
        $text = ScriptedLlmSubscriber::snapshot($input);
        preg_match('/\\[R1\\]\\n<<<SOURCE_TEXT\\n(.*?)\\nSOURCE_TEXT>>>/s', $text, $m);
        return ScriptedLlmSubscriber::text(json_encode(['sentences' => [
          ['i' => 1, 'verdict' => 'supported', 'quote' => mb_substr(trim($m[1] ?? ''), 0, 24), 'keep' => 'The group keeps fictional notes.'],
        ]]));
      },
    ];
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', $script);
    $this->assertStringContainsString('The group keeps fictional notes', $run['answer']);
    $this->assertStringNotContainsString('moon', $run['answer'], 'The uncited claim never reaches the member.');
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
    $script = [
      self::searchTurn('social_posts', 'notes'),
      self::searchTurn('social_comments', 'notes'),
      static function (ChatInput $input) {
        $labels = ScriptedLlmSubscriber::toolLabels($input);
        return ScriptedLlmSubscriber::text("## The group keeps fictional notes [" . $labels[0] . "].\nAnother point from the pages [" . ($labels[1] ?? $labels[0]) . "].");
      },
      static function (ChatInput $input) {
        $text = ScriptedLlmSubscriber::snapshot($input);
        preg_match_all('/\\[R(\\d+)\\]\\n<<<SOURCE_TEXT\\n(.*?)\\nSOURCE_TEXT>>>/s', $text, $m, PREG_SET_ORDER);
        $quote = static fn (int $i): string => mb_substr(trim($m[$i][2] ?? $m[0][2] ?? ''), 0, 20);
        return ScriptedLlmSubscriber::text(json_encode(['sentences' => [
          ['i' => 1, 'verdict' => 'supported', 'quote' => $quote(0), 'keep' => 'The group keeps fictional notes.'],
          ['i' => 2, 'verdict' => 'supported', 'quote' => $quote(1), 'keep' => 'Another point from the pages.'],
        ]]));
      },
    ];
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', $script);
    $this->assertSame([], $run['violations'], 'Continuous numbers from [1], and Sources match the markers (CitationInvariants).');
    $this->assertStringContainsString('[1]', $run['answer']);
  }

}
