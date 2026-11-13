<?php

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\message\Entity\Message;
use Drupal\message\Entity\MessageTemplate;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Departure notices require real content access or a generic report alert.
 *
 * @group localnodes_platform
 */
final class NoticeDepartureAccessTest extends GroupAccessKernelTestBase {

  protected static $modules = [
    'message', 'dynamic_entity_reference', 'activity_creator', 'activity_logger',
    'activity_send', 'activity_send_email', 'social_content_report',
    'localnodes_platform',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['message', 'activity'] as $type) $this->installEntitySchema($type);
    $this->installSchema('activity_creator', ['activity_notification_status']);
    $this->installSchema('activity_send_email', ['user_activity_digest']);
    foreach (['entity', 'message', 'recipient_user', 'recipient_group', 'destinations', 'status', 'output_text'] as $suffix) {
      $field = 'field_activity_' . $suffix;
      $this->installShippedConfig('activity_creator', ["field.storage.activity.$field", "field.field.activity.activity.$field"]);
    }
  }

  /** @dataProvider recipientPermissions */
  public function testPrivateContentAndReportNotice(string $permission): void {
    Role::create(['id' => 'notice_manager', 'label' => 'Notice manager', 'permissions' => [$permission]])->save();
    $member = $this->createPersona('notice_leaver');
    $manager = $this->createPersona('notice_former_manager');
    $never = $this->createPersona('notice_never_manager');
    foreach ([$manager, $never] as $account) $account->addRole('notice_manager')->save();
    $group = $this->createFlexibleGroup('Content group', 'community');
    $this->addMember($group, $member);
    $this->addMember($group, $manager);
    $private = $this->createGroupTopic($group, 'Private group topic', 'Private body.', 'group');
    $activities = [];
    foreach (['content_reported', 'create_mention_post'] as $template) {
      MessageTemplate::create([
        'template' => $template, 'label' => $template,
        'text' => array_fill(0, 3, ['value' => 'Notice test text.', 'format' => 'plain_text']),
      ])->save();
      $message = Message::create(['template' => $template, 'uid' => $this->siteOwner->id()]);
      $message->save();
      $activity = Activity::create([
        'user_id' => $this->siteOwner->id(), 'status' => 1,
        'field_activity_entity' => ['target_type' => 'node', 'target_id' => $private->id()],
        'field_activity_recipient_group' => $group->id(),
        'field_activity_recipient_user' => array_map(static fn ($account) => ['target_id' => $account->id()], [$member, $manager, $never]),
        'field_activity_message' => $message->id(),
        'field_activity_destinations' => [['value' => 'stream_home']],
        'field_activity_status' => 1,
        'field_activity_output_text' => ['value' => 'Notice test text.', 'format' => 'plain_text'],
      ]);
      $activity->save();
      $activities[$template] = $activity;
    }
    $db = $this->container->get('database');
    foreach ([$member, $manager, $never] as $account) {
      foreach ($activities as $activity) {
        $db->insert('user_activity_digest')->fields(['uid' => $account->id(), 'activity' => $activity->id(), 'frequency' => 'daily'])->execute();
      }
    }
    $group->removeMember($member);
    $group->removeMember($manager);
    foreach (['node', 'group'] as $type) $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    $this->assertTrue($group->access('view', $member), 'Reading a community group page is distinct from its private content.');
    $this->assertFalse($private->access('view', $member));
    foreach ([$manager, $never] as $account) {
      $this->assertSame($permission === 'bypass node access', $private->access('view', $account), 'Core node bypass remains an actual view grant; other site permissions do not grant content access.');
    }
    $this->assertTrue($manager->hasPermission($permission));
    $cleanup = $this->container->get('localnodes_platform.group_notice_cleanup');
    $ids = array_map(static fn ($activity) => $activity->id(), array_values($activities));
    $report = $activities['content_reported'];
    $reportAlert = $permission === 'view inappropriate reports';
    foreach ([$member, $manager, $never] as $account) {
      $full = $permission === 'bypass node access' && $account->id() === $never->id();
      foreach ($activities as $activity) {
        $this->assertSame($full, $cleanup->allows($activity, (int) $account->id()), 'Full delivery requires actual view access and no departure suppression.');
      }
      $payload = ['uid' => $account->id(), 'activities' => $ids];
      $generic = $reportAlert && $account->id() !== $member->id();
      $expected = $full ? $payload : ($generic ? ['uid' => $account->id(), 'activities' => [$report->id()]] : NULL);
      $this->assertSame($expected, $cleanup->filterDigest($payload));
    }
    $expectedRows = $reportAlert ? [
      [(int) $manager->id(), (int) $report->id()], [(int) $never->id(), (int) $report->id()],
    ] : [];
    if ($permission === 'bypass node access') {
      $expectedRows = array_map(static fn ($activity) => [(int) $never->id(), (int) $activity->id()], array_values($activities));
    }
    $rows = $db->select('user_activity_digest', 'd')->fields('d', ['uid', 'activity'])->orderBy('uid')->orderBy('activity')->execute()->fetchAll();
    $this->assertSame($expectedRows, array_map(static fn ($row) => [(int) $row->uid, (int) $row->activity], $rows), 'The final check deletes ineligible rows and retains only generic report alerts.');
    $manager->removeRole('notice_manager')->save();
    $this->assertNull($cleanup->filterDigest(['uid' => $manager->id(), 'activities' => [$report->id()]]), 'Revoking report permission also revokes the generic alert.');
    $this->addMember($group, $member);
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->assertTrue($private->access('view', $member), 'A real member can read the same private page.');
    foreach ($activities as $activity) $this->assertTrue($cleanup->allows($activity, (int) $member->id()), 'Rejoining restores notices with current content access.');
  }

  public static function recipientPermissions(): array {
    return ['group management' => ['manage all groups'], 'node bypass' => ['bypass node access'], 'report handler' => ['view inappropriate reports']];
  }

}
