<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One short, link-only notice for one member about one round.
 *
 * A row says only which kind of event happened, to whom, about which round
 * and when. It has no text, title, amount, count or name field: the
 * Group's name and the round's link are read at display time. The row is
 * also the delivery ledger: one row per occurrence key and recipient, so a
 * repeated delivery writes nothing twice, and its email state records
 * whether an email is still owed (pending), went out (sent) or is not
 * sent at all (off).
 *
 * preSave() refuses a second row for the same occurrence and recipient, an
 * unknown kind or email state, and any change to a saved row except its
 * email state. Viewing is for the recipient only, and then only while the
 * one reader still allows the round (ConsensusNoticeAccessControlHandler).
 * There is no admin permission; entity-API writes are refused for
 * everyone and happen only in ConsensusNotifier.
 *
 * @ContentEntityType(
 *   id = "localnodes_consensus_notice",
 *   label = @Translation("Decision notice"),
 *   label_collection = @Translation("Decision notices"),
 *   label_singular = @Translation("decision notice"),
 *   label_plural = @Translation("decision notices"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_consensus\Access\ConsensusNoticeAccessControlHandler",
 *   },
 *   base_table = "localnodes_consensus_notice",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class ConsensusNotice extends ContentEntityBase {

  /**
   * The entity type id.
   */
  public const ENTITY_TYPE_ID = 'localnodes_consensus_notice';

  /**
   * The four kinds of event.
   */
  public const OPENED = 'opened';
  public const BLOCK = 'block';
  public const WITHDRAWN_REPLACED = 'withdrawn_replaced';
  public const CLOSED = 'closed';
  public const KINDS = [self::OPENED, self::BLOCK, self::WITHDRAWN_REPLACED, self::CLOSED];

  /**
   * Email states.
   */
  public const EMAIL_PENDING = 'pending';
  public const EMAIL_SENT = 'sent';
  public const EMAIL_OFF = 'off';
  public const EMAIL_STATES = [self::EMAIL_PENDING, self::EMAIL_SENT, self::EMAIL_OFF];

  /**
   * Fields that never change once saved.
   */
  public const FIXED = ['uuid', 'recipient', 'round', 'kind', 'occurrence', 'created'];

  /**
   * The words for one kind: a heading and one line naming the Group.
   *
   * The notice screen's wording, with the Group's own name.
   * They carry no decision text, title, amount, count or name.
   *
   * @param string $kind
   *   One of KINDS.
   * @param string $group
   *   The Group's name.
   * @param array $options
   *   Translation options, such as langcode.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]|null
   *   The heading and the line, or NULL for an unknown kind.
   */
  public static function words(string $kind, string $group, array $options = []): ?array {
    $args = ['@group' => $group];
    return match ($kind) {
      self::OPENED => [
        new TranslatableMarkup('A decision round has opened', [], $options),
        new TranslatableMarkup('There is a new round to read in @group.', $args, $options),
      ],
      self::BLOCK => [
        new TranslatableMarkup('A block was recorded', [], $options),
        new TranslatableMarkup('A round needs your attention in @group.', $args, $options),
      ],
      self::WITHDRAWN_REPLACED => [
        new TranslatableMarkup('A round was withdrawn or replaced', [], $options),
        new TranslatableMarkup('A round has changed in @group.', $args, $options),
      ],
      self::CLOSED => [
        new TranslatableMarkup('A decision round has closed', [], $options),
        new TranslatableMarkup('A recorded result is ready to read in @group.', $args, $options),
      ],
      default => NULL,
    };
  }

  /**
   * The recipient's account id.
   */
  public function getRecipientId(): int {
    return (int) $this->get('recipient')->target_id;
  }

  /**
   * The round's id.
   */
  public function getRoundId(): int {
    return (int) $this->get('round')->target_id;
  }

  /**
   * The kind of event.
   */
  public function getKind(): string {
    return (string) $this->get('kind')->value;
  }

  /**
   * The occurrence key this row was delivered for.
   */
  public function getOccurrence(): string {
    return (string) $this->get('occurrence')->value;
  }

  /**
   * When the row was delivered.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * The email state: pending, sent or off.
   */
  public function getEmailState(): string {
    return (string) $this->get('email_state')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!in_array($this->getEmailState(), self::EMAIL_STATES, TRUE)) {
      throw new \LogicException('Unknown notice email state.');
    }
    if (!$this->isNew()) {
      // Always the stored row: a copy left from a refused save keeps a
      // stale $this->original, which must never stand in for it.
      $original = $storage->loadUnchanged($this->id());
      if (!$original instanceof self) {
        throw new \LogicException('A notice is saved only over its stored original.');
      }
      foreach (self::FIXED as $name) {
        if (ConsensusRound::plain($this, $name) !== ConsensusRound::plain($original, $name)) {
          throw new \LogicException("A notice never changes, except its email state: $name.");
        }
      }
      return;
    }
    if ($this->getRecipientId() < 1 || $this->getRoundId() < 1 || trim($this->getOccurrence()) === '' || $this->getCreatedTime() < 1) {
      throw new \LogicException('A notice has a recipient, a round, an occurrence and a time.');
    }
    if (!in_array($this->getKind(), self::KINDS, TRUE)) {
      throw new \LogicException('Unknown notice kind.');
    }
    $existing = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('occurrence', $this->getOccurrence())
      ->condition('recipient', $this->getRecipientId())
      ->count()
      ->execute();
    if ((int) $existing > 0) {
      throw new \LogicException('One notice per occurrence and recipient.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['recipient'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('For'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['round'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Decision'))
      ->setSetting('target_type', ConsensusRound::ENTITY_TYPE_ID)
      ->setRequired(TRUE);

    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Kind'))
      ->setSetting('max_length', 32)
      ->setRequired(TRUE);

    $fields['occurrence'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Occurrence'))
      ->setSetting('max_length', 128)
      ->setRequired(TRUE);

    $fields['created'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Delivered'))
      ->setRequired(TRUE);

    $fields['email_state'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Email'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);

    return $fields;
  }

}
