<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing_test\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The scripted LLM double for Kernel tests (AI-SPEC §5 "LLM double").
 *
 * Runs on PreGenerateResponseEvent at priority -1000, after every real
 * subscriber, so what it records is exactly what the provider would receive
 * (leak door 1). It then short-circuits the call with the next scripted
 * ChatOutput through setForcedOutputObject(), before any network traffic
 * (ProviderProxy.php:248-250). The provider behind it (echoai) is never
 * called.
 *
 * Inactive until script() is called, so tests that never script it pass
 * through untouched. Once armed, an exhausted script throws: a test must say
 * how many model turns it expects.
 */
final class ScriptedLlmSubscriber implements EventSubscriberInterface {

  /**
   * Scripted turns, consumed in order.
   *
   * @var list<\Drupal\ai\OperationType\Chat\ChatOutput|callable>
   */
  private array $queue = [];

  /**
   * Whether script() has been called since the last reset().
   */
  private bool $armed = FALSE;

  /**
   * Every chat request seen while armed.
   *
   * @var list<array{input: mixed, configuration: array, tags: array, provider: string, model: string, text: string}>
   */
  private array $inputs = [];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', -1000]];
  }

  /**
   * Queues model turns and arms the double.
   *
   * @param array $outputs
   *   Each entry is a ChatOutput, or a callable taking (ChatInput $input,
   *   array $configuration) and returning a ChatOutput. Callables let a turn
   *   depend on what the model was given (for example, cite the labels the
   *   tool returned).
   */
  public function script(array $outputs): void {
    $this->queue = array_values($outputs);
    $this->armed = TRUE;
  }

  /**
   * Every chat request recorded since the last reset().
   *
   * Each record holds the input object, the configuration and tags, and
   * 'text': a snapshot of everything the model would read, taken at request
   * time (system prompt, every message's role and text, every tool call's
   * arguments). Later code mutates message objects in place (the citation
   * rewrite), so leak checks read 'text', not the objects.
   *
   * @return list<array{input: mixed, configuration: array, tags: array, provider: string, model: string, text: string}>
   *   The records, in call order.
   */
  public function inputs(): array {
    return $this->inputs;
  }

  /**
   * Turns left in the script.
   */
  public function remaining(): int {
    return count($this->queue);
  }

  /**
   * Disarms the double and forgets every recorded input.
   */
  public function reset(): void {
    $this->queue = [];
    $this->inputs = [];
    $this->armed = FALSE;
  }

  /**
   * Records the request and answers it from the script.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if (!$this->armed) {
      $this->answerFromState($event);
      return;
    }
    $input = $event->getInput();
    $this->inputs[] = [
      'input' => $input,
      'configuration' => $event->getConfiguration(),
      'tags' => $event->getTags(),
      'provider' => $event->getProviderId(),
      'model' => $event->getModelId(),
      'text' => self::snapshot($input),
    ];
    if ($this->queue === []) {
      throw new \LogicException('Scripted LLM: no scripted turn left for request ' . count($this->inputs) . '.');
    }
    $turn = array_shift($this->queue);
    $output = is_callable($turn) ? $turn($input, $event->getConfiguration()) : $turn;
    if (!$output instanceof ChatOutput) {
      throw new \LogicException('Scripted LLM: a turn must be a ChatOutput.');
    }
    $event->setForcedOutputObject($output);
  }

  /**
   * State key for the HTTP script (Functional tests).
   */
  public const HTTP_SCRIPT_KEY = 'social_ai_indexing_test.http_script';

  /**
   * State key for the requests the HTTP script answered.
   */
  public const HTTP_CALLS_KEY = 'social_ai_indexing_test.http_calls';

  /**
   * Answers a web request's chat call from State (Functional tests).
   *
   * A Functional test's requests run in the web server's PHP process, where
   * script() was never called. The test puts a script in State instead:
   * ['mode' => 'cite_all'] cites every [R#] label the request holds (or
   * answers NO_RELEVANT_CONTENT when it holds none); ['mode' => 'text',
   * 'text' => '...'] answers with that text. Each answered request is
   * appended to State (tags and the snapshot text), so the test can count
   * model calls and read what the model was given. Unset: does nothing.
   */
  private function answerFromState(PreGenerateResponseEvent $event): void {
    $script = \Drupal::state()->get(self::HTTP_SCRIPT_KEY);
    if (!is_array($script) || !isset($script['mode'])) {
      return;
    }
    $input = $event->getInput();
    $snapshot = self::snapshot($input);
    $calls = \Drupal::state()->get(self::HTTP_CALLS_KEY, []);
    $calls[] = ['tags' => array_values($event->getTags()), 'text' => $snapshot];
    \Drupal::state()->set(self::HTTP_CALLS_KEY, $calls);

    if ($script['mode'] === 'text') {
      $event->setForcedOutputObject(self::text((string) ($script['text'] ?? '')));
      return;
    }
    preg_match_all('/^\[(R\d+)\] /m', $snapshot, $matches);
    $labels = array_values(array_unique($matches[1]));
    if ($labels === []) {
      $event->setForcedOutputObject(self::text('NO_RELEVANT_CONTENT'));
      return;
    }
    $sentences = array_map(static fn (string $label, int $i): string => 'Fictional point ' . ($i + 1) . " [$label].", $labels, array_keys($labels));
    $event->setForcedOutputObject(self::text(implode(' ', $sentences)));
  }

  /**
   * A model turn that answers with text.
   */
  public static function text(string $text): ChatOutput {
    return new ChatOutput(new ChatMessage('assistant', $text), [$text], []);
  }

  /**
   * A model turn that calls one tool.
   *
   * @param string $function_name
   *   The tool's function name, e.g. ai_search_rag_search.
   * @param array $arguments
   *   Argument name => value. Only properties the agent exposes to the model
   *   (force_value + hide_property ones are not).
   * @param \Drupal\ai\OperationType\Chat\ChatInput|null $input
   *   The request being answered; when given, the tool's input definition is
   *   attached so argument properties resolve as they would from a provider.
   * @param string $tool_id
   *   The tool call id.
   */
  public static function toolCall(string $function_name, array $arguments, ?ChatInput $input = NULL, string $tool_id = ''): ChatOutput {
    $definition = $input?->getChatTools()?->getFunctionByName($function_name);
    $tool = new ToolsFunctionOutput($definition, $tool_id !== '' ? $tool_id : 'call_' . bin2hex(random_bytes(4)));
    $tool->setName($function_name);
    $tool->addArguments($arguments);
    $message = new ChatMessage('assistant', '');
    $message->setTools([$tool]);
    return new ChatOutput($message, [], []);
  }

  /**
   * The [R#] labels in every tool message of a request, in first-seen order.
   *
   * @return list<string>
   *   Labels such as 'R1'.
   */
  public static function toolLabels(ChatInput $input): array {
    $labels = [];
    foreach ($input->getMessages() as $message) {
      if ($message instanceof ChatMessage && $message->getRole() === 'tool') {
        preg_match_all('/^\[(R\d+)\] /m', $message->getText(), $matches);
        foreach ($matches[1] as $label) {
          $labels[$label] = $label;
        }
      }
    }
    return array_values($labels);
  }

  /**
   * Everything the model would read in a request, as one string.
   */
  public static function snapshot(mixed $input): string {
    if (is_string($input)) {
      return $input;
    }
    if (!$input instanceof ChatInput) {
      return is_object($input) && method_exists($input, 'toString') ? (string) $input->toString() : '';
    }
    $lines = ['system: ' . $input->getSystemPrompt()];
    foreach ($input->getMessages() as $message) {
      if (!$message instanceof ChatMessage) {
        continue;
      }
      $lines[] = $message->getRole() . ': ' . $message->getText();
      foreach ($message->getTools() ?? [] as $tool) {
        $arguments = [];
        foreach ($tool->getArguments() as $argument) {
          $arguments[$argument->getName()] = $argument->getValue();
        }
        $lines[] = 'tool_call ' . $tool->getName() . ' ' . json_encode($arguments);
      }
    }
    return implode("\n", $lines);
  }

}
