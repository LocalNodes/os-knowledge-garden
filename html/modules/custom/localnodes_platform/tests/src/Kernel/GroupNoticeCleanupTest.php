<?php

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Component\Datetime\Time;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\group\Entity\GroupRole;
use Drupal\group\PermissionScopeInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_notice_test\Entity\NoticeActivity;
use Drupal\user\Entity\User;

/**
 * Membership deletion removes digest rows while delivery guards filter queued mail.
 *
 * @group localnodes_platform
 */
final class GroupNoticeCleanupTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'options', 'text', 'filter', 'message', 'entity', 'flexible_permissions', 'group', 'advancedqueue', 'localnodes_notice_test', 'localnodes_platform'];

  protected $strictConfigSchema = FALSE;

  /**
   * @dataProvider removalPaths
   */
  public function testRemovalDropsOnlyThisMembersGroupMail(bool $remove_via_group): void {
    $this->installConfig(['system', 'user', 'field', 'group', 'message']);
    foreach (['user', 'group', 'group_content', 'group_config_wrapper', 'message', 'activity'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('localnodes_notice_test', ['user_activity_digest']);
    $this->installSchema('advancedqueue', ['advancedqueue']);
    $db = $this->container->get('database');
    $clock = new class($this->container->get('request_stack')) extends Time {
      public int $now = 2000000000;

      public function getCurrentTime() {
        return $this->now;
      }
    };
    $this->container->set('datetime.time', $clock);
    User::create(['uid' => 1, 'name' => 'root', 'status' => 1])->save();
    $member = User::create(['uid' => 7, 'name' => 'member-seven', 'status' => 1]);
    $member->save();
    $remaining = User::create(['uid' => 8, 'name' => 'member-eight', 'status' => 1]);
    $remaining->save();
    GroupType::create(['id' => 'notice_group', 'label' => 'Notice group', 'creator_membership' => FALSE])->save();
    GroupRole::create([
      'id' => 'notice_group-notice_reader', 'label' => 'Group reader',
      'group_type' => 'notice_group', 'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'permissions' => ['view group'],
    ])->save();
    $group = Group::create(['type' => 'notice_group', 'label' => 'First group']);
    $group->save();
    $other = Group::create(['type' => 'notice_group', 'label' => 'Other group']);
    $other->save();
    $group->addMember($member, ['group_roles' => ['notice_group-notice_reader']]);
    $other->addMember($member, ['group_roles' => ['notice_group-notice_reader']]);
    $group->addMember($remaining, ['group_roles' => ['notice_group-notice_reader']]);
    $this->assertTrue($other->access('view', $member));
    $this->assertTrue($group->access('view', $remaining), 'The other recipient has real current group access.');

    $activities = [
      10 => $this->activity($group, 10),
      11 => $this->activity($other, 11),
      12 => $this->activity($group, 12),
      13 => $this->activity($group->getMember($member)->getGroupRelationship(), 13),
    ];

    foreach ([[7, 10, 'daily'], [7, 12, 'weekly'], [7, 13, 'daily'], [7, 11, 'daily'], [8, 10, 'weekly']] as [$uid, $activity, $frequency]) {
      $db->insert('user_activity_digest')->fields(compact('uid', 'activity', 'frequency'))->execute();
    }
    $queue = $this->container->get('queue')->get('activity_send_email_worker', TRUE);
    $queue->createItem(['entity_id' => 12]);
    $queue->createItem(['entity_id' => 13]);
    $shared_id = $queue->createItem(['entity_id' => 10]);
    $queue->createItem(['entity_id' => 11]);
    $queue->createItem(['entity_id' => 10, 'recipients' => [8]]);
    $digest = $this->container->get('queue')->get('activity_digest_worker', TRUE);
    $digest_id = $digest->createItem(['uid' => 7, 'frequency' => 'daily', 'activities' => [10, 11, 12]]);
    $digest->createItem(['uid' => 7, 'frequency' => 'weekly', 'activities' => [12]]);
    $digest->createItem(['uid' => 8, 'frequency' => 'daily', 'activities' => [10]]);
    $this->container->get('queue')->get('unrelated_worker', TRUE)->createItem(['entity_id' => 12]);
    $advanced_shared = $this->job(['entity_id' => 10]);
    $this->job(['entity_id' => 12]);
    $this->job(['entity_id' => 12], 'failure');
    $this->job(['entity_id' => 11]);
    $this->job(['entity_id' => 10, 'recipients' => [8]]);
    $this->job(['entity_id' => 12], 'success');
    $this->job(['entity_id' => 12], 'queued', 'unrelated_worker');

    $queued_before = $this->queueSnapshot('queue');
    $advanced_before = $this->queueSnapshot('advancedqueue');

    if ($remove_via_group) {
      $group->removeMember($member);
    }
    else {
      $group->getMember($member)->getGroupRelationship()->delete();
    }

    $rows = $db->select('user_activity_digest', 'd')->fields('d', ['uid', 'activity'])->orderBy('uid')->execute()->fetchAll();
    $this->assertSame([[7, 11], [8, 10]], array_map(static fn($row) => [(int) $row->uid, (int) $row->activity], $rows));
    $this->assertSame($queued_before, $this->queueSnapshot('queue'), 'Membership deletion must not rewrite or delete core queue jobs.');
    $this->assertSame($advanced_before, $this->queueSnapshot('advancedqueue'), 'Advanced queue batches and recipients stay byte-identical.');
    $this->assertFalse((bool) $group->getMember($member));
    $this->assertNotFalse($other->getMember($member));
    $cleanup = $this->container->get('localnodes_platform.group_notice_cleanup');
    $this->assertFalse($cleanup->allows($activities[10], 7), 'A producer that finishes after removal cannot send or store this notice.');
    $this->assertTrue($cleanup->allows($activities[11], 7));
    $this->assertTrue($cleanup->allows($activities[10], 8));
    $this->assertSame(['uid' => 7, 'frequency' => 'daily', 'activities' => [11]], $cleanup->filterDigest($this->payload('queue', $digest_id)));
    $this->assertSame(['entity_id' => 10], $this->payload('queue', $shared_id));
    $this->assertSame(['entity_id' => 10], $this->payload('advancedqueue', $advanced_shared));

    $clock->now += 9 * 86400;
    $queued_activity = $this->container->get('entity_type.manager')->getStorage('activity')->load($this->payload('queue', $shared_id)['entity_id']);
    $this->assertFalse($cleanup->allows($queued_activity, 7), 'A queued notice remains refused nine days after leaving.');
    $this->assertSame(['uid' => 7, 'frequency' => 'daily', 'activities' => [11]], $cleanup->filterDigest($this->payload('queue', $digest_id)), 'A digest already handed to cron remains filtered after nine days.');
    $this->assertSame(['uid' => 7, 'frequency' => 'daily', 'activities' => [11]], $cleanup->filterDigest(['uid' => 7, 'frequency' => 'daily', 'activities' => [10, 11]]));

    $group->addMember($member, ['group_roles' => ['notice_group-notice_reader']]);
    $this->assertNull($this->container->get('keyvalue')->get('localnodes_notice_departures')->get('7:' . $group->id()), 'Rejoining clears the marker.');
    $this->assertSame($this->payload('queue', $digest_id), $cleanup->filterDigest($this->payload('queue', $digest_id)), 'Earlier queued notices are allowed again after rejoin.');
    $this->assertTrue($cleanup->allows($activities[10], 7), 'Current members can receive new notices after joining again.');
  }

  public function removalPaths(): array {
    return ['leave through group API' => [TRUE], 'remove membership record' => [FALSE]];
  }

  private function activity(\Drupal\Core\Entity\EntityInterface $related, int $id): NoticeActivity {
    $activity = $this->container->get('entity_type.manager')->getStorage('activity')->create([
      'id' => $id,
      'related_type' => $related->getEntityTypeId(),
      'related_id' => $related->id(),
    ]);
    $this->assertInstanceOf(NoticeActivity::class, $activity);
    $activity->enforceIsNew()->save();
    return $activity;
  }

  private function queueSnapshot(string $table): array {
    $key = $table === 'queue' ? 'item_id' : 'job_id';
    $rows = $this->container->get('database')->select($table, 'q')->fields('q')->orderBy($key)->execute()->fetchAll();
    return array_map(static fn($row) => (array) $row, $rows);
  }

  private function job(array $payload, string $state = 'queued', string $type = 'activity_send_email_worker'): int {
    return (int) $this->container->get('database')->insert('advancedqueue')->fields(['queue_id' => 'default', 'type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'state' => $state])->execute();
  }

  private function payload(string $table, int $id): array {
    $field = $table === 'queue' ? 'data' : 'payload';
    $key = $table === 'queue' ? 'item_id' : 'job_id';
    $data = $this->container->get('database')->select($table, 'q')->fields('q', [$field])->condition($key, $id)->execute()->fetchField();
    $this->assertNotFalse($data);
    return $table === 'queue' ? unserialize($data, ['allowed_classes' => FALSE]) : json_decode($data, TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
