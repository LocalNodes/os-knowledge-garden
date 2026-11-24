<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Views;

/**
 * A member's profile stream shows their group activity only to viewers who
 * may see that group.
 *
 * Runs the shipped profile stream view with rows written as the activity
 * creator writes them: the group as recipient group, stream_profile as
 * destination and the template's text, which names the group. Regression
 * guard: this already holds on a fresh install.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class ProfileStreamGroupActivityTest extends GroupAccessKernelTestBase {

  protected static $modules = [
    'block', 'dropdown', 'profile', 'social_user', 'role_delegation', 'social_post',
    'message', 'dynamic_entity_reference', 'activity_creator', 'activity_logger',
    'activity_basics', 'activity_viewer', 'localnodes_platform',
  ];

  private const KINDS = ['topic', 'event', 'post', 'comment'];

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
    // An event row is a node row to the stream's query. The event type is
    // made here with its shipped visibility field and group relationship,
    // without social_event's enrollment stack.
    NodeType::create(['type' => 'event', 'name' => 'Event'])->save();
    $this->installShippedConfig('social_event', ['field.field.node.event.field_content_visibility']);
    $this->installShippedConfig('social_group_flexible_group', ['taxonomy.vocabulary.group_type', 'group.content_type.flexible_group-group_node-event']);
    $this->installShippedConfig('social_group', ['flag.flag.mute_group_notifications']);
    require_once DRUPAL_ROOT . '/core/includes/module.inc';
    $this->container->get('module_handler')->loadInclude('social_group_flexible_group', 'install');
    social_group_flexible_group_install();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    _localnodes_platform_protect_private_groups();
    foreach (['anonymous', 'authenticated', 'verified'] as $role) {
      user_role_grant_permissions($role, ['view node.event.field_content_visibility:public content', 'view public posts', 'view published post entities']);
    }
    user_role_grant_permissions('authenticated', ['access user profiles']);
    user_role_grant_permissions('verified', ['view node.event.field_content_visibility:community content', 'view community posts']);
    $this->container->get('views.views_data')->clear();
    // The shipped view, unchanged: its filters, its profile argument, its
    // role access and its row plugin.
    $path = $this->container->get('extension.list.module')->getPath('social_activity');
    $definition = (new FileStorage($path . '/config/optional'))->read('views.view.activity_stream_profile');
    $this->assertIsArray($definition);
    View::create($definition)->save();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Ana's topic, event, post and comment in one group, with their activities.
   *
   * @return int[]
   *   Activity ids keyed by kind.
   */
  private function activitiesIn(GroupInterface $group, AccountInterface $ana, string $content_visibility, string $post_visibility): array {
    $label = $group->label();
    $topic = $this->createGroupTopic($group, "$label topic", 'Topic body.', $content_visibility);
    $topic->setOwnerId($ana->id())->save();
    $event = Node::create([
      'type' => 'event', 'title' => "$label event", 'uid' => $ana->id(), 'status' => 1,
      'field_content_visibility' => $content_visibility,
    ]);
    $event->save();
    $group->addRelationship($event, 'group_node:event');
    $post = Post::create([
      'type' => 'post', 'user_id' => $ana->id(), 'status' => 1,
      'field_visibility' => $post_visibility, 'field_recipient_group' => $group->id(),
      'field_post' => ['value' => "$label post body.", 'format' => 'plain_text'],
    ]);
    $post->save();
    $comment = $this->createComment($topic, "$label comment body.");
    $comment->setOwnerId($ana->id())->save();
    $link = '<a href="/group/' . $group->id() . '">' . $label . '</a>';
    $name = $ana->getDisplayName();
    // The texts of create_topic_group, create_event_group, create_post_group
    // and create_comment_group_node.
    $texts = [
      'topic' => "$name created a topic in $link",
      'event' => "$name created an event in $link",
      'post' => "$name posted in $link",
      'comment' => "$name commented on $name's topic in $link",
    ];
    $ids = [];
    foreach (['topic' => $topic, 'event' => $event, 'post' => $post, 'comment' => $comment] as $kind => $entity) {
      $ids[$kind] = $this->activity($ana, $entity, $group, $texts[$kind]);
    }
    return $ids;
  }

  private function activity(AccountInterface $author, ContentEntityInterface $entity, ?GroupInterface $group, string $text): int {
    $activity = Activity::create([
      'user_id' => $author->id(), 'status' => 1,
      'field_activity_entity' => [['target_type' => $entity->getEntityTypeId(), 'target_id' => $entity->id()]],
      'field_activity_recipient_group' => $group?->id(),
      'field_activity_destinations' => [['value' => 'stream_profile']],
      'field_activity_status' => 1,
      'field_activity_output_text' => ['value' => '<p>' . $text . '</p>', 'format' => 'plain_text'],
    ]);
    $activity->save();
    return (int) $activity->id();
  }

  /**
   * Three groups with Ana's activities, a fellow member and an outsider.
   */
  private function fixture(): array {
    $ana = $this->createPersona('Ana_Profile');
    $carl = $this->createPersona('Carl_Fellow');
    $ben = $this->createPersona('Ben_Outsider');
    // Hidden from non-members, as the demo's Mendwell Stewards Circle is.
    $hidden = $this->createFlexibleGroup('Mendwell Stewards Circle', 'members');
    // Listed to site members, with its content for group members only.
    $closed = $this->createFlexibleGroup('Cascadia Governance Council', 'community');
    $public = $this->createFlexibleGroup('Watershed Guardians Collective', 'public');
    foreach ([$hidden, $closed, $public] as $group) $this->addMember($group, $ana);
    $this->addMember($hidden, $carl);
    $this->addMember($closed, $carl);
    return [
      'ana' => $ana, 'carl' => $carl, 'ben' => $ben,
      'hidden' => $this->activitiesIn($hidden, $ana, 'group', '3'),
      'closed' => $this->activitiesIn($closed, $ana, 'group', '3'),
      'public' => $this->activitiesIn($public, $ana, 'public', '1'),
    ];
  }

  /**
   * Ana's profile stream as one viewer sees it.
   *
   * @return array
   *   Whether the display lets the viewer in, the activity ids and the
   *   rendered rows.
   */
  private function profileStream(AccountInterface $owner, AccountInterface $viewer): array {
    $this->container->get('current_user')->setAccount($viewer);
    drupal_static_reset('getAllGroupsForUser');
    $view = Views::getView('activity_stream_profile');
    $this->assertNotNull($view);
    $this->assertTrue($view->setDisplay('block_1'));
    $access = $view->access('block_1', $viewer);
    $view->setItemsPerPage(0);
    $view->setArguments([(string) $owner->id()]);
    $view->execute();
    $ids = array_map(static fn ($row): int => (int) $row->_entity->id(), $view->result);
    $view->rowPlugin->preRender($view->result);
    $rows = [];
    foreach ($view->result as $row) $rows[] = $view->rowPlugin->render($row);
    $html = (string) $this->container->get('renderer')->renderInIsolation($rows);
    $view->destroy();
    return [$access, $ids, $html];
  }

  public function testOutsiderSeesNoActivityFromGroupsTheyCannotSee(): void {
    $f = $this->fixture();
    [$access, $ids, $html] = $this->profileStream($f['ana'], $f['ben']);
    $this->assertTrue($access, 'A signed-in member may open a profile stream.');
    foreach (['hidden', 'closed'] as $group) {
      foreach (self::KINDS as $kind) {
        $this->assertNotContains($f[$group][$kind], $ids, "The outsider sees no $kind activity from the $group group.");
      }
    }
    $this->assertStringNotContainsString('Mendwell Stewards Circle', $html);
    $this->assertStringNotContainsString('Cascadia Governance Council', $html);
  }

  public function testSignedOutVisitorSeesNoActivityFromGroupsTheyCannotSee(): void {
    $f = $this->fixture();
    [$access, $ids, $html] = $this->profileStream($f['ana'], $this->anonymous());
    $this->assertFalse($access, 'The profile stream is for signed-in members only.');
    // Below the display's access, the query itself keeps the rows out too.
    foreach (['hidden', 'closed'] as $group) {
      foreach (self::KINDS as $kind) {
        $this->assertNotContains($f[$group][$kind], $ids, "The visitor's query admits no $kind activity from the $group group.");
      }
    }
    $this->assertStringNotContainsString('Mendwell Stewards Circle', $html);
    $this->assertStringNotContainsString('Cascadia Governance Council', $html);
  }

  public function testFellowMemberSeesTheGroupActivity(): void {
    $f = $this->fixture();
    [, $ids, $html] = $this->profileStream($f['ana'], $f['carl']);
    foreach (['hidden', 'closed'] as $group) {
      foreach (self::KINDS as $kind) $this->assertContains($f[$group][$kind], $ids, "The fellow member sees the $kind activity from the $group group.");
    }
    $this->assertStringContainsString('Mendwell Stewards Circle', $html);
    $this->assertStringContainsString('Cascadia Governance Council', $html);
  }

  public function testPublicGroupActivityShowsToEveryMember(): void {
    $f = $this->fixture();
    foreach (['ben', 'carl', 'ana'] as $viewer) {
      [, $ids, $html] = $this->profileStream($f['ana'], $f[$viewer]);
      foreach (['topic', 'event', 'post'] as $kind) $this->assertContains($f['public'][$kind], $ids, "$viewer sees the public group's $kind activity.");
      $this->assertStringContainsString('Watershed Guardians Collective', $html);
    }
  }

  public function testOwnerSeesAllTheirGroupActivity(): void {
    $f = $this->fixture();
    [, $ids] = $this->profileStream($f['ana'], $f['ana']);
    foreach (['hidden', 'closed', 'public'] as $group) {
      foreach (self::KINDS as $kind) $this->assertContains($f[$group][$kind], $ids, "Ana sees her own $kind activity from the $group group.");
    }
  }

}
