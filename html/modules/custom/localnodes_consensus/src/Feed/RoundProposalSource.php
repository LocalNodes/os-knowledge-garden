<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Feed;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Access\CurrentEligibleReader;
use Drupal\localnodes_consensus\Entity\ConsensusResponse;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_proposal_feed\ProposalSourceInterface;
use Drupal\user\UserInterface;

/**
 * The one feed adapter for decision rounds.
 *
 * The proposal feed keeps one card per round and asks this adapter what the
 * card shows. It only reads: it never saves, never dispatches and never
 * decides who sees a card (the feed checks the reported visibility when a
 * card is shown). Every decision type is KIND_DECISION, money included,
 * because a consensus decision records an amount and pays nothing; the
 * facts carry no amount key, which the feed keeps for payments.
 */
class RoundProposalSource implements ProposalSourceInterface {

  use StringTranslationTrait;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly CurrentEligibleReader $reader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(ContentEntityInterface $source): bool {
    return $source instanceof ConsensusRound && !$source->isNew();
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(ContentEntityInterface $source): ?GroupInterface {
    $group = $this->round($source)->get('gid')->entity;
    return $group instanceof GroupInterface ? $group : NULL;
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
    return match ($this->round($source)->getStatus()) {
      ConsensusRound::OPEN => self::STATE_OPEN,
      ConsensusRound::ENDED_PENDING => self::STATE_ENDED,
      ConsensusRound::WITHDRAWN, ConsensusRound::REPLACED => self::STATE_WITHDRAWN,
      default => self::STATE_CLOSED,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getVisibility(ContentEntityInterface $source): ?string {
    $round = $this->round($source);
    $group = $this->getGroup($round);
    if (!$group instanceof GroupInterface) {
      return self::VISIBILITY_GROUP;
    }
    $stored = $round->getVisibility();
    if (!in_array($stored, CurrentEligibleReader::VALUES, TRUE)) {
      return self::VISIBILITY_GROUP;
    }
    return match ($this->reader->effectiveVisibility($group, $stored)) {
      CurrentEligibleReader::PUBLIC => self::VISIBILITY_PUBLIC,
      CurrentEligibleReader::COMMUNITY => self::VISIBILITY_COMMUNITY,
      default => self::VISIBILITY_GROUP,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(ContentEntityInterface $source): string {
    foreach (preg_split('/\R/u', $this->getText($source)) ?: [] as $line) {
      $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
      if ($line !== '') {
        return Unicode::truncate($line, ConsensusRound::TITLE_LENGTH, TRUE);
      }
    }
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getText(ContentEntityInterface $source): string {
    return $this->round($source)->getText();
  }

  /**
   * {@inheritdoc}
   */
  public function getProposer(ContentEntityInterface $source): ?AccountInterface {
    $account = $this->entityTypeManager->getStorage('user')->load($this->round($source)->getProposerId());
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getOpenedTime(ContentEntityInterface $source): int {
    return $this->round($source)->getOpenedAt();
  }

  /**
   * {@inheritdoc}
   *
   * The status and result lines are the card's wording.
   */
  public function getFacts(ContentEntityInterface $source): array {
    $round = $this->round($source);
    $roster = $round->getOpeningRoster();
    $counts = [ConsensusResponse::AGREE => 0, ConsensusResponse::STAND_ASIDE => 0, ConsensusResponse::BLOCK => 0];
    $responded = 0;
    foreach ($round->getResponses() as $response) {
      if (in_array($response['member'], $roster, TRUE)) {
        $responded++;
        if (isset($counts[$response['choice']])) {
          $counts[$response['choice']]++;
        }
      }
    }
    $facts = [
      'closes' => $round->getDeadline(),
      'status' => NULL,
      'result' => NULL,
      'kind_label' => $this->kindLabel($round->getDecisionType()),
    ];
    if (!$round->isTerminal()) {
      $facts['status'] = (string) $this->t('@count of @total have responded', ['@count' => $responded, '@total' => count($roster)]);
      return $facts;
    }
    $facts['result'] = (string) $this->t('@outcome: @agree agree, @aside stand aside, @block block', [
      '@outcome' => $this->outcome((string) $round->getClosedState()),
      '@agree' => $counts[ConsensusResponse::AGREE],
      '@aside' => $counts[ConsensusResponse::STAND_ASIDE],
      '@block' => $counts[ConsensusResponse::BLOCK],
    ]);
    return $facts;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceUrl(ContentEntityInterface $source): ?Url {
    $round = $this->round($source);
    return $round->getEntityType()->hasLinkTemplate('canonical') ? $round->toUrl() : NULL;
  }

  /**
   * The plain-words name of a decision type.
   */
  protected function kindLabel(string $type): ?string {
    return match ($type) {
      'everyday' => (string) $this->t('Everyday decision'),
      'money' => (string) $this->t('Money decision'),
      'rule_change' => (string) $this->t('Rule change'),
      default => NULL,
    };
  }

  /**
   * The plain-words outcome of a terminal state.
   */
  protected function outcome(string $state): string {
    return (string) match ($state) {
      ConsensusRound::AGREED => $this->t('Agreed'),
      ConsensusRound::CLOSED_BLOCKED => $this->t('Not agreed, blocked'),
      ConsensusRound::CLOSED_PARTICIPATION => $this->t('Not agreed, too few responded'),
      ConsensusRound::CLOSED_NO_AGREE => $this->t('Not agreed, nobody agreed'),
      ConsensusRound::WITHDRAWN => $this->t('Withdrawn'),
      ConsensusRound::REPLACED => $this->t('Replaced by a new version'),
      default => $this->t('Closed'),
    };
  }

  /**
   * The round this adapter reads.
   */
  protected function round(ContentEntityInterface $source): ConsensusRound {
    if (!$source instanceof ConsensusRound) {
      throw new \InvalidArgumentException('This adapter reads decision rounds only.');
    }
    return $source;
  }

}
