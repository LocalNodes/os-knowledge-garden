<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Citation;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Request-wide registry: one label per entity across indexes and tool calls.
 *
 * Labels run 1..N with no gaps: a number is only taken when an entity key is
 * new. reset() runs at the start of every agent run (D-19, KNOW-04).
 */
final class SourceRegistry {

  /**
   * Registered sources keyed by "type:id", in registration order.
   *
   * @var array<string, \Drupal\social_ai_indexing\Citation\RetrievedSource>
   */
  private array $sources = [];

  /**
   * The message object the subscriber rewrote, if any.
   *
   * Kept as a reference, never as an object id: ids are reused after
   * garbage collection.
   */
  private ?ChatMessage $rewrittenMessage = NULL;

  /**
   * Excerpts shown to the model, by source key (the support check's input).
   *
   * @var array<string, list<string>>
   */
  private array $excerpts = [];

  /**
   * Whether a search in this run broke (backend error, bad index or field).
   *
   * Never set for a refused group: that must read like an empty search.
   */
  private bool $searchTrouble = FALSE;

  /**
   * The kind of AI service failure in this run: 'quota', 'setup' or NULL.
   */
  private ?string $serviceFailure = NULL;

  /**
   * Registers a retrieved entity, or returns its existing source.
   *
   * @throws \InvalidArgumentException
   *   When the entity cannot be cited (for example an orphan comment). No
   *   number is consumed in that case.
   */
  public function register(ContentEntityInterface $entity, string $type): RetrievedSource {
    $key = $entity->getEntityTypeId() . ':' . (int) $entity->id();
    if (!isset($this->sources[$key])) {
      $this->sources[$key] = RetrievedSource::fromEntity(count($this->sources) + 1, $entity, $type);
    }
    return $this->sources[$key];
  }

  /**
   * Records the excerpt the model was shown for a source (once per text).
   */
  public function addExcerpt(RetrievedSource $source, string $excerpt): void {
    if ($excerpt !== '' && !in_array($excerpt, $this->excerpts[$source->key()] ?? [], TRUE)) {
      $this->excerpts[$source->key()][] = $excerpt;
    }
  }

  /**
   * Every excerpt the model was shown under label [R$n], in order.
   *
   * @return list<string>
   *   Empty when the label is unknown or showed no text.
   */
  public function excerptsForLabel(int $n): array {
    $source = $this->byLabelNumber($n);
    return $source === NULL ? [] : ($this->excerpts[$source->key()] ?? []);
  }

  /**
   * The source behind label [R$n], if any.
   */
  public function byLabelNumber(int $n): ?RetrievedSource {
    foreach ($this->sources as $source) {
      if ($source->n === $n) {
        return $source;
      }
    }
    return NULL;
  }

  /**
   * All sources in label order.
   *
   * @return list<\Drupal\social_ai_indexing\Citation\RetrievedSource>
   *   The registered sources.
   */
  public function all(): array {
    return array_values($this->sources);
  }

  /**
   * The number of registered sources.
   */
  public function count(): int {
    return count($this->sources);
  }

  /**
   * Records the message object that went through the rewriter.
   *
   * The object matters, not just the fact: on a failed chat call, ai_agents'
   * finished event carries a throwaway message that is not the one
   * AgentRunner returns, so CitationGuardAgentRunner compares identity.
   */
  public function markRewritten(ChatMessage $message): void {
    $this->rewrittenMessage = $message;
  }

  /**
   * Whether a message went through the rewriter.
   *
   * This says a rewrite happened, not that the person receives it; use
   * rewrittenMessage() to check what is returned.
   */
  public function wasRewritten(): bool {
    return $this->rewrittenMessage !== NULL;
  }

  /**
   * The message object the subscriber rewrote, or NULL if none was.
   */
  public function rewrittenMessage(): ?ChatMessage {
    return $this->rewrittenMessage;
  }

  /**
   * Records that a search in this run broke.
   *
   * Only for causes that are the same whichever group was asked for, so the
   * reply can say so without hinting that a group exists.
   */
  public function markSearchTrouble(): void {
    $this->searchTrouble = TRUE;
  }

  /**
   * Whether a search in this run broke.
   */
  public function hadSearchTrouble(): bool {
    return $this->searchTrouble;
  }

  /**
   * Records that the AI service ran out of quota or isn't set up.
   *
   * @param string $kind
   *   'quota' or 'setup'.
   */
  public function markServiceFailure(string $kind): void {
    $this->serviceFailure = $kind;
  }

  /**
   * The AI service failure in this run ('quota' or 'setup'), or NULL.
   */
  public function serviceFailure(): ?string {
    return $this->serviceFailure;
  }

  /**
   * Empties the registry, forgets the rewritten message and clears both flags.
   */
  public function reset(): void {
    $this->sources = [];
    $this->excerpts = [];
    $this->rewrittenMessage = NULL;
    $this->searchTrouble = FALSE;
    $this->serviceFailure = NULL;
  }

}
