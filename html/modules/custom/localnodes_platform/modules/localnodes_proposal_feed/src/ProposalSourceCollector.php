<?php

declare(strict_types=1);

namespace Drupal\localnodes_proposal_feed;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Collects the source adapters of every kind of proposal.
 *
 * Adapters are services tagged "localnodes_proposal_feed.source"; the
 * container adds each one here through addSource().
 */
final class ProposalSourceCollector {

  /**
   * Adapters by priority, then by service ID.
   *
   * @var array<int, array<string, \Drupal\localnodes_proposal_feed\ProposalSourceInterface>>
   */
  private array $sources = [];

  /**
   * Adapters sorted by priority, highest first, keyed by service ID.
   *
   * @var array<string, \Drupal\localnodes_proposal_feed\ProposalSourceInterface>|null
   */
  private ?array $sorted = NULL;

  /**
   * Adds an adapter.
   *
   * @param \Drupal\localnodes_proposal_feed\ProposalSourceInterface $source
   *   The adapter.
   * @param string $id
   *   The adapter's service ID.
   * @param int $priority
   *   Higher priorities are asked first.
   */
  public function addSource(ProposalSourceInterface $source, string $id, int $priority = 0): void {
    $this->sources[$priority][$id] = $source;
    $this->sorted = NULL;
  }

  /**
   * Returns every adapter, highest priority first.
   *
   * @return array<string, \Drupal\localnodes_proposal_feed\ProposalSourceInterface>
   *   The adapters, keyed by service ID.
   */
  public function getSources(): array {
    if ($this->sorted === NULL) {
      krsort($this->sources);
      $this->sorted = $this->sources ? array_merge(...array_values($this->sources)) : [];
    }
    return $this->sorted;
  }

  /**
   * Returns the adapter that reads the given entity as a proposal.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Any content entity.
   *
   * @return \Drupal\localnodes_proposal_feed\ProposalSourceInterface|null
   *   The first adapter that applies, or NULL when none does.
   */
  public function getSourceFor(ContentEntityInterface $entity): ?ProposalSourceInterface {
    foreach ($this->getSources() as $source) {
      if ($source->applies($entity)) {
        return $source;
      }
    }
    return NULL;
  }

}
