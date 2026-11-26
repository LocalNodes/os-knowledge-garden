<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\localnodes_consensus\Record\DecisionRecordInterface;

/**
 * One decision round: the frozen decision record.
 *
 * Created only by RoundLifecycle. At opening it freezes the Group, method,
 * schema version, text and its version, the predecessor, the decision type,
 * the visibility, the opening roster, the live rules version, quorum,
 * threshold, deadline, and the amount or proposed rules the type needs.
 * The status moves once from open to a terminal state, together with the
 * closing reason, note, time and closure key.
 *
 * preSave() refuses a new round missing a frozen input, any change to a
 * frozen input after save, and any save at all once the stored round is
 * terminal: a signing module may fingerprint the closed record. preDelete()
 * refuses every deletion, so records are permanent and the module cannot
 * be uninstalled once it holds one. There is no admin permission; viewing goes
 * through the one reader decision with the round's own visibility, and
 * entity-API writes are refused for everyone.
 *
 * The round's own page is /decision/{id}; its route requires
 * `_entity_access: localnodes_consensus_round.view`, so it reaches the same
 * reader. Notices and the feed link to it. Its body is the record page,
 * built from the consensus_record template (DecisionRegister::recordBuild()).
 *
 * @ContentEntityType(
 *   id = "localnodes_consensus_round",
 *   label = @Translation("Decision"),
 *   label_collection = @Translation("Decisions"),
 *   label_singular = @Translation("decision"),
 *   label_plural = @Translation("decisions"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_consensus\Access\ConsensusRecordAccessControlHandler",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "localnodes_consensus_round",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "canonical" = "/decision/{localnodes_consensus_round}",
 *   },
 * )
 */
class ConsensusRound extends ContentEntityBase implements DecisionRecordInterface {

  /**
   * Longest short title, in characters: the register and the feed card.
   */
  public const TITLE_LENGTH = 120;

  /**
   * Stored status while the round takes responses.
   */
  public const OPEN = 'open';

  /**
   * Derived status: past the deadline, not finalized yet.
   */
  public const ENDED_PENDING = 'ended_pending';

  /**
   * Terminal states, with the early agreed close.
   */
  public const AGREED = 'agreed';
  public const CLOSED_BLOCKED = 'closed_blocked';
  public const CLOSED_PARTICIPATION = 'closed_participation';
  public const CLOSED_NO_AGREE = 'closed_no_agree';
  public const WITHDRAWN = 'withdrawn';
  public const REPLACED = 'replaced';
  public const TERMINAL = [
    self::AGREED,
    self::CLOSED_BLOCKED,
    self::CLOSED_PARTICIPATION,
    self::CLOSED_NO_AGREE,
    self::WITHDRAWN,
    self::REPLACED,
  ];

  /**
   * Closing reasons.
   */
  public const REASON_ALL_AGREED = 'all_agreed';
  public const REASON_DEADLINE = 'deadline';
  public const REASON_ROSTER_CHANGED = 'roster_changed';
  public const REASON_WITHDRAWN = 'withdrawn';
  public const REASON_REPLACED = 'replaced';

  /**
   * Fields frozen at opening.
   */
  public const FROZEN = [
    'uuid',
    'gid',
    'method',
    'schema_version',
    'opened_at',
    'proposer',
    'facilitator',
    'text',
    'text_version',
    'predecessor',
    'decision_type',
    'visibility',
    'opening_roster',
    'rules_version',
    'quorum_numerator',
    'quorum_denominator',
    'quorum_required',
    'threshold_numerator',
    'threshold_denominator',
    'deadline',
    'amount_up_to_cents',
    'proposed_rules',
  ];

  /**
   * Fields written once, by the terminal transition.
   */
  public const CLOSING = ['status', 'closed_reason', 'closed_note', 'closed_at', 'closure_key'];

  /**
   * {@inheritdoc}
   *
   * The round has no title field; its page and links use this label.
   */
  public function label() {
    return $this->isNew() ? NULL : t('Decision @id', ['@id' => $this->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getRecordId(): int {
    return (int) $this->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getGroupId(): int {
    return (int) $this->get('gid')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getMethod(): string {
    return (string) $this->get('method')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaVersion(): int {
    return (int) $this->get('schema_version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOpenedAt(): int {
    return (int) $this->get('opened_at')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getText(): string {
    return (string) $this->get('text')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTextVersion(): int {
    return (int) $this->get('text_version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTextHash(): string {
    return hash('sha256', $this->getText());
  }

  /**
   * {@inheritdoc}
   */
  public function getPredecessorRecordId(): ?int {
    return $this->nullableInt('predecessor');
  }

  /**
   * {@inheritdoc}
   */
  public function getDecisionType(): string {
    return (string) $this->get('decision_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getVisibility(): string {
    return (string) $this->get('visibility')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOpeningRoster(): array {
    $ids = json_decode((string) $this->get('opening_roster')->value, TRUE);
    return is_array($ids) ? array_map('intval', $ids) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getRulesVersionId(): ?int {
    return $this->nullableInt('rules_version');
  }

  /**
   * {@inheritdoc}
   */
  public function getQuorum(): array {
    return [
      'numerator' => (int) $this->get('quorum_numerator')->value,
      'denominator' => (int) $this->get('quorum_denominator')->value,
      'required' => (int) $this->get('quorum_required')->value,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getThreshold(): ?array {
    $numerator = $this->nullableInt('threshold_numerator');
    $denominator = $this->nullableInt('threshold_denominator');
    return $numerator === NULL || $denominator === NULL ? NULL : ['numerator' => $numerator, 'denominator' => $denominator];
  }

  /**
   * {@inheritdoc}
   */
  public function getDeadline(): int {
    return (int) $this->get('deadline')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getAmountUpToCents(): ?int {
    return $this->nullableInt('amount_up_to_cents');
  }

  /**
   * {@inheritdoc}
   */
  public function getProposedRules(): ?array {
    $value = $this->get('proposed_rules')->value;
    if ($value === NULL || $value === '') {
      return NULL;
    }
    $rules = json_decode((string) $value, TRUE);
    return is_array($rules) ? $rules : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getProposerId(): int {
    return (int) $this->get('proposer')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getFacilitatorId(): int {
    return (int) $this->get('facilitator')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponses(): array {
    $current = [];
    foreach ($this->getResponseRevisions() as $revision) {
      $current[$revision['member']] = $revision;
    }
    ksort($current);
    return array_values($current);
  }

  /**
   * {@inheritdoc}
   */
  public function getResponseRevisions(): array {
    if ($this->isNew()) {
      return [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage(ConsensusResponse::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('round', $this->id())
      ->sort('id', 'ASC')
      ->execute();
    $revisions = [];
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $response) {
      if ($response instanceof ConsensusResponse) {
        $revisions[] = $response->toRecord();
      }
    }
    return $revisions;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    $status = $this->getStoredStatus();
    if ($status === self::OPEN && \Drupal::time()->getCurrentTime() >= $this->getDeadline()) {
      return self::ENDED_PENDING;
    }
    return $status;
  }

  /**
   * The stored status: open or a terminal state.
   */
  public function getStoredStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * Whether the stored status is terminal.
   */
  public function isTerminal(): bool {
    return in_array($this->getStoredStatus(), self::TERMINAL, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getClosedState(): ?string {
    return $this->isTerminal() ? $this->getStoredStatus() : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getClosedReason(): ?string {
    return $this->nullableString('closed_reason');
  }

  /**
   * {@inheritdoc}
   */
  public function getClosedNote(): ?string {
    return $this->nullableString('closed_note');
  }

  /**
   * {@inheritdoc}
   */
  public function getClosedAt(): ?int {
    return $this->nullableInt('closed_at');
  }

  /**
   * The stable closure key written with the terminal state, or NULL.
   */
  public function getClosureKey(): ?string {
    return $this->nullableString('closure_key');
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew()) {
      $this->checkNew();
      return;
    }
    // Always the stored row: a copy left from a refused save keeps a
    // stale $this->original, which must never stand in for it.
    $original = $storage->loadUnchanged($this->id());
    if (!$original instanceof self) {
      throw new \LogicException('A decision record is saved only over its stored original.');
    }
    // The one save that closes a round has an open original; after it the
    // record never changes, not even by a save with no change.
    if ($original->isTerminal()) {
      throw new \LogicException('A closed decision record never changes.');
    }
    foreach (self::FROZEN as $name) {
      if (self::plain($this, $name) !== self::plain($original, $name)) {
        throw new \LogicException("A decision record's frozen input never changes: $name.");
      }
    }
    if ($this->isTerminal()) {
      if ($this->getClosedReason() === NULL || $this->getClosedAt() === NULL || $this->getClosureKey() === NULL) {
        throw new \LogicException('A closed decision record has a reason, a time and a closure key.');
      }
    }
    elseif ($this->getStoredStatus() !== self::OPEN) {
      throw new \LogicException('Unknown decision status.');
    }
    else {
      foreach (array_diff(self::CLOSING, ['status']) as $name) {
        if (!$this->get($name)->isEmpty()) {
          throw new \LogicException("An open decision record has no closing value: $name.");
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Decision records are permanent.
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    parent::preDelete($storage, $entities);
    throw new \LogicException('A decision record is never deleted.');
  }

  /**
   * Refuses a new round missing a frozen input.
   */
  protected function checkNew(): void {
    $type = $this->getDecisionType();
    $quorum = $this->getQuorum();
    $roster = $this->getOpeningRoster();
    $missing = match (TRUE) {
      $this->getGroupId() < 1 => 'gid',
      $this->getMethod() === '' => 'method',
      $this->getSchemaVersion() !== self::SCHEMA_VERSION => 'schema_version',
      $this->getOpenedAt() < 1 => 'opened_at',
      $this->getProposerId() < 1 => 'proposer',
      $this->getFacilitatorId() < 1 => 'facilitator',
      trim($this->getText()) === '' => 'text',
      $this->getTextVersion() < 1 => 'text_version',
      !in_array($type, DecisionRules::TYPES, TRUE) => 'decision_type',
      !in_array($this->getVisibility(), ['public', 'community', 'group'], TRUE) => 'visibility',
      $roster === [] => 'opening_roster',
      $quorum['denominator'] < 1 || $quorum['numerator'] < 1 || $quorum['required'] < 1 || $quorum['required'] > count($roster) => 'quorum',
      $this->getDeadline() <= $this->getOpenedAt() => 'deadline',
      ($type === 'money') !== (($this->getAmountUpToCents() ?? 0) > 0) => 'amount_up_to_cents',
      ($type === 'rule_change') !== ($this->getProposedRules() !== NULL) => 'proposed_rules',
      $this->getStoredStatus() !== self::OPEN => 'status',
      default => NULL,
    };
    if ($missing !== NULL) {
      throw new \LogicException("A new decision record needs every frozen input: $missing.");
    }
    foreach (array_diff(self::CLOSING, ['status']) as $name) {
      if (!$this->get($name)->isEmpty()) {
        throw new \LogicException("A new decision record has no closing value: $name.");
      }
    }
  }

  /**
   * A field's main property values as strings, for comparison.
   *
   * @return array<int, string|null>
   *   Values.
   */
  public static function plain(FieldableEntityInterface $entity, string $name): array {
    $values = [];
    foreach ($entity->get($name) as $item) {
      $value = $item->get($item->mainPropertyName())->getValue();
      $values[] = $value === NULL ? NULL : (string) $value;
    }
    return $values;
  }

  /**
   * An integer field value, or NULL when empty.
   */
  protected function nullableInt(string $name): ?int {
    $value = $this->get($name)->isEmpty() ? NULL : $this->get($name)->first()->get($this->get($name)->first()->mainPropertyName())->getValue();
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * A string field value, or NULL when empty.
   */
  protected function nullableString(string $name): ?string {
    $value = $this->get($name)->value;
    return $value === NULL || $value === '' ? NULL : (string) $value;
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
    $fields['method'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Method'))
      ->setSetting('max_length', 32)
      ->setRequired(TRUE);
    $fields['schema_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Schema version'))
      ->setRequired(TRUE);
    $fields['opened_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Opened'))
      ->setRequired(TRUE);
    $fields['proposer'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Proposed by'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);
    $fields['facilitator'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Facilitator'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);
    $fields['text'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Text'))
      ->setRequired(TRUE);
    $fields['text_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Text version'))
      ->setRequired(TRUE);
    $fields['predecessor'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Replaces'));
    $fields['decision_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Decision type'))
      ->setSetting('max_length', 32)
      ->setRequired(TRUE);
    $fields['visibility'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Visibility'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);
    $fields['opening_roster'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Opening roster'))
      ->setRequired(TRUE);
    $fields['rules_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Rules version'));
    foreach (['quorum_numerator', 'quorum_denominator', 'quorum_required'] as $name) {
      $fields[$name] = BaseFieldDefinition::create('integer')
        ->setLabel(t('Quorum'))
        ->setRequired(TRUE);
    }
    foreach (['threshold_numerator', 'threshold_denominator'] as $name) {
      $fields[$name] = BaseFieldDefinition::create('integer')
        ->setLabel(t('Threshold'));
    }
    $fields['deadline'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Deadline'))
      ->setRequired(TRUE);
    $fields['amount_up_to_cents'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Up to (cents)'))
      ->setSetting('size', 'big');
    $fields['proposed_rules'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Proposed rules'));
    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setSetting('max_length', 32)
      ->setRequired(TRUE);
    $fields['closed_reason'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Closed because'))
      ->setSetting('max_length', 32);
    $fields['closed_note'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Closing note'));
    $fields['closed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Closed'));
    $fields['closure_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Closure key'))
      ->setSetting('max_length', 128);

    return $fields;
  }

}
