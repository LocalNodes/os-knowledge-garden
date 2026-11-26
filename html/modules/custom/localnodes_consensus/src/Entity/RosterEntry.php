<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * One account's standing on one Group's roster.
 *
 * One row per account and Group, created only for a current member by
 * RosterManager. The stored standing is approved, removed, disputed or
 * left; a left row stays as history. Whether an approved account is on the
 * roster right now also depends on its live Group membership, which
 * RosterManager::standing() checks. Every change has its RosterAudit row.
 *
 * Base fields only; no bundles, forms or Field UI. There is no admin
 * permission. Viewing goes through the one reader decision; creating,
 * changing and deleting through the entity API are refused for everyone, so
 * only RosterManager writes it.
 *
 * @ContentEntityType(
 *   id = "localnodes_roster_entry",
 *   label = @Translation("Roster entry"),
 *   label_collection = @Translation("Roster entries"),
 *   label_singular = @Translation("roster entry"),
 *   label_plural = @Translation("roster entries"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_consensus\Access\ConsensusRecordAccessControlHandler",
 *   },
 *   base_table = "localnodes_roster_entry",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class RosterEntry extends ContentEntityBase {

  /**
   * The stored standings.
   */
  public const APPROVED = 'approved';
  public const REMOVED = 'removed';
  public const DISPUTED = 'disputed';
  public const LEFT = 'left';

  /**
   * The standing of an account with no entry.
   */
  public const NONE = 'none';

  /**
   * The id of the Group.
   */
  public function getGroupId(): int {
    return (int) $this->get('gid')->target_id;
  }

  /**
   * The id of the account.
   */
  public function getAccountId(): int {
    return (int) $this->get('account')->target_id;
  }

  /**
   * The stored standing.
   */
  public function getStanding(): string {
    return (string) $this->get('standing')->value;
  }

  /**
   * When the standing last changed.
   */
  public function getChangedTime(): int {
    return (int) $this->get('changed_at')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!in_array($this->getStanding(), [self::APPROVED, self::REMOVED, self::DISPUTED, self::LEFT], TRUE)) {
      throw new \LogicException('A roster entry has a known standing.');
    }
    if ($this->getGroupId() < 1 || $this->getAccountId() < 1) {
      throw new \LogicException('A roster entry names a Group and an account.');
    }
    if (!$this->isNew()) {
      $original = $storage->loadUnchanged($this->id());
      if ($original instanceof self && ($original->getGroupId() !== $this->getGroupId() || $original->getAccountId() !== $this->getAccountId())) {
        throw new \LogicException('A roster entry keeps its Group and account.');
      }
      return;
    }
    $taken = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $this->getGroupId())
      ->condition('account', $this->getAccountId())
      ->count()
      ->execute();
    if ($taken > 0) {
      throw new \LogicException('An account has one roster entry per Group.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['gid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Group'))
      ->setSetting('target_type', 'group')
      ->setRequired(TRUE);

    $fields['account'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Member'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['standing'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Standing'))
      ->setDescription(t('approved, removed, disputed or left.'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);

    $fields['changed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Changed'))
      ->setRequired(TRUE);

    return $fields;
  }

}
