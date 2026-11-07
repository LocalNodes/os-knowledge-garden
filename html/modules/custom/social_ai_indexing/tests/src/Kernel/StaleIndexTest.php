<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\search_api\Entity\Index;
use Drupal\social_ai_indexing\Rag\PermissionAwareRagTool;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * Stale index door (AI-SPEC §5 door 7; T-22-31).
 *
 * The vector DB can lag Drupal: a Topic tightened to members-only, or
 * deleted, keeps its old point until the next reindex (or forever, if a
 * delete never reached Qdrant). The stub's records are snapshotted before
 * the change and put back after it, so the "vector DB" still holds the old
 * payloads. The tool re-checks access on the live entity at answer time, and
 * skips what no longer exists, so neither reaches a non-member or visitor.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class StaleIndexTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   *
   * See RagToolTrait for why social_email_broadcast is needed.
   */
  protected static $modules = ['social_ai_indexing_test', 'social_email_broadcast'];

  /**
   * Strings only G-PUB, its comment and G-COM carry.
   */
  private const STALE_CANARIES = [
    'Fictional G public notice',
    'public notice for group G',
    'Fictional G community notice',
    'community notice for group G',
    'fictional public reply',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $server = $this->createStubSearchServer();
    $this->createShippedIndex('social_posts', $server);
    $this->createShippedIndex('social_comments', $server);
    $this->setUpLeakFixtures();
    $this->indexAll();
    $this->resetSourceRegistry();
  }

  /**
   * Stub backend modes.
   *
   * @return array
   *   [mode].
   */
  public static function modes(): array {
    return [
      'vector filter fails open' => ['ignore'],
      'vector filter works (on stale payloads)' => ['honour'],
    ];
  }

  /**
   * G-COM tightened and G-PUB deleted, without reindexing.
   *
   * @dataProvider modes
   */
  public function testTightenedAndDeletedTopicsNeverReachOthers(string $mode): void {
    $store = $this->stubStore();
    $snapshot = $store->getAll();
    $g_pub_key = 'social_posts|' . $this->itemId($this->fixtures['G-PUB']);
    $g_com_key = 'social_posts|' . $this->itemId($this->fixtures['G-COM']);
    $g_com = $this->fixtures['G-COM'];
    $g_com_id = (int) $g_com->id();
    $g_pub_id = (int) $this->fixtures['G-PUB']->id();

    $g_com->set('field_content_visibility', 'group')->save();
    // Deleting the Topic also deletes its comment (G-PUB-COMMENT).
    $this->fixtures['G-PUB']->delete();

    // The vector DB never heard about either change.
    $store->deleteAll();
    $store->setMultiple($snapshot);
    $this->assertNotNull($store->get($g_pub_key), 'The deleted Topic is still in the stale index.');
    $this->assertSame(['community'], $store->get($g_com_key)['fields']['field_content_visibility'], 'The tightened Topic still carries its old visibility in the stale index.');
    // No access-cache reset here: the code under test must do its own
    // (RetrievalAccessCache). A reset in the test hid the RQ-13 leak;
    // RQ13StaleAccessTest warms the cache first and proves it.

    $this->setStubMode($mode);
    $group_g = (int) $this->groups['G']->id();
    foreach (['P-ANON', 'P-NON'] as $persona) {
      $this->resetSourceRegistry();
      $output = '';
      foreach (['social_posts', 'social_comments'] as $index_id) {
        $output .= "\n" . $this->runRagTool($index_id, $this->personas[$persona], $group_g)->getReadableOutput();
      }
      foreach (self::STALE_CANARIES as $canary) {
        $this->assertStringNotContainsString($canary, $output, "STALE LEAK [$mode]: $persona got '$canary'.");
      }
      $keys = array_map(static fn ($s): string => $s->key(), $this->container->get('social_ai_indexing.source_registry')->all());
      $this->assertNotContains("node:$g_com_id", $keys, "$persona: the tightened Topic has no label.");
      $this->assertNotContains("node:$g_pub_id", $keys, "$persona: the deleted Topic has no label.");
    }

    // Positive control: a member of G still gets the now members-only Topic.
    $this->resetSourceRegistry();
    $member = $this->runRagTool('social_posts', $this->personas['P-MEM'], $group_g)->getReadableOutput();
    $this->assertStringContainsString('Fictional G community notice', $member, "[$mode] P-MEM still gets G-COM.");
    $this->assertStringNotContainsString('Fictional G public notice', $member, "[$mode] Nobody gets the deleted G-PUB.");
  }

  /**
   * Missing entities and orphan comments are skipped and take no number.
   */
  public function testMissingEntityAndOrphanCommentTakeNoLabel(): void {
    $store = $this->stubStore();

    // A point whose node is gone (a delete that never reached the DB).
    $ghost = $store->get('social_posts|' . $this->itemId($this->fixtures['G-PUB']));
    $ghost['item_id'] = 'entity:node/999999:en';
    $ghost['extra']['drupal_entity_id'] = 'entity:node/999999:en';
    $ghost['extra']['content'] = 'Fictional ghost page text.';
    $store->set('social_posts|entity:node/999999:en', $ghost);
    $ghost_comment = $store->get('social_comments|' . $this->itemId($this->fixtures['G-PUB-COMMENT']));
    $ghost_comment['item_id'] = 'entity:comment/999999:en';
    $ghost_comment['extra']['drupal_entity_id'] = 'entity:comment/999999:en';
    $ghost_comment['extra']['content'] = 'Fictional ghost comment text.';
    $store->set('social_comments|entity:comment/999999:en', $ghost_comment);

    // A comment whose parent is gone, without core's delete cascade.
    $orphan = $this->createComment($this->fixtures['G-PUB'], 'A fictional orphaned reply.');
    $this->indexAll();
    $this->container->get('database')->update('comment_field_data')
      ->fields(['entity_id' => 999998])
      ->condition('cid', $orphan->id())
      ->execute();
    $this->container->get('entity_type.manager')->getStorage('comment')->resetCache([$orphan->id()]);
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('comment')->load($orphan->id())->getCommentedEntity(), 'The comment is an orphan.');

    $this->setStubMode('ignore');
    $this->setStubScores([
      'entity:node/999999:en' => 0.99,
      'entity:comment/999999:en' => 0.99,
      $this->itemId($orphan) => 0.98,
    ]);

    $group_g = (int) $this->groups['G']->id();
    $posts = $this->runRagTool('social_posts', $this->personas['P-MEM'], $group_g)->getReadableOutput();
    $comments = $this->runRagTool('social_comments', $this->personas['P-MEM'], $group_g)->getReadableOutput();
    $all = $posts . "\n" . $comments;

    $numbers = array_keys(self::labelLines($all));
    $this->assertNotEmpty($numbers);
    $this->assertSame(range(1, count($numbers)), $numbers, 'Labels start at R1 with no gaps.');
    $this->assertSame(count($numbers), $this->container->get('social_ai_indexing.source_registry')->count());
    foreach (['ghost page', 'ghost comment', 'orphaned reply'] as $text) {
      $this->assertStringNotContainsString($text, $all, "The skipped item's text ('$text') never reaches the LLM.");
    }
    $keys = array_map(static fn ($s): string => $s->key(), $this->container->get('social_ai_indexing.source_registry')->all());
    $this->assertNotContains('node:999999', $keys);
    $this->assertNotContains('comment:999999', $keys);
    $this->assertNotContains('comment:' . $orphan->id(), $keys);
    // The real comments are still there, after the posts' labels.
    $this->assertContains('Comment on ' . $this->fixtures['G-PUB']->label(), self::labelLines($comments));
    $this->assertNotSame(PermissionAwareRagTool::NO_RESULTS, $posts);
  }

  /**
   * Indexes every tracked item on both indexes.
   */
  private function indexAll(): void {
    foreach (['social_posts', 'social_comments'] as $index_id) {
      $this->indexAllItems(Index::load($index_id));
    }
    $this->mirrorCommentPayloads();
  }

}
