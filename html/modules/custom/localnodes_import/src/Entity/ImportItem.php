<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * One source (file or web page) of an import run, and what became of it.
 *
 * The row is the durable report (D-21): it is saved as "processing" before
 * the risky step and updated with the final outcome afterwards, so a crash
 * cannot erase the record. Base fields only.
 *
 * @ContentEntityType(
 *   id = "localnodes_import_item",
 *   label = @Translation("Import item"),
 *   label_collection = @Translation("Import items"),
 *   label_singular = @Translation("import item"),
 *   label_plural = @Translation("import items"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_import\Access\ImportLogAccessControlHandler",
 *     "storage_schema" = "Drupal\localnodes_import\Entity\ImportItemStorageSchema",
 *   },
 *   base_table = "localnodes_import_item",
 *   admin_permission = "administer group",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "source_name",
 *   },
 * )
 */
class ImportItem extends ContentEntityBase implements EntityChangedInterface {

  use EntityChangedTrait;

  public const OUTCOME_QUEUED = 'queued';
  public const OUTCOME_PROCESSING = 'processing';
  public const OUTCOME_IMPORTED = 'imported';
  public const OUTCOME_NOT_SEARCHABLE = 'imported_not_searchable';
  public const OUTCOME_DUPLICATE = 'duplicate';
  public const OUTCOME_REFRESHED = 'refreshed';
  public const OUTCOME_FAILED = 'failed';
  public const OUTCOME_NOT_UPLOADED = 'not_uploaded';
  public const OUTCOME_REFUSED = 'refused';
  public const OUTCOME_DELETED = 'deleted';

  /**
   * Outcomes that make a Topic an imported page (Source box, report link).
   *
   * Duplicate and failed rows can name a Topic too, but don't make it
   * imported.
   */
  public const IMPORTED_OUTCOMES = [
    self::OUTCOME_IMPORTED,
    self::OUTCOME_NOT_SEARCHABLE,
    self::OUTCOME_REFRESHED,
  ];

  /**
   * The id of the group the source was imported into.
   */
  public function getGroupId(): ?int {
    $gid = $this->get('gid')->value;
    return $gid === NULL ? NULL : (int) $gid;
  }

  /**
   * The id of the run this item belongs to.
   */
  public function getRunId(): ?int {
    $run = $this->get('run')->target_id;
    return $run === NULL ? NULL : (int) $run;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['run'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Import run'))
      ->setSetting('target_type', 'localnodes_import_run')
      ->setRequired(TRUE);

    // Denormalised from the run for group-wide status queries (S5).
    $fields['gid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Group ID'))
      ->setSetting('unsigned', TRUE)
      ->setRequired(TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Submitted order'))
      ->setDefaultValue(0);

    $fields['source_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setDescription(t('Original file name or web address.'))
      ->setSetting('max_length', 2048);

    $fields['outcome'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Outcome'))
      ->setSetting('max_length', 32)
      ->setRequired(TRUE)
      ->setDefaultValue(self::OUTCOME_QUEUED);

    $fields['reason'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Reason code'))
      ->setSetting('max_length', 64);

    $fields['nid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Topic'))
      ->setSetting('target_type', 'node');

    $fields['fid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('File ID'))
      ->setSetting('unsigned', TRUE);

    $fields['content_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Content hash'))
      ->setSetting('max_length', 64);

    $fields['canonical_url'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Canonical address'))
      ->setSetting('max_length', 2048);

    $fields['revid'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Source revision id'))
      ->setSetting('unsigned', TRUE);

    $fields['search_item_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Search item id'))
      // copy-allow: developer-only field description; no form shows it.
      ->setDescription(t('entity:node/{nid}:{langcode}, the search_api_item key.'))
      ->setSetting('max_length', 255);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
