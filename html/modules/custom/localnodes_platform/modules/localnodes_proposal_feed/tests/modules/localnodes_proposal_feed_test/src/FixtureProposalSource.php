<?php

declare(strict_types=1);

namespace Drupal\localnodes_proposal_feed_test;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_proposal_feed\ProposalSourceInterface;

/**
 * A fixture adapter: reads any entity labelled FIXTURE_LABEL as a decision.
 *
 * It belongs to no group, so the feed would make no card for it. Tests use
 * it to prove adapters are collected and asked; later tests may replace it.
 */
final class FixtureProposalSource implements ProposalSourceInterface {

  /**
   * The label that makes an entity a fixture proposal.
   */
  public const FIXTURE_LABEL = 'A fixture proposal';

  /**
   * {@inheritdoc}
   */
  public function applies(ContentEntityInterface $source): bool {
    return $source->label() === self::FIXTURE_LABEL;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(ContentEntityInterface $source): ?GroupInterface {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getKind(ContentEntityInterface $source): string {
    return self::KIND_DECISION;
  }

  /**
   * {@inheritdoc}
   */
  public function getState(ContentEntityInterface $source): string {
    return self::STATE_OPEN;
  }

  /**
   * {@inheritdoc}
   */
  public function getVisibility(ContentEntityInterface $source): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(ContentEntityInterface $source): string {
    return (string) $source->label();
  }

  /**
   * {@inheritdoc}
   */
  public function getText(ContentEntityInterface $source): string {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getProposer(ContentEntityInterface $source): ?AccountInterface {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getOpenedTime(ContentEntityInterface $source): int {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getFacts(ContentEntityInterface $source): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceUrl(ContentEntityInterface $source): ?Url {
    return NULL;
  }

}
