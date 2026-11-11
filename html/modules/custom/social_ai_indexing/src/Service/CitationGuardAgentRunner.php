<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_assistant_api\Service\AgentRunner;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Drupal\social_ai_indexing\EventSubscriber\CitationRewriteSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The last gate on Group assistant answers (AI-SPEC §4 step 5, guardrail G4).
 *
 * Decorates ai_assistant_api.agent_runner, which AiAssistantApiRunner calls
 * through \Drupal::service(). AgentFinishedExecutionEvent, where the answer
 * is rewritten, doesn't fire when max_loops is exceeded, in verbose mode, or
 * when a tool returns directly. AgentRunner then returns end($history): raw
 * model text, a tool-call turn, or a raw tool message holding excerpts
 * (AI-SPEC pitfall 19). A failed chat call is a fourth exit: ai_agents then
 * dispatches the finished event with a throwaway message that is not in the
 * chat history, and AgentRunner returns the last history message (the last
 * tool result, or the asker's own question). So a group_assistant exit passes
 * only if the returned message is the very object the subscriber rewrote.
 * Any other exit is replaced here by the fixed fallback copy and counted by a
 * 'guard_fallback' warning.
 */
final class CitationGuardAgentRunner extends AgentRunner {

  /**
   * Constructs the guard; the first four arguments are AgentRunner's.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStore
   *   The private temp store.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param mixed $aiAgentPluginManager
   *   The AI agent plugin manager, if ai_agents is enabled.
   * @param \Drupal\social_ai_indexing\Citation\SourceRegistry $registry
   *   The request-wide source registry.
   * @param \Psr\Log\LoggerInterface $logger
   *   The social_ai_indexing logger channel.
   */
  public function __construct(
    AiProviderPluginManager $aiProvider,
    PrivateTempStoreFactory $tempStore,
    EventDispatcherInterface $eventDispatcher,
    mixed $aiAgentPluginManager,
    private readonly SourceRegistry $registry,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($aiProvider, $tempStore, $eventDispatcher, $aiAgentPluginManager);
  }

  /**
   * {@inheritdoc}
   */
  public function runAsAgent(string $assistant_id, array $chat_history, array $defaults, string $job_id, bool $verbose_mode = FALSE, array $context = []): ChatOutput {
    // One agent run, one registry: labels never carry over from an earlier
    // run in the same PHP process (drush, tests, queue workers).
    $this->registry->reset();
    $output = parent::runAsAgent($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
    // $assistant_id is the agent id here (AiAssistantApiRunner.php:330).
    if ($assistant_id !== CitationRewriteSubscriber::AGENT_ID) {
      return $output;
    }
    // Identity, not the flag and not the role: the flag is also set for the
    // throwaway message of a failed call, and a role can be changed in place.
    $rewritten = $this->registry->rewrittenMessage();
    if ($rewritten !== NULL && $output->getNormalized() === $rewritten) {
      return $output;
    }

    $reason = $this->reason($output, $verbose_mode);
    $this->logger->warning('guard_fallback agent=@agent reason=@reason', [
      '@agent' => $assistant_id,
      '@reason' => $reason,
    ]);
    // AgentRunner stored the unfinished agent under the thread key, loop
    // counter included, and would reload it for the next question: an agent
    // past max_loops would answer every later question with this fallback.
    // Verbose mode is the one exit meant to resume, one loop per request.
    if ($reason !== 'verbose') {
      $this->tempStore->get('ai_assistant_threads')->delete($job_id);
    }
    // A quota or setup failure says the assistant is unavailable: the cause
    // is the same whichever group was asked for. It never replaces a cited
    // answer, which returned above.
    $text = $this->registry->serviceFailure() !== NULL ? CitedAnswer::UNAVAILABLE : CitedAnswer::fallbackMarkdown();
    return new ChatOutput(new ChatMessage('assistant', $text), [$text], []);
  }

  /**
   * Why an exit escaped the rewrite, as a reason code.
   */
  private function reason(ChatOutput $output, bool $verbose_mode): string {
    if ($verbose_mode) {
      return 'verbose';
    }
    // The finished event fired, but the run returned another message: a
    // failed chat call.
    if ($this->registry->wasRewritten()) {
      return 'rewrite_not_returned';
    }
    $message = $output->getNormalized();
    if (!$message instanceof ChatMessage) {
      return 'not_a_message';
    }
    if ($message->getRole() === 'tool') {
      return 'tool_message';
    }
    // The agent stopped without finishing: the loop cap (a tool call on the
    // last allowed loop, or the required-tool reminder loop).
    return 'max_loops';
  }

}
