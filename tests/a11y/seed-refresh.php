<?php

/**
 * @file
 * Seeds a report with a duplicate web row for the refresh dialog (22-24).
 *
 * Run with: ddev drush php:script tests/a11y/seed-refresh.php
 * (after tests/a11y/seed.php and tests/a11y/seed-source-box.php, which
 * create the "A11y Garden" group and its imported web Topic).
 *
 * The run is built directly: one finished web run whose only row is
 * "Already here" for the Source box fixture's web Topic, as a second import
 * of the same address leaves it. Nothing is fetched. The refresh itself is
 * never submitted by the spec, so the Topic doesn't change.
 *
 * Idempotent: a rerun finds the run by its row and creates nothing. Nothing
 * is deleted. Prints LN_REFRESH_RUN=<run id> and LN_REFRESH_NID=<nid> for
 * tests/a11y/web.spec.ts.
 */

use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;

const REFRESH_GROUP_LABEL = 'A11y Garden';
const REFRESH_WEB_TITLE = 'Bioregionalism (Source box fixture)';
const REFRESH_ADDRESS = 'https://wiki.p2pfoundation.net/Bioregionalism';

$entity_type_manager = \Drupal::entityTypeManager();
$groups = $entity_type_manager->getStorage('group')
  ->loadByProperties(['type' => 'flexible_group', 'label' => REFRESH_GROUP_LABEL]);
ksort($groups);
$group = $groups ? reset($groups) : NULL;
if (!$group instanceof GroupInterface) {
  fwrite(STDERR, "Group '" . REFRESH_GROUP_LABEL . "' not found. Run tests/a11y/seed.php first.\n");
  exit(1);
}

$topic = NULL;
foreach ($group->getRelatedEntities('group_node:topic') as $node) {
  if ($node->label() === REFRESH_WEB_TITLE) {
    $topic = $node;
    break;
  }
}
if ($topic === NULL) {
  fwrite(STDERR, "Topic '" . REFRESH_WEB_TITLE . "' not found. Run tests/a11y/seed-source-box.php first.\n");
  exit(1);
}

$items = $entity_type_manager->getStorage('localnodes_import_item');
$existing = $items->getQuery()->accessCheck(FALSE)
  ->condition('gid', $group->id())
  ->condition('nid', $topic->id())
  ->condition('outcome', ImportItem::OUTCOME_DUPLICATE)
  ->sort('id')
  ->range(0, 1)
  ->execute();
if ($existing) {
  $run_id = (int) $items->load(reset($existing))->get('run')->target_id;
}
else {
  $owner = (int) $group->getOwnerId();
  $time = \Drupal::time()->getRequestTime();
  $run = $entity_type_manager->getStorage('localnodes_import_run')->create([
    'gid' => $group->id(),
    'uid' => $owner,
    'kind' => ImportRun::KIND_WEB,
    'status' => ImportRun::STATUS_FINISHED,
    'visibility' => 'public',
    'rights_confirmed_by' => $owner,
    'rights_confirmed_at' => $time,
    'finished' => $time,
  ]);
  $run->save();
  $items->create([
    'run' => $run->id(),
    'gid' => $group->id(),
    'source_name' => REFRESH_ADDRESS,
    'canonical_url' => REFRESH_ADDRESS,
    'outcome' => ImportItem::OUTCOME_DUPLICATE,
    'nid' => $topic->id(),
  ])->save();
  $run_id = (int) $run->id();
}

print 'LN_REFRESH_RUN=' . $run_id . PHP_EOL;
print 'LN_REFRESH_NID=' . $topic->id() . PHP_EOL;
