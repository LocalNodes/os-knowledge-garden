<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * One change to a Group's roster, append-only.
 *
 * Each row holds the Group, the account whose standing changed, who changed
 * it (the actor), the server time, a reason and the standing before and
 * after. Every roster ending (removed, disputed, left) has exactly one row,
 * and the roster event carries that row's id, so a round can compare the
 * ending's time with its own deadline.
 *
 * preSave() refuses any change to a saved row and preDelete() refuses every
 * delete, whoever saves. There is no admin permission; viewing goes through
 * the one reader decision and entity-API writes are refused for everyone.
 *
 * announced_at records when the roster event for an ending went out. A
 * manager's change and a lazily recorded ending are announced as they are
 * written, so the row is saved with it set. A Group membership delete
 * writes its row inside Group's transaction and announces nothing there;
 * RosterManager::announcePending() later sets the time once, by a
 * conditional update outside the entity API, and then sends the event.
 * Approvals announce nothing and keep it empty.
 *
 * @ContentEntityType(
 *   id = "localnodes_roster_audit",
 *   label = @Translation("Roster change"),
 *   label_collection = @Translation("Roster history"),
 *   label_singular = @Translation("roster change"),
 *   label_plural = @Translation("roster changes"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_consensus\Access\ConsensusRecordAccessControlHandler",
 *   },
 *   base_table = "localnodes_roster_audit",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class RosterAudit extends ContentEntityBase {

  /**
   * The reason recorded when a Group membership ended.
   */
  public const MEMBERSHIP_ENDED = 'membership_ended';

  /**
   * The id of the Group.
   */
  public function getGroupId(): int {
    return (int) $this->get('gid')->target_id;
  }

  /**
   * The id of the account whose standing changed.
   */
  public function getAccountId(): int {
    return (int) $this->get('account')->target_id;
  }

  /**
   * The id of the account that made the change (0 for the system).
   */
  public function getActorId(): int {
    return (int) $this->get('actor')->target_id;
  }

  /**
   * When the change was made (server time).
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * Why.
   */
  public function getReason(): string {
    return (string) $this->get('reason')->value;
  }

  /**
   * The standing before the change (none for a new entry).
   */
  public function getBefore(): string {
    return (string) $this->get('standing_before')->value;
  }

  /**
   * The standing after the change.
   */
  public function getAfter(): string {
    return (string) $this->get('standing_after')->value;
  }

  /**
   * When the roster event for this row went out, or NULL if not yet.
   */
  public function getAnnouncedTime(): ?int {
    $value = $this->get('announced_at')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!$this->isNew()) {
      throw new \LogicException('Roster history never changes.');
    }
    if ($this->getGroupId() < 1 || $this->getAccountId() < 1 || trim($this->getReason()) === '' || $this->getBefore() === '' || $this->getAfter() === '' || $this->getCreatedTime() < 1) {
      throw new \LogicException('A roster change has a Group, an account, a time, a reason, and standings before and after.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    throw new \LogicException('Roster history is never deleted.');
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

    $fields['actor'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Changed by'))
      ->setSetting('target_type', 'user');

    $fields['created'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('When'))
      ->setRequired(TRUE);

    $fields['reason'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Reason'))
      ->setRequired(TRUE);

    $fields['standing_before'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Standing before'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);

    $fields['standing_after'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Standing after'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);

    $fields['announced_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Announced'))
      ->setDescription(t('When the roster event for this ending went out.'));

    return $fields;
  }

}
