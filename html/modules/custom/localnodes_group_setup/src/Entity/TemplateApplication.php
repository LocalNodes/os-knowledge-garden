<?php

declare(strict_types=1);

namespace Drupal\localnodes_group_setup\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * A Group's set-up record: one confirmed application of a Group template.
 *
 * Holds the Group, its kind, the template id and version, the values
 * confirmed at apply (`applied_snapshot`, history only: nothing reads it as
 * live values), the ids of the starter content it generated, the link to
 * each record another module seeded from it, the community-wide flag passed
 * in at apply, a step ledger, the status, who confirmed and when, and an
 * append-only change history.
 *
 * Base fields only; no bundles, forms or Field UI. There is no admin
 * permission and no access handler beyond Drupal's default, so no entity
 * operation is allowed through the entity API: the record is read and
 * changed only through TemplateApplicationManager, and screens render it
 * after their own access check.
 *
 * Structured values are stored as JSON text so a save can compare them
 * exactly with what was stored before. preSave() refuses, whoever saves:
 * - any change to the Group, kind, template, version, snapshot, confirmer
 *   or confirmation time;
 * - replacing or removing a stored link, and adding one once the record is
 *   no longer in progress (a link is written once, empty to id, while the
 *   application runs);
 * - rewriting history or the step ledger (both only grow);
 * - leaving a final status (applied or refused);
 * - changing the community-wide flag, or the generated content of an
 *   applied record, without a new history entry.
 *
 * @ContentEntityType(
 *   id = "localnodes_template_application",
 *   label = @Translation("Group set-up record"),
 *   label_collection = @Translation("Group set-up records"),
 *   label_singular = @Translation("Group set-up record"),
 *   label_plural = @Translation("Group set-up records"),
 *   base_table = "localnodes_template_application",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 * )
 */
class TemplateApplication extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  public const STATUS_IN_PROGRESS = 'in_progress';
  public const STATUS_APPLIED = 'applied';
  public const STATUS_REFUSED = 'refused';

  /**
   * The id of the Group this record sets up.
   */
  public function getGroupId(): int {
    return (int) $this->get('gid')->target_id;
  }

  /**
   * The kind of Group, as the template names it.
   */
  public function getKind(): string {
    return (string) $this->get('kind')->value;
  }

  /**
   * The applied template's id.
   */
  public function getTemplateId(): string {
    return (string) $this->get('template_id')->value;
  }

  /**
   * The applied template's version.
   */
  public function getTemplateVersion(): int {
    return (int) $this->get('template_version')->value;
  }

  /**
   * One of the STATUS_* constants.
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * Whether this Group is the community-wide one, as set by data.
   */
  public function isCommunityWide(): bool {
    return (bool) $this->get('community_wide')->value;
  }

  /**
   * When the template was confirmed.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * The values confirmed at apply. History only, never live values.
   *
   * Live values each have one owner elsewhere (the decision rules live in
   * the consensus module's per-Group rules record). This is the record's
   * own history accessor and the only way the snapshot is read.
   */
  public function getAppliedSnapshot(): array {
    return $this->json('applied_snapshot');
  }

  /**
   * Starter content ids by starter key, for items whose creation is noted.
   *
   * @return array<string, int>
   *   Node ids keyed by starter key.
   */
  public function getGeneratedIds(): array {
    $ids = [];
    foreach ($this->json('generated') as $key => $entry) {
      if (isset($entry['id'])) {
        $ids[$key] = (int) $entry['id'];
      }
    }
    return $ids;
  }

  /**
   * The full generated-content ledger: uuid and id (NULL while pending).
   *
   * @return array<string, array{uuid: string, id: int|null}>
   *   Entries keyed by starter key.
   */
  public function getGeneratedLedger(): array {
    return $this->json('generated');
  }

  /**
   * Links to records other modules seeded from this one, by name.
   *
   * @return array<string, int>
   *   Record ids keyed by link name (for example decision_rules).
   */
  public function getLinks(): array {
    return array_map('intval', $this->json('links'));
  }

  /**
   * One link, or NULL when it has not been made.
   */
  public function getLink(string $name): ?int {
    return $this->getLinks()[$name] ?? NULL;
  }

  /**
   * The step ledger: steps done, in order.
   *
   * @return string[]
   *   Step names.
   */
  public function getSteps(): array {
    return $this->json('steps');
  }

  /**
   * The append-only change history, oldest first.
   *
   * Each entry: action, uid, at, and where relevant reason, before, after.
   */
  public function getHistory(): array {
    return $this->json('history');
  }

  /**
   * Notes a starter item as pending (uuid chosen) or created (id known).
   *
   * @internal
   *   Only TemplateApplicationManager calls this.
   */
  public function setGenerated(string $key, string $uuid, ?int $id): static {
    $generated = $this->json('generated');
    $generated[$key] = ['uuid' => $uuid, 'id' => $id];
    return $this->setJson('generated', $generated);
  }

  /**
   * Replaces the generated ledger (a correction).
   *
   * @internal
   *   Only TemplateApplicationManager calls this.
   */
  public function setGeneratedLedger(array $ledger): static {
    return $this->setJson('generated', $ledger);
  }

  /**
   * Adds a link to a record another module seeded from this one.
   *
   * @internal
   *   Only TemplateApplicationManager calls this; a save refuses replacing or
   *   removing a link once stored.
   */
  public function addLink(string $name, int $id): static {
    $links = $this->json('links');
    $links[$name] = $id;
    return $this->setJson('links', $links);
  }

  /**
   * Notes a step as done.
   *
   * @internal
   *   Only TemplateApplicationManager calls this.
   */
  public function addStep(string $step): static {
    $steps = $this->json('steps');
    $steps[] = $step;
    return $this->setJson('steps', $steps);
  }

  /**
   * Appends one history entry.
   *
   * @internal
   *   Only TemplateApplicationManager calls this.
   */
  public function appendHistory(array $entry): static {
    $history = $this->json('history');
    $history[] = $entry;
    return $this->setJson('history', $history);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);
    if ($this->isNew()) {
      return;
    }
    $original = $storage->loadUnchanged($this->id());
    if (!$original instanceof self) {
      return;
    }
    foreach (['gid', 'kind', 'template_id', 'template_version', 'applied_snapshot', 'uid', 'created'] as $field) {
      if ($this->get($field)->getString() !== $original->get($field)->getString()) {
        throw new \LogicException("A set-up record's $field never changes.");
      }
    }

    $was = $original->getStatus();
    if ($was !== self::STATUS_IN_PROGRESS && $this->getStatus() !== $was) {
      throw new \LogicException('A set-up record keeps its final status.');
    }
    if (!in_array($this->getStatus(), [self::STATUS_IN_PROGRESS, self::STATUS_APPLIED, self::STATUS_REFUSED], TRUE)) {
      throw new \LogicException('A set-up record has a known status.');
    }

    $links = $this->getLinks();
    foreach ($original->getLinks() as $name => $id) {
      if (($links[$name] ?? NULL) !== $id) {
        throw new \LogicException("A set-up record's $name link is written once.");
      }
    }
    if (count($links) > count($original->getLinks()) && $was !== self::STATUS_IN_PROGRESS) {
      throw new \LogicException('A set-up record gains links only while its application runs.');
    }

    foreach (['history', 'steps'] as $field) {
      $before = $original->json($field);
      if (array_slice($this->json($field), 0, count($before)) !== $before) {
        throw new \LogicException("A set-up record's $field only grows.");
      }
    }

    $corrected = $this->isCommunityWide() !== $original->isCommunityWide()
      || ($was === self::STATUS_APPLIED && $this->getGeneratedLedger() !== $original->getGeneratedLedger());
    if ($corrected && count($this->getHistory()) <= count($original->getHistory())) {
      throw new \LogicException('A correction to a set-up record is saved with its history.');
    }
  }

  /**
   * Decodes a JSON text field; an empty field is an empty array.
   */
  protected function json(string $field): array {
    $value = (string) $this->get($field)->value;
    $decoded = $value === '' ? [] : json_decode($value, TRUE, 64, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Encodes a value into a JSON text field.
   */
  protected function setJson(string $field, array $value): static {
    $this->set($field, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(t('Confirmed by'));

    $fields['gid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Group'))
      ->setSetting('target_type', 'group')
      ->setRequired(TRUE);

    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Kind'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE);

    $fields['template_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Template'))
      ->setSetting('max_length', 64)
      ->setRequired(TRUE);

    $fields['template_version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Template version'))
      ->setRequired(TRUE);

    $fields['applied_snapshot'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Values confirmed at apply (history only)'))
      ->setRequired(TRUE);

    $fields['generated'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Generated content'));

    $fields['links'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Seeded records'));

    $fields['community_wide'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Community-wide Group'))
      ->setDefaultValue(FALSE);

    $fields['steps'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Steps done'));

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_IN_PROGRESS);

    $fields['history'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Change history'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Confirmed'));

    return $fields;
  }

}
