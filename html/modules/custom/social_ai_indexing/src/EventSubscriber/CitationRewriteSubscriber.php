<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Event\AgentFinishedExecutionEvent;
use Drupal\ai_agents\Event\AgentStartedExecutionEvent;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\group\Entity\GroupInterface;
use Drupal\social_ai_indexing\Citation\CitationRewriter;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\social_ai_indexing\Citation\SupportCheck;
use Drupal\social_ai_indexing\Citation\UncitableAnswerException;
use Drupal\social_ai_indexing\Usage\TokenTally;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rewrites every Group assistant answer before it is sent (D-19, KNOW-04).
 *
 * AI-SPEC §4 step 4. On AgentFinishedExecutionEvent for the group_assistant
 * agent, the model's final text goes through CitationRewriter: [R#] labels
 * become [1], [2], [3] by first use, the Sources list is rebuilt from the
 * request's SourceRegistry, and everything else the model wrote about
 * sources is dropped. Text that can't be cited becomes the fixed fallback
 * copy. On a normal finish the rewritten message is the one AgentRunner
 * returns as end($history). On a failed chat call ai_agents passes a
 * throwaway message instead, so the registry keeps the object rewritten here
 * and CitationGuardAgentRunner replaces any exit that isn't it.
 *
 * Exits that never fire the finished event are caught by
 * CitationGuardAgentRunner too: it passes only the message object marked
 * here.
 *
 * Logs one 'answer_summary' per answer: counts, labels, reason codes, the
 * chat calls and tokens behind it (TokenTally) and a prompt hash. Never
 * question, answer, title or excerpt text (E10).
 */
final class CitationRewriteSubscriber implements EventSubscriberInterface {

  /**
   * The agent whose answers are rewritten.
   */
  public const AGENT_ID = 'group_assistant';

  /**
   * Start times by agent runner id (hrtime nanoseconds).
   *
   * @var array<string, int>
   */
  private array $started = [];

  /**
   * Chat calls and tokens of the group_assistant run in progress.
   *
   * Counted from the ai module's post-generate event while a run is open,
   * so it holds every loop of the agent, tool round trips included.
   */
  private ?TokenTally $tally = NULL;

  /**
   * Rewrite reasons that mean "nothing citable", not a failure.
   *
   * gemini-3.8-flash sometimes declines in its own words, or answers
   * without a label, where it should write NO_RELEVANT_CONTENT or cite
   * (rerun 5, 2026-11-07). Such a reply is a no-answer and gets the
   * no-answer copy, so every no-answer reads the same (door 4). The
   * fallback stays for real failures: empty output, an internal invariant
   * (unrewritten label, bad citation type), a provider error, and the
   * guard's exits (max loops, verbose, raw tool output).
   */
  public const NO_ANSWER_REASONS = ['no_valid_citations', 'empty_body'];

  /**
   * The reason logged when the model's whole reply is a tool-call echo.
   *
   * gemini-3.8-flash sometimes answers with the provider's text for its own
   * earlier tool-call turn ("Called ai_search_rag_search {...}") instead of
   * answering (22.2-15; Phase 22 records 129, 174, 180, 198). It is still a
   * no-answer with the one no-answer copy; the reason only makes it
   * countable in answer_summary and the health report.
   */
  public const REASON_TOOL_CALL_ECHO = 'tool_call_echo';

  /**
   * One line of a tool-call echo: "Called <tool>" with optional JSON
   * arguments, or the provider's "Called a tool." for a call it can't name.
   */
  private const ECHO_LINE = '/^Called (?:a tool\.|[A-Za-z_][A-Za-z0-9_:\-]*(?:\s+(?:\{.*\}|\[.*\]))?)$/';

  /**
   * The runtime support check; NULL leaves answers unchecked.
   */
  private ?SupportCheck $supportCheck = NULL;

  public function __construct(
    private readonly SourceRegistry $registry,
    private readonly CitationRewriter $rewriter,
    private readonly LoggerInterface $logger,
    private readonly AiAssistantApiRunner $assistantRunner,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Sets the runtime support check (service container setter injection).
   */
  public function setSupportCheck(SupportCheck $support_check): void {
    $this->supportCheck = $support_check;
  }

  /**
   * {@inheritdoc}
   *
   * Event names are literal strings, not the ai_agents class constants:
   * this module doesn't depend on ai_agents, and reading a constant would
   * autoload a class that may not exist when the container is compiled.
   */
  public static function getSubscribedEvents(): array {
    return [
      // AgentStartedExecutionEvent::EVENT_NAME.
      'ai_agents.started_execution' => ['onStarted', 0],
      // AgentFinishedExecutionEvent::EVENT_NAME.
      'ai_agents.finished_execution' => ['onFinished', -100],
      PostGenerateResponseEvent::EVENT_NAME => ['onPostGenerate', 0],
    ];
  }

  /**
   * Records when a group_assistant run started, for the latency figure.
   */
  public function onStarted(AgentStartedExecutionEvent $event): void {
    if ($event->getAgentId() !== self::AGENT_ID) {
      return;
    }
    if ($this->started === []) {
      $this->tally = new TokenTally();
    }
    $this->started[$event->getAgentRunnerId()] ??= hrtime(TRUE);
  }

  /**
   * Counts the tokens of each chat call made while a run is open.
   */
  public function onPostGenerate(PostGenerateResponseEvent $event): void {
    if ($this->tally === NULL || $event->getOperationType() !== 'chat') {
      return;
    }
    $this->tally->add($event->getOutput());
  }

  /**
   * The no-answer reply that says which kind of could-not it was.
   *
   * With nothing retrieved, a broken search or an unavailable AI service is
   * said plainly. Both causes are the same whichever group was asked for: a
   * refused group stops before any search, so it still gets the no-result
   * copy, byte for byte. With sources retrieved, an answer whose
   * every sentence was dropped says it couldn't give a sourced answer; the
   * model's own "nothing relevant" keeps the no-result copy.
   *
   * @param bool $in_group
   *   Whether the question was asked in a group.
   * @param bool $rejected
   *   TRUE when the answer had text but nothing citable survived.
   * @param string $reason
   *   The log reason, updated to search_trouble or service_unavailable.
   */
  private function noAnswerText(bool $in_group, bool $rejected, string &$reason): string {
    if ($this->registry->serviceFailure() !== NULL) {
      $reason = 'service_unavailable';
      return CitedAnswer::UNAVAILABLE;
    }
    if ($this->registry->count() === 0) {
      if ($this->registry->hadSearchTrouble()) {
        $reason = 'search_trouble';
        return CitedAnswer::SEARCH_TROUBLE;
      }
      return CitedAnswer::noRelevantContent($in_group)->toMarkdown();
    }
    if ($rejected) {
      return $in_group ? CitedAnswer::NO_SOURCED_ANSWER_GROUP : CitedAnswer::NO_SOURCED_ANSWER_SITE;
    }
    return CitedAnswer::noRelevantContent($in_group)->toMarkdown();
  }

  /**
   * Rewrites the final answer and marks the registry.
   */
  public function onFinished(AgentFinishedExecutionEvent $event): void {
    if ($event->getAgentId() !== self::AGENT_ID) {
      return;
    }
    // On a normal finish this is the message AgentRunner returns; on a failed
    // chat call it is a throwaway, which the guard recognises by identity.
    $message = $event->getResponse()->getNormalized();
    if (!$message instanceof ChatMessage) {
      // Streamed output can't be rewritten; the guard replaces it.
      return;
    }

    $context = $this->assistantRunner->getContext();
    $hint = is_array($context) ? ($context['group_id'] ?? NULL) : NULL;
    // A hint that is present at all selects the group copy, valid or not, so
    // forged scopes (S, 999999, abc) all get the same reply (door 4).
    $in_group = $hint !== NULL && $hint !== '';

    $text = $message->getText();
    $model_text = $text;
    $found = self::countLabels($text);
    $kept = 0;
    $check = ['status' => 'skipped', 'cited' => 0, 'kept' => 0, 'trimmed' => 0, 'dropped' => 0];
    try {
      if ($this->supportCheck !== NULL) {
        // Unsupported sentences go before the citations are rewritten, so
        // the Sources list follows what is kept (22-27 option B).
        $check = $this->supportCheck->check($text, $this->registry, 'assistant');
        $text = $check['text'];
      }
      $answer = $this->rewriter->rewrite($text, $this->registry, $in_group);
      $kept = count($answer->citations);
      $outcome = $answer->noAnswer ? 'no_answer' : 'cited';
      $reason = $answer->noAnswer ? 'no_relevant_content' : 'ok';
      $message->setText($answer->noAnswer ? $this->noAnswerText($in_group, FALSE, $reason) : $answer->toMarkdown());
    }
    catch (UncitableAnswerException $e) {
      // The message is a fixed reason code, never model text.
      $reason = $e->getMessage();
      if (in_array($reason, self::NO_ANSWER_REASONS, TRUE)) {
        // Nothing citable: a no-answer, with the one no-answer copy (door
        // 4), never the uncited text (22-27 ruling, option A).
        $outcome = 'no_answer';
        $message->setText($this->noAnswerText($in_group, TRUE, $reason));
        if (self::isToolCallEcho($model_text)) {
          // Counted apart from an ordinary uncited reply; the reader sees
          // the same copy.
          $reason = self::REASON_TOOL_CALL_ECHO;
        }
      }
      else {
        $outcome = 'fallback';
        $message->setText(CitedAnswer::fallbackMarkdown());
      }
    }
    catch (\Throwable $e) {
      // Fail closed on any other error: the raw model text never goes out.
      $outcome = 'fallback';
      $reason = 'rewrite_error:' . get_class($e);
      $message->setText(CitedAnswer::fallbackMarkdown());
    }
    $this->registry->markRewritten($message);

    $runner_id = $event->getAgentRunnerId();
    $latency = isset($this->started[$runner_id]) ? (int) round((hrtime(TRUE) - $this->started[$runner_id]) / 1e6) : -1;
    unset($this->started[$runner_id]);
    $tally = $this->tally ?? new TokenTally();
    if ($this->started === []) {
      $this->tally = NULL;
    }

    // Counts, labels, reason codes and a hash only (E10, AI-SPEC §7).
    $this->logger->info('answer_summary path=@path group=@group asker=@asker outcome=@outcome reason=@reason sources=@sources found=@found kept=@kept dropped=@dropped loops=@loops latency_ms=@latency prompt_hash=@prompt_hash ' . SupportCheck::LOG_TEMPLATE . ' ' . TokenTally::LOG_TEMPLATE, [
      '@path' => 'assistant',
      '@group' => self::groupLabel($hint),
      '@asker' => $this->askerClass($hint),
      '@outcome' => $outcome,
      '@reason' => $reason,
      '@sources' => $this->registry->count(),
      '@found' => $found,
      '@kept' => $kept,
      '@dropped' => max(0, $found - $kept),
      '@loops' => $event->getLoopCount(),
      '@latency' => $latency,
      '@prompt_hash' => substr(hash('sha256', $event->getSystemPrompt()), 0, 12),
    ] + SupportCheck::logContext($check) + $tally->logContext());
  }

  /**
   * Whether a reply is only the provider's wording for tool calls.
   *
   * TRUE when every non-empty line is "Called <tool>" with optional JSON
   * arguments, or "Called a tool."; FALSE for empty text and for any reply
   * with a line of anything else.
   */
  public static function isToolCallEcho(string $text): bool {
    $lines = array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn (string $line): bool => $line !== '');
    if ($lines === []) {
      return FALSE;
    }
    foreach ($lines as $line) {
      if (preg_match(self::ECHO_LINE, $line) !== 1) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Distinct [R#] labels in model text, including grouped ones ([R1, R3]).
   */
  public static function countLabels(string $text): int {
    if (preg_match_all('/\[\s*(R\d+(?:\s*,\s*R\d+)*)\s*\]/i', $text, $matches) === FALSE) {
      return 0;
    }
    $labels = [];
    foreach ($matches[1] as $group) {
      foreach (explode(',', $group) as $label) {
        $labels[strtoupper(trim($label))] = TRUE;
      }
    }
    return count($labels);
  }

  /**
   * The scope hint as a log value: a group id, 'invalid' or 'none'.
   *
   * The hint comes from the browser, so anything that isn't a positive
   * integer is never written to the log as given.
   */
  private static function groupLabel(mixed $hint): string {
    if ($hint === NULL || $hint === '') {
      return 'none';
    }
    if (is_int($hint) ? $hint > 0 : (is_string($hint) && preg_match('/^[1-9][0-9]{0,17}$/', $hint) === 1)) {
      return (string) $hint;
    }
    return 'invalid';
  }

  /**
   * The asker relative to the scope group: member, non_member or anonymous.
   *
   * A class, never a uid.
   */
  private function askerClass(mixed $hint): string {
    if ($this->currentUser->isAnonymous()) {
      return 'anonymous';
    }
    $group_id = self::groupLabel($hint);
    if (!ctype_digit($group_id)) {
      return 'non_member';
    }
    try {
      $group = $this->entityTypeManager->getStorage('group')->load((int) $group_id);
      return $group instanceof GroupInterface && $group->getMember($this->currentUser->getAccount()) ? 'member' : 'non_member';
    }
    catch (\Throwable) {
      return 'non_member';
    }
  }

}
