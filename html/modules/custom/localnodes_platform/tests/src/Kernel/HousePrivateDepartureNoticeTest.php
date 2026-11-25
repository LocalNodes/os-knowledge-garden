<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\activity_creator\Entity\Activity;
use Drupal\comment\Entity\Comment;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\mentions\Plugin\Filter\MentionsFilter;
use Drupal\message\Entity\Message;
use Drupal\message\Entity\MessageTemplate;
use Drupal\node\Entity\Node;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * A departed author can read without receiving new pings or notices.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class HousePrivateDepartureNoticeTest extends GroupAccessKernelTestBase {

  // Activity Basics runs its real action plugins on configuration saves during
  // the shared base's setup; those plugins require Activity Logger's factory.
  // There are no message templates yet, so that factory only scans empty config
  // until our real activity/message schemas and fixtures are installed below.
  protected static $modules = [
    'message', 'dynamic_entity_reference', 'activity_creator', 'activity_logger', 'activity_basics',
    'activity_send', 'activity_send_email', 'mentions', 'social_mentions',
    'localnodes_platform',
  ];

  protected function setUp(): void {
    parent::setUp();
    foreach (['activity', 'message', 'mentions'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('activity_creator', ['activity_notification_status']);
    foreach (['entity', 'message', 'recipient_user', 'recipient_group', 'destinations', 'status', 'output_text'] as $suffix) {
      $field = 'field_activity_' . $suffix;
      $this->installShippedConfig('activity_creator', [
        "field.storage.activity.$field", "field.field.activity.activity.$field",
      ]);
    }
    $this->installShippedConfig('social_group', ['flag.flag.mute_group_notifications']);
    $this->installShippedConfig('mentions', ['mentions.mentions_type.UserMention']);
    $this->config('mentions.settings')->set('supported_entity_types', ['comment' => 'comment'])->save();
    FilterFormat::create([
      'format' => 'house_mentions', 'name' => 'House mentions',
      'filters' => ['filter_mentions' => [
        'id' => 'filter_mentions', 'provider' => 'mentions', 'status' => TRUE,
        'settings' => ['mentions_filter' => ['UserMention' => 'UserMention']],
      ]],
    ])->save();
    $this->setCurrentUser($this->siteOwner);
  }

  private function fixture(): array {
    $group = $this->createFlexibleGroup('Fictional private house', 'community');
    $author = $this->createPersona('former_author');
    $member = $this->createPersona('remaining_member');
    $this->addMember($group, $author);
    $this->addMember($group, $member);
    $topic = $this->createGroupTopic($group, 'Fictional author page', 'Original page.', 'group');
    $topic->setOwnerId($author->id())->save();
    return [$group, $author, $member, $topic];
  }

  private function message(): Message {
    MessageTemplate::create([
      'template' => 'house_reply_notice', 'label' => 'House reply notice',
      'text' => [['value' => 'A fictional reply.', 'format' => 'plain_text']],
      'settings' => ['token options' => ['token replace' => TRUE]],
    ])->save();
    $message = Message::create(['template' => 'house_reply_notice', 'uid' => $this->siteOwner->id()]);
    $message->save();
    return $message;
  }

  public function testRealOwnerProducerDoesNotCreateDepartedAuthorNotification(): void {
    [$group, $author, $member, $topic] = $this->fixture();
    $message = $this->message();
    $reply = $this->createComment($topic, 'A later reply.');
    $reply->setOwnerId($member->id())->save();
    $group->removeMember($author);
    $data = [
      'mid' => $message->id(), 'message_template' => 'create_comment',
      'actor' => $member->id(), 'context' => 'owner_activity_context',
      'related_object' => [['target_type' => 'comment', 'target_id' => $reply->id()]],
      'destination' => [['value' => 'notifications']],
    ];
    $context = $this->container->get('plugin.manager.activity_context.processor')->createInstance('owner_activity_context');
    $data['recipient'] = $context->getRecipients($data, 0, 0);
    // The real upstream owner context can still nominate the departed author.
    // The shared final producer must suppress that recipient, including queues.
    $this->container->get('activity_creator.activity_factory')->createActivities($data);
    $this->assertSame(0, (int) $this->container->get('database')->select('activity_notification_status', 'n')->condition('uid', $author->id())->countQuery()->execute()->fetchField());

    $data['recipient'] = [['target_type' => 'user', 'target_id' => $member->id()]];
    $activities = $this->container->get('activity_creator.activity_factory')->createActivities($data);
    $this->assertCount(1, $activities, 'A real member still receives the activity.');
    $this->assertSame(1, (int) $this->container->get('database')->select('activity_notification_status', 'n')->condition('uid', $member->id())->countQuery()->execute()->fetchField());
  }

  public function testRealMentionProducerDoesNotNotifyDepartedTarget(): void {
    [$group, $author, $member, $topic] = $this->fixture();
    $comment = $this->createComment($topic, '<p>@former_author</p>');
    $comment->setOwnerId($member->id());
    $comment->field_comment_body->format = 'house_mentions';
    $comment->save();
    $parser = MentionsFilter::create($this->container, [], 'filter_mentions', []);
    $parser->setTextFormat('house_mentions');
    $this->assertTrue($parser->shouldApplyFilter());
    $parsed = $parser->getMentions($comment->field_comment_body->value);
    $this->container->get('module_handler')->loadInclude('mentions', 'module');
    mentions_crud_update('comment', $parsed, (int) $comment->id(), (int) $member->id());
    $mentions = $this->container->get('entity_type.manager')->getStorage('mentions')->loadByProperties(['entity_type' => 'comment', 'entity_id' => $comment->id()]);
    $this->assertCount(1, $mentions);
    $mention = reset($mentions);
    $message = $this->message();
    $group->removeMember($author);
    $data = [
      'mid' => $message->id(), 'message_template' => 'create_mention_comment',
      'actor' => $member->id(), 'context' => 'mention_activity_context',
      'related_object' => [['target_type' => 'mentions', 'target_id' => $mention->id()]],
      'destination' => [['value' => 'notifications']],
    ];
    $context = $this->container->get('plugin.manager.activity_context.processor')->createInstance('mention_activity_context');
    $data['recipient'] = $context->getRecipients($data, 0, 0);
    if ($data['recipient']) {
      $this->assertSame([], $this->container->get('activity_creator.activity_factory')->createActivities($data), 'Even a real context nominating a now-readable former author cannot create a personal notification.');
    }
    $this->assertSame(0, (int) $this->container->get('database')->select('activity_notification_status', 'n')->condition('uid', $author->id())->countQuery()->execute()->fetchField());
  }

  public function testDepartureRemovesOldNotificationRowsAndPersonalActivityAccess(): void {
    [$group, $author, $member, $topic] = $this->fixture();
    $message = $this->message();
    $data = [
      'mid' => $message->id(), 'message_template' => 'create_comment',
      'actor' => $member->id(),
      'related_object' => [['target_type' => 'node', 'target_id' => $topic->id()]],
      'destination' => [['value' => 'notifications']],
      'recipient' => [['target_type' => 'user', 'target_id' => $author->id()]],
    ];
    $activities = $this->container->get('activity_creator.activity_factory')->createActivities($data);
    $this->assertCount(1, $activities);
    $this->assertSame(1, (int) $this->container->get('database')->select('activity_notification_status', 'n')->condition('uid', $author->id())->countQuery()->execute()->fetchField());
    $group->removeMember($author);
    $this->assertSame(0, (int) $this->container->get('database')->select('activity_notification_status', 'n')->condition('uid', $author->id())->countQuery()->execute()->fetchField(), 'Departure clears the old house notification as well as digest mail.');
    $this->container->get('entity_type.manager')->getAccessControlHandler('activity')->resetCache();
    $this->assertFalse($activities[0]->access('view', $author), 'An issued personal activity link stays closed even when the authored page is readable.');
  }

  public function testRealParserAndEntityValidationRejectOnlyNewDepartedMentions(): void {
    [$group, $author, $member, $topic] = $this->fixture();
    $comment = Comment::create([
      'entity_type' => 'node', 'entity_id' => $topic->id(),
      'field_name' => 'field_topic_comments', 'comment_type' => 'comment',
      'uid' => $member->id(), 'status' => 1, 'subject' => 'Fictional reply',
      'field_comment_body' => ['value' => '<p>@former_author</p>', 'format' => 'house_mentions'],
    ]);
    $comment->save();
    $filter = MentionsFilter::create($this->container, [], 'filter_mentions', []);
    $filter->setTextFormat('house_mentions');
    $this->assertTrue($filter->shouldApplyFilter());
    $parsed = $filter->getMentions($comment->field_comment_body->value);
    $this->assertSame((int) $author->id(), (int) reset($parsed)['target']['entity_id'], 'The shipped parser resolves the actual target.');
    // Upstream mentions_entity_insert deliberately skips CLI; exercise its real
    // parser and persistence routine explicitly, rather than claiming auto-parse.
    $this->container->get('module_handler')->loadInclude('mentions', 'module');
    mentions_crud_update('comment', $parsed, (int) $comment->id(), (int) $member->id());
    $this->assertSame(1, (int) $this->container->get('entity_type.manager')->getStorage('mentions')->getQuery()->accessCheck(FALSE)->condition('uid', $author->id())->count()->execute());
    $other_house = $this->createFlexibleGroup('Fictional other house', 'community');
    $this->addMember($other_house, $author);
    $group->removeMember($author);
    $this->setCurrentUser($member);
    $unchanged = array_map(static fn ($v) => (string) $v->getMessage(), iterator_to_array($comment->validate()));
    $this->assertNotContains('Only current house members can be mentioned here. Remove the new mention before saving.', $unchanged, 'Historical mentions are preserved.');
    $comment->field_comment_body->value .= '<p>@former_author</p>';
    $messages = array_map(static fn ($v) => (string) $v->getMessage(), iterator_to_array($comment->validate()));
    $this->assertContains('Only current house members can be mentioned here. Remove the new mention before saving.', $messages, 'Typed or pasted additional mentions are rejected by server-side entity validation.');
    try {
      $comment->save();
      $this->fail('A direct save must not bypass new-mention eligibility.');
    }
    catch (EntityStorageException $exception) {
      $stored = $this->container->get('entity_type.manager')->getStorage('comment')->loadUnchanged($comment->id());
      $this->assertSame('<p>@former_author</p>', $stored->field_comment_body->value, 'The rejected duplicate does not rewrite historical text.');
    }
    $comment->field_comment_body->value = '<p>@remaining_member</p>';
    $messages = array_map(static fn ($v) => (string) $v->getMessage(), iterator_to_array($comment->validate()));
    $this->assertNotContains('Only current house members can be mentioned here. Remove the new mention before saving.', $messages, 'Current house members remain mentionable.');
  }

  public function testFirstSaveMentionChecksActualSelectedGroupBeforeRelationshipExists(): void {
    [$group, $author, $member, $topic] = $this->fixture();
    $group->removeMember($author);
    $this->setCurrentUser($member);
    $values = [
      'type' => 'topic', 'title' => 'Fictional new house page',
      'uid' => $member->id(), 'status' => 1,
      'field_content_visibility' => 'group',
      'groups' => [['target_id' => $group->id()]],
      'body' => ['value' => '<p>@remaining_member</p>', 'format' => 'house_mentions'],
    ];
    $new = Node::create($values);
    $messages = array_map(static fn ($v) => (string) $v->getMessage(), iterator_to_array($new->validate()));
    $this->assertNotContains('Only current house members can be mentioned here. Remove the new mention before saving.', $messages, 'A selected real house permits its current member before the first node save.');
    $new->save();
    $relationships = $this->container->get('entity_type.manager')->getStorage('group_content')->loadByEntity($new);
    $this->assertCount(1, $relationships, 'The actual computed group field creates the single intended relation after save.');
    $values['body']['value'] = '<p>@former_author</p>';
    $invalid = Node::create($values);
    $messages = array_map(static fn ($v) => (string) $v->getMessage(), iterator_to_array($invalid->validate()));
    $this->assertContains('Only current house members can be mentioned here. Remove the new mention before saving.', $messages, 'The same first-save path rejects a departed target.');
  }

}
