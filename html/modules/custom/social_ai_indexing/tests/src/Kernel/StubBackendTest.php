<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\search_api\Query\QueryInterface;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * The stub AI search backend behaves like the production filter path.
 *
 * Queries here run with PROCESSING_NONE, so neither the shipped processors
 * nor SearchQuerySubscriber add conditions: only the conditions this test
 * adds reach the backend. That keeps the test about the stub alone.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class StubBackendTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['social_ai_indexing_test'];

  /**
   * The three modes, the dropped-field rule and the recorded conditions.
   */
  public function testModes(): void {
    $index = $this->createShippedIndex('social_comments', $this->createStubSearchServer());
    $this->assertSame('social_ai_indexing_test_stub', $index->getServerInstance()->getBackendId());
    $this->setUpLeakFixtures();
    $this->assertSame(2, $this->indexAllItems($index), 'Both leak-fixture comments are indexed.');

    $all = [
      $this->itemId($this->fixtures['G-MO-COMMENT']),
      $this->itemId($this->fixtures['G-PUB-COMMENT']),
    ];
    sort($all);
    $group_g = (int) $this->groups['G']->id();
    $group_h = (int) $this->groups['H']->id();

    // Honour: no conditions -> everything.
    $this->setStubMode('honour');
    $this->assertSame($all, $this->sortedIds($this->query($index, [])));

    // Honour: '=' on an indexed field filters.
    $this->assertSame($all, $this->sortedIds($this->query($index, [['group_id', $group_g, '=']])));
    $this->assertSame([], $this->sortedIds($this->query($index, [['group_id', $group_h, '=']])));

    // Honour: IN and <>.
    $this->assertSame($all, $this->sortedIds($this->query($index, [['group_id', [$group_h, $group_g], 'IN']])));
    $this->assertSame([], $this->sortedIds($this->query($index, [['group_id', $group_g, '<>']])));
    $this->assertSame($all, $this->sortedIds($this->query($index, [['group_id', $group_h, '<>']])));

    // Honour: a condition on a field the index lacks is dropped silently,
    // as QdrantProvider::convertConditionToQdrant() drops it (F-2).
    $this->assertNull($index->getField('phase22_not_indexed'));
    $this->assertSame($all, $this->sortedIds($this->query($index, [['phase22_not_indexed', 'nothing-matches-this', '=']])), 'A condition on a missing field has no effect.');
    $this->assertSame([], $this->sortedIds($this->query($index, [
      ['phase22_not_indexed', 'nothing-matches-this', '='],
      ['group_id', $group_h, '='],
    ])), 'The indexed condition still applies next to a dropped one.');

    // Honour: OR group (should) and a nested group whose only condition is
    // dropped (empty nested filter, dropped).
    $query = $index->query()->setProcessingLevel(QueryInterface::PROCESSING_NONE);
    $or = $query->createConditionGroup('OR');
    $or->addCondition('group_id', 999999);
    $or->addCondition('group_id', $group_g);
    $query->addConditionGroup($or);
    $query->execute();
    $this->assertSame($all, $this->sortedIds($query));

    $query = $index->query()->setProcessingLevel(QueryInterface::PROCESSING_NONE);
    $nested = $query->createConditionGroup('AND');
    $nested->addCondition('phase22_not_indexed', 'x');
    $query->addConditionGroup($nested);
    $query->addCondition('group_id', $group_g);
    $query->execute();
    $this->assertSame($all, $this->sortedIds($query));

    // last_conditions records the condition group, nested groups included.
    $recorded = $this->lastStubConditions();
    $this->assertSame('AND', $recorded['conjunction']);
    $this->assertCount(2, $recorded['conditions']);
    $this->assertSame('AND', $recorded['conditions'][0]['conjunction']);
    $this->assertSame(['field' => 'phase22_not_indexed', 'value' => 'x', 'operator' => '='], $recorded['conditions'][0]['conditions'][0]);
    $this->assertSame(['field' => 'group_id', 'value' => $group_g, 'operator' => '='], $recorded['conditions'][1]);

    // Extra data the RAG tool reads.
    $query = $this->query($index, [['group_id', $group_g, '=']]);
    $item = $query->getResults()->getResultItems()[$this->itemId($this->fixtures['G-MO-COMMENT'])];
    $this->assertSame($this->itemId($this->fixtures['G-MO-COMMENT']), $item->getExtraData('drupal_entity_id'));
    $this->assertIsString($item->getExtraData('content'));
    $this->assertSame(1.0, $item->getScore());

    // Ignore: fails open, every item whatever the conditions.
    $this->setStubMode('ignore');
    $this->assertSame($all, $this->sortedIds($this->query($index, [['group_id', 999999, '=']])), 'Ignore mode returns every item.');

    // Throw.
    $this->setStubMode('throw');
    $this->expectException(\RuntimeException::class);
    $this->query($index, []);
  }

  /**
   * Runs an unprocessed query with plain conditions.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The index.
   * @param array $conditions
   *   List of [field, value, operator].
   */
  private function query($index, array $conditions): QueryInterface {
    $this->resetStubConditions();
    $query = $index->query()->setProcessingLevel(QueryInterface::PROCESSING_NONE);
    foreach ($conditions as [$field, $value, $operator]) {
      $query->addCondition($field, $value, $operator);
    }
    $query->execute();
    return $query;
  }

  /**
   * Sorted result ids.
   */
  private function sortedIds(QueryInterface $query): array {
    $ids = $this->resultIds($query);
    sort($ids);
    return $ids;
  }

}
