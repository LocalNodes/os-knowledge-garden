<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * Leak door 6: the logs never hold members' text (AI-SPEC E10, T-22-47).
 *
 * A spy logger on every channel records the whole leak suite: every
 * AgentLeakMatrix case (RQ-07 to RQ-12, RQ-10 for three forged scopes) in
 * both stub modes, plus the door-4 pair. No record, message or context, may
 * hold a fixture canary, a fixture title, a question or an answer. Each
 * answer gets exactly one 'answer_summary' line, made of counts, labels,
 * reason codes, token counts and a hash.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class LogHygieneTest extends GroupAccessKernelTestBase {

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
   * Strings that must never reach a log (fixture canaries).
   */
  private const CANARIES = ['ZQ7', 'HK3', 'SX9', 'old mill', 'seed vault key'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAgentLoop();
  }

  /**
   * The full leak run leaves only counts, labels, codes and hashes in logs.
   */
  public function testLeakSuiteLogsHoldNoContent(): void {
    $spy = $this->container->get('social_ai_indexing_test.spy_logger');
    $this->assertInstanceOf(SpyLogger::class, $spy);
    $spy->reset();

    $forbidden = self::CANARIES;
    foreach ($this->fixtures as $entity) {
      $forbidden[] = (string) $entity->label();
    }
    foreach ($this->fixtureCanaries() as $strings) {
      $forbidden = array_merge($forbidden, $strings);
    }

    $answers = [];
    $expected_askers = [];
    foreach (['ignore', 'honour'] as $mode) {
      $this->setStubMode($mode);
      foreach ($this->leakCases() as $case) {
        $run = $this->askAssistant($this->personaAccount($case['persona']), $this->scopeValue($case['scope']), $case['question'], $this->citeEverythingScript($case['question']));
        $forbidden[] = $case['question'];
        $answers[] = $run['answer'];
        $expected_askers[] = $this->askerClass($case['persona'], $case['scope']);
      }
      // Door 4 pair.
      $question = 'Where is the seed vault key kept?';
      foreach ([$question => 'seed vault', 'zzqx blorf nonsense' => 'zzqx blorf'] as $asked => $term) {
        $run = $this->askAssistant($this->personas['P-NON'], $this->scopeValue('G'), $asked, $this->citeRelevantScript($asked, $term));
        $forbidden[] = $asked;
        $answers[] = $run['answer'];
        $expected_askers[] = 'non_member';
      }
    }
    // The answers themselves: logs carry counts, never answer text.
    foreach ($answers as $answer) {
      $forbidden[] = strtok($answer, "\n");
    }
    $forbidden = array_values(array_unique(array_filter($forbidden, static fn (string $s): bool => trim($s) !== '')));

    $records = $spy->records();
    $this->assertNotEmpty($records, 'The spy saw the run (the tool and the rewrite both log).');

    $leaks = [];
    $summaries = [];
    foreach ($records as $i => $record) {
      $text = SpyLogger::flatten($record);
      foreach ($forbidden as $string) {
        if (stripos($text, $string) !== FALSE) {
          $leaks[] = "record $i ({$record['channel']}): holds '" . substr($string, 0, 60) . "'";
        }
      }
      if ($record['channel'] === 'social_ai_indexing' && str_starts_with($record['message'], 'answer_summary ')) {
        $summaries[] = $record['context'];
      }
    }
    $this->assertSame([], $leaks, "Log records hold content:\n" . implode("\n", $leaks));

    // One answer_summary per answer, in order, with the right asker class.
    $this->assertCount(count($answers), $summaries, 'One answer_summary per answer.');
    $keys = [
      '@path', '@group', '@asker', '@outcome', '@reason', '@sources',
      '@found', '@kept', '@dropped', '@loops', '@latency', '@prompt_hash',
      '@support_check', '@sc_cited', '@sc_kept', '@sc_trimmed', '@sc_dropped', '@sc_ms',
      '@chat_calls', '@input_tokens', '@output_tokens', '@reasoning_tokens',
      '@uncounted_calls',
    ];
    // Keys the logger channel adds to every record.
    $channel_keys = array_flip([
      'channel', 'link', 'uid', 'request_uri', 'referer', 'ip', 'timestamp',
      'backtrace', 'exception',
    ]);
    foreach ($summaries as $i => $summary) {
      $fields = array_keys(array_diff_key($summary, $channel_keys));
      $this->assertSame($keys, $fields, "answer_summary $i carries exactly the documented fields.");
      $this->assertSame('assistant', $summary['@path']);
      $this->assertSame($expected_askers[$i], $summary['@asker'], "answer_summary $i asker class.");
      $this->assertContains($summary['@outcome'], ['cited', 'no_answer', 'fallback']);
      $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $summary['@prompt_hash']);
      $this->assertMatchesRegularExpression('/^(\d+|invalid|none)$/', (string) $summary['@group'], 'The group is an id or a code, never browser text.');
      $this->assertContains($summary['@support_check'], ['checked', 'unchecked', 'skipped', 'none'], "answer_summary $i support_check is a status code.");
      foreach (['@sources', '@found', '@kept', '@dropped', '@loops', '@latency', '@sc_cited', '@sc_kept', '@sc_trimmed', '@sc_dropped', '@sc_ms', '@chat_calls', '@input_tokens', '@output_tokens', '@reasoning_tokens', '@uncounted_calls'] as $count) {
        $this->assertIsInt($summary[$count], "answer_summary $i $count is a number.");
      }
    }
  }

  /**
   * The assistant's answer_summary sums the tokens of its run's chat calls.
   *
   * The scripted LLM answers before the provider runs, so the ai module
   * fires no post-generate event for those turns. This test dispatches
   * post-generate events itself, while the run is open, the way the
   * provider proxy does after each real call (22-29, ruling 3):
   * - a chat call with the provider's TokenUsageDto;
   * - a chat call with only Gemini's raw usageMetadata (the Fiber case);
   * - a chat call with neither (uncounted);
   * - an embeddings call (not counted).
   * A chat event outside any run is ignored, and the next run starts at 0.
   */
  public function testAnswerSummaryCountsTokens(): void {
    $spy = $this->container->get('social_ai_indexing_test.spy_logger');
    $dispatcher = $this->container->get('event_dispatcher');
    $event = static fn (string $operation, mixed $output): PostGenerateResponseEvent => new PostGenerateResponseEvent('t', 'echoai', $operation, [], new ChatInput([]), 'm', $output);
    $with_dto = new ChatOutput(new ChatMessage('assistant', 'x'), [], [], new TokenUsageDto(input: 1200, output: 150, total: 1400, reasoning: 50));
    $with_raw = new ChatOutput(new ChatMessage('assistant', 'x'), ['usageMetadata' => ['promptTokenCount' => 800, 'candidatesTokenCount' => 60, 'thoughtsTokenCount' => 25]], []);
    $with_none = new ChatOutput(new ChatMessage('assistant', 'x'), ['x'], []);

    // Outside a run: ignored.
    $dispatcher->dispatch($event('chat', $with_dto), PostGenerateResponseEvent::EVENT_NAME);

    $inject = TRUE;
    $dispatcher->addListener('ai_agents.started_execution', static function () use (&$inject, $dispatcher, $event, $with_dto, $with_raw, $with_none): void {
      if (!$inject) {
        return;
      }
      $inject = FALSE;
      foreach ([['chat', $with_dto], ['chat', $with_raw], ['chat', $with_none], ['embeddings', $with_dto]] as [$operation, $output]) {
        $dispatcher->dispatch($event($operation, $output), PostGenerateResponseEvent::EVENT_NAME);
      }
    }, -10);

    $summaries = [];
    foreach ([1, 2] as $run) {
      $spy->reset();
      $question = 'Where is the seed vault key kept?';
      $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), $question, $this->citeEverythingScript($question));
      foreach ($spy->records() as $record) {
        if ($record['channel'] === 'social_ai_indexing' && str_starts_with($record['message'], 'answer_summary ')) {
          $summaries[$run][] = $record['context'];
        }
      }
      $this->assertCount(1, $summaries[$run] ?? [], "Run $run logged one answer_summary.");
    }

    $first = $summaries[1][0];
    $this->assertSame(3, $first['@chat_calls'], 'Three chat calls counted; the embeddings call and the call outside the run are not.');
    $this->assertSame(2000, $first['@input_tokens'], 'Input tokens: 1200 from the DTO plus 800 from usageMetadata.');
    $this->assertSame(210, $first['@output_tokens'], 'Output tokens: 150 plus 60.');
    $this->assertSame(75, $first['@reasoning_tokens'], 'Reasoning tokens: 50 plus 25.');
    $this->assertSame(1, $first['@uncounted_calls'], 'The call with no counts is uncounted.');

    $second = $summaries[2][0];
    $this->assertSame([0, 0, 0, 0, 0], [$second['@chat_calls'], $second['@input_tokens'], $second['@output_tokens'], $second['@reasoning_tokens'], $second['@uncounted_calls']], 'The next run starts from zero.');
  }

  /**
   * The asker class a case's answer_summary must record.
   */
  private function askerClass(string $persona, string $scope): string {
    if ($persona === 'P-ANON') {
      return 'anonymous';
    }
    $account = $this->personaAccount($persona);
    if (isset($this->groups[$scope]) && $this->groups[$scope]->getMember($account)) {
      return 'member';
    }
    return 'non_member';
  }

}
