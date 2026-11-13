<?php

namespace Drupal\localnodes_notice_test\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\Annotation\ContentEntityType;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * A stored activity whose related object is a real entity.
 *
 * @ContentEntityType(
 *   id = "activity",
 *   label = @Translation("Notice test activity"),
 *   base_table = "localnodes_notice_test_activity",
 *   entity_keys = {"id" = "id"}
 * )
 */
final class NoticeActivity extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);
    $fields['related_type'] = BaseFieldDefinition::create('string')->setRequired(TRUE);
    $fields['related_id'] = BaseFieldDefinition::create('integer')->setRequired(TRUE);
    $fields['field_activity_recipient_group'] = BaseFieldDefinition::create('entity_reference')
      ->setSetting('target_type', 'group');
    $fields['field_activity_message'] = BaseFieldDefinition::create('entity_reference')
      ->setSetting('target_type', 'message');
    return $fields;
  }

  public function getRelatedEntity(): ?EntityInterface {
    return $this->entityTypeManager()
      ->getStorage($this->get('related_type')->value)
      ->load($this->get('related_id')->value);
  }

}
