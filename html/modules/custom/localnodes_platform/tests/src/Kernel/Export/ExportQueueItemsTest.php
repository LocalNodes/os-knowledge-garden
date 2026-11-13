<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Export\ConversationRows;

/**
 * Queued work goes into the copy unless it names a conversation.
 *
 * On a site without messaging nothing in the queue is touched, even data
 * that cannot be read. On a site with conversations, only the items that
 * name one, or cannot be read, are left out.
 *
 * @group localnodes_platform
 */
final class ExportQueueItemsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user'];

  /**
   * The activity that is a notice about a conversation.
   */
  private const CONVERSATION_ACTIVITY = 5;

  /**
   * Queues items in an unrelated activity queue and one naming a
   * conversation, and returns their ids by label.
   *
   * @return array<string, int>
   */
  private function queueItems(): array {
    $queue = $this->container->get('queue')->get('activity_custom');
    $object = new \stdClass();
    $object->entity_id = 41;
    $object->note = 'an unrelated object payload';
    $named = new \stdClass();
    $named->activity = self::CONVERSATION_ACTIVITY;
    $items = [
      'object' => (int) $queue->createItem($object),
      'empty list' => (int) $queue->createItem(['activities' => []]),
      'unrelated' => (int) $queue->createItem(['activity' => 6]),
      'mixed list' => (int) $queue->createItem(['activities' => [self::CONVERSATION_ACTIVITY, 6]]),
      'conversation' => (int) $queue->createItem(['activity' => self::CONVERSATION_ACTIVITY]),
      'object conversation' => (int) $queue->createItem($named),
    ];
    // Data no reader can unserialize.
    $items['unreadable'] = (int) $this->container->get('database')->insert('queue')
      ->fields(['name' => 'activity_custom', 'data' => 'not serialized', 'expire' => 0, 'created' => 1])
      ->execute();
    return $items;
  }

  /**
   * What the export does with each queued item: kept, changed or left out.
   *
   * @return array<string, string>
   */
  private function outcomes(array $items): array {
    $rows = new ConversationRows($this->container->get('database'));
    $outcomes = [];
    foreach ($rows->rows('queue') as $row) {
      $label = array_search((int) $row['item_id'], $items, TRUE);
      $kept = $rows->keep('queue', '', $row);
      $outcomes[$label] = $kept === NULL ? 'left out' : ($kept === $row ? 'kept' : 'changed');
    }
    ksort($outcomes);
    return $outcomes;
  }

  /**
   * Without messaging, every queued item goes into the copy unchanged.
   */
  public function testNoMessagingLeavesTheQueueAlone(): void {
    $items = $this->queueItems();
    $this->assertSame([
      'conversation' => 'kept',
      'empty list' => 'kept',
      'mixed list' => 'kept',
      'object' => 'kept',
      'object conversation' => 'kept',
      'unreadable' => 'kept',
      'unrelated' => 'kept',
    ], $this->outcomes($items));
    $this->assertFalse((new ConversationRows($this->container->get('database')))->touches('queue', ''));
  }

  /**
   * Makes activity CONVERSATION_ACTIVITY a notice about a conversation.
   */
  private function addConversation(): void {
    $schema = $this->container->get('database')->schema();
    $schema->createTable('private_message', ['fields' => ['id' => ['type' => 'int', 'not null' => TRUE]], 'primary key' => ['id']]);
    $schema->createTable('activity__field_activity_entity', ['fields' => [
      'entity_id' => ['type' => 'int', 'not null' => TRUE],
      'field_activity_entity_target_type' => ['type' => 'varchar_ascii', 'length' => 64, 'not null' => TRUE],
      'field_activity_entity_target_id' => ['type' => 'int', 'not null' => TRUE],
    ]]);
    $this->container->get('database')->insert('activity__field_activity_entity')->fields([
      'entity_id' => self::CONVERSATION_ACTIVITY,
      'field_activity_entity_target_type' => 'private_message',
      'field_activity_entity_target_id' => 1,
    ])->execute();
  }

  /**
   * Activity mail jobs: only a job naming a conversation notice goes.
   */
  public function testOnlyConversationJobsGo(): void {
    $jobs = [
      'conversation' => ['type' => 'activity_send_email_worker', 'payload' => json_encode(['entity_id' => self::CONVERSATION_ACTIVITY])],
      'unrelated' => ['type' => 'activity_send_email_worker', 'payload' => json_encode(['entity_id' => 6])],
      'other type' => ['type' => 'other_worker', 'payload' => 'not json'],
      'unreadable' => ['type' => 'activity_send_email_worker', 'payload' => 'not json'],
    ];
    $outcomes = function () use ($jobs): array {
      $rows = new ConversationRows($this->container->get('database'));
      return array_map(fn (array $job): string => $rows->keep('advancedqueue', '', $job) === NULL ? 'left out' : 'kept', $jobs);
    };

    $this->assertSame(array_fill_keys(array_keys($jobs), 'kept'), $outcomes(), 'Without messaging every job is kept.');
    $this->addConversation();
    $this->assertSame([
      'conversation' => 'left out',
      'unrelated' => 'kept',
      'other type' => 'kept',
      'unreadable' => 'left out',
    ], $outcomes());
  }

  /**
   * With conversations, only what names one is left out or trimmed.
   */
  public function testOnlyConversationItemsGo(): void {
    $this->addConversation();

    $items = $this->queueItems();
    $this->assertSame([
      'conversation' => 'left out',
      'empty list' => 'kept',
      'mixed list' => 'changed',
      'object' => 'kept',
      'object conversation' => 'left out',
      'unreadable' => 'left out',
      'unrelated' => 'kept',
    ], $this->outcomes($items));
  }

}
