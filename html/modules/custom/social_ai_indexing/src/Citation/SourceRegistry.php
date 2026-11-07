<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Citation;

use Drupal\Core\Entity\ContentEntityInterface;

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
   * Whether the final answer has been rewritten.
   */
  private bool $rewritten = FALSE;

  /**
   * Excerpts shown to the model, by source key (the support check's input).
   *
   * @var array<string, list<string>>
   */
  private array $excerpts = [];

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
   * Records that the final answer went through the rewriter.
   */
  public function markRewritten(): void {
    $this->rewritten = TRUE;
  }

  /**
   * Whether the final answer went through the rewriter.
   */
  public function wasRewritten(): bool {
    return $this->rewritten;
  }

  /**
   * Empties the registry and clears the rewritten flag.
   */
  public function reset(): void {
    $this->sources = [];
    $this->excerpts = [];
    $this->rewritten = FALSE;
  }

}
