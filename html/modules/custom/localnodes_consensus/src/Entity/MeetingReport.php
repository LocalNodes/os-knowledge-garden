<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * A human-confirmed report of a decision made at a meeting.
 *
 * It keeps the four facts of the meeting (its date, the source or minutes,
 * the deciding body and its method, and the member who confirmed the
 * report), who saved it and when, its own visibility, and a schema version.
 * Its provenance differs from an online round: nobody responded online, so
 * it has no responses, result, counts or roster, and no amount or payment
 * data. It is a recorded fact, not a proposal, so no feed event or notice
 * goes out for it.
 *
 * Saved only through MeetingRecords. preSave() refuses any save of a saved
 * report and a new one missing a fact; preDelete() refuses every delete.
 * There is no admin permission; viewing goes through the one reader decision
 * with the report's own visibility, and entity-API writes are refused for
 * everyone.
 *
 * @ContentEntityType(
 *   id = "localnodes_meeting_report",
 *   label = @Translation("Meeting report"),
 *   label_collection = @Translation("Meeting reports"),
 *   label_singular = @Translation("meeting report"),
 *   label_plural = @Translation("meeting reports"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_consensus\Access\ConsensusRecordAccessControlHandler",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "localnodes_meeting_report",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "canonical" = "/decision/meeting/{localnodes_meeting_report}",
 *   },
 * )
 */
class MeetingReport extends ContentEntityBase {

  /**
   * The entity type id.
   */
  public const ENTITY_TYPE_ID = 'localnodes_meeting_report';

  /**
   * The provenance mark: confirmed by a person, no online responses.
   */
  public const PROVENANCE = 'meeting_report';

  /**
   * The record schema version.
   */
  public const SCHEMA_VERSION = 1;

  /**
   * The four facts, as stored fields, each required.
   */
  public const FACTS = ['title', 'decision_text', 'meeting_date', 'minutes', 'deciding_body', 'method', 'confirmer'];

  /**
   * The longest title, in characters (one line, as the register shows it).
   */
  public const TITLE_LENGTH = 120;

  /**
   * Where this record came from: always a meeting, never an online round.
   */
  public function getProvenance(): string {
    return self::PROVENANCE;
  }

  /**
   * The id of the Group.
   */
  public function getGroupId(): int {
    return (int) $this->get('gid')->target_id;
  }

  /**
   * The stored visibility: public, community or group.
   */
  public function getVisibility(): string {
    return (string) $this->get('visibility')->value;
  }

  /**
   * The meeting date, as YYYY-MM-DD.
   */
  public function getMeetingDate(): string {
    return (string) $this->get('meeting_date')->value;
  }

  /**
   * The source or minutes.
   */
  public function getMinutes(): string {
    return (string) $this->get('minutes')->value;
  }

  /**
   * Who made the decision.
   */
  public function getDecidingBody(): string {
    return (string) $this->get('deciding_body')->value;
  }

  /**
   * How they decided.
   */
  public function getMethod(): string {
    return (string) $this->get('method')->value;
  }

  /**
   * The id of the member who confirmed the report.
   */
  public function getConfirmerId(): int {
    return (int) $this->get('confirmer')->target_id;
  }

  /**
   * The id of the manager who saved it.
   */
  public function getRecordedById(): int {
    return (int) $this->get('recorded_by')->target_id;
  }

  /**
   * When it was saved (server time).
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * The decision title.
   */
  public function getTitle(): string {
    return (string) $this->get('title')->value;
  }

  /**
   * What was decided.
   */
  public function getDecisionText(): string {
    return (string) $this->get('decision_text')->value;
  }

  /**
   * Whether a title is one non-empty line of at most TITLE_LENGTH characters.
   */
  public static function isTitle(string $value): bool {
    return trim($value) !== '' && !preg_match('/[\r\n]/', $value) && mb_strlen($value) <= self::TITLE_LENGTH;
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->getTitle() !== '' ? $this->getTitle() : t('Meeting report of @date', ['@date' => $this->getMeetingDate()]);
  }

  /**
   * Whether a value is a real calendar date written YYYY-MM-DD.
   */
  public static function isDate(string $value): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) {
      return FALSE;
    }
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!$this->isNew()) {
      throw new \LogicException('A meeting report never changes.');
    }
    $missing = match (TRUE) {
      $this->getGroupId() < 1 => 'gid',
      !in_array($this->getVisibility(), ['public', 'community', 'group'], TRUE) => 'visibility',
      !self::isTitle($this->getTitle()) => 'title',
      trim($this->getDecisionText()) === '' => 'decision_text',
      !self::isDate($this->getMeetingDate()) => 'meeting_date',
      trim($this->getMinutes()) === '' => 'minutes',
      trim($this->getDecidingBody()) === '' => 'deciding_body',
      trim($this->getMethod()) === '' => 'method',
      $this->getConfirmerId() < 1 => 'confirmer',
      $this->getRecordedById() < 1 => 'recorded_by',
      $this->getCreatedTime() < 1 => 'created',
      (int) $this->get('schema_version')->value !== self::SCHEMA_VERSION => 'schema_version',
      default => NULL,
    };
    if ($missing !== NULL) {
      throw new \LogicException("A meeting report needs every fact: $missing.");
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    throw new \LogicException('A meeting report is never deleted.');
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
    $fields['visibility'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Visibility'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Decision title'))
      ->setSetting('max_length', self::TITLE_LENGTH)
      ->setRequired(TRUE);
    $fields['decision_text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('What was decided?'))
      ->setRequired(TRUE);
    $fields['meeting_date'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Meeting date'))
      ->setSetting('max_length', 10)
      ->setRequired(TRUE);
    $fields['minutes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Source or minutes'))
      ->setRequired(TRUE);
    $fields['deciding_body'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Who made the decision'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);
    $fields['method'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Decision method'))
      ->setSetting('max_length', 255)
      ->setRequired(TRUE);
    $fields['confirmer'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Confirmed by'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);
    $fields['recorded_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Recorded by'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);
    $fields['created'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Recorded'))
      ->setRequired(TRUE);
    $fields['schema_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Schema version'))
      ->setRequired(TRUE);

    return $fields;
  }

}
