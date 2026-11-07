<?php

/**
 * @file
 * Read-only dev-site check: every INSTALLED field storage definition of the
 * Web3 entity types has its database column.
 *
 * Why: the safe_transaction `description` field definition was installed
 * (06de8ae) but its column never created, so every proposal INSERT failed
 * ("Unknown column 'description'", Phase 21 Run 1b step c). Core's status
 * report can't see this: the installed definition matches the code.
 * Fresh installs WERE affected too, until safe_smart_accounts dropped its
 * hook_schema() (21-25 fix 5): hook_schema made safe_transaction without the
 * column, and core then skipped creating the entity's own table. A site
 * installed before that fix, or a long-lived dev database, can still drift.
 *
 * Usage: ddev drush php:script scripts/ops/check-entity-column-drift.php
 * Exit: prints OK or DRIFT lines; changes nothing.
 */

$etm = \Drupal::entityTypeManager();
$repo = \Drupal::service('entity.last_installed_schema.repository');
$schema = \Drupal::database()->schema();
$missing = [];
foreach (['safe_account', 'safe_transaction', 'safe_configuration'] as $et) {
  if (!$etm->hasDefinition($et)) {
    continue;
  }
  $storage = $etm->getStorage($et);
  if (!$storage instanceof \Drupal\Core\Entity\Sql\SqlEntityStorageInterface) {
    // Config entities (safe_configuration) have no field tables.
    continue;
  }
  $installed = $repo->getLastInstalledFieldStorageDefinitions($et);
  $map = $storage->getTableMapping($installed);
  foreach ($installed as $name => $def) {
    foreach ($map->getAllFieldTableNames($name) as $table) {
      if (!$schema->tableExists($table)) {
        $missing[] = "$et.$name: table $table missing";
        continue;
      }
      foreach ($map->getColumnNames($name) as $column) {
        if (!$schema->fieldExists($table, $column)) {
          $missing[] = "$et.$name: column $table.$column missing";
        }
      }
    }
  }
}
echo $missing
  ? "DRIFT:\n  " . implode("\n  ", $missing) . "\n"
  : "OK: every installed field of the SQL-stored Safe entity types has its column\n";
