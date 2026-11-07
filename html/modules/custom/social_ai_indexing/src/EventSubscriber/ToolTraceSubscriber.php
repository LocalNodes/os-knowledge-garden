<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\social_ai_indexing\Citation\SourceRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Tool-level trace for the Group assistant (AI-SPEC §4 "Tool Use", D-20).
 *
 * Logs one 'tool_trace' line per tool call: plugin id, index, result count,
 * the labels this call returned and the registry size. Never titles, URLs,
 * the search string or excerpt text (E10): the tool's structured output
 * carries titles and URLs, so only its label numbers are read.
 */
final class ToolTraceSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly SourceRegistry $registry,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   *
   * A literal event name (AgentToolFinishedExecutionEvent::EVENT_NAME): this
   * module doesn't depend on ai_agents.
   */
  public static function getSubscribedEvents(): array {
    return ['ai_agents.tool_finished_executed' => ['onToolFinished', 0]];
  }

  /**
   * Logs the trace line for a group_assistant tool call.
   */
  public function onToolFinished(AgentToolFinishedExecutionEvent $event): void {
    if ($event->getAgentId() !== CitationRewriteSubscriber::AGENT_ID) {
      return;
    }
    $tool = $event->getTool();
    $structured = method_exists($tool, 'getStructuredOutput') ? $tool->getStructuredOutput() : [];
    $results = is_array($structured['results'] ?? NULL) ? $structured['results'] : [];
    $labels = [];
    foreach ($results as $result) {
      if (is_array($result) && is_int($result['n'] ?? NULL)) {
        $labels[] = 'R' . $result['n'];
      }
    }
    // The index argument is model-written: log it only when it looks like a
    // machine name.
    $index = is_string($structured['index'] ?? NULL) && preg_match('/^[a-z0-9_]{1,64}$/', $structured['index']) === 1
      ? $structured['index']
      : 'invalid';

    $this->logger->info('tool_trace agent=@agent plugin=@plugin index=@index results=@results labels=@labels registry=@registry', [
      '@agent' => $event->getAgentId(),
      '@plugin' => $tool->getPluginId(),
      '@index' => $index,
      '@results' => count($results),
      '@labels' => $labels ? implode(',', $labels) : '-',
      '@registry' => $this->registry->count(),
    ]);
  }

}
