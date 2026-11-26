<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * One version of a Group's live decision rules.
 *
 * Each row is one append-only version: the Group, a version number unique
 * per Group, the path (simple or advanced), the rules map for each decision
 * type, where the version came from (the set-up record it was seeded from,
 * or the decision that changed the rules), who changed them, when, and an
 * optional reason. The latest version per Group is live; a Group with no
 * version reads the module defaults (localnodes_consensus.settings).
 *
 * Quorum is stored as a share (numerator and denominator); each decision
 * rounds it up to a head-count of its opening roster when it opens.
 *
 * preSave() refuses, whoever saves:
 * - any change to a saved version (history never changes; deletes are
 *   refused too);
 * - a version with neither, or both, a set-up source and a decision;
 * - a version number the Group already has;
 * - a second version for the same Group and decision;
 * - a seeded version for a Group that already has any version;
 * - rules that DecisionRulesManager::validate() refuses.
 *
 * Base fields only; no bundles, forms or Field UI. There is no admin
 * permission. Viewing goes through the one reader decision with visibility
 * group (ConsensusRecordAccessControlHandler); creating, changing and
 * deleting through the entity API are refused for everyone, so only
 * DecisionRulesManager writes it.
 *
 * @ContentEntityType(
 *   id = "localnodes_decision_rules",
 *   label = @Translation("Decision rules"),
 *   label_collection = @Translation("Decision rules"),
 *   label_singular = @Translation("decision rules version"),
 *   label_plural = @Translation("decision rules versions"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_consensus\Access\ConsensusRecordAccessControlHandler",
 *   },
 *   base_table = "localnodes_decision_rules",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 * )
 */
class DecisionRules extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  /**
   * The decision types, in order.
   */
  public const TYPES = ['everyday', 'money', 'rule_change'];

  /**
   * The paths: one shared rule, or one rule per type.
   */
  public const PATH_SIMPLE = 'simple';
  public const PATH_ADVANCED = 'advanced';

  /**
   * The one method implemented now. The stored method keeps the record
   * method-neutral: another method adds its own evaluator behind it.
   */
  public const METHOD_CONSENSUS = 'consensus';

  /**
   * The keys of one type's rule, in order.
   */
  public const RULE_KEYS = [
    'method',
    'quorum_numerator',
    'quorum_denominator',
    'threshold_numerator',
    'threshold_denominator',
    'response_days',
  ];

  /**
   * The id of the Group these rules belong to.
   */
  public function getGroupId(): int {
    return (int) $this->get('gid')->target_id;
  }

  /**
   * The version number, unique per Group, starting at 1.
   */
  public function getVersion(): int {
    return (int) $this->get('version')->value;
  }

  /**
   * The path: simple or advanced.
   */
  public function getPath(): string {
    return (string) $this->get('path')->value;
  }

  /**
   * The full rules array: path, then each type's rule.
   *
   * @return array{path: string, everyday: array, money: array, rule_change: array}
   *   The rules, in the same shape set-up hands over.
   */
  public function getRules(): array {
    $value = (string) $this->get('rules')->value;
    $map = $value === '' ? [] : json_decode($value, TRUE, 64, JSON_THROW_ON_ERROR);
    return ['path' => $this->getPath()] + (is_array($map) ? $map : []);
  }

  /**
   * The set-up record this version was seeded from, or NULL.
   */
  public function getSetupRecordId(): ?int {
    $value = $this->get('setup_record')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * The decision record that changed the rules to this version, or NULL.
   */
  public function getDecisionRecordId(): ?int {
    $value = $this->get('decision_record')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * When this version was saved.
   */
  public function getChangedTime(): int {
    return (int) $this->get('changed_at')->value;
  }

  /**
   * Why the rules changed, if given.
   */
  public function getReason(): ?string {
    $value = $this->get('reason')->value;
    return $value === NULL ? NULL : (string) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if (!$this->isNew()) {
      throw new \LogicException('A decision rules version never changes; save a new version.');
    }
    $setup = $this->getSetupRecordId();
    $decision = $this->getDecisionRecordId();
    if (($setup === NULL) === ($decision === NULL)) {
      throw new \LogicException('A decision rules version comes from set-up or from one decision.');
    }
    if (($setup ?? $decision) < 1 || $this->getVersion() < 1) {
      throw new \LogicException('A decision rules version has positive ids and version number.');
    }
    $existing = function (array $conditions) use ($storage): bool {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('gid', $this->getGroupId());
      foreach ($conditions as $field => $value) {
        $query->condition($field, $value);
      }
      return (bool) $query->count()->execute();
    };
    if ($existing(['version' => $this->getVersion()])) {
      throw new \LogicException('This Group already has that rules version.');
    }
    if ($decision !== NULL && $existing(['decision_record' => $decision])) {
      throw new \LogicException('This decision already changed the rules.');
    }
    if ($setup !== NULL && $existing([])) {
      throw new \LogicException('Set-up seeds only a Group with no rules version.');
    }
    \Drupal::service('localnodes_consensus.decision_rules')->validate($this->getRules());
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities): void {
    throw new \LogicException('Decision rules versions are never deleted.');
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(t('Changed by'));

    $fields['gid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Group'))
      ->setSetting('target_type', 'group')
      ->setRequired(TRUE);

    $fields['version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version'))
      ->setSetting('unsigned', TRUE)
      ->setRequired(TRUE);

    $fields['path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Path'))
      ->setDescription(t('simple (one shared rule) or advanced (one rule per type).'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE);

    $fields['rules'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Rules for each type of decision'))
      ->setRequired(TRUE);

    // Plain ids, not entity references: the set-up record lives in another
    // module this one does not depend on, and the decision record is added
    // later in this module.
    $fields['setup_record'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Seeded from set-up record'))
      ->setSetting('unsigned', TRUE);

    $fields['decision_record'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Changed by decision'))
      ->setSetting('unsigned', TRUE);

    $fields['changed_at'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Changed'));

    $fields['reason'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Reason'));

    return $fields;
  }

}
