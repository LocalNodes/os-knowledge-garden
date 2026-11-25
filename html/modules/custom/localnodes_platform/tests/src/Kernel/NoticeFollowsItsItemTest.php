<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Plugin\views\filter\ActivityRecipientOnlyFilter;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * A notice opens for its recipient only while they may view its item.
 *
 * One notice of each kind a member gets about a group, in a group whose
 * content is for its members and in a secret group. Each kind is written
 * with the notification list as its only destination, and with the
 * notification list and mail.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class NoticeFollowsItsItemTest extends GroupAccessKernelTestBase {

  protected static $modules = [
    'block', 'dropdown', 'profile', 'social_user', 'role_delegation', 'social_post',
    'message', 'dynamic_entity_reference', 'activity_creator', 'activity_logger',
    'activity_send', 'activity_send_email', 'activity_basics', 'activity_viewer',
    'mentions', 'votingapi', 'social_event', 'social_content_report',
    'ginvite', 'social_group_invite', 'localnodes_platform',
  ];

  private const SHAPES = [
    'notifications only' => [['value' => 'notifications']],
    'notifications and mail' => [['value' => 'notifications'], ['value' => 'email']],
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['post', 'profile', 'message', 'activity', 'mentions', 'vote', 'vote_result', 'event_enrollment'] as $type) $this->installEntitySchema($type);
    $this->installSchema('activity_creator', ['activity_notification_status']);
    $this->installSchema('activity_send_email', ['user_activity_digest']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installShippedConfig('social_post', ['social_post.post_type.post', 'comment.type.post_comment']);
    foreach (['visibility', 'recipient_group', 'recipient_user', 'post', 'post_comments'] as $suffix) {
      $name = 'field_' . $suffix;
      $this->installShippedConfig('social_post', ["field.storage.post.$name", "field.field.post.post.$name"]);
    }
    foreach (['entity', 'message', 'recipient_user', 'recipient_group', 'destinations', 'status', 'output_text'] as $suffix) {
      $name = 'field_activity_' . $suffix;
      $this->installShippedConfig('activity_creator', ["field.storage.activity.$name", "field.field.activity.activity.$name"]);
    }
    $this->installShippedConfig('social_event', ['node.type.event', 'field.field.node.event.field_content_visibility']);
    foreach (['field_event_enroll', 'field_hide_enrollments', 'field_event_send_confirmation', 'field_enroll_method'] as $name) {
      $this->installShippedConfig('social_event', ["field.storage.node.$name", "field.field.node.event.$name"]);
    }
    foreach (['field_event', 'field_account', 'field_enrollment_status', 'field_request_or_invite_status'] as $name) {
      $this->installShippedConfig('social_event', ["field.storage.event_enrollment.$name", "field.field.event_enrollment.event_enrollment.$name"]);
    }
    $this->installShippedConfig('social_group_flexible_group', ['taxonomy.vocabulary.group_type', 'group.content_type.flexible_group-group_node-event']);
    $this->installShippedConfig('social_group', ['flag.flag.mute_group_notifications']);
    $this->installShippedConfig('social_content_report', ['flag.flag.report_node']);
    $this->installConfig(['ginvite']);
    $types = $this->container->get('entity_type.manager')->getStorage('group_content_type');
    if (!$types->load('flexible_group-group_invitation')) {
      $path = $this->container->get('extension.list.module')->getPath('social_group_invite') . '/config/optional';
      $types->createFromStorageRecord((new FileStorage($path))->read('group.content_type.flexible_group-group_invitation'))->save();
    }
    require_once DRUPAL_ROOT . '/core/includes/module.inc';
    $this->container->get('module_handler')->loadInclude('social_group_flexible_group', 'install');
    social_group_flexible_group_install();
    user_role_grant_permissions('authenticated', ['view public posts', 'view published post entities']);
    user_role_grant_permissions('verified', ['view community posts']);
    \Drupal\votingapi\Entity\VoteType::create(['id' => 'like', 'label' => 'Like', 'value_type' => 'points'])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * One item of each kind in a group, keyed by the notice kind.
   */
  private function items(GroupInterface $group, AccountInterface $author, AccountInterface $recipient, string $tag): array {
    $topic = $this->createGroupTopic($group, "$tag plan", 'Body.', 'group');
    $comment = $this->createComment($topic, "$tag comment.");
    $comment->setOwnerId($author->id())->save();
    $post = Post::create([
      'type' => 'post', 'user_id' => $author->id(), 'status' => 1,
      'field_visibility' => '3', 'field_recipient_group' => $group->id(),
      'field_post' => ['value' => "$tag post.", 'format' => 'plain_text'],
    ]);
    $post->save();
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_content')->loadByEntity($topic);
    $invitation = $group->addRelationship(User::load($recipient->id()), 'group_invitation', ['invitation_status' => 1]);
    $mention = \Drupal\mentions\Entity\Mentions::create([
      'entity_type' => 'comment', 'entity_id' => $comment->id(), 'uid' => $recipient->id(), 'auid' => $author->id(),
    ]);
    $mention->save();
    $like = \Drupal\votingapi\Entity\Vote::create([
      'type' => 'like', 'entity_type' => 'node', 'entity_id' => $topic->id(), 'value' => 1, 'value_type' => 'points', 'user_id' => $author->id(),
    ]);
    $like->save();
    $report = \Drupal\flag\Entity\Flagging::create([
      'flag_id' => 'report_node', 'entity_type' => 'node', 'entity_id' => $topic->id(), 'uid' => $author->id(), 'global' => 0,
    ]);
    $report->save();
    $event = \Drupal\node\Entity\Node::create([
      'type' => 'event', 'title' => "$tag gathering", 'uid' => $author->id(),
      'status' => 1, 'field_content_visibility' => 'group',
    ]);
    $event->save();
    $group->addRelationship($event, 'group_node:event');
    $enrollment = \Drupal\social_event\Entity\EventEnrollment::create([
      'user_id' => $author->id(), 'field_event' => $event->id(), 'field_account' => $recipient->id(),
      'field_enrollment_status' => 0, 'field_request_or_invite_status' => 4, 'status' => 1,
    ]);
    $enrollment->save();
    return [
      'topic' => $topic,
      'comment' => $comment,
      'post' => $post,
      'new content in a group you joined' => reset($relationships),
      'group invitation' => $invitation,
      'mention' => $mention,
      'like' => $like,
      'content report' => $report,
      'event invitation' => $enrollment,
    ];
  }

  private function notice(AccountInterface $author, array $recipients, ContentEntityInterface $item, int $gid, array $destinations): int {
    $activity = Activity::create([
      'user_id' => $author->id(), 'status' => 1,
      'field_activity_entity' => [['target_type' => $item->getEntityTypeId(), 'target_id' => $item->id()]],
      'field_activity_recipient_user' => array_map(static fn (AccountInterface $a): array => ['target_type' => 'user', 'target_id' => $a->id()], $recipients),
      'field_activity_recipient_group' => $gid,
      'field_activity_destinations' => $destinations,
      'field_activity_status' => 1,
      'field_activity_output_text' => ['value' => '<p>A notice.</p>', 'format' => 'plain_text'],
    ]);
    $activity->save();
    return (int) $activity->id();
  }

  private function resetAccess(): void {
    drupal_static_reset('getAllGroupsForUser');
    $types = $this->container->get('entity_type.manager');
    foreach (['activity', 'node', 'group', 'group_content', 'comment', 'post', 'event_enrollment', 'mentions', 'vote', 'flagging', 'user'] as $type) {
      $types->getAccessControlHandler($type)->resetCache();
    }
    foreach (['event_enrollment', 'group_content', 'user'] as $type) $types->getStorage($type)->resetCache();
  }

  /**
   * Whether the viewer opens the notice's own page.
   */
  private function opens(int $id, AccountInterface $viewer): string {
    $this->resetAccess();
    $entity = Activity::load($id)->access('view', $viewer);
    $this->resetAccess();
    $route = $this->container->get('access_manager')->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $viewer);
    if ($entity === $route) return $entity ? 'opens' : 'refused';
    return 'entity ' . ($entity ? 'opens' : 'refused') . ', route ' . ($route ? 'opens' : 'refused');
  }

  /**
   * A member who has left the group is refused each notice about it.
   *
   * While a member, the recipient opens every kind. Once they have left,
   * every kind is refused, and the notifications rule refuses it on its own
   * for every kind that points at an item; a group invitation points at the
   * invited account, and the departed-member rule refuses it.
   */
  public function testMemberWhoLeftIsRefusedEveryKindOfNotice(): void {
    $ana = $this->createPersona('Ana_Author');
    $carl = $this->createPersona('Carl_Member');
    Role::create(['id' => 'reviewer', 'label' => 'Reviewer'])->save();
    user_role_grant_permissions('reviewer', ['view inappropriate reports']);
    User::load($carl->id())->addRole('reviewer')->save();
    $carl = User::load($carl->id());
    $groups = [
      'members-only group' => $this->createFlexibleGroup('Larkspur Council', 'community'),
      'secret group' => $this->createFlexibleGroup('Mendwell Circle', 'members'),
    ];
    $ids = $rule = [];
    foreach ($groups as $where => $group) {
      $this->addMember($group, $ana);
      $this->addMember($group, $carl);
      foreach ($this->items($group, $ana, $carl, $where) as $kind => $item) {
        foreach (self::SHAPES as $shape => $destinations) {
          $ids["$kind ($shape), $where"] = $this->notice($ana, [$carl], $item, (int) $group->id(), $destinations);
          if ($kind !== 'group invitation') $rule[] = "$kind ($shape), $where";
        }
      }
    }
    $outcomes = $expected = [];
    foreach ($ids as $label => $id) {
      $outcomes["$label: a member opens it"] = $this->opens($id, $carl);
      $expected["$label: a member opens it"] = 'opens';
    }
    foreach ($groups as $group) $group->removeMember($carl);
    foreach ($ids as $label => $id) {
      $outcomes["$label: a member who has left is refused it"] = $this->opens($id, $carl);
      $expected["$label: a member who has left is refused it"] = 'refused';
    }
    foreach ($rule as $label) {
      $this->resetAccess();
      $forbidden = ActivityRecipientOnlyFilter::personalNotificationAccess(Activity::load($ids[$label]), 'view', $carl)->isForbidden();
      $outcomes["$label: the notifications rule refuses a member who has left"] = $forbidden ? 'refused' : 'not refused';
      $expected["$label: the notifications rule refuses a member who has left"] = 'refused';
    }
    $this->assertSame($expected, $outcomes);
  }

  /**
   * A notice about a record whose item is gone opens for its recipient.
   */
  public function testNoticeAboutARecordWhoseItemIsGoneOpens(): void {
    $ana = $this->createPersona('Ana_Author');
    $carl = $this->createPersona('Carl_Member');
    $ben = $this->createPersona('Ben_Other');
    $group = $this->createFlexibleGroup('Larkspur Council', 'community');
    $this->addMember($group, $ana);
    $this->addMember($group, $carl);
    // A mention whose comment is gone.
    $mention = \Drupal\mentions\Entity\Mentions::create([
      'entity_type' => 'comment', 'entity_id' => 999999, 'uid' => $carl->id(), 'auid' => $ana->id(),
    ]);
    $mention->save();
    $ids = [];
    foreach (self::SHAPES as $shape => $destinations) {
      $ids[$shape] = $this->notice($ana, [$carl], $mention, (int) $group->id(), $destinations);
    }
    $outcomes = [];
    foreach ($ids as $shape => $id) {
      $outcomes["$shape: its recipient opens it"] = $this->opens($id, $carl);
      $outcomes["$shape: another member is refused it"] = $this->opens($id, $ben);
    }
    $this->assertSame([
      'notifications only: its recipient opens it' => 'opens',
      'notifications only: another member is refused it' => 'refused',
      'notifications and mail: its recipient opens it' => 'opens',
      'notifications and mail: another member is refused it' => 'refused',
    ], $outcomes);
  }

  /**
   * The rule's answer follows changes to what it depends on.
   *
   * The records a notice points through, its items, and the viewer's
   * permissions.
   */
  public function testAnswerKeepsTheCacheDataOfWhatItAsked(): void {
    $ana = $this->createPersona('Ana_Author');
    $carl = $this->createPersona('Carl_Member');
    $group = $this->createFlexibleGroup('Larkspur Council', 'community');
    $this->addMember($group, $ana);
    $this->addMember($group, $carl);
    $items = $this->items($group, $ana, $carl, 'Larkspur');
    $mention = $items['mention'];
    $comment = $items['comment'];
    $enrollment = $items['event invitation'];
    $expected = [
      'mention' => [$mention, ['mentions:' . $mention->id(), 'comment:' . $comment->id(), 'node:' . $comment->getCommentedEntityId()]],
      'event invitation' => [$enrollment, ['event_enrollment:' . $enrollment->id(), 'node:' . $enrollment->get('field_event')->target_id]],
    ];
    foreach ($expected as $kind => [$item, $tags]) {
      foreach (self::SHAPES as $shape => $destinations) {
        $id = $this->notice($ana, [$carl], $item, (int) $group->id(), $destinations);
        $this->resetAccess();
        $result = ActivityRecipientOnlyFilter::personalNotificationAccess(Activity::load($id), 'view', $carl);
        $this->assertTrue($result->isAllowed(), "$kind ($shape): a member opens it.");
        foreach ($tags as $tag) $this->assertContains($tag, $result->getCacheTags(), "$kind ($shape): the answer follows changes to $tag.");
        $this->assertContains('user.permissions', $result->getCacheContexts(), "$kind ($shape): the answer changes with the viewer's permissions.");
      }
    }
    // For an activity administrator and another member, the answer follows
    // changes to their permissions.
    $admin = $this->createUser(['administer activity entities']);
    $ben = $this->createPersona('Ben_Other');
    foreach (['an activity administrator' => $admin, 'another member' => $ben] as $who => $viewer) {
      $this->resetAccess();
      $result = ActivityRecipientOnlyFilter::personalNotificationAccess(Activity::load($id), 'view', $viewer);
      $this->assertContains('user.permissions', $result->getCacheContexts(), "$who: the answer changes with the viewer's permissions.");
    }
  }

  /**
   * A blocked account is refused a notice, also as its first recipient.
   */
  public function testBlockedRecipientIsRefused(): void {
    $ana = $this->createPersona('Ana_Author');
    $carl = $this->createPersona('Carl_Member');
    $group = $this->createFlexibleGroup('Larkspur Council', 'community');
    $this->addMember($group, $ana);
    $this->addMember($group, $carl);
    $topic = $this->createGroupTopic($group, 'Larkspur plan', 'Body.', 'community');
    $ids = [];
    foreach (self::SHAPES as $shape => $destinations) {
      $ids[$shape] = $this->notice($ana, [$carl, $ana], $topic, (int) $group->id(), $destinations);
    }
    $active = $blocked = [];
    foreach ($ids as $shape => $id) $active[$shape] = $this->opens($id, $carl);
    $this->assertSame(array_fill_keys(array_keys($ids), 'opens'), $active, 'An active recipient opens it.');
    User::load($carl->id())->block()->save();
    $account = User::load($carl->id());
    foreach ($ids as $shape => $id) {
      $blocked[$shape] = $this->opens($id, $account);
      $this->resetAccess();
      $result = ActivityRecipientOnlyFilter::personalNotificationAccess(Activity::load($id), 'view', $account);
      $this->assertTrue($result->isForbidden(), "$shape: the notifications rule refuses a blocked account.");
      $this->assertContains('user:' . $account->id(), $result->getCacheTags(), "$shape: the refusal varies with the account.");
    }
    $this->assertSame(array_fill_keys(array_keys($ids), 'refused'), $blocked, 'A blocked account is refused it.');
  }

}
