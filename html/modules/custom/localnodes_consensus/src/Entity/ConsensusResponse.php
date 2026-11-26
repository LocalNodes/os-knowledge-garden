<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * One response revision to a decision round, append-only.
 *
 * Each change of a member's response is a new row; its id is the stable
 * revision id. A row holds the round, the member, the choice (agree,
 * stand_aside or block), the written reason (required for a block), when
 * it was given and signing_state, a plain string that defaults to
 * "unsigned". Nothing in this module reads signing_state to decide
 * anything.
 *
 * Responses are read with their round (ConsensusRecordAccessControlHandler
 * follows the round reference); entity-API writes are refused for everyone,
 * so only RoundLifecycle writes them.
 *
 * At the entity layer: a saved row refuses every change except
 * signing_state, which a signing module may set also after the round closes; a new
 * row for a closed round is refused; no row is ever deleted.
 *
 * @ContentEntityType(
 *   id = "localnodes_consensus_response",
 *   label = @Translation("Response"),
 *   label_collection = @Translation("Responses"),
 *   label_singular = @Translation("response"),
 *   label_plural = @Translation("responses"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_consensus\Access\ConsensusRecordAccessControlHandler",
 *   },
 *   base_table = "localnodes_consensus_response",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class ConsensusResponse extends ContentEntityBase {

  /**
   * The entity type id.
   */
  public const ENTITY_TYPE_ID = 'localnodes_consensus_response';

  /**
   * The choices.
   */
  public const AGREE = 'agree';
  public const STAND_ASIDE = 'stand_aside';
  public const BLOCK = 'block';
  public const CHOICES = [self::AGREE, self::STAND_ASIDE, self::BLOCK];

  /**
   * The signing_state of a new revision.
   */
  public const UNSIGNED = 'unsigned';

  /**
   * Fields a saved revision never changes (all but signing_state).
   */
  public const FIXED = ['uuid', 'round', 'member', 'choice', 'reason', 'changed_at'];

  /**
   * The round's id.
   */
  public function getRoundId(): int {
    return (int) $this->get('round')->target_id;
  }

  /**
   * The member's account id.
   */
  public function getMemberId(): int {
    return (int) $this->get('member')->target_id;
  }

  /**
   * The choice.
   */
  public function getChoice(): string {
    return (string) $this->get('choice')->value;
  }

  /**
   * The written reason, or NULL.
   */
  public function getReason(): ?string {
    $reason = $this->get('reason')->value;
    return $reason === NULL || $reason === '' ? NULL : (string) $reason;
  }

  /**
   * When the response was given (server time).
   */
  public function getChangedAt(): int {
    return (int) $this->get('changed_at')->value;
  }

  /**
   * The signing state.
   */
  public function getSigningState(): string {
    return (string) $this->get('signing_state')->value;
  }

  /**
   * The revision as the record contract returns it.
   *
   * @return array{revision_id: int, member: int, choice: string, reason: ?string, changed_at: int, signing_state: string}
   *   The revision.
   */
  public function toRecord(): array {
    return [
      'revision_id' => (int) $this->id(),
      'member' => $this->getMemberId(),
      'choice' => $this->getChoice(),
      'reason' => $this->getReason(),
      'changed_at' => $this->getChangedAt(),
      'signing_state' => $this->getSigningState(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!$this->isNew()) {
      // Always the stored row: a copy left from a refused save keeps a
      // stale $this->original, which must never stand in for it.
      $original = $storage->loadUnchanged($this->id());
      if (!$original instanceof self) {
        throw new \LogicException('A response is saved only over its stored original.');
      }
      foreach (self::FIXED as $name) {
        if (ConsensusRound::plain($this, $name) !== ConsensusRound::plain($original, $name)) {
          throw new \LogicException("A saved response never changes: $name.");
        }
      }
      self::checkSigningState($this->getSigningState());
      return;
    }
    if ($this->get('signing_state')->isEmpty()) {
      $this->set('signing_state', self::UNSIGNED);
    }
    self::checkSigningState($this->getSigningState());
    if ($this->getRoundId() < 1 || $this->getMemberId() < 1 || $this->getChangedAt() < 1 || !in_array($this->getChoice(), self::CHOICES, TRUE)) {
      throw new \LogicException('A response has a round, a member, a known choice and a time.');
    }
    if ($this->getChoice() === self::BLOCK && trim((string) $this->getReason()) === '') {
      throw new \LogicException('A block needs a written reason.');
    }
    $round = \Drupal::entityTypeManager()->getStorage(ConsensusRound::ENTITY_TYPE_ID)->loadUnchanged($this->getRoundId());
    if (!$round instanceof ConsensusRound || $round->isTerminal()) {
      throw new \LogicException('A closed decision takes no new response.');
    }
    // However it is saved, a response timed at or after the deadline is late.
    if ($this->getChangedAt() >= $round->getDeadline()) {
      throw new \LogicException('A response after the deadline is not taken.');
    }
  }

  /**
   * {@inheritdoc}
   *
   * Responses are permanent.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    throw new \LogicException('A response is never deleted.');
  }

  /**
   * Refuses a signing_state that is not a short plain string.
   *
   * One line of at most 64 characters, with no surrounding spaces, no
   * control characters and no markup characters. Its meaning is the signing module's.
   */
  public static function checkSigningState(string $state): void {
    if ($state === '' || $state !== trim($state) || mb_strlen($state) > 64 || preg_match('/[\p{C}<>&"\']/u', $state)) {
      throw new \LogicException('signing_state is a short plain string.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['round'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Decision'))
      ->setSetting('target_type', 'localnodes_consensus_round')
      ->setRequired(TRUE);
    $fields['member'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Member'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);
    $fields['choice'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Response'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);
    $fields['reason'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Reason'));
    $fields['changed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('When'))
      ->setRequired(TRUE);
    $fields['signing_state'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Signing state'))
      ->setSetting('max_length', 64)
      ->setDefaultValue(self::UNSIGNED)
      ->setRequired(TRUE);

    return $fields;
  }

}
