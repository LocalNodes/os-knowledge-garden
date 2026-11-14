<?php

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\activity_creator\Entity\Activity;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\State\StateInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\Storage\GroupRelationshipStorageInterface;
use Drupal\comment\CommentInterface;
use Drupal\mentions\MentionsInterface;
use Drupal\votingapi\VoteInterface;
use Drupal\flag\FlaggingInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\FirstLanding;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\message\Entity\Message;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Mail cleanup retains other recipients and groups in shared queue items.
 *
 * @group localnodes_platform
 */
final class GroupNoticeCleanupTest extends UnitTestCase {

  private const NOW = 2000000000;

  private GroupNoticeCleanup $cleanup;

  private MemoryStorage $departures;

  private array $activities;

  protected function setUp(): void {
    parent::setUp();
    $this->activities = [10 => $this->activity(5, [7, 8]), 11 => $this->activity(6, [7]), 12 => $this->activity(5, [7])];
    $this->departures = new MemoryStorage('notice_departures');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(fn($id) => $this->activities[$id] ?? NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturn(TRUE);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->willReturnCallback(function ($gid) {
      foreach ($this->activities as $activity) {
        $group = $activity->getRelatedEntity();
        if ((int) $group->id() === (int) $gid) return $group;
      }
      return NULL;
    });
    $users = $this->users();
    $manager->method('getStorage')->willReturnMap([['activity', $storage], ['group', $groups], ['user', $users]]);
    $this->cleanup = new GroupNoticeCleanup($this->database(), $manager, $this->departures, $this->clock());
  }

  public function testRecentDepartureBlocksOnlyThatMemberAndGroup(): void {
    $this->departures->set('7:5', self::NOW);
    $this->assertFalse($this->cleanup->allows($this->activities[10], 7));
    $this->assertTrue($this->cleanup->allows($this->activities[10], 8));
    $this->assertTrue($this->cleanup->allows($this->activities[11], 7));
    $this->assertTrue($this->cleanup->allows($this->activities[10], 9), 'Another recipient with view access keeps this public group notice.');
  }

  public function testQueuedDigestKeepsOnlyOtherGroups(): void {
    $this->departures->set('7:5', self::NOW);
    $payload = ['uid' => 7, 'frequency' => 'daily', 'activities' => [10, 11, 12]];
    $this->assertSame(['uid' => 7, 'frequency' => 'daily', 'activities' => [11]], $this->cleanup->filterDigest($payload));
    $payload['uid'] = 8;
    $this->assertSame($payload, $this->cleanup->filterDigest($payload));
    $payload['uid'] = 7;
    $payload['activities'] = [10, 12];
    $this->assertNull($this->cleanup->filterDigest($payload));
  }

  public function testMarkerHasNoAgeLimit(): void {
    $this->departures->set('7:5', self::NOW - 8 * 86400);
    $this->assertFalse($this->cleanup->allows($this->activities[10], 7), 'Exactly eight days remains covered.');
    $this->departures->set('7:5', self::NOW - 8 * 86400 - 1);
    $this->assertFalse($this->cleanup->allows($this->activities[10], 7), 'An older departure still blocks queued mail.');
    $this->departures->set('7:5', self::NOW - 9 * 86400);
    $this->assertNull($this->cleanup->filterDigest(['uid' => 7, 'activities' => [10, 12]]), 'A digest held for nine days remains blocked.');
    $this->assertTrue($this->cleanup->allows($this->activities[10], 9));
  }

  public function testRejoinHookDeletesOnlyThatMembershipMarker(): void {
    $this->departures->set('7:5', self::NOW);
    $this->departures->set('7:6', self::NOW);
    $this->installHookContainer();
    localnodes_platform_group_content_insert($this->membership());
    $this->assertNull($this->departures->get('7:5'));
    $this->assertSame(self::NOW, $this->departures->get('7:6'));
    $this->assertTrue($this->cleanup->allows($this->activities[10], 7));
  }

  /**
   * @dataProvider departureNoticeTable
   */
  public function testDepartureNoticeTable(?string $template, array $permissions, string $recipient, bool $full, bool $mail): void {
    $account = $this->account(7);
    $account->method('hasPermission')->willReturnCallback(static fn($name) => in_array($name, $permissions, TRUE));
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->with(7)->willReturn($account);
    $groups = $this->createMock(EntityStorageInterface::class);
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('id')->willReturn(5);
    $group->method('access')->willReturn($recipient === 'rejoined member');
    $groups->method('load')->willReturn($group);
    $message = NULL;
    if ($template !== NULL) {
      $message = $this->getMockBuilder(Message::class)->disableOriginalConstructor()->onlyMethods(['bundle'])->getMock();
      $message->method('bundle')->willReturn($template);
    }
    $field = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods(['__get'])->getMock();
    $field->method('__get')->with('entity')->willReturn($message);
    $activity = $this->getMockBuilder(Activity::class)->disableOriginalConstructor()->onlyMethods(['getRelatedEntity', 'hasField', 'get'])->getMock();
    $activity->method('getRelatedEntity')->willReturn($group);
    $activity->method('hasField')->willReturnCallback(static fn($name) => $name === 'field_activity_message');
    $activity->method('get')->with('field_activity_message')->willReturn($field);
    $activities = $this->createMock(EntityStorageInterface::class);
    $activities->method('load')->willReturn($activity);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturn(TRUE);
    $manager->method('getStorage')->willReturnMap([['user', $users], ['group', $groups], ['activity', $activities]]);
    $database = $this->database();
    $cleanup = new GroupNoticeCleanup($database, $manager, $this->departures, $this->clock());
    if ($recipient !== 'never-member manager') {
      $this->departures->set('7:5', self::NOW - 9 * 86400);
    }
    if ($recipient === 'rejoined member') {
      $cleanup->clearDeparture(7, 5);
    }
    $this->assertSame($full, $cleanup->allows($activity, 7));
    $payload = ['uid' => 7, 'activities' => [10]];
    $this->assertSame($mail ? $payload : NULL, $cleanup->filterDigest($payload));
  }

  public function departureNoticeTable(): array {
    $notices = [
      'mention in a post' => 'create_mention_post',
      'comment on a post' => 'create_comment_group_post',
      'new node' => 'create_topic_group',
      'like' => 'create_like_node_or_post',
      'report notice' => 'content_reported',
    ];
    $recipients = [
      'leaver with node bypass' => ['bypass node access'],
      'leaver with group management' => ['manage all groups'],
      'leaver with report permission' => ['view inappropriate reports'],
      'plain leaver' => [],
      'never-member manager' => ['manage all groups', 'bypass node access', 'view inappropriate reports'],
      'rejoined member' => [],
    ];
    $rows = [];
    foreach ($notices as $notice => $template) {
      foreach ($recipients as $recipient => $permissions) {
        $full = $recipient === 'rejoined member';
        $mail = $full || ($notice === 'report notice' && in_array('view inappropriate reports', $permissions, TRUE));
        $rows[$notice . ' / ' . $recipient] = [$template, $permissions, $recipient, $full, $mail];
      }
    }
    $rows['missing message / report handler'] = [NULL, ['view inappropriate reports'], 'leaver with report permission', FALSE, FALSE];
    $rows['different template / report handler'] = ['content_reported_other', ['view inappropriate reports'], 'leaver with report permission', FALSE, FALSE];
    return $rows;
  }

  public function testCleanupFailureCannotPreventLeavingOrLogMemberWords(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturn(TRUE);
    $users = $this->users();
    $manager->method('getStorage')->with('user')->willReturn($users);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willThrowException(new \RuntimeException('PrivateWordsNeverLog829', 17));
    $cleanup = new GroupNoticeCleanup($database, $manager, $this->departures, $this->clock());
    $this->installHookContainer($cleanup);
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('warning')->with(
      'Waiting group notice cleanup failed (@class, code @code).',
      ['@class' => \RuntimeException::class, '@code' => 17],
    );
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->with('localnodes_platform')->willReturn($logger);
    \Drupal::getContainer()->set('logger.factory', $factory);
    localnodes_platform_group_content_predelete($this->membership());
    $this->assertSame(self::NOW, $this->departures->get('7:5'));
    $this->assertSame([[FirstLanding::MODULE, 7, FirstLanding::LEFT, [5]]], $this->userDataWrites, 'The departure is still recorded for the first-sign-in join.');
  }

  public function testNoActivityModuleDoesNotQueryAnyTables(): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->with('activity')->willReturn(FALSE);
    $database = $this->database();
    $database->expects($this->never())->method('schema');
    (new GroupNoticeCleanup($database, $manager, $this->departures, $this->clock()))->remove(7, 5);
    $this->assertSame(self::NOW, $this->departures->get('7:5'));
  }

  public function testRecipientGroupCanIdentifyTheActivityDirectly(): void {
    $group_field = $this->createMock(FieldItemListInterface::class);
    $group_field->method('getValue')->willReturn([['target_id' => 5]]);
    $user_field = $this->createMock(FieldItemListInterface::class);
    $user_field->method('getValue')->willReturn([['target_id' => 7]]);
    $activity = $this->getMockBuilder(Activity::class)->disableOriginalConstructor()->onlyMethods(['getRelatedEntity', 'get', 'hasField'])->getMock();
    $activity->method('hasField')->willReturnCallback(static fn($name) => $name === 'field_activity_recipient_group');
    $activity->method('get')->willReturnMap([['field_activity_recipient_group', $group_field], ['field_activity_recipient_user', $user_field]]);
    $activity->method('getRelatedEntity')->willReturn(NULL);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(10)->willReturn($activity);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('activity')->willReturn($storage);
    $cleanup = new GroupNoticeCleanup($this->database(), $manager, new MemoryStorage('notice_departures'), $this->clock());
    $this->departures->set('7:5', self::NOW);
    $this->assertFalse($this->cleanup->allows($activity, 7));
  }

  /**
   * @dataProvider relatedEntities
   */
  public function testUnderlyingGroupIsFoundThroughNoticeWrappers(string $wrapper): void {
    $post = $this->createMock(FieldableEntityInterface::class);
    $post->method('getEntityTypeId')->willReturn('post');
    $post->method('access')->willReturnCallback(static fn ($operation, $account) => $operation === 'view' && (int) $account->id() === 8);
    $post->method('hasField')->willReturnCallback(static fn($name) => $name === 'field_recipient_group');
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn([['target_id' => 5]]);
    $post->method('get')->with('field_recipient_group')->willReturn($field);
    $related = $post;
    if ($wrapper === 'mentions') {
      $related = $this->createMock(MentionsInterface::class);
      $related->method('getEntityTypeId')->willReturn('mentions');
      $related->method('getMentionedEntity')->willReturn($post);
    }
    elseif ($wrapper === 'comment') {
      $related = $this->createMock(CommentInterface::class);
      $related->method('getEntityTypeId')->willReturn('comment');
      $related->method('getCommentedEntity')->willReturn($post);
    }
    elseif ($wrapper === 'vote') {
      $related = $this->createMock(VoteInterface::class);
      $related->method('getEntityTypeId')->willReturn('vote');
      $related->method('getVotedEntityType')->willReturn('post');
      $related->method('getVotedEntityId')->willReturn(20);
    }
    elseif ($wrapper === 'flagging') {
      $related = $this->createMock(FlaggingInterface::class);
      $related->method('getEntityTypeId')->willReturn('flagging');
      $related->method('getFlaggable')->willReturn($post);
    }
    elseif ($wrapper === 'event_enrollment') {
      $related = $this->createMock(FieldableEntityInterface::class);
      $related->method('getEntityTypeId')->willReturn('event_enrollment');
      $related->method('hasField')->willReturnCallback(static fn($name) => $name === 'field_event');
      $event_field = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods(['__get'])->getMock();
      $event_field->method('__get')->with('entity')->willReturn($post);
      $related->method('get')->with('field_event')->willReturn($event_field);
    }
    elseif ($wrapper === 'group_content') {
      $related = $this->createMock(GroupRelationshipInterface::class);
      $related->method('getEntityTypeId')->willReturn('group_content');
      $related->method('getGroupId')->willReturn(5);
      $related->method('getPluginId')->willReturn('group_node:topic');
      $related->method('getEntity')->willReturn($post);
    }
    elseif (in_array($wrapper, ['node', 'crossposted_node'], TRUE)) {
      $related = $this->createMock(EntityInterface::class);
      $related->method('getEntityTypeId')->willReturn('node');
    }
    if ($related !== $post) $related->method('access')->willReturnCallback(static fn ($operation, $account) => $operation === 'view' && (int) $account->id() === 8);
    $recipients = $this->createMock(FieldItemListInterface::class);
    $recipients->method('getValue')->willReturn([['target_id' => 7]]);
    $activity = $this->getMockBuilder(Activity::class)->disableOriginalConstructor()->onlyMethods(['getRelatedEntity', 'get', 'hasField'])->getMock();
    $activity->method('getRelatedEntity')->willReturn($related);
    $activity->method('hasField')->willReturn(FALSE);
    $activity->method('get')->with('field_activity_recipient_user')->willReturn($recipients);
    $activities = $this->createMock(EntityStorageInterface::class);
    $activities->method('load')->with(10)->willReturn($activity);
    $posts = $this->createMock(EntityStorageInterface::class);
    $posts->method('load')->with(20)->willReturn($post);
    $relationships = $this->createMock(GroupRelationshipStorageInterface::class);
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('getGroupId')->willReturn(5);
    $other_relationship = $this->createMock(GroupRelationshipInterface::class);
    $other_relationship->method('getGroupId')->willReturn(6);
    $relationships->method('loadByEntity')->willReturn($wrapper === 'crossposted_node' ? [$relationship, $other_relationship] : [$relationship]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('hasDefinition')->willReturn(TRUE);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->willReturn(NULL);
    $users = $this->users();
    $manager->method('getStorage')->willReturnMap([['activity', $activities], ['post', $posts], ['group_content', $relationships], ['group', $groups], ['user', $users]]);
    $departures = new MemoryStorage('notice_departures');
    $departures->set('7:5', self::NOW);
    $cleanup = new GroupNoticeCleanup($this->database(), $manager, $departures, $this->clock());
    $this->assertFalse($cleanup->allows($activity, 7), 'A second group without membership cannot preserve a private notice.');
    $this->assertTrue($cleanup->allows($activity, 8));
  }

  public function relatedEntities(): array {
    return array_combine(['post', 'mentions', 'comment', 'vote', 'flagging', 'event_enrollment', 'group_content', 'node', 'crossposted_node'], array_map(static fn($type) => [$type], ['post', 'mentions', 'comment', 'vote', 'flagging', 'event_enrollment', 'group_content', 'node', 'crossposted_node']));
  }

  private function clock(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(self::NOW);
    return $time;
  }

  /**
   * What the first-sign-in join's user data store was asked to write.
   */
  private array $userDataWrites = [];

  private function installHookContainer(?GroupNoticeCleanup $cleanup = NULL): void {
    require_once $this->root . '/modules/custom/localnodes_platform/localnodes_platform.module';
    $container = new ContainerBuilder();
    $container->set('localnodes_platform.group_notice_cleanup', $cleanup ?? $this->cleanup);
    $userData = $this->createMock(UserDataInterface::class);
    $userData->method('set')->willReturnCallback(function (...$args): void {
      $this->userDataWrites[] = $args;
    });
    $container->set('localnodes_platform.first_landing', new FirstLanding(
      $this->createMock(StateInterface::class),
      $userData,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(AccessManagerInterface::class),
    ));
    \Drupal::setContainer($container);
  }

  private function membership(): GroupRelationshipInterface {
    $membership = $this->createMock(GroupRelationshipInterface::class);
    $membership->method('getPluginId')->willReturn('group_membership');
    $membership->method('getEntityId')->willReturn(7);
    $membership->method('getGroupId')->willReturn(5);
    return $membership;
  }

  private function activity(int $gid, array $recipients): Activity {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('id')->willReturn($gid);
    $group->method('access')->willReturn(TRUE);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('getValue')->willReturn(array_map(static fn($uid) => ['target_id' => $uid], $recipients));
    $activity = $this->getMockBuilder(Activity::class)->disableOriginalConstructor()->onlyMethods(['getRelatedEntity', 'get', 'hasField'])->getMock();
    $activity->method('getRelatedEntity')->willReturn($group);
    $activity->method('hasField')->willReturn(FALSE);
    $activity->method('get')->with('field_activity_recipient_user')->willReturn($field);
    return $activity;
  }

  private function account(int $uid): UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn((string) $uid);
    $account->method('isActive')->willReturn(TRUE);
    $account->method('isAuthenticated')->willReturn(TRUE);
    return $account;
  }

  private function users(): EntityStorageInterface {
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->willReturnCallback(fn ($uid) => $this->account((int) $uid));
    return $users;
  }

  private function database(): Connection {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    return $database;
  }

}
