<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\Core\Session\AccountInterface;
use Drupal\localnodes_platform\Activity\HouseActivityAccess;
use Drupal\localnodes_platform\Plugin\views\filter\ActivityRecipientOnlyFilter;
use Drupal\node\NodeInterface;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * A notice opens only when both notice rules allow it.
 *
 * The rules: a personal notification opens only for its recipients, and a
 * house notice is no longer addressed to a member who has left the house.
 * A refusal from either rule stands.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class NoticeAccessRulesJoinTest extends GroupAccessKernelTestBase {

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

  private const MAIL = [['value' => 'notifications'], ['value' => 'email']];

  /**
   * A notice about a topic, for one or more recipients in order.
   */
  private function notice(AccountInterface|array $recipient, NodeInterface $topic, int $gid, array $destinations = [['value' => 'notifications']]): Activity {
    $recipients = is_array($recipient) ? $recipient : [$recipient];
    $activity = Activity::create([
      'user_id' => $this->siteOwner->id(), 'status' => 1,
      'field_activity_entity' => ['target_type' => 'node', 'target_id' => $topic->id()],
      'field_activity_recipient_user' => array_map(static fn (AccountInterface $a): array => ['target_type' => 'user', 'target_id' => $a->id()], $recipients),
      'field_activity_recipient_group' => $gid,
      'field_activity_destinations' => $destinations,
      'field_activity_status' => 1,
      'field_activity_output_text' => ['value' => 'A new topic in the house.', 'format' => 'plain_text'],
    ]);
    $activity->save();
    return $activity;
  }

  private function resetAccess(): void {
    foreach (['node', 'group', 'activity'] as $type) $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
  }

  public function testEitherRulesRefusalStands(): void {
    $leaver = $this->createPersona('join_leaver');
    $carl = $this->createPersona('join_carl');
    $ben = $this->createPersona('join_ben');
    $house = $this->createFlexibleGroup('Join house', 'community');
    foreach ([$leaver, $carl, $ben] as $account) $this->addMember($house, $account);
    $topic = $this->createGroupTopic($house, 'House plan', 'Body.', 'group');
    $open = $this->createGroupTopic($house, 'House open day', 'Body.', 'community');
    $toLeaver = $this->notice($leaver, $open, (int) $house->id());
    // A notice that also goes by mail opens only for its recipient.
    $toCarl = $this->notice($carl, $topic, (int) $house->id(), self::MAIL);
    // A recipient in second place: the same rules apply.
    $leaverSecond = $this->notice([$carl, $leaver], $open, (int) $house->id(), self::MAIL);
    $carlSecond = $this->notice([$ben, $carl], $topic, (int) $house->id());
    $house->removeMember($leaver);
    $this->resetAccess();

    // A member who has left, about an item they may still view: they are
    // its recipient, and the departed-member rule refuses.
    $this->assertTrue($open->access('view', $leaver), 'The recipient may still view the item.');
    $this->assertTrue(HouseActivityAccess::access($toLeaver, 'view', $leaver)->isForbidden(), 'The departed-member rule refuses a notice for a member who has left.');
    $this->assertFalse($toLeaver->access('view', $leaver), 'A member who has left the house does not open its notice.');
    $this->assertFalse($leaverSecond->access('view', $leaver), 'A member who has left the house is refused it as its second recipient: either refusal stands.');

    // A current member of the house who is not the recipient: the
    // departed-member rule allows, the notifications rule refuses.
    $this->assertFalse(HouseActivityAccess::access($toCarl, 'view', $ben)->isForbidden(), 'The departed-member rule allows a current member.');
    $this->assertTrue(ActivityRecipientOnlyFilter::personalNotificationAccess($toCarl, 'view', $ben)->isForbidden(), 'The notifications rule refuses another member.');
    $this->assertFalse($toCarl->access('view', $ben), 'A notice opens only for its recipients.');

    // A refusal by the departed-member rule also follows what the
    // notifications rule depends on.
    $refused = localnodes_platform_activity_access($toLeaver, 'view', $leaver);
    $this->assertTrue($refused->isForbidden());
    $this->assertContains('user:' . $leaver->id(), $refused->getCacheTags(), 'The refusal follows changes to the recipient\'s account.');
    $this->assertContains('node:' . $open->id(), $refused->getCacheTags(), 'The refusal follows changes to the item.');

    // The joined result keeps both rules' cache data.
    $joined = localnodes_platform_activity_access($toCarl, 'view', $ben);
    $this->assertTrue($joined->isForbidden());
    $this->assertContains('user', $joined->getCacheContexts());
    $this->assertContains('group_content_list', $joined->getCacheTags(), 'The departed-member rule\'s cache data is kept.');
    $this->assertContains('activity:' . $toCarl->id(), $joined->getCacheTags());

    // A member still opens their own ordinary notice.
    $this->assertTrue($toCarl->access('view', $carl), 'A member opens their own notice.');
    $this->assertTrue($carlSecond->access('view', $carl), 'A member opens their own notice as its second recipient.');
  }

}
