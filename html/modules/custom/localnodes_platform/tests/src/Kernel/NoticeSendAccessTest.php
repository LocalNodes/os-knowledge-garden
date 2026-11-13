<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\activity_send_email\Plugin\ActivityDestination\EmailActivityDestination;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\localnodes_platform\Mail\NoticeDeferredFrequency;
use Drupal\localnodes_platform\Mail\NoticeDigestWorker;
use Drupal\localnodes_platform\Mail\NoticeImmediateFrequency;
use Drupal\mentions\Entity\Mentions;
use Drupal\message\Entity\Message;
use Drupal\message\Entity\MessageTemplate;
use Drupal\node\NodeInterface;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

/**
 * Real mention targets and the final mail processors enforce current access.
 *
 * @group localnodes_platform
 */
final class NoticeSendAccessTest extends GroupAccessKernelTestBase {

  protected static $modules = [
    'language', 'block', 'dropdown', 'profile', 'social_user', 'role_delegation',
    'social_core', 'message', 'dynamic_entity_reference', 'activity_creator',
    'activity_logger', 'activity_send', 'activity_send_email', 'mentions',
    'social_content_report',
    'localnodes_platform',
  ];

  private const CANARY = 'NOTICE_PRIVATE_AUTHOR_AND_TITLE_CANARY';

  private array $delivered = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['language']);
    foreach (['profile', 'message', 'activity', 'mentions'] as $type) $this->installEntitySchema($type);
    $this->installSchema('activity_creator', ['activity_notification_status']);
    $this->installSchema('activity_send_email', ['user_activity_digest']);
    foreach (['entity', 'message', 'recipient_user', 'recipient_group', 'destinations', 'status', 'output_text'] as $suffix) {
      $field = 'field_activity_' . $suffix;
      $this->installShippedConfig('activity_creator', ["field.storage.activity.$field", "field.field.activity.activity.$field"]);
    }
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_protect_private_groups();
    $this->config('activity_send.settings')->set('activity_send_offline_window', 0)->save();
    $this->config('social_user.settings')->set('do_not_send_emails_new_users', FALSE)->save();
    $this->config('system.site')->set('name', 'Notice test site')->set('mail', 'notice@example.invalid')->save();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $language = $this->container->get('language_manager');
    $language->setConfigOverrideLanguage($language->getDefaultLanguage());
    $this->container->get('router.builder')->rebuild();

    // Capture the transport call after the actual access checks, frequency
    // plugin and Twig renderer. No recipient, target or message is mocked.
    $transport = $this->createMock(MailManagerInterface::class);
    $transport->method('mail')->willReturnCallback(function ($module, $key, $to, $langcode, $params): array {
      $this->delivered[] = ['to' => $to, 'langcode' => $langcode, 'body' => (string) ($params['body'] ?? ''), 'subject' => (string) ($params['subject'] ?? '')];
      return ['result' => TRUE];
    });
    $this->container->set('plugin.manager.mail', $transport);
    MessageTemplate::create([
      'template' => 'notice_send_access_test', 'label' => 'Notice delivery test',
      'text' => array_fill(0, 3, ['value' => self::CANARY, 'format' => 'plain_text']),
      'settings' => ['token options' => ['token replace' => TRUE]],
      'third_party_settings' => ['activity_logger' => ['email_subject' => 'Notice test subject']],
    ])->save();
  }

  private function clearAccess(): void {
    foreach (['group', 'node', 'comment', 'mentions', 'activity'] as $type) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    }
  }

  /** @return array{GroupInterface, GroupInterface, NodeInterface, User, User} */
  private function sharedPage(string $visibility = 'members'): array {
    $a = $this->createFlexibleGroup('First house', $visibility);
    $b = $this->createFlexibleGroup('Second house', $visibility);
    $leaver = $this->createPersona('notice_leaver');
    $member = $this->createPersona('notice_current_member');
    $this->addMember($a, $leaver);
    $this->addMember($a, $member);
    $page = $this->createGroupTopic($a, 'Notice private title', 'Notice private body.', $visibility === 'public' ? 'public' : 'group');
    $b->addRelationship($page, 'group_node:topic');
    return [$a, $b, $page, $leaver, $member];
  }

  private function notice(NodeInterface $page, User $recipient): array {
    $comment = $this->createComment($page, 'Notice private reply.');
    $mention = Mentions::create([
      'entity_type' => 'comment', 'entity_id' => $comment->id(),
      'uid' => $recipient->id(), 'auid' => $this->siteOwner->id(),
    ]);
    $mention->save();
    $message = Message::create(['template' => 'notice_send_access_test', 'uid' => $this->siteOwner->id()]);
    $message->save();
    $activity = Activity::create([
      'user_id' => $this->siteOwner->id(), 'status' => 1,
      'field_activity_entity' => ['target_type' => 'mentions', 'target_id' => $mention->id()],
      'field_activity_message' => $message->id(),
      'field_activity_recipient_user' => $recipient->id(),
      'field_activity_destinations' => [['value' => 'stream_home']],
      'field_activity_status' => 1,
      'field_activity_output_text' => ['value' => self::CANARY, 'format' => 'plain_text'],
    ]);
    $activity->save();
    $this->assertSame(self::CANARY, EmailActivityDestination::getSendEmailOutputText($message));
    $this->assertTrue($mention->access('view', $recipient), 'The shipped mention wrapper itself does not check content access.');
    return [$activity, $message, $comment];
  }

  private function row(Activity $activity, User $recipient): void {
    $this->container->get('database')->insert('user_activity_digest')->fields([
      'uid' => $recipient->id(), 'activity' => $activity->id(),
      'frequency' => 'daily', 'timestamp' => 1800000000,
    ])->execute();
  }

  private function rows(Activity $activity, User $recipient): int {
    return (int) $this->container->get('database')->select('user_activity_digest', 'd')
      ->condition('uid', $recipient->id())->condition('activity', $activity->id())
      ->countQuery()->execute()->fetchField();
  }

  private function deliver(string $stage, Activity $activity, Message $message, User $recipient, ?string $body = NULL): void {
    if ($stage === 'queued_digest') {
      $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('activity_digest_worker');
      $this->assertInstanceOf(NoticeDigestWorker::class, $worker);
      $worker->processItem(['uid' => $recipient->id(), 'frequency' => 'daily', 'activities' => [$activity->id()]]);
    }
    else {
      $processor = $this->container->get('plugin.manager.emailfrequency')->createInstance($stage);
      $this->assertInstanceOf($stage === 'immediately' ? NoticeImmediateFrequency::class : NoticeDeferredFrequency::class, $processor);
      $processor->processItem($activity, $message, $recipient, $body);
    }
  }

  public static function stages(): array {
    return [['immediately'], ['daily'], ['queued_digest']];
  }

  /** @dataProvider stages */
  public function testLeavingFirstHouseCannotDeliverTheSecondHousePart(string $stage): void {
    [$a, $b, $page, $leaver] = $this->sharedPage();
    [$activity, $message] = $this->notice($page, $leaver);
    $this->row($activity, $leaver);
    $this->assertTrue($page->access('view', $leaver));
    $this->assertFalse($b->getMember($leaver));
    $a->removeMember($leaver);
    $this->clearAccess();
    $this->assertFalse($page->access('view', $leaver));
    $this->deliver($stage, $activity, $message, $leaver);
    $this->assertSame([], $this->delivered, 'The wrapper cannot carry private author/title metadata to the leaver.');
    $this->assertSame(0, $this->rows($activity, $leaver), 'Drop any stored row for this recipient and activity.');
  }

  /** @dataProvider stages */
  public function testNeverMemberCannotReceiveAPreparedNoticeAfterPrivacyChanges(string $stage): void {
    [$a, $b, $page, , $member] = $this->sharedPage('public');
    $never = $this->createPersona('notice_never_member');
    [$activity, $message] = $this->notice($page, $never);
    $activity->set('field_activity_recipient_user', [['target_id' => $never->id()], ['target_id' => $member->id()]])->save();
    $this->assertTrue($page->access('view', $never), 'The notice was prepared while its target was public.');
    $this->row($activity, $never);
    $this->row($activity, $member);
    foreach ([$a, $b] as $house) $house->set('field_flexible_group_visibility', 'members')->save();
    $page->set('field_content_visibility', 'group')->save();
    $this->clearAccess();
    $this->assertFalse($page->access('view', $never));
    $this->assertTrue($page->access('view', $member));
    $this->assertSame(1, $this->rows($activity, $never), 'Privacy changes have not yet consumed the queued row.');
    $this->deliver($stage, $activity, $message, $never);
    $this->assertSame([], $this->delivered);
    $this->assertSame(0, $this->rows($activity, $never));
    $this->assertSame(1, $this->rows($activity, $member), 'Keep another recipient of the same activity intact.');
  }

  /** @dataProvider stages */
  public function testCurrentMemberStillReceivesTheNotice(string $stage): void {
    [, , $page, , $member] = $this->sharedPage();
    [$activity, $message] = $this->notice($page, $member);
    $this->assertTrue($page->access('view', $member));
    if ($stage === 'queued_digest') $this->row($activity, $member);
    $this->deliver($stage, $activity, $message, $member);
    if ($stage === 'daily') {
      $this->assertSame([], $this->delivered);
      $this->assertSame(1, $this->rows($activity, $member));
    }
    else {
      $this->assertCount(1, $this->delivered);
      $this->assertSame($member->getEmail(), $this->delivered[0]['to']);
      $this->assertStringContainsString(self::CANARY, $this->delivered[0]['body']);
    }
  }

  /** @dataProvider stages */
  public function testGroupPrivacyChangesCloseACachedNoticeWithoutEditingItsPublicPage(string $stage): void {
    [$a, $b, $page] = $this->sharedPage('public');
    $never = $this->createPersona('notice_cached_never_member');
    [$activity, $message] = $this->notice($page, $never);
    $this->assertTrue($page->access('view', $never), 'Warm the native view and group relationship caches.');
    $this->row($activity, $never);
    foreach ([$a, $b] as $house) $house->set('field_flexible_group_visibility', 'members')->save();
    // Keep both the public page field and existing native access caches.
    $this->assertSame('public', $page->get('field_content_visibility')->value);
    $this->deliver($stage, $activity, $message, $never);
    $this->assertSame([], $this->delivered);
    $this->assertSame(0, $this->rows($activity, $never));
  }

  public static function reportStages(): array {
    return [['immediately'], ['queued_digest']];
  }

  /** @dataProvider stages */
  public function testUnpublishingTheReplyClosesAPreparedNoticeEvenWhenItsPageStaysReadable(string $stage): void {
    [, , $page, , $member] = $this->sharedPage();
    [$activity, $message, $comment] = $this->notice($page, $member);
    $this->row($activity, $member);
    $comment->setUnpublished()->save();
    $this->clearAccess();
    $this->assertTrue($page->access('view', $member));
    $this->assertFalse($comment->access('view', $member));
    $this->deliver($stage, $activity, $message, $member);
    $this->assertSame([], $this->delivered);
    $this->assertSame(0, $this->rows($activity, $member));
  }

  /** @dataProvider reportStages */
  public function testReportHandlerWithoutContentAccessGetsOnlyAGenericAlert(string $stage): void {
    [, , $page] = $this->sharedPage();
    $handler = $this->createPersona('notice_report_handler');
    // This is the content_reported Activity subsystem. It does not use or
    // change the separate report-a-problem form or its mail path.
    Role::create(['id' => 'notice_report_handler', 'label' => 'Report handler', 'permissions' => ['view inappropriate reports']])->save();
    $handler->addRole('notice_report_handler')->save();
    MessageTemplate::create([
      'template' => 'content_reported', 'label' => 'Content reported',
      'text' => array_fill(0, 3, ['value' => self::CANARY, 'format' => 'plain_text']),
      'settings' => ['token options' => ['token replace' => TRUE]],
      'third_party_settings' => ['activity_logger' => ['email_subject' => self::CANARY]],
    ])->save();
    $message = Message::create(['template' => 'content_reported', 'uid' => $this->siteOwner->id()]);
    $message->save();
    $activity = Activity::create([
      'user_id' => $this->siteOwner->id(), 'status' => 1,
      'field_activity_entity' => ['target_type' => 'node', 'target_id' => $page->id()],
      'field_activity_message' => $message->id(),
      'field_activity_recipient_user' => $handler->id(),
      'field_activity_destinations' => [['value' => 'stream_home']],
      'field_activity_status' => 1,
      'field_activity_output_text' => ['value' => self::CANARY, 'format' => 'plain_text'],
    ]);
    $activity->save();
    $this->assertTrue($handler->hasPermission('view inappropriate reports'));
    $this->assertFalse($page->access('view', $handler));
    if ($stage === 'queued_digest') $this->row($activity, $handler);
    $this->deliver($stage, $activity, $message, $handler, self::CANARY);
    $this->assertCount(1, $this->delivered, 'Responsible handlers still receive an alert.');
    $mail = $this->delivered[0];
    $this->assertSame($handler->getEmail(), $mail['to']);
    $this->assertStringContainsString('A report was made on this site.', $mail['body']);
    foreach ([self::CANARY, $page->label(), $this->siteOwner->getDisplayName()] as $private) {
      $this->assertStringNotContainsString($private, $mail['body'] . $mail['subject']);
    }
  }

  private function relationshipNotice(GroupInterface $house, NodeInterface $page, User $recipient): array {
    [$activity, $message] = $this->notice($page, $recipient);
    $relationship = NULL;
    foreach ($this->container->get('entity_type.manager')->getStorage('group_content')->loadByEntity($page) as $candidate) {
      if ((int) $candidate->getGroupId() === (int) $house->id()) $relationship = $candidate;
    }
    $this->assertInstanceOf(GroupRelationshipInterface::class, $relationship);
    $this->assertSame('group_node:topic', $relationship->getPluginId());
    $activity->set('field_activity_entity', ['target_type' => 'group_content', 'target_id' => $relationship->id()])->save();
    $this->assertInstanceOf(GroupRelationshipInterface::class, $activity->getRelatedEntity());
    return [$activity, $message];
  }

  public static function relationshipStates(): array {
    $cases = [];
    foreach (self::stages() as [$stage]) {
      foreach (['published', 'unpublished', 'former_without_marker'] as $state) $cases[$stage . '/' . $state] = [$stage, $state];
    }
    return $cases;
  }

  /** @dataProvider relationshipStates */
  public function testGroupRelationshipNoticeRequiresTheActualPage(string $stage, string $state): void {
    [$a, , $page, $leaver, $member] = $this->sharedPage();
    $recipient = $state === 'former_without_marker' ? $leaver : $member;
    [$activity, $message] = $this->relationshipNotice($a, $page, $recipient);
    $this->assertTrue($page->access('view', $recipient), 'The original page is an ordinary member-readable positive control.');
    if ($state === 'unpublished') $page->setUnpublished()->save();
    if ($state === 'former_without_marker') {
      $a->removeMember($recipient);
      $this->container->get('localnodes_platform.group_notice_cleanup')->clearDeparture((int) $recipient->id(), (int) $a->id());
      $this->assertNull($this->container->get('keyvalue')->get('localnodes_notice_departures')->get($recipient->id() . ':' . $a->id()));
    }
    $this->clearAccess();
    $this->assertSame($state === 'published', $page->access('view', $recipient));
    if ($state !== 'published' || $stage === 'queued_digest') $this->row($activity, $recipient);
    $this->deliver($stage, $activity, $message, $recipient);
    if ($state !== 'published') {
      $this->assertSame([], $this->delivered, 'A readable relationship does not authorize its inaccessible page.');
      $this->assertSame(0, $this->rows($activity, $recipient));
    }
    elseif ($stage === 'daily') {
      $this->assertSame([], $this->delivered);
      $this->assertSame(1, $this->rows($activity, $recipient));
    }
    else {
      $this->assertCount(1, $this->delivered);
      $this->assertStringContainsString(self::CANARY, $this->delivered[0]['body']);
    }
  }

  public static function digestCounts(): array { return [[1], [2]]; }

  /** @dataProvider digestCounts */
  public function testRenderedDigestHeadingContainsTheActualNumber(int $count): void {
    [, , $page, , $member] = $this->sharedPage();
    $ids = [];
    for ($i = 0; $i < $count; ++$i) {
      [$activity] = $this->notice($page, $member);
      $this->row($activity, $member);
      $ids[] = $activity->id();
    }
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('activity_digest_worker');
    $this->assertInstanceOf(NoticeDigestWorker::class, $worker);
    $worker->processItem(['uid' => $member->id(), 'frequency' => 'daily', 'activities' => $ids]);
    $this->assertCount(1, $this->delivered);
    $text = preg_replace('/\s+/', ' ', strip_tags($this->delivered[0]['body']));
    $this->assertStringContainsString('You have received ' . $count . ($count === 1 ? ' notification' : ' notifications'), $text);
    $this->assertStringNotContainsString(':count', $this->delivered[0]['body']);
  }

  public function testLeavingOneHouseKeepsTheSharedPageRowForMembershipInTheOtherHouse(): void {
    [$a, $b, $page, $leaver] = $this->sharedPage();
    $this->addMember($b, $leaver);
    [$activity, $message] = $this->notice($page, $leaver);
    $this->row($activity, $leaver);
    $a->removeMember($leaver);
    $this->clearAccess();
    $this->assertFalse((bool) $a->getMember($leaver));
    $this->assertNotFalse($b->getMember($leaver));
    $this->assertTrue($page->access('view', $leaver));
    $this->assertSame(1, $this->rows($activity, $leaver), 'Predelete cleanup preserves the row for a real other-house member.');
    $this->deliver('queued_digest', $activity, $message, $leaver);
    $this->assertCount(1, $this->delivered);
    $this->assertStringContainsString(self::CANARY, $this->delivered[0]['body']);
  }

}
