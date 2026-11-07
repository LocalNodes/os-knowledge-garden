<?php

/**
 * @file
 * Restores the two fixtures RQ-13 changes, so RQ-13 can run again (22-27).
 *
 * LOCAL DDEV ONLY, on a site seeded by seed_live_check.php after a
 * --destructive RQ-13 run. RQ-13 turns G-COM members-only and deletes G-PUB
 * (and its comment). This puts them back the way the seeder made them,
 * without a reinstall:
 * - G-COM's visibility goes back to community (same node);
 * - a new G-PUB Topic and G-PUB-COMMENT are created in group G with the
 *   seeder's exact strings, owner (the steward) and visibility;
 * - the seed map in state gets the new ids, and the RQ-13 done flag is
 *   cleared.
 * Nothing is indexed here: index afterwards the way the worker does
 * (drush cron:run search_api_cron --force), or the next RQ-13 asks against
 * a site that never indexed the restored pages.
 *
 * Run:
 *   ddev drush php-script html/modules/custom/localnodes_import/scripts/restore_rq13_fixtures.php
 *
 * Prints the changed ids only, never fixture text (E10). Refuses to run
 * unless RQ-13's change was made (the done flag is set) and G-PUB is gone.
 */

use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\group\Entity\Group;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

$seed_key = 'localnodes_import.live_check_seed';
$done_key = 'social_ai_indexing.live_run_rq13_done';
$state = \Drupal::state();
$seed = $state->get($seed_key);
if (!is_array($seed) || empty($seed['fixtures']['G-COM']) || empty($seed['groups']['G'])) {
  print "No seed map in state $seed_key; nothing to restore.\n";
  exit(1);
}
if (!$state->get($done_key)) {
  print "RQ-13 has not run (no $done_key); nothing to restore.\n";
  exit(1);
}
$g_pub_id = (int) substr((string) $seed['fixtures']['G-PUB'], 5);
if (Node::load($g_pub_id) !== NULL) {
  print "G-PUB (node $g_pub_id) still exists; this site doesn't look post-RQ-13.\n";
  exit(1);
}

$owner_id = (int) $seed['users']['steward'];
$group = Group::load((int) $seed['groups']['G']);

$g_com = Node::load((int) substr((string) $seed['fixtures']['G-COM'], 5));
$g_com->set('field_content_visibility', 'community')->save();

$g_pub = Node::create([
  'type' => 'topic',
  'title' => 'Fictional G public notice',
  'body' => ['value' => 'A fictional public notice for group G.', 'format' => 'plain_text'],
  'field_content_visibility' => 'public',
  'uid' => $owner_id,
  'status' => NodeInterface::PUBLISHED,
]);
$g_pub->save();
$group->addRelationship($g_pub, 'group_node:topic');

$comment = Comment::create([
  'entity_type' => 'node',
  'entity_id' => $g_pub->id(),
  'field_name' => 'field_topic_comments',
  'comment_type' => 'comment',
  'field_comment_body' => ['value' => 'A fictional public reply.', 'format' => 'plain_text'],
  'uid' => $owner_id,
  'status' => CommentInterface::PUBLISHED,
]);
$comment->save();

$seed['fixtures']['G-PUB'] = 'node:' . $g_pub->id();
$seed['fixtures']['G-PUB-COMMENT'] = 'comment:' . $comment->id();
$state->set($seed_key, $seed);
$state->delete($done_key);

printf("Restored: G-COM %s community; G-PUB %s; G-PUB-COMMENT %s; %s cleared.\n", $seed['fixtures']['G-COM'], $seed['fixtures']['G-PUB'], $seed['fixtures']['G-PUB-COMMENT'], $done_key);
