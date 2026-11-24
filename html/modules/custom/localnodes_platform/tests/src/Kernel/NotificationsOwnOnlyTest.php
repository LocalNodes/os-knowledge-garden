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
    'activity_basics', 'activity_viewer', 'localnodes_platform',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['post', 'profile', 'message', 'activity'] as $type) $this->installEntitySchema($type);
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
    $this->installShippedConfig('social_group_flexible_group', ['taxonomy.vocabulary.group_type']);
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

  private function notification(AccountInterface $author, AccountInterface $recipient, ContentEntityInterface $entity, $gid, string $text): int {
    $activity = Activity::create([
      'user_id' => $author->id(), 'status' => 1,
      'field_activity_entity' => [['target_type' => $entity->getEntityTypeId(), 'target_id' => $entity->id()]],
      'field_activity_recipient_user' => $recipient->id(),
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
  private function notifications(AccountInterface $viewer, string $display, ?AccountInterface $recipient): array {
    $this->container->get('current_user')->setAccount($viewer);
    drupal_static_reset('getAllGroupsForUser');
    $view = Views::getView('activity_stream_notifications');
    $this->assertTrue($view->setDisplay($display));
    $view->setItemsPerPage(0);
    if ($recipient) $view->setArguments([(string) $recipient->id()]);
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
    $this->fixture();
    $view = Views::getView('activity_stream_notifications');
    // The list's own query is never run for a visitor: the page and the
    // notification centre are for signed-in members only.
    $this->assertFalse($view->access('page_1', $this->anonymous()));
    $this->assertFalse($view->access('block_1', $this->anonymous()));
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
