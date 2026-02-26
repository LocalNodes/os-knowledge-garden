<?php

namespace Drupal\ai_assistant_api\Service;

use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class AgentRunner, runs agents as assistants.
 */
class AgentRunner {

  /**
   * The agent to keep for the streaming.
   *
   * @var \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface|null
   */
  protected $agent = NULL;

  /**
   * The job id.
   *
   * @var string|null
   */
  protected ?string $jobId = NULL;

  /**
   * Constructor.
   *
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStore
   *   The private temp store.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param mixed $aiAgentPluginManager
   *   The AI agent plugin manager if it exists.
   */
  public function __construct(
    public AiProviderPluginManager $aiProvider,
    protected PrivateTempStoreFactory $tempStore,
    protected EventDispatcherInterface $eventDispatcher,
    protected mixed $aiAgentPluginManager = NULL,
  ) {
  }

  /**
   * The assistant.
   *
   * @param string $assistant_id
   *   The assistant id.
   * @param array $chat_history
   *   The chat history.
   * @param array $defaults
   *   The defaults.
   * @param string $job_id
   *   The job id.
   * @param bool $verbose_mode
   *   Whether to run in verbose mode.
   * @param array $context
   *   The context from assistant.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The chat output.
   */
  public function runAsAgent(string $assistant_id, array $chat_history, array $defaults, string $job_id, bool $verbose_mode = FALSE, array $context = []): ChatOutput {
    $this->jobId = $job_id;
    $logger = \Drupal::logger('agent_runner_debug');
    $logger->notice('runAsAgent called: job_id=@job_id, verbose=@verbose, history_count=@count', [
      '@job_id' => $job_id,
      '@verbose' => $verbose_mode ? 'true' : 'false',
      '@count' => count($chat_history),
    ]);
    /** @var \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent */
    $agent = $this->aiAgentPluginManager->createInstance($assistant_id);
    // Load the agent from temp store if it exists.
    if ($agent_data = $this->tempStore->get('ai_assistant_threads')->get($job_id)) {
      $logger->notice('Loaded agent from tempstore for job_id=@job_id, keys=@keys', [
        '@job_id' => $job_id,
        '@keys' => implode(',', array_keys($agent_data)),
      ]);
      $agent->fromArray($agent_data);
      // Re-enable looping for continuation requests so the agent can
      // execute pending tools and complete the tool cycle. The first
      // request disabled looping (verbose mode) to return early with
      // tool_calls, but the continuation must be allowed to loop to
      // actually execute those tools and get the final LLM response.
      $agent->setLooped(TRUE);
    }
    else {
      $logger->notice('No tempstore data for job_id=@job_id, creating fresh agent', ['@job_id' => $job_id]);
      // Remove the last message from the chat history.
      $new_messages = [];
      foreach ($chat_history as $message) {
        $new_messages[] = new ChatMessage($message['role'], $message['message']);
      }
      $input = new ChatInput($new_messages, []);
      $agent->setChatInput($input);
      $agent->setAiProvider($this->aiProvider->createInstance($defaults['provider_id']));
      $agent->setModelName($defaults['model_id']);
      $agent->setCreateDirectly(TRUE);
      if ($verbose_mode) {
        // We only want to run one loop at a time.
        $agent->setLooped(FALSE);
      }
    }
    $event = new AiAssistantPassContextToAgentEvent($agent, $context);
    $this->eventDispatcher->dispatch($event, AiAssistantPassContextToAgentEvent::EVENT_NAME);

    try {
      $agent->determineSolvability();
    }
    catch (\Exception $e) {
      // Clean up tempstore on failure to prevent stale agent data from
      // poisoning subsequent requests with the same job_id.
      $this->tempStore->get('ai_assistant_threads')->delete($job_id);
      $logger->error('determineSolvability failed for job_id=@job_id, cleaned up tempstore: @error', [
        '@job_id' => $job_id,
        '@error' => substr($e->getMessage(), 0, 200),
      ]);
      throw $e;
    }

    $logger->notice('After determineSolvability: finished=@finished', [
      '@finished' => $agent->isFinished() ? 'true' : 'false',
    ]);
    // If the agent is still running, we store it for the next run.
    if (!$agent->isFinished()) {
      $this->tempStore->get('ai_assistant_threads')->set($job_id, $agent->toArray());
      $logger->notice('Agent NOT finished, saved to tempstore');
    }
    else {
      // Cleanup when finished.
      $this->tempStore->get('ai_assistant_threads')->delete($job_id);
      $logger->notice('Agent FINISHED, cleaned up tempstore');
    }
    // When verbose mode is off and the agent didn't finish (hit max_loops),
    // return a clean error instead of a message with pending tools that
    // would cause the browser to loop indefinitely.
    if (!$agent->isFinished() && !$verbose_mode) {
      $this->tempStore->get('ai_assistant_threads')->delete($job_id);
      return new ChatOutput(
        new ChatMessage('assistant', 'I was unable to complete my research within the allowed steps. Please try rephrasing your question.'),
        ['Agent exceeded max_loops without finishing'],
        [],
      );
    }

    // Job will always be solvable if we are here.
    $response = $agent->solve() ?? '';

    // Check if tools was used.
    $message = new ChatMessage('assistant', $response);

    if ($history = $agent->getChatHistory()) {
      // Get the last message from the history.
      $message = end($history);
      $logger->notice('Last history message: role=@role, has_tools=@tools, text_len=@len', [
        '@role' => $message->getRole(),
        '@tools' => !empty($message->getTools()) ? 'YES(' . count($message->getTools()) . ')' : 'no',
        '@len' => strlen($message->getText()),
      ]);
    }
    return new ChatOutput(
      $message,
      [$response],
      [],
    );
  }

}
