<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing_test\EventSubscriber\ScriptedLlmSubscriber;
use Drupal\social_ai_indexing_test\Logger\SpyLogger;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * Guardrail G4: no Group assistant exit escapes the rewrite (pitfall 19).
 *
 * AgentFinishedExecutionEvent doesn't fire when max_loops is exceeded, in
 * verbose mode, or when a tool returns directly; AgentRunner then returns
 * end($history) as it stands. CitationGuardAgentRunner must replace each of
 * those exits with exactly the fallback copy and log one 'guard_fallback'
 * warning with the reason.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class GuardRunnerTest extends GroupAccessKernelTestBase {

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
    $this->spy()->reset();
  }

  /**
   * A model that keeps calling the tool runs into max_loops (5).
   */
  public function testMaxLoopsExitIsReplaced(): void {
    $search = self::searchTurn('social_posts', 'notes');
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', array_fill(0, 8, $search));

    $this->assertCount(5, $run['inputs'], 'The agent stops at max_loops: 5 model calls.');
    $this->assertFalse($run['rewritten'], 'No finished event, so no rewrite.');
    $this->assertSame(CitedAnswer::FALLBACK, $run['answer']);
    $this->assertSame('assistant', $run['role']);
    $this->assertSame(['max_loops'], $this->guardReasons());
  }

  /**
   * After a max_loops exit, the next question on the thread starts afresh.
   *
   * AgentRunner stores an unfinished agent in the ai_assistant_threads
   * tempstore by thread key, loop counter included, and reloads it for the
   * next request on that thread. Left there, every later question would
   * reload an agent already past max_loops and get the fallback copy again.
   */
  public function testMaxLoopsExitDoesNotPoisonTheThread(): void {
    $thread = 'phase22_poison_' . bin2hex(random_bytes(4));
    $search = self::searchTurn('social_posts', 'notes');
    $first = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', array_fill(0, 8, $search), FALSE, $thread);
    $this->assertSame(CitedAnswer::FALLBACK, $first['answer']);

    $next = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', $this->citeEverythingScript('What do the notes say?'), FALSE, $thread);
    $this->assertCount(3, $next['inputs'], 'The next question runs a fresh agent loop.');
    $this->assertTrue($next['rewritten']);
    $this->assertStringContainsString("\n" . CitedAnswer::SOURCES_HEADING . "\n", $next['answer']);
    $this->assertSame(['max_loops'], $this->guardReasons(), 'Only the first question hit the guard.');
  }

  /**
   * Verbose mode runs one loop, so the first tool call ends the request.
   */
  public function testVerboseExitIsReplaced(): void {
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', $this->citeEverythingScript('What do the notes say?'), TRUE);

    $this->assertCount(1, $run['inputs'], 'Verbose mode makes one model call per request.');
    $this->assertSame(CitedAnswer::FALLBACK, $run['answer']);
    $this->assertSame(['verbose'], $this->guardReasons());
  }

  /**
   * A tool that returns directly leaves a raw tool message as the last turn.
   *
   * The shipped agent sets return_directly to 0; an admin can switch it on.
   * Then end($history) is the tool's output, fenced excerpts and all.
   */
  public function testRawToolMessageExitIsReplaced(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_agent');
    $agent = $storage->load('group_assistant');
    $settings = $agent->get('tool_settings');
    $settings['ai_search:rag_search']['return_directly'] = 1;
    $agent->set('tool_settings', $settings)->save();

    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', $this->citeEverythingScript('What do the notes say?'));

    $this->assertCount(1, $run['inputs'], 'The tool result is returned without a second model call.');
    $this->assertSame(CitedAnswer::FALLBACK, $run['answer'], 'The raw tool output (excerpts) is never returned.');
    $this->assertStringNotContainsString('SOURCE_TEXT', $run['answer']);
    $this->assertSame(['tool_message'], $this->guardReasons());
  }

  /**
   * A normal run is rewritten and never touches the guard.
   */
  public function testRewrittenExitPassesThrough(): void {
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', $this->citeEverythingScript('What do the notes say?'));

    $this->assertTrue($run['rewritten']);
    $this->assertStringContainsString("\n" . CitedAnswer::SOURCES_HEADING . "\n", $run['answer']);
    $this->assertSame([], $run['violations']);
    $this->assertSame([], $this->guardReasons());
  }

  /**
   * The registry is reset at the start of every run.
   *
   * A label from an earlier run in the same process must not resolve in the
   * next one. The second run searches comments only (two sources, R1 and
   * R2) and cites [R3], which existed only in the first run: it is dropped,
   * nothing citable is left, and the reply says no sourced answer could be
   * given (no valid citation is a no-answer, not a failure).
   */
  public function testRegistryIsResetPerRun(): void {
    $first = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', $this->citeEverythingScript('What do the notes say?'));
    $this->assertCount(5, $first['sources'], 'Three Topics and two comments in group G for a member.');

    $second = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'And the replies?', [
      self::searchTurn('social_comments', 'replies'),
      ScriptedLlmSubscriber::text('Stale answer [R3].'),
    ]);
    $this->assertCount(2, $second['sources'], 'The registry started empty: only this run\'s two comments.');
    $this->assertTrue($second['rewritten']);
    $this->assertSame(CitedAnswer::NO_SOURCED_ANSWER_GROUP, $second['answer'], 'The stale [R3] maps to nothing: sources but no valid citation, so the no-sourced-answer copy.');
    $this->assertSame([], $this->guardReasons());
  }

  /**
   * The answer call after a search fails: the person gets the fallback copy.
   *
   * ai_agents dispatches the finished event with a throwaway assistant
   * message when the provider call throws, and AgentRunner then returns the
   * last history message: here the raw tool result.
   */
  public function testFailedAnswerCallAfterSearchIsReplaced(): void {
    $question = 'What do the notes say?';
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), $question, [
      self::searchTurn('social_posts', $question),
      static function (): never {
        throw new AiRateLimitException('PROVIDER-ERR-7Q2 429 quota exceeded');
      },
    ]);

    $this->assertCount(2, $run['inputs'], 'Precondition: the search ran, then the answer call failed.');
    $this->assertStringContainsString('tool: ', $run['inputs'][1], 'Precondition: the second call was given the tool result.');
    $this->assertStringContainsString('<<<SOURCE_TEXT', $run['inputs'][1], 'Precondition: the tool result holds fenced excerpts.');
    $this->assertStringContainsString(self::ZQ7_BODY, $run['inputs'][1], 'Precondition: the member can see the G-MO excerpt.');
    $this->assertTrue($run['rewritten'], 'Precondition: the subscriber rewrote the throwaway message, which sets the flag.');

    $this->assertFailedCallReplyIsFallback($run, [$question]);
  }

  /**
   * The first chat call fails: the asker's own question is not the answer.
   */
  public function testFailedFirstCallDoesNotEchoTheQuestion(): void {
    $question = 'What does QX5-ASKER-CANARY say about the notes?';
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), $question, [
      static function (): never {
        throw new AiResponseErrorException('PROVIDER-ERR-7Q2 503 unavailable');
      },
    ]);

    $this->assertCount(1, $run['inputs'], 'Precondition: only the first call was made.');
    $this->assertStringContainsString('QX5-ASKER-CANARY', $run['inputs'][0], 'Precondition: the question reached the model.');
    $this->assertTrue($run['rewritten'], 'Precondition: the subscriber rewrote the throwaway message, which sets the flag.');

    $this->assertFailedCallReplyIsFallback($run, [$question, 'QX5-ASKER-CANARY']);
  }

  /**
   * A tool message whose role was changed in place still can't get out.
   *
   * The guard checks which object is returned, not its role (our Gemini
   * patch changes tool messages to user in place today).
   */
  public function testRoleMutatedToolMessageIsReplaced(): void {
    $question = 'What do the notes say?';
    $mutated = 0;
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), $question, [
      self::searchTurn('social_posts', $question),
      static function (ChatInput $input) use (&$mutated): never {
        foreach ($input->getMessages() as $message) {
          if ($message instanceof ChatMessage && $message->getRole() === 'tool') {
            $message->setRole('user');
            $mutated++;
          }
        }
        throw new AiResponseErrorException('PROVIDER-ERR-7Q2 503 unavailable');
      },
    ]);

    $this->assertGreaterThanOrEqual(1, $mutated, 'Precondition: a tool message was there to change.');
    $this->assertTrue($run['rewritten'], 'Precondition: the subscriber rewrote the throwaway message, which sets the flag.');

    $this->assertFailedCallReplyIsFallback($run, [$question]);
  }

  /**
   * Shared leak assertions for a failed chat call.
   *
   * @param array $run
   *   The askAssistant() result.
   * @param list<string> $question_texts
   *   Question text that must not come back as the answer.
   */
  private function assertFailedCallReplyIsFallback(array $run, array $question_texts): void {
    $this->assertSame(CitedAnswer::fallbackMarkdown(), $run['answer'], 'The fixed fallback copy, nothing else.');
    $this->assertSame('assistant', $run['role']);
    $forbidden = [self::ZQ7_BODY, '<<<SOURCE_TEXT', 'SOURCE_TEXT>>>', '[R1]', 'PROVIDER-ERR-7Q2', ...$question_texts];
    foreach ($forbidden as $text) {
      $this->assertStringNotContainsString($text, $run['answer']);
    }
    $this->assertSame(['rewrite_not_returned'], $this->guardReasons());
    foreach ($this->spy()->records() as $record) {
      $flat = SpyLogger::flatten($record);
      foreach (['PROVIDER-ERR-7Q2', 'QX5-ASKER-CANARY', self::ZQ7_BODY] as $canary) {
        $this->assertStringNotContainsString($canary, $flat, 'No log record on channel ' . $record['channel'] . ' carries provider, question or excerpt text.');
      }
    }
  }

  /**
   * The spy logger.
   */
  private function spy(): SpyLogger {
    return $this->container->get('social_ai_indexing_test.spy_logger');
  }

  /**
   * Reasons of every guard_fallback warning on social_ai_indexing.
   *
   * @return list<string>
   *   Reason codes, in log order.
   */
  private function guardReasons(): array {
    $reasons = [];
    foreach ($this->spy()->records() as $record) {
      if ($record['channel'] === 'social_ai_indexing' && str_starts_with($record['message'], 'guard_fallback ')) {
        $this->assertSame(RfcLogLevel::WARNING, $record['level'], 'guard_fallback is a warning.');
        $reasons[] = (string) $record['context']['@reason'];
      }
    }
    return $reasons;
  }

  /**
   * Sources found but nothing cited: the exact no-sourced-answer copy.
   *
   * gemini-3.8-flash sometimes declines in its own words with no label, or
   * answers without citing. Sources were found, so the scope was a real one
   * and the reply may say so: "couldn't give you an answer with a source",
   * never the fallback and never the uncited text. The fallback stays for
   * real failures (max loops, verbose, raw tool output).
   */
  public function testUncitedReplyGetsTheNoAnswerCopy(): void {
    foreach (["I couldn't find anything about that in the group's pages.", 'The notes say the key is kept somewhere safe.', "**Sources**\n- [One](/node/1)"] as $reply) {
      $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', [
        self::searchTurn('social_posts', 'notes'),
        static fn () => ScriptedLlmSubscriber::text($reply),
      ]);
      $this->assertNotSame([], $run['sources'], 'The search did return sources.');
      $this->assertSame(CitedAnswer::NO_SOURCED_ANSWER_GROUP, $run['answer'], "No valid citation in '$reply': the no-sourced-answer copy.");
    }
    $this->assertSame([], $this->guardReasons(), 'Not a guard exit.');
  }

}
