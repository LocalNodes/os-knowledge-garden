<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\comment\Entity\Comment;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A member's notifications page shows only the viewer's own notifications.
 *
 * Runs the shipped notifications view with rows written as the activity
 * creator writes them: the recipient user, the notifications destination
 * and the template's text.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class NotificationsOwnOnlyTest extends GroupAccessKernelTestBase {

  protected static $modules = [
    'block', 'dropdown', 'profile', 'social_user', 'role_delegation', 'social_post',
    'message', 'dynamic_entity_reference', 'activity_creator', 'activity_logger',
    'activity_basics', 'activity_viewer', 'mentions', 'votingapi', 'social_event', 'localnodes_platform',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['post', 'profile', 'message', 'activity', 'mentions', 'vote', 'vote_result'] as $type) $this->installEntitySchema($type);
    $this->installSchema('activity_creator', ['activity_notification_status']);
    $this->installShippedConfig('social_post', ['social_post.post_type.post', 'comment.type.post_comment']);
    foreach (['visibility', 'recipient_group', 'recipient_user', 'post', 'post_comments'] as $suffix) {
      $name = 'field_' . $suffix;
      $this->installShippedConfig('social_post', ["field.storage.post.$name", "field.field.post.post.$name"]);
    }
    foreach (['entity', 'message', 'recipient_user', 'recipient_group', 'destinations', 'status', 'output_text'] as $suffix) {
      $name = 'field_activity_' . $suffix;
      $this->installShippedConfig('activity_creator', ["field.storage.activity.$name", "field.field.activity.activity.$name"]);
    }
    $this->installShippedConfig('activity_creator', ['core.entity_view_display.activity.activity.default']);
    // Events and their enrollments, for event invitations.
    $this->installEntitySchema('event_enrollment');
    $this->installShippedConfig('social_event', ['node.type.event', 'field.field.node.event.field_content_visibility']);
    foreach (['field_event_enroll', 'field_hide_enrollments', 'field_event_send_confirmation', 'field_enroll_method'] as $name) {
      $this->installShippedConfig('social_event', ["field.storage.node.$name", "field.field.node.event.$name"]);
    }
    foreach (['field_event', 'field_account', 'field_enrollment_status', 'field_request_or_invite_status'] as $name) {
      $this->installShippedConfig('social_event', ["field.storage.event_enrollment.$name", "field.field.event_enrollment.event_enrollment.$name"]);
    }
    $this->installShippedConfig('social_group_flexible_group', ['taxonomy.vocabulary.group_type', 'group.content_type.flexible_group-group_node-event']);
    $this->installShippedConfig('social_group', ['flag.flag.mute_group_notifications']);
    require_once DRUPAL_ROOT . '/core/includes/module.inc';
    $this->container->get('module_handler')->loadInclude('social_group_flexible_group', 'install');
    social_group_flexible_group_install();
    user_role_grant_permissions('authenticated', ['view public posts', 'view published post entities']);
    user_role_grant_permissions('verified', ['view community posts']);
    $this->container->get('views.views_data')->clear();
    $path = $this->container->get('extension.list.module')->getPath('social_activity');
    $definition = (new FileStorage($path . '/config/install'))->read('views.view.activity_stream_notifications');
    $this->assertIsArray($definition);
    View::create($definition)->save();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Notifications for Carl, a member of a hidden group, one per kind.
   *
   * @return array
   *   Personas, and Carl's activity ids keyed by kind.
   */
  private function fixture(): array {
    $ana = $this->createPersona('Ana_Author');
    $carl = $this->createPersona('Carl_Member');
    $ben = $this->createPersona('Ben_Other');
    $hidden = $this->createFlexibleGroup('Mendwell Stewards Circle', 'members');
    $this->addMember($hidden, $ana);
    $this->addMember($hidden, $carl);
    $topic = $this->createGroupTopic($hidden, 'Carl hidden plan', 'Body.', 'group');
    $topic->setOwnerId($carl->id())->save();
    $post = Post::create([
      'type' => 'post', 'user_id' => $ana->id(), 'status' => 1,
      'field_visibility' => '3', 'field_recipient_group' => $hidden->id(),
      'field_post' => ['value' => 'Hidden post.', 'format' => 'plain_text'],
    ]);
    $post->save();
    $carlComment = $this->createComment($topic, 'Carl comment.');
    $carlComment->setOwnerId($carl->id())->save();
    $comment = $this->createComment($topic, 'Ana comment.');
    $comment->setOwnerId($ana->id())->save();
    $reply = Comment::create([
      'entity_type' => 'node', 'entity_id' => $topic->id(), 'field_name' => 'field_topic_comments',
      'comment_type' => 'comment', 'pid' => $carlComment->id(), 'uid' => $ana->id(), 'status' => 1,
      'field_comment_body' => ['value' => 'Ana reply.', 'format' => 'plain_text'],
    ]);
    $reply->save();
    $membership = $hidden->getMember($ana)->getGroupRelationship();
    $a = 'Ana_Author';
    // Template texts of the kinds a member gets, each addressed to Carl.
    $kinds = [
      'group post' => [$post, $hidden->id(), "$a created a post in the Mendwell Stewards Circle group"],
      'group topic' => [$topic, $hidden->id(), "$a created a Topic Carl hidden plan in the Mendwell Stewards Circle group"],
      'comment on your content' => [$comment, NULL, "$a commented on your topic Carl hidden plan"],
      'reply' => [$reply, NULL, "$a replied on your comment in topic Carl hidden plan"],
      'followed content' => [$comment, NULL, "$a commented on Carl_Member's topic Carl hidden plan you are following"],
      'mention' => [$post, NULL, "$a mentioned you in a post"],
      'membership request' => [$membership, $hidden->id(), "$a has requested membership to the Mendwell Stewards Circle group"],
      'invitation' => [$membership, $hidden->id(), "$a invited you to join the Mendwell Stewards Circle group"],
    ];
    $ids = [];
    foreach ($kinds as $kind => [$entity, $gid, $text]) {
      $ids[$kind] = $this->notification($ana, $carl, $entity, $gid, $text);
    }
    $own = $this->notification($ana, $ben, $post, NULL, "$a mentioned you in a post");
    return ['ana' => $ana, 'carl' => $carl, 'ben' => $ben, 'carls' => $ids, 'bens' => $own];
  }

  private function notification(AccountInterface $author, AccountInterface|array $recipient, ContentEntityInterface $entity, $gid, string $text): int {
    $recipients = array_map(static fn (AccountInterface $a): array => ['target_type' => 'user', 'target_id' => $a->id()], is_array($recipient) ? $recipient : [$recipient]);
    $activity = Activity::create([
      'user_id' => $author->id(), 'status' => 1,
      'field_activity_entity' => [['target_type' => $entity->getEntityTypeId(), 'target_id' => $entity->id()]],
      'field_activity_recipient_user' => $recipients,
      'field_activity_recipient_group' => $gid,
      'field_activity_destinations' => [['value' => 'notifications']],
      'field_activity_status' => 1,
      'field_activity_output_text' => ['value' => "<p>$text</p>", 'format' => 'plain_text'],
    ]);
    $activity->save();
    return (int) $activity->id();
  }

  /**
   * The notifications list for one recipient number, as one viewer.
   */
  private function notifications(AccountInterface $viewer, string $display, ?AccountInterface $recipient, ?string $without = NULL): array {
    $this->container->get('current_user')->setAccount($viewer);
    drupal_static_reset('getAllGroupsForUser');
    $view = Views::getView('activity_stream_notifications');
    $this->assertTrue($view->setDisplay($display));
    $view->setItemsPerPage(0);
    if ($recipient) $view->setArguments([(string) $recipient->id()]);
    if ($without) $view->removeHandler($display, 'filter', $without);
    // Below the page's own refusal: the list itself.
    $view->build();
    $view->execute();
    $ids = array_map(static fn ($row): int => (int) $row->_entity->id(), $view->result);
    $view->rowPlugin->preRender($view->result);
    $rows = [];
    foreach ($view->result as $row) $rows[] = $view->rowPlugin->render($row);
    $html = (string) $this->container->get('renderer')->renderInIsolation($rows);
    $view->destroy();
    return [$ids, $html];
  }

  public function testOtherMembersNotificationsAreNotListed(): void {
    $f = $this->fixture();
    foreach (['page_1', 'block_1'] as $display) {
      [$ids, $html] = $this->notifications($f['ben'], $display, $f['carl']);
      foreach ($f['carls'] as $kind => $id) $this->assertNotContains($id, $ids, "$display: no '$kind' notification of another member.");
      $this->assertStringNotContainsString('Mendwell Stewards Circle', $html);
      $this->assertStringNotContainsString('Carl hidden plan', $html);
    }
  }

  public function testSignedOutVisitorGetsNoNotifications(): void {
    $f = $this->fixture();
    // A notice addressed to the anonymous account.
    $comment = Comment::load(Activity::load($f['carls']['comment on your content'])->get('field_activity_entity')->target_id);
    $this->notification($f['ana'], $this->anonymous(), $comment, NULL, 'Ana_Author commented on your topic Carl hidden plan in Mendwell Stewards Circle');
    $view = Views::getView('activity_stream_notifications');
    $this->assertFalse($view->access('page_1', $this->anonymous()));
    $this->assertFalse($view->access('block_1', $this->anonymous()));
    $wrong = [];
    // The notification centre's own address is for signed-in members only.
    if ($this->container->get('access_manager')->checkNamedRoute('activity_creator.stream.notifications', [], $this->anonymous())) $wrong[] = 'the notification centre is for signed-in members';
    // The recipient filter lists nothing for a signed-out visitor. Only this
    // filter is tested here.
    foreach (['page_1', 'block_1'] as $display) {
      [$ids, $html] = $this->notifications($this->anonymous(), $display, NULL, 'activity_notification_visibility_access_filter');
      if ($ids !== []) $wrong[] = "$display: a signed-out visitor has no notifications";
      if (str_contains($html, 'Carl hidden plan')) $wrong[] = "$display: a notice's text is for its recipients only";
    }
    $this->assertSame([], $wrong);
  }

  /**
   * A notice's own page opens for its recipients only.
   */
  public function testNotificationPageOpensForItsRecipientOnly(): void {
    $f = $this->fixture();
    $carl = $f['carl'];
    // A mention of Carl in the hidden group, as mentions are written: two
    // destinations, the notice list and email.
    $topic = Comment::load(Activity::load($f['carls']['comment on your content'])->get('field_activity_entity')->target_id)->getCommentedEntity();
    $mention = \Drupal\mentions\Entity\Mentions::create([
      'entity_type' => 'node', 'entity_id' => $topic->id(), 'uid' => $carl->id(), 'auid' => $f['ana']->id(),
    ]);
    $mention->save();
    $ids = $f['carls'];
    $ids['mention in a hidden topic'] = $this->notification($f['ana'], $carl, $mention, NULL, 'Ana_Author mentioned you in Carl hidden plan');
    // A like of Carl's hidden topic: the notice's related item is the vote.
    \Drupal\votingapi\Entity\VoteType::create(['id' => 'like', 'label' => 'Like', 'value_type' => 'points'])->save();
    $like = \Drupal\votingapi\Entity\Vote::create([
      'type' => 'like', 'entity_type' => 'node', 'entity_id' => $topic->id(), 'value' => 1, 'value_type' => 'points', 'user_id' => $f['ana']->id(),
    ]);
    $like->save();
    $ids['like of a hidden topic'] = $this->notification($f['ana'], $carl, $like, NULL, 'Ana_Author liked your topic Carl hidden plan');
    // A notice for several members, Carl not first.
    $ids['several members'] = $this->notification($f['ana'], [$f['ana'], $carl], $mention, NULL, 'Ana_Author mentioned you in Carl hidden plan');
    $access = $this->container->get('access_manager');
    $wrong = [];
    foreach ($ids as $kind => $id) {
      Activity::load($id)->set('field_activity_destinations', [['value' => 'notifications'], ['value' => 'email']])->save();
      $this->container->get('entity_type.manager')->getAccessControlHandler('activity')->resetCache();
      foreach (['another member' => $f['ben'], 'a signed-out visitor' => $this->anonymous()] as $who => $viewer) {
        if ($access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $viewer)) $wrong[] = "'$kind' ($who): a notice opens only for its recipients";
      }
      $this->assertTrue($access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $carl), "Carl opens his own '$kind' notice.");
    }
    $this->assertSame([], $wrong);
  }

  /**
   * Every recipient opens a notice for several members; nobody else does.
   *
   * A notice whose only destination is the notification list, as
   * invitations are written, addressed to two members in either order. A
   * blocked account is refused it, also as its first recipient.
   */
  public function testEveryRecipientOpensANoticeForSeveralMembers(): void {
    $f = $this->fixture();
    $access = $this->container->get('access_manager');
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('activity');
    $post = Post::load(Activity::load($f['carls']['group post'])->get('field_activity_entity')->target_id);
    $problems = [];
    foreach (['Ana first' => [$f['ana'], $f['carl']], 'Carl first' => [$f['carl'], $f['ana']]] as $order => $recipients) {
      $id = $this->notification($f['ana'], $recipients, $post, NULL, 'Ana_Author invited you to join the Mendwell Stewards Circle group');
      $handler->resetCache();
      foreach (['Ana' => $f['ana'], 'Carl' => $f['carl']] as $who => $viewer) {
        if (!$access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $viewer)) $problems[] = "$order: recipient $who opens it";
      }
      foreach (['another member' => $f['ben'], 'a signed-out visitor' => $this->anonymous()] as $who => $viewer) {
        if ($access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $viewer)) $problems[] = "$order: $who is refused it";
      }
    }
    $this->assertSame([], $problems);
    // A blocked account is refused a notice in which it is the first
    // recipient, in both shapes.
    $ids = ['notifications only' => $id, 'notifications and mail' => $this->notification($f['ana'], [$f['carl'], $f['ana']], $post, NULL, 'Ana_Author mentioned you in a post')];
    Activity::load($ids['notifications and mail'])->set('field_activity_destinations', [['value' => 'notifications'], ['value' => 'email']])->save();
    \Drupal\user\Entity\User::load($f['carl']->id())->block()->save();
    $blocked = \Drupal\user\Entity\User::load($f['carl']->id());
    $rule = [\Drupal\localnodes_platform\Plugin\views\filter\ActivityRecipientOnlyFilter::class, 'personalNotificationAccess'];
    foreach ($ids as $shape => $notice) {
      $this->resetAccess();
      $result = $rule(Activity::load($notice), 'view', $blocked);
      $this->assertTrue($result->isForbidden(), "$shape: the rule refuses a blocked account.");
      $this->assertContains('user:' . $blocked->id(), $result->getCacheTags(), "$shape: the refusal varies with the account.");
      $this->assertFalse($access->checkNamedRoute('entity.activity.canonical', ['activity' => $notice], $blocked), "$shape: a blocked account is refused it.");
    }
  }

  /**
   * A recipient who has left a hidden group no longer opens its notices.
   *
   * A notice that also goes by mail follows its item too: a topic in a
   * hidden group is for its members.
   */
  public function testRemovedRecipientCannotOpenNoticeAboutHiddenTopic(): void {
    $f = $this->fixture();
    $group = $this->createFlexibleGroup('Larkspur Quiet Council', 'members');
    $this->addMember($group, $f['ana']);
    $this->addMember($group, $f['carl']);
    $topic = $this->createGroupTopic($group, 'Larkspur private agenda', 'Body.', 'group');
    $topic->setOwnerId($f['ana']->id())->save();
    $id = $this->notification($f['ana'], $f['carl'], $topic, $group->id(), 'Ana_Author created a Topic Larkspur private agenda in the Larkspur Quiet Council group');
    Activity::load($id)->set('field_activity_destinations', [['value' => 'notifications'], ['value' => 'email']])->save();
    $access = $this->container->get('access_manager');
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('activity');
    $handler->resetCache();
    $this->assertTrue($access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $f['carl']), 'A member of the group opens the notice.');
    $group->removeMember(\Drupal\user\Entity\User::load($f['carl']->id()));
    drupal_static_reset('getAllGroupsForUser');
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $handler->resetCache();
    $this->assertFalse($access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $f['carl']), 'A recipient who has left the hidden group cannot open the notice.');
  }

  /**
   * A notice for the notification list alone follows its item.
   *
   * A recipient opens it only while they may view the topic it is about,
   * in whichever place they stand among its recipients.
   */
  public function testRecipientWhoLeftTheGroupIsRefusedANoticeForTheListOnly(): void {
    $f = $this->fixture();
    $group = $this->createFlexibleGroup('Larkspur Quiet Council', 'members');
    $this->addMember($group, $f['ana']);
    $this->addMember($group, $f['carl']);
    $topic = $this->createGroupTopic($group, 'Larkspur private agenda', 'Body.', 'group');
    $text = 'Ana_Author invited you to join the Larkspur Quiet Council group';
    $ids = [
      'Carl first' => $this->notification($f['ana'], [$f['carl'], $f['ana']], $topic, $group->id(), $text),
      'Carl second' => $this->notification($f['ana'], [$f['ana'], $f['carl']], $topic, $group->id(), $text),
    ];
    $access = $this->container->get('access_manager');
    $this->resetAccess();
    foreach ($ids as $order => $id) {
      $this->assertTrue($access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $f['carl']), "$order: a member of the group opens the notice.");
    }
    $group->removeMember(\Drupal\user\Entity\User::load($f['carl']->id()));
    $this->resetAccess();
    $problems = [];
    foreach ($ids as $order => $id) {
      if ($access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $f['carl'])) $problems[] = "$order: a recipient who has left the group is refused the notice";
      if (!$access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $f['ana'])) $problems[] = "$order: a recipient who is still a member opens the notice";
    }
    $this->assertSame([], $problems);
  }

  /**
   * Being a recipient is enough for a notice about no item or a gone item.
   */
  public function testRecipientOpensNoticeAboutNoItemOrAGoneItem(): void {
    $f = $this->fixture();
    $comment = $this->createComment(Comment::load(Activity::load($f['carls']['comment on your content'])->get('field_activity_entity')->target_id)->getCommentedEntity(), 'Soon gone.');
    $gone = $this->notification($f['ana'], [$f['ana'], $f['carl']], $comment, NULL, 'Ana_Author commented on your topic');
    $comment->delete();
    $none = $this->notification($f['ana'], [$f['ana'], $f['carl']], $comment, NULL, 'Ana_Author sent you a notice');
    Activity::load($none)->set('field_activity_entity', [])->save();
    $access = $this->container->get('access_manager');
    $this->resetAccess();
    $problems = [];
    foreach (['about no item' => $none, 'about a gone item' => $gone] as $kind => $id) {
      foreach (['Ana' => $f['ana'], 'Carl' => $f['carl']] as $who => $viewer) {
        if (!$access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $viewer)) $problems[] = "$kind: recipient $who opens it";
      }
      foreach (['another member' => $f['ben'], 'a signed-out visitor' => $this->anonymous()] as $who => $viewer) {
        if ($access->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $viewer)) $problems[] = "$kind: $who is refused it";
      }
    }
    $this->assertSame([], $problems);
  }

  /**
   * An event invitation follows the event it invites to.
   *
   * The invitee opens it while they may view the event, and is refused it
   * once they may not; when the event is gone, being invited is enough.
   */
  public function testEventInvitationFollowsTheEvent(): void {
    $f = $this->fixture();
    $group = $this->createFlexibleGroup('Larkspur Quiet Council', 'members');
    $this->addMember($group, $f['ana']);
    $this->addMember($group, $f['carl']);
    $event = \Drupal\node\Entity\Node::create([
      'type' => 'event', 'title' => 'Larkspur private gathering', 'uid' => $f['ana']->id(),
      'status' => 1, 'field_content_visibility' => 'group',
    ]);
    $event->save();
    $group->addRelationship($event, 'group_node:event');
    $invite = function (AccountInterface $invitee) use ($f, $event): int {
      $enrollment = \Drupal\social_event\Entity\EventEnrollment::create([
        'user_id' => $f['ana']->id(), 'field_event' => $event->id(), 'field_account' => $invitee->id(),
        'field_enrollment_status' => 0, 'field_request_or_invite_status' => 4, 'status' => 1,
      ]);
      $enrollment->save();
      return $this->notification($f['ana'], [$f['ana'], $invitee], $enrollment, NULL, 'Ana_Author invited you to the event Larkspur private gathering');
    };
    $carls = $invite($f['carl']);
    $bens = $invite($f['ben']);
    $access = $this->container->get('access_manager');
    $this->resetAccess();
    $this->assertFalse(\Drupal\social_event\Entity\EventEnrollment::load(Activity::load($carls)->get('field_activity_entity')->target_id)->access('view', $f['carl']), 'The invitee may not view the enrollment record itself.');
    $this->assertTrue($event->access('view', $f['carl']), 'A member of the group may view the event.');
    $this->assertTrue($access->checkNamedRoute('entity.activity.canonical', ['activity' => $carls], $f['carl']), 'An invitee who may view the event opens the invitation.');
    $this->assertFalse($access->checkNamedRoute('entity.activity.canonical', ['activity' => $bens], $f['ben']), 'An invitee who may not view the event is refused the invitation.');
    $group->removeMember(\Drupal\user\Entity\User::load($f['carl']->id()));
    $this->resetAccess();
    $this->assertFalse($access->checkNamedRoute('entity.activity.canonical', ['activity' => $carls], $f['carl']), 'An invitee who has left the group is refused the invitation.');
    $event->delete();
    $this->resetAccess();
    $this->assertTrue($access->checkNamedRoute('entity.activity.canonical', ['activity' => $bens], $f['ben']), 'When the event is gone, being invited is enough.');
  }

  private function resetAccess(): void {
    drupal_static_reset('getAllGroupsForUser');
    foreach (['activity', 'node', 'group', 'group_content', 'comment', 'event_enrollment'] as $type) {
      $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    }
    // A fresh page load reads an enrollment's event anew.
    $this->container->get('entity_type.manager')->getStorage('event_enrollment')->resetCache();
  }

  /**
   * The rule holds for a signed-out visitor and varies by viewer.
   */
  public function testNoticeForAnonymousAccountIsRefusedAndCachedPerUser(): void {
    $f = $this->fixture();
    $rule = [\Drupal\localnodes_platform\Plugin\views\filter\ActivityRecipientOnlyFilter::class, 'personalNotificationAccess'];
    $comment = Comment::load(Activity::load($f['carls']['comment on your content'])->get('field_activity_entity')->target_id);
    // A notice left addressed to the anonymous account.
    $id = $this->notification($f['ana'], $this->anonymous(), $comment, NULL, 'Ana_Author commented on your topic Carl hidden plan');
    $activity = Activity::load($id);
    $result = $rule($activity, 'view', $this->anonymous());
    $this->assertTrue($result->isForbidden(), 'A signed-out visitor is refused a notice addressed to the anonymous account.');
    $this->assertFalse($this->container->get('access_manager')->checkNamedRoute('entity.activity.canonical', ['activity' => $id], $this->anonymous()));
    foreach ([$this->anonymous(), $f['ben'], $f['carl']] as $viewer) {
      $this->assertContains('user', $rule($activity, 'view', $viewer)->getCacheContexts(), 'The answer varies by viewer.');
    }
    $this->assertContains('user', $rule(Activity::load($f['carls']['mention']), 'view', $f['carl'])->getCacheContexts());
  }

  /**
   * Streams are left as they were; activity administrators keep notices.
   */
  public function testStreamActivitiesAndAdministratorsKeepTheirAccess(): void {
    $f = $this->fixture();
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('activity');
    $admin = $this->createUser(['administer activity entities', 'access content']);
    $mention = $f['carls']['mention'];
    Activity::load($mention)->set('field_activity_destinations', [['value' => 'notifications'], ['value' => 'email']])->save();
    $handler->resetCache();
    // The rule refuses others, never an activity administrator; what the
    // activity module itself grants them is left as it was.
    $rule = [\Drupal\localnodes_platform\Plugin\views\filter\ActivityRecipientOnlyFilter::class, 'personalNotificationAccess'];
    $this->assertFalse($rule(Activity::load($mention), 'view', $admin)->isForbidden(), 'An activity administrator is not refused a notice.');
    $this->assertTrue($rule(Activity::load($mention), 'view', $f['ben'])->isForbidden(), 'Another member is refused it.');
    // A public post on Carl's profile, as it appears on his profile stream:
    // addressed to him, but not a notification.
    $post = Post::create([
      'type' => 'post', 'user_id' => $f['ana']->id(), 'status' => 1, 'field_visibility' => '1',
      'field_recipient_user' => $f['carl']->id(),
      'field_post' => ['value' => 'Hello Carl.', 'format' => 'plain_text'],
    ]);
    $post->save();
    $stream = Activity::load($this->notification($f['ana'], $f['carl'], $post, NULL, 'Ana_Author posted on the profile of Carl_Member'));
    $stream->set('field_activity_destinations', [['value' => 'stream_profile']])->save();
    $group = Activity::load($f['carls']['group topic']);
    $group->set('field_activity_recipient_user', [])->set('field_activity_destinations', [['value' => 'stream_group']])->save();
    foreach (['profile stream' => $stream, 'group stream' => $group] as $kind => $activity) {
      foreach ([$f['ben'], $this->anonymous(), $admin] as $viewer) {
        $this->assertTrue($rule($activity, 'view', $viewer)->isNeutral(), "No new rule on a $kind activity.");
      }
    }
    $handler->resetCache();
    $this->assertTrue($stream->access('view', $f['ben']), 'Another member still opens a public profile-stream post.');
  }

  public function testOwnNotificationsAreListed(): void {
    $f = $this->fixture();
    [$ids, $html] = $this->notifications($f['carl'], 'page_1', NULL);
    foreach ($f['carls'] as $kind => $id) $this->assertContains($id, $ids, "Carl sees his '$kind' notification.");
    $this->assertStringContainsString('Mendwell Stewards Circle', $html);
    [$ids] = $this->notifications($f['carl'], 'block_1', NULL);
    foreach ($f['carls'] as $kind => $id) $this->assertContains($id, $ids, "Carl's notification centre lists his '$kind' notification.");
    [$ids] = $this->notifications($f['ben'], 'page_1', NULL);
    $this->assertSame([$f['bens']], $ids, 'Ben sees exactly his own notification.');
  }

  public function testNotificationForSeveralMembersIsListedOnce(): void {
    $f = $this->fixture();
    $shared = Activity::create([
      'user_id' => $f['ana']->id(), 'status' => 1,
      'field_activity_entity' => Activity::load($f['carls']['reply'])->get('field_activity_entity')->getValue(),
      // As the activity factory writes several recipients.
      'field_activity_recipient_user' => array_map(static fn ($uid): array => ['target_id' => $uid, 'target_type' => 'user'], [$f['carl']->id(), $f['ana']->id(), $f['ben']->id()]),
      'field_activity_destinations' => [['value' => 'notifications']],
      'field_activity_status' => 1,
      'field_activity_output_text' => ['value' => '<p>A notice for three members.</p>', 'format' => 'plain_text'],
    ]);
    $shared->save();
    foreach (['page_1', 'block_1'] as $display) {
      [$ids] = $this->notifications($f['carl'], $display, NULL);
      $this->assertSame(1, count(array_keys($ids, (int) $shared->id())), "$display lists a notice for several members once.");
      $this->assertSame(array_values(array_unique($ids)), $ids, "$display repeats no notification.");
    }
  }

  public function testOtherMembersNotificationsPageIsRefused(): void {
    $f = $this->fixture();
    $this->container->get('current_user')->setAccount($f['ben']);
    $view = Views::getView('activity_stream_notifications');
    $view->setDisplay('page_1');
    $this->expectException(AccessDeniedHttpException::class);
    $view->preExecute([(string) $f['carl']->id()]);
  }

  public function testOwnNotificationsPageByNumberIsServed(): void {
    $f = $this->fixture();
    $this->container->get('current_user')->setAccount($f['carl']);
    $view = Views::getView('activity_stream_notifications');
    $view->setDisplay('page_1');
    $view->preExecute([(string) $f['carl']->id()]);
    $view->execute();
    $this->assertCount(count($f['carls']), $view->result);
  }

}
