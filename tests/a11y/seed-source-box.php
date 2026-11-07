<?php

/**
 * @file
 * Seeds one imported web Topic and one imported file Topic (plan 22-20).
 *
 * Run with: ddev drush php:script tests/a11y/seed-source-box.php
 * (after tests/a11y/seed.php, which creates the "A11y Garden" group).
 *
 * The Topics are built directly, with their source fields and import item
 * rows, so no page is fetched and no file is extracted:
 * - web: field_source_url, field_source_license cc_by_sa_4_0 and the site
 *   name as attribution, with a quoted writer in the imported text;
 * - file: an attached private file, field_source_license not_stated, the
 *   organizer's licence text "CC BY 4.0" in field_source_license_text and a
 *   credit (D-25d).
 *
 * Search: the Topics are tracked as usual but taken out of this request's
 * immediate indexing, so the seed doesn't need an AI provider key.
 *
 * Idempotent: a rerun finds the Topics by title in the group and only fills
 * in what is missing. Nothing is deleted. Prints LN_SOURCE_WEB=<nid> and
 * LN_SOURCE_FILE=<nid> for tests/a11y/source-box.spec.ts.
 */

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

const SOURCE_BOX_GROUP_LABEL = 'A11y Garden';
const SOURCE_BOX_WEB_TITLE = 'Bioregionalism (Source box fixture)';
const SOURCE_BOX_FILE_TITLE = 'Garden committee minutes (Source box fixture)';
const SOURCE_BOX_ADDRESS = 'https://wiki.p2pfoundation.net/Bioregionalism';

$entity_type_manager = \Drupal::entityTypeManager();

$groups = $entity_type_manager->getStorage('group')
  ->loadByProperties(['type' => 'flexible_group', 'label' => SOURCE_BOX_GROUP_LABEL]);
ksort($groups);
$group = $groups ? reset($groups) : NULL;
if (!$group instanceof GroupInterface) {
  fwrite(STDERR, "Group '" . SOURCE_BOX_GROUP_LABEL . "' not found. Run tests/a11y/seed.php first.\n");
  exit(1);
}
$owner = $group->getOwnerId();

/**
 * Finds the group's Topic with this title, or creates it.
 */
function source_box_topic(GroupInterface $group, int $owner, string $title, string $visibility): NodeInterface {
  foreach ($group->getRelatedEntities('group_node:topic') as $node) {
    if ($node->label() === $title) {
      return $node;
    }
  }
  $node = Node::create([
    'type' => 'topic',
    'title' => $title,
    'uid' => $owner,
    'status' => NodeInterface::PUBLISHED,
    'field_content_visibility' => $visibility,
  ]);
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
    ->loadByProperties(['vid' => 'topic_types', 'name' => 'Reference']);
  if ($terms) {
    $node->set('field_topic_type', reset($terms)->id());
  }
  $node->save();
  $group->addRelationship($node, 'group_node:topic');
  return $node;
}

/**
 * Records one finished run with an "imported" row for the Topic, once.
 */
function source_box_record(GroupInterface $group, int $owner, NodeInterface $node, string $kind, string $source_name, ?int $fid): void {
  $items = \Drupal::entityTypeManager()->getStorage('localnodes_import_item');
  $existing = $items->getQuery()->accessCheck(FALSE)
    ->condition('nid', $node->id())
    ->condition('outcome', ImportItem::OUTCOME_IMPORTED)
    ->count()->execute();
  if ($existing) {
    return;
  }
  $time = \Drupal::time()->getRequestTime();
  $run = \Drupal::entityTypeManager()->getStorage('localnodes_import_run')->create([
    'gid' => $group->id(),
    'uid' => $owner,
    'kind' => $kind,
    'status' => ImportRun::STATUS_FINISHED,
    'rights_confirmed_by' => $owner,
    'rights_confirmed_at' => $time,
  ]);
  $run->save();
  $items->create([
    'run' => $run->id(),
    'gid' => $group->id(),
    'source_name' => $source_name,
    'outcome' => ImportItem::OUTCOME_IMPORTED,
    'nid' => $node->id(),
    'fid' => $fid,
  ])->save();
}

// Web page, as 22-18's TopicFactory::fromWebPage() writes it.
$web = source_box_topic($group, (int) $owner, SOURCE_BOX_WEB_TITLE, 'public');
$web->set('body', [
  'value' => '<p>Bioregionalism is a way of living in place.</p>'
  . '<blockquote><p>[Bioregion] refers both to a geographical terrain and a terrain of consciousness.</p></blockquote>'
  . '<p>(Source: Peter Berg and Raymond Dasmann, 1977)</p>',
  'format' => 'imported_html',
]);
$web->set('field_source_url', ['uri' => SOURCE_BOX_ADDRESS]);
$web->set('field_source_license', 'cc_by_sa_4_0');
$web->set('field_source_license_text', NULL);
$web->set('field_source_attribution', 'P2P Foundation Wiki');
$web->save();
source_box_record($group, (int) $owner, $web, ImportRun::KIND_WEB, SOURCE_BOX_ADDRESS, NULL);

// Uploaded file, as fromFile() writes it.
$file_topic = source_box_topic($group, (int) $owner, SOURCE_BOX_FILE_TITLE, 'group');
$file = NULL;
if (!$file_topic->get('field_files')->isEmpty()) {
  $file = $file_topic->get('field_files')->entity;
}
if (!$file instanceof FileInterface) {
  $directory = 'private://localnodes_import';
  \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $uri = $directory . '/garden-committee-minutes.txt';
  file_put_contents($uri, "Garden committee minutes\n\nWe agreed to share the tool shed rota.\n");
  $file = File::create([
    'uri' => $uri,
    'filename' => 'garden-committee-minutes.txt',
    'uid' => $owner,
    'status' => FileInterface::STATUS_PERMANENT,
  ]);
  $file->save();
}
$file_topic->set('body', ['value' => '<p>We agreed to share the tool shed rota.</p>', 'format' => 'plain_text']);
$file_topic->set('field_files', [['target_id' => $file->id(), 'display' => 1]]);
$file_topic->set('field_source_license', 'not_stated');
$file_topic->set('field_source_license_text', 'CC BY 4.0');
$file_topic->set('field_source_attribution', 'Garden Committee, 2024');
$file_topic->save();
source_box_record($group, (int) $owner, $file_topic, ImportRun::KIND_FILES, 'garden-committee-minutes.txt', (int) $file->id());

// Indexes with index_directly would embed both Topics when this script ends,
// which needs a live AI provider key the local site may not have. The Topics
// stay tracked, so cron or `drush search-api:index` still indexes them; the
// fixture just doesn't depend on it.
$post_request = \Drupal::service('search_api.post_request_indexing');
foreach ($entity_type_manager->getStorage('search_api_index')->loadMultiple() as $index) {
  $ids = [];
  foreach ([$web, $file_topic] as $node) {
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $ids[] = 'entity:node/' . $node->id() . ':' . $langcode;
    }
  }
  $post_request->removeFromIndexing((string) $index->id(), $ids);
}

print 'LN_SOURCE_WEB=' . $web->id() . PHP_EOL;
print 'LN_SOURCE_FILE=' . $file_topic->id() . PHP_EOL;
