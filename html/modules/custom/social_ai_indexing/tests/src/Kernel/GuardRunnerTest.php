<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
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
   * nothing citable is left, and the reply is the no-answer copy (22-27
   * ruling, option A: no valid citation is a no-answer, not a failure).
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
    $this->assertSame(CitedAnswer::NO_ANSWER_GROUP, $second['answer'], 'The stale [R3] maps to nothing: no valid citation, so the no-answer copy.');
    $this->assertSame([], $this->guardReasons());
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
   * Sources found but nothing cited: the exact no-answer copy (door 4).
   *
   * 22-27 ruling, option A: gemini-3.8-flash sometimes declines in its own
   * words with no label, or answers without citing. Every no-answer must
   * read the same (door 4), so a reply with no valid citation gets the
   * no-answer copy, never the fallback and never the uncited text. The
   * fallback stays for real failures (max loops, verbose, raw tool output).
   */
  public function testUncitedReplyGetsTheNoAnswerCopy(): void {
    foreach (["I couldn't find anything about that in the group's pages.", 'The notes say the key is kept somewhere safe.', "**Sources**\n- [One](/node/1)"] as $reply) {
      $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notes say?', [
        self::searchTurn('social_posts', 'notes'),
        static fn () => ScriptedLlmSubscriber::text($reply),
      ]);
      $this->assertNotSame([], $run['sources'], 'The search did return sources.');
      $this->assertSame(CitedAnswer::NO_ANSWER_GROUP, $run['answer'], "No valid citation in '$reply': the no-answer copy.");
    }
    $this->assertSame([], $this->guardReasons(), 'Not a guard exit.');
  }

}
