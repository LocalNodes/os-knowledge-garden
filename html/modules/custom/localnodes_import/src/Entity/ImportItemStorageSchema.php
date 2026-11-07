<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Database indexes for the import item table (RESEARCH Pattern 9).
 *
 * - (gid, outcome): group-wide status queries ("waiting", "searchable") on
 *   the Import tab (S5, 22-25).
 * - (run): the run report. The entity_reference base field already gets
 *   localnodes_import_item_field__run__target_id from its field schema, so
 *   a second index on the same column would only cost writes.
 */
class ImportItemStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $base_table = $this->storage->getBaseTable();
    if ($base_table && isset($schema[$base_table])) {
      $schema[$base_table]['indexes'] += [
        'localnodes_import_item__gid_outcome' => ['gid', 'outcome'],
      ];
    }
    return $schema;
  }

}
