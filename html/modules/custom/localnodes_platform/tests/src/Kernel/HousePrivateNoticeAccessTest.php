<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\activity_send_email\EmailFrequencyInterface;
use Drupal\activity_send_email\EmailFrequencyManager;
use Drupal\activity_send_email\Plugin\QueueWorker\ActivitySendEmailWorker;
use Drupal\message\Entity\Message;
use Drupal\message\Entity\MessageTemplate;
use Drupal\social_group\GroupMuteNotify;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * Ordinary activity and mail gates use the related house content's access.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class HousePrivateNoticeAccessTest extends GroupAccessKernelTestBase {

  protected static $modules = [
    'message', 'dynamic_entity_reference', 'activity_creator',
    'activity_send', 'activity_send_email', 'localnodes_platform',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('activity');
    foreach (['field_activity_entity', 'field_activity_recipient_user', 'field_activity_recipient_group'] as $field) {
      $this->installShippedConfig('activity_creator', [
        "field.storage.activity.$field", "field.field.activity.activity.$field",
      ]);
    }
    foreach (['sitemanager', 'contentmanager'] as $role) {
      user_role_grant_permissions($role, [
        'bypass node access', 'administer nodes', 'manage all groups',
        'view node.topic.field_content_visibility:group content',
        'view node.topic.field_content_visibility:community content',
      ]);
    }
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    if (function_exists('_localnodes_platform_protect_private_groups')) {
      _localnodes_platform_protect_private_groups();
    }
  }

  public static function staffAndHouse(): array {
    $cases = [];
    foreach (['sitemanager', 'contentmanager'] as $role) {
      foreach (['community', 'members'] as $visibility) {
        $cases["$role:$visibility"] = [$role, $visibility];
      }
    }
    return $cases;
  }

  /** @dataProvider staffAndHouse */
  public function testOrdinaryNoticeCannotDeliverPrivateHouseWords(string $role, string $visibility): void {
    $group = $this->createFlexibleGroup('Fictional private house', $visibility);
    $topic = $this->createGroupTopic($group, 'Fictional private minutes', 'NOTICE-HOUSE-CANARY', 'group');
    $outsider = $this->createUser([], 'notice_staff', FALSE, ['roles' => ['verified', $role]]);
    $member = $this->createPersona('notice_member');
    $this->addMember($group, $member);
    // This is a real Activity entity with a real related node. No group/user
    // recipient is set, so its direct-access handler delegates to that node.
    $activity = Activity::create([
      'user_id' => $this->siteOwner->id(), 'status' => 1,
      'field_activity_entity' => ['target_type' => 'node', 'target_id' => $topic->id()],
    ]);
    $this->assertSame((int) $topic->id(), (int) $activity->getRelatedEntity()->id());
    $this->assertFalse($activity->access('view', $outsider), 'The ordinary activity cannot reveal the private related node to site staff.');
    $this->assertTrue($activity->access('view', $member), 'The ordinary activity remains visible to a real house member.');

    MessageTemplate::create([
      'template' => 'house_ordinary_notice', 'label' => 'Ordinary house notice',
      'text' => [['value' => 'NOTICE-HOUSE-CANARY', 'format' => 'plain_text']],
      // The shipped format is structured. ActivityFactory's token pass turns
      // each partial's value into the strings consumed by the email worker.
      'settings' => ['token options' => ['token replace' => TRUE]],
    ])->save();
    $message = Message::create(['template' => 'house_ordinary_notice']);
    $this->assertSame('NOTICE-HOUSE-CANARY', $message->getTemplate()->getRawText()[0]['value']);
    $this->assertSame('NOTICE-HOUSE-CANARY', $this->container->get('activity_creator.activity_factory')->getMessageText($message)[0]);
    $delivered = [];
    $frequency = $this->createMock(EmailFrequencyInterface::class);
    $frequency->method('processItem')->willReturnCallback(static function ($activity, $message, $recipient, $body) use (&$delivered): void {
      $delivered[(int) $recipient->id()] = $body;
    });
    $manager = $this->createMock(EmailFrequencyManager::class);
    $manager->method('hasDefinition')->with('immediately')->willReturn(TRUE);
    $manager->method('createInstance')->with('immediately')->willReturn($frequency);
    // Muting is an independent preference. Keep it out of this focused access
    // gate test; neither related-node lookup nor entity access is mocked.
    $mute = $this->createMock(GroupMuteNotify::class);
    $mute->method('getGroupByContent')->willReturn(NULL);
    $worker = new ActivitySendEmailWorker(
      [], 'activity_send_email_worker', [], $manager,
      $this->container->get('database'),
      $this->container->get('activity_creator.activity_notifications'),
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('queue'),
      $this->container->get('language_manager'), $mute,
    );
    // Enter the real worker after frequency/preferences selection: the method
    // loads real recipients and checks related_entity->access before handing
    // any message text to the downstream frequency plugin.
    $send = new \ReflectionMethod(ActivitySendEmailWorker::class, 'sendToFrequencyManager');
    $send->setAccessible(TRUE);
    $send->invoke($worker, [
      'activity' => $activity, 'message' => $message,
      'target_recipients' => [$outsider->id(), $member->id()],
      'frequency' => 'immediately',
    ]);
    $this->assertArrayNotHasKey((int) $outsider->id(), $delivered, 'No ordinary notice reaches the nonmember site role.');
    $this->assertSame('NOTICE-HOUSE-CANARY', $delivered[(int) $member->id()] ?? NULL, 'The member receives the same notice through the real access gate.');
  }

}
