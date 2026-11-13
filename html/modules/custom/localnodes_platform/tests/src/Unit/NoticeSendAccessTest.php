<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\activity_creator\Entity\Activity;
use Drupal\comment\CommentInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\flag\FlaggingInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\Storage\GroupRelationshipStorageInterface;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\mentions\MentionsInterface;
use Drupal\message\Entity\Message;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Drupal\votingapi\VoteInterface;

/** @group localnodes_platform */
final class NoticeSendAccessTest extends UnitTestCase {

  private array $readable = [7 => FALSE, 8 => TRUE, 9 => FALSE, 10 => FALSE];

  private bool $commentReadable = TRUE;

  private function fixture(string $wrapper, bool $report = FALSE, bool $groupPrivacyChanged = FALSE): array {
    $root = $this->createMock(FieldableEntityInterface::class);
    $type = $wrapper === 'post' || $wrapper === 'mention_post' ? 'post' : 'node';
    $root->method('getEntityTypeId')->willReturn($type);
    $root->method('id')->willReturn('20');
    $root->method('hasField')->willReturnCallback(static fn ($name) => $type === 'post' && $name === 'field_recipient_group');
    $groupsField = $this->createMock(FieldItemListInterface::class);
    $groupsField->method('getValue')->willReturn([['target_id' => 5], ['target_id' => 6]]);
    $root->method('get')->with('field_recipient_group')->willReturn($groupsField);
$root->method('access')->willReturnCallback(fn ($operation, $account) => $operation === 'view' && ($this->readable[(int) $account->id()] ?? FALSE));
    $related = $root;
    if (in_array($wrapper, ['comment', 'mention_comment'], TRUE)) {
      $related = $this->createMock(CommentInterface::class);
      $related->method('getEntityTypeId')->willReturn('comment');
      $related->method('getCommentedEntity')->willReturn($root);
      $related->method('access')->willReturnCallback(fn () => $this->commentReadable);
    }
    if (in_array($wrapper, ['mention_comment', 'mention_post'], TRUE)) {
      $mention = $this->createMock(MentionsInterface::class);
      $mention->method('getEntityTypeId')->willReturn('mentions');
      $mention->method('getMentionedEntity')->willReturn($related);
      $mention->method('access')->willReturn(TRUE);
      $related = $mention;
    }
    elseif ($wrapper === 'like') {
      $related = $this->createMock(VoteInterface::class);
      $related->method('getEntityTypeId')->willReturn('vote');
      $related->method('getVotedEntityType')->willReturn('node');
      $related->method('getVotedEntityId')->willReturn(20);
      $related->method('access')->willReturn(TRUE);
    }
    elseif ($wrapper === 'flagging') {
      $related = $this->createMock(FlaggingInterface::class);
      $related->method('getEntityTypeId')->willReturn('flagging');
      $related->method('getFlaggable')->willReturn($root);
      $related->method('access')->willReturn(TRUE);
    }
    elseif (in_array($wrapper, ['group_content', 'relationship_missing'], TRUE)) {
      $related = $this->createMock(GroupRelationshipInterface::class);
      $related->method('getEntityTypeId')->willReturn('group_content');
      $related->method('getGroupId')->willReturn(5);
      $related->method('getPluginId')->willReturn('group_node:topic');
      $related->method('getEntity')->willReturn($wrapper === 'relationship_missing' ? NULL : $root);
      $related->method('access')->willReturn(TRUE);
    }
    elseif ($wrapper === 'missing') {
      $related = NULL;
    }
    $message = $this->getMockBuilder(Message::class)->disableOriginalConstructor()->onlyMethods(['bundle'])->getMock();
    $message->method('bundle')->willReturn($report ? 'content_reported' : 'create_mention_comment');
    $messageField = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods(['__get'])->getMock();
    $messageField->method('__get')->with('entity')->willReturn($message);
    $activity = $this->getMockBuilder(Activity::class)->disableOriginalConstructor()->onlyMethods(['id', 'getRelatedEntity', 'hasField', 'get'])->getMock();
    $activity->method('id')->willReturn('30');
    $activity->method('getRelatedEntity')->willReturn($related);
    $activity->method('hasField')->willReturnCallback(static fn ($name) => in_array($name, ['field_activity_recipient_group', 'field_activity_message'], TRUE));
    $activity->method('get')->willReturnMap([['field_activity_recipient_group', $groupsField], ['field_activity_message', $messageField]]);
    $accounts = [];
    foreach ([7, 8, 9, 10] as $uid) {
      $account = $this->createMock(UserInterface::class);
      $account->method('id')->willReturn((string) $uid);
      $account->method('isAuthenticated')->willReturn(TRUE);
      $account->method('isActive')->willReturn(TRUE);
      $account->method('hasPermission')->willReturnCallback(static fn ($permission) => $uid === 10 && $permission === 'view inappropriate reports');
      $accounts[$uid] = $account;
    }
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->willReturnCallback(static fn ($uid) => $accounts[(int) $uid] ?? NULL);
    $groups = [];
    $relationships = [];
    foreach ([5, 6] as $gid) {
      $group = $this->createMock(GroupInterface::class);
      $group->method('getEntityTypeId')->willReturn('group');
      $group->method('id')->willReturn((string) $gid);
      $group->method('getMember')->willReturnCallback(static fn ($account) => (int) $account->id() === 8 ? new \stdClass() : NULL);
      $groups[$gid] = $group;
      $relationship = $this->createMock(GroupRelationshipInterface::class);
      $relationship->method('getGroupId')->willReturn($gid);
      $relationship->method('getGroup')->willReturn($group);
      $relationships[] = $relationship;
    }
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->willReturnCallback(static fn ($gid) => $groups[(int) $gid] ?? NULL);
    $relationshipStorage = $this->createMock(GroupRelationshipStorageInterface::class);
    $relationshipStorage->method('loadByEntity')->willReturn($relationships);
    $relationshipStorage->method('resetCache')->willReturnCallback(function () use ($groupPrivacyChanged): void {
      if ($groupPrivacyChanged) $this->readable[9] = FALSE;
    });
    $rootStorage = $this->createMock(EntityStorageInterface::class);
    $rootStorage->method('load')->willReturn($root);
    $activities = $this->createMock(EntityStorageInterface::class);
    $activities->method('load')->willReturn($activity);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->willReturn(TRUE);
    $entities->method('getStorage')->willReturnMap([
      ['user', $users], ['group', $groupStorage], ['group_content', $relationshipStorage],
      ['node', $rootStorage], ['post', $rootStorage], ['activity', $activities],
    ]);
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $departures = new MemoryStorage('departures');
    $departures->set('7:5', 1800000000);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(1800000000);
    return [new GroupNoticeCleanup($database, $entities, $departures, $time), $activity, $root, $accounts];
  }

  /** @dataProvider wrappersAndStages */
  public function testLeavingOneGroupDoesNotMakeTheOtherGroupEligible(string $wrapper, string $stage): void {
    [$cleanup, $activity, $root, $accounts] = $this->fixture($wrapper);
    $this->assertFalse($root->access('view', $accounts[7]));
    $payload = ['uid' => 7, 'frequency' => 'daily', 'activities' => [30]];
    if ($stage === 'immediate') {
      $this->assertFalse($cleanup->allows($activity, 7), 'No marker for group B is not current access to its private content.');
    }
    else {
      $this->assertNull($cleanup->filterDigest($payload), 'An already queued digest must not revive the other-group allowance.');
    }
  }

  /** @dataProvider wrappersAndStages */
  public function testNeverMemberLosesTheNoticeWhenTheContentBecomesPrivate(string $wrapper, string $stage): void {
    [$cleanup, $activity] = $this->fixture($wrapper);
    $this->readable[9] = TRUE;
    $payload = ['uid' => 9, 'frequency' => 'daily', 'activities' => [30]];
    if ($stage === 'immediate') {
      $this->assertTrue($cleanup->allows($activity, 9), 'Previously public content is a positive control.');
      $this->readable[9] = FALSE;
      $this->assertFalse($cleanup->allows($activity, 9), 'Access must be checked again after the content becomes private.');
    }
    else {
      $this->assertSame($payload, $cleanup->filterDigest($payload));
      $this->readable[9] = FALSE;
      $this->assertNull($cleanup->filterDigest($payload));
    }
  }

  /** @dataProvider wrappers */
  public function testCurrentMemberKeepsBothDeliveryPaths(string $wrapper): void {
    [$cleanup, $activity, $root, $accounts] = $this->fixture($wrapper);
    $this->assertTrue($root->access('view', $accounts[8]));
    $this->assertTrue($cleanup->allows($activity, 8));
    $payload = ['uid' => 8, 'frequency' => 'weekly', 'activities' => [30]];
    $this->assertSame($payload, $cleanup->filterDigest($payload));
  }

  public function testReportPermissionDoesNotAuthorizeTheFullNotice(): void {
    [$cleanup, $activity, $root, $accounts] = $this->fixture('flagging', TRUE);
    $this->assertTrue($accounts[10]->hasPermission('view inappropriate reports'));
    $this->assertFalse($root->access('view', $accounts[10]));
    $this->assertFalse($cleanup->allows($activity, 10), 'A generic alert must not authorize the content-bearing notice.');
  }

  public function testAnUnresolvedTargetDoesNotAuthorizeANotice(): void {
    [$cleanup, $activity] = $this->fixture('missing');
    $this->assertFalse($cleanup->allows($activity, 9));
    $this->assertNull($cleanup->filterDigest(['uid' => 9, 'activities' => [30]]));
  }

  /** @dataProvider stages */
  public function testAReadablePageDoesNotAuthorizeAHiddenReply(string $stage): void {
    [$cleanup, $activity, $root, $accounts] = $this->fixture('mention_comment');
    $this->readable[9] = TRUE;
    $this->commentReadable = FALSE;
    $this->assertTrue($root->access('view', $accounts[9]));
    $this->assertFalse($activity->getRelatedEntity()->getMentionedEntity()->access('view', $accounts[9]));
    if ($stage === 'immediate') $this->assertFalse($cleanup->allows($activity, 9));
    else $this->assertNull($cleanup->filterDigest(['uid' => 9, 'activities' => [30]]));
  }

  public static function stages(): array { return [['immediate'], ['digest']]; }

  /** @dataProvider stages */
  public function testChangedGroupPrivacyDoesNotUseACachedRelationship(string $stage): void {
    [$cleanup, $activity, $root, $accounts] = $this->fixture('document', FALSE, TRUE);
    $this->readable[9] = TRUE;
    $this->assertTrue($root->access('view', $accounts[9]), 'The old group relationship still exposes the previous public view.');
    if ($stage === 'immediate') $this->assertFalse($cleanup->allows($activity, 9));
    else $this->assertNull($cleanup->filterDigest(['uid' => 9, 'activities' => [30]]));
  }

  public static function wrappers(): array {
    return array_combine(['document', 'post', 'comment', 'mention_comment', 'mention_post', 'like', 'flagging', 'group_content'], array_map(static fn ($wrapper) => [$wrapper], ['document', 'post', 'comment', 'mention_comment', 'mention_post', 'like', 'flagging', 'group_content']));
  }

  public static function wrappersAndStages(): array {
    $rows = [];
    foreach (self::wrappers() as $name => [$wrapper]) {
      foreach (['immediate', 'digest'] as $stage) $rows[$name . '/' . $stage] = [$wrapper, $stage];
    }
    return $rows;
  }

  public function testRelationshipWithMissingContentCannotAuthorizeANotice(): void {
    [$cleanup, $activity] = $this->fixture('relationship_missing');
    $this->assertFalse($cleanup->allows($activity, 8));
  }

  public function testRelationshipMustCheckThePageForAFormerMemberWithoutAMarker(): void {
    [$cleanup, $activity] = $this->fixture('group_content');
    $cleanup->clearDeparture(7, 5);
    $this->assertFalse($cleanup->allows($activity, 7));
  }


}
