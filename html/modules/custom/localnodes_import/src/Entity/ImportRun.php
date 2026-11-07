<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * One import run: a set of files or web pages a steward imported at once.
 *
 * Base fields only (D-21): the schema installs with the module, there are no
 * bundles, forms or Field UI. Counts are computed from the run's items.
 *
 * @ContentEntityType(
 *   id = "localnodes_import_run",
 *   label = @Translation("Import run"),
 *   label_collection = @Translation("Import runs"),
 *   label_singular = @Translation("import run"),
 *   label_plural = @Translation("import runs"),
 *   handlers = {
 *     "access" = "Drupal\localnodes_import\Access\ImportLogAccessControlHandler",
 *   },
 *   base_table = "localnodes_import_run",
 *   admin_permission = "administer group",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 * )
 */
class ImportRun extends ContentEntityBase implements EntityOwnerInterface {

  use EntityOwnerTrait;

  public const KIND_FILES = 'files';
  public const KIND_WEB = 'web';

  public const STATUS_IN_PROGRESS = 'in_progress';
  public const STATUS_FINISHED = 'finished';
  public const STATUS_STOPPED = 'stopped';

  /**
   * The id of the group this run imported into.
   */
  public function getGroupId(): ?int {
    $gid = $this->get('gid')->target_id;
    return $gid === NULL ? NULL : (int) $gid;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['gid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Group'))
      ->setSetting('target_type', 'group')
      ->setRequired(TRUE);

    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Kind'))
      ->setDescription(t('files or web.'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE)
      ->setDefaultValue(self::KIND_FILES);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('in_progress, finished or stopped.'))
      ->setSetting('max_length', 16)
      ->setRequired(TRUE)
      ->setDefaultValue(self::STATUS_IN_PROGRESS);

    $fields['visibility'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Visibility'))
      ->setDescription(t('field_content_visibility value given to every imported Topic.'))
      ->setSetting('max_length', 32)
      ->setDefaultValue('group');

    // The optional upload licence text, exactly as typed (trimmed). Empty for
    // web and refresh runs: each web page records its own detected licence
    // (D-25). 255 matches field_source_license_text.
    $fields['license'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Licence'))
      ->setSetting('max_length', 255);

    $fields['credit'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Credit'))
      ->setSetting('max_length', 255);

    // D-25b: who ticked "I have the right to share these here", and when.
    // Set by the form from the current user and the request time, never
    // from posted values.
    $fields['rights_confirmed_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Rights confirmed by'))
      ->setSetting('target_type', 'user');

    $fields['rights_confirmed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Rights confirmed at'));

    // D-26: the topic type every Topic of this run gets (default Reference).
    $fields['topic_type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Type of page'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler', 'default:taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['topic_types' => 'topic_types']]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Started'));

    $fields['finished'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Finished'));

    return $fields;
  }

}
