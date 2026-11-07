<?php

/**
 * @file
 * Seeds the S1 and S5 states of the Import tab (plan 22-25).
 *
 * Run with: ddev drush php:script tests/a11y/seed-status.php
 * (after tests/a11y/seed.php, which creates the a11y-steward persona).
 *
 * Two groups of their own, so the other specs' "A11y Garden" is left as it
 * is, both with a11y-steward as group manager:
 * - "A11y Status Garden": one files run with two searchable pages, two
 *   pages waiting for two hours (the stale-worker alert) and one scanned PDF
 *   with no readable text, plus 51 older runs, so Past imports has a second
 *   page (52 runs, 50 per page);
 * - "A11y Empty Garden": no imports, for the S1 empty state.
 *
 * The Topics are built directly with their import item rows; nothing is
 * fetched or extracted, and no page is embedded: the Topics are taken out
 * of this request's immediate indexing (as seed-source-box.php does), and
 * their social_posts tracker rows are set by hand. Searchable pages are
 * marked indexed, so a later cron run never embeds these fictional pages;
 * waiting pages are marked waiting with a change two hours ago.
 *
 * Idempotent: a rerun finds the groups by label and the main run by its
 * first row, creates nothing twice and deletes nothing. Every run resets the
 * fixture's tracker rows, so a cron run in between doesn't change the state
 * the spec checks. Prints LN_STATUS_GROUP=<gid> and LN_EMPTY_GROUP=<gid>.
 */

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

const STATUS_GROUP_LABEL = 'A11y Status Garden';
const STATUS_EMPTY_LABEL = 'A11y Empty Garden';
const STATUS_MANAGER_ROLE = 'flexible_group-group_manager';
const STATUS_FIRST_ROW = 'tool-share-rules-status-fixture.pdf';
const STATUS_PAST_RUNS = 51;

$entity_type_manager = \Drupal::entityTypeManager();
$database = \Drupal::database();
$now = (int) \Drupal::time()->getRequestTime();

$stewards = $entity_type_manager->getStorage('user')->loadByProperties(['name' => 'a11y-steward']);
$steward = $stewards ? reset($stewards) : NULL;
if (!$steward instanceof UserInterface) {
  fwrite(STDERR, "User a11y-steward not found. Run tests/a11y/seed.php first.\n");
  exit(1);
}

/**
 * Finds the group by label, or creates it, with the steward as manager.
 */
function status_group(string $label, UserInterface $steward): GroupInterface {
  $groups = \Drupal::entityTypeManager()->getStorage('group')
    ->loadByProperties(['type' => 'flexible_group', 'label' => $label]);
  ksort($groups);
  $group = $groups ? reset($groups) : NULL;
  if (!$group instanceof GroupInterface) {
    $group = Group::create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $steward->id(),
      'field_flexible_group_visibility' => 'community',
      'field_group_allowed_visibility' => ['public', 'community', 'group'],
      'field_group_allowed_join_method' => ['direct'],
    ]);
    $group->save();
  }
  $membership = $group->getMember($steward);
  if (!$membership) {
    $group->addMember($steward, ['group_roles' => [STATUS_MANAGER_ROLE]]);
  }
  elseif (!array_key_exists(STATUS_MANAGER_ROLE, $membership->getRoles(FALSE))) {
    $membership->addRole(STATUS_MANAGER_ROLE);
  }
  return $group;
}

$group = status_group(STATUS_GROUP_LABEL, $steward);
$empty = status_group(STATUS_EMPTY_LABEL, $steward);

$runs = $entity_type_manager->getStorage('localnodes_import_run');
$items = $entity_type_manager->getStorage('localnodes_import_item');

$existing = $items->getQuery()->accessCheck(FALSE)
  ->condition('gid', $group->id())
  ->condition('source_name', STATUS_FIRST_ROW)
  ->range(0, 1)
  ->execute();

$created_nodes = [];
if (!$existing) {
  // Older runs first, so the main run is the newest.
  for ($i = 1; $i <= STATUS_PAST_RUNS; $i++) {
    $run = $runs->create([
      'gid' => $group->id(),
      'uid' => $steward->id(),
      'kind' => $i % 3 === 0 ? ImportRun::KIND_WEB : ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_FINISHED,
      'created' => $now - 86400 * 30 + $i * 3600,
      'finished' => $now - 86400 * 30 + $i * 3600 + 60,
    ]);
    $run->save();
    $items->create([
      'run' => $run->id(),
      'gid' => $group->id(),
      'source_name' => sprintf('old-newsletter-%02d.pdf', $i),
      'outcome' => $i % 2 ? ImportItem::OUTCOME_DUPLICATE : ImportItem::OUTCOME_FAILED,
      'reason' => $i % 2 ? NULL : 'unreadable',
    ])->save();
  }

  $run = $runs->create([
    'gid' => $group->id(),
    'uid' => $steward->id(),
    'kind' => ImportRun::KIND_FILES,
    'status' => ImportRun::STATUS_FINISHED,
    'created' => $now - 3 * 3600,
    'finished' => $now - 3 * 3600 + 120,
  ]);
  $run->save();

  $pages = [
    [STATUS_FIRST_ROW, 'Tool share rules (status fixture)', ImportItem::OUTCOME_IMPORTED, NULL],
    ['orchard-pruning-notes-status-fixture.pdf', 'Orchard pruning notes (status fixture)', ImportItem::OUTCOME_IMPORTED, NULL],
    ['rainwater-barrels-status-fixture.pdf', 'Rainwater barrels (status fixture)', ImportItem::OUTCOME_IMPORTED, NULL],
    ['potluck-checklist-status-fixture.pdf', 'Potluck checklist (status fixture)', ImportItem::OUTCOME_IMPORTED, NULL],
    ['scan-0042-status-fixture.pdf', 'Garden committee minutes, March (status fixture)', ImportItem::OUTCOME_NOT_SEARCHABLE, 'scanned_pdf'],
  ];
  foreach ($pages as $weight => [$file_name, $title, $outcome, $reason]) {
    $node = Node::create([
      'type' => 'topic',
      'title' => $title,
      'body' => ['value' => '<p>A fictional page for the search status fixture.</p>', 'format' => 'basic_html'],
      'field_content_visibility' => 'group',
      'uid' => $steward->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:topic');
    $created_nodes[] = $node;
    $items->create([
      'run' => $run->id(),
      'gid' => $group->id(),
      'weight' => $weight,
      'source_name' => $file_name,
      'outcome' => $outcome,
      'reason' => $reason,
      'nid' => $node->id(),
      'search_item_id' => 'entity:node/' . $node->id() . ':' . $node->language()->getId(),
    ])->save();
  }
  $main_run = (int) $run->id();
}
else {
  $main_run = (int) $items->load(reset($existing))->get('run')->target_id;
}

// Indexes with index_directly would embed the new Topics when this script
// ends (a live AI provider call). Take them out of this request's indexing.
if ($created_nodes) {
  $post_request = \Drupal::service('search_api.post_request_indexing');
  foreach ($entity_type_manager->getStorage('search_api_index')->loadMultiple() as $index) {
    $ids = [];
    foreach ($created_nodes as $node) {
      $ids[] = 'entity:node/' . $node->id() . ':' . $node->language()->getId();
    }
    $post_request->removeFromIndexing((string) $index->id(), $ids);
  }
}

// The fixture's social_posts tracker rows: the first two searchable, the
// next two waiting for two hours, the scanned PDF (never searchable) left
// as it is. Reset on every run.
$rows = $items->loadMultiple($items->getQuery()->accessCheck(FALSE)
  ->condition('run', $main_run)
  ->sort('weight')
  ->execute());
$position = 0;
foreach ($rows as $item) {
  $search_id = $item->get('search_item_id')->value;
  if ($search_id === NULL || $item->get('outcome')->value !== ImportItem::OUTCOME_IMPORTED) {
    continue;
  }
  $searchable = $position < 2;
  $database->merge('search_api_item')
    ->keys(['index_id' => 'social_posts', 'item_id' => $search_id])
    ->fields([
      'datasource' => 'entity:node',
      'status' => $searchable ? 0 : 1,
      'changed' => $searchable ? $now - 3 * 3600 : $now - 2 * 3600,
    ])
    ->execute();
  $position++;
}

print 'LN_STATUS_GROUP=' . $group->id() . PHP_EOL;
print 'LN_EMPTY_GROUP=' . $empty->id() . PHP_EOL;
