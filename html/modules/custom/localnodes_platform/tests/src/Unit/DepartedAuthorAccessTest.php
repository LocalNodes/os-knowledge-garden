<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\comment\Entity\Comment;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\Storage\GroupRelationshipStorageInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\node\Entity\Node;
use Drupal\social_event\Entity\EventEnrollment;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\UnitTestCase;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/** @group localnodes_platform */
final class DepartedAuthorAccessTest extends UnitTestCase {

  private const LEFT_AT = 2000000000;
  private MemoryStorage $markers;

  /** The fixture page's type and owner, for what a departure records. */
  private string $fixtureType = 'node';
  private int $fixtureOwner = 7;
  private GroupNoticeCleanup $cleanup;
  private DepartedAuthorAccess $policy;
  private array $invalidated = [];
  private array $members = [];
  private int $now = self::LEFT_AT;

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  private function field($value = NULL, array $rows = []): FieldItemList {
    $field = $this->createMock(FieldItemList::class);
    $field->method('__get')->willReturnCallback(static fn (string $property) => $property === 'value' ? $value : NULL);
    $field->method('getValue')->willReturn($rows);
    return $field;
  }

  private function actor(int $uid = 7, array $permissions = ['access content', 'view published post entities', 'view community posts'], bool $authenticated = TRUE, bool $admin = FALSE, bool $active = TRUE): UserInterface {
    $actor = $this->createMock(UserInterface::class);
    $actor->method('id')->willReturn((string) $uid);
    $actor->method('isAuthenticated')->willReturn($authenticated);
    $actor->method('isActive')->willReturn($active);
    $actor->method('getRoles')->willReturn([$admin ? 'administrator' : 'verified']);
    $actor->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => in_array($permission, $permissions, TRUE));
    return $actor;
  }

  /** Creates the actual two services; only entity/storage/clock infrastructure is mocked. */
  private function fixture(string $type = 'node', int $owner = 7, bool $published = TRUE, bool $private = TRUE, bool $new = FALSE, int $created = self::LEFT_AT - 400 * 86400): object {
    $this->fixtureType = $type;
    $this->fixtureOwner = $owner;
    $this->markers = new class('notice_departures') extends MemoryStorage {
      public int $scans = 0;
      public function getAll() { ++$this->scans; return parent::getAll(); }
    };
    $this->invalidated = [];
    $this->members = [];
    $this->now = self::LEFT_AT;
    $entity = $this->createMock($type === 'post' ? Post::class : Node::class);
    $entity->method('getEntityTypeId')->willReturn($type);
    $entity->method('id')->willReturn('10');
    $entity->method('isNew')->willReturn($new);
    $entity->method('getCreatedTime')->willReturn($created);
    $entity->method('isPublished')->willReturn($published);
    $entity->method('getOwnerId')->willReturn((string) $owner);
    $entity->method('getCacheContexts')->willReturn([]);
    $entity->method('getCacheTags')->willReturn([$type . ':10']);
    $entity->method('getCacheMaxAge')->willReturn(-1);
    $fields = $type === 'post'
      ? ['field_visibility' => $this->field($private ? '3' : '1'), 'field_recipient_group' => $this->field(NULL, [['target_id' => 5]])]
      : ['field_content_visibility' => $this->field($private ? 'group' : 'public')];
    $entity->method('hasField')->willReturnCallback(static fn (string $name): bool => isset($fields[$name]));
    $entity->method('get')->willReturnCallback(static fn (string $name) => $fields[$name]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('5');
    $group->method('bundle')->willReturn('flexible_group');
    $group->method('hasField')->willReturnCallback(static fn (string $field): bool => $field === 'field_flexible_group_visibility');
    $group->method('get')->willReturn($this->field($private ? 'members' : 'public'));
    $group->method('getMember')->willReturnCallback(fn ($actor) => !empty($this->members[(int) $actor->id()]) ? new \stdClass() : FALSE);
    $group->method('getCacheContexts')->willReturn([]);
    $group->method('getCacheTags')->willReturn(['group:5']);
    $group->method('getCacheMaxAge')->willReturn(-1);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('loadMultiple')->willReturnCallback(static fn (array $ids): array => in_array(5, $ids) ? [5 => $group] : []);
    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->method('getGroupId')->willReturn('5');
    $relationships = $this->createMock(GroupRelationshipStorageInterface::class);
    $relationships->method('loadByEntity')->willReturn([$relationship]);
    $roles = $this->createMock(EntityStorageInterface::class);
    $roles->method('loadMultiple')->willReturnCallback(function (array $ids): array {
      $loaded = [];
      foreach ($ids as $id) {
        $role = $this->createMock(RoleInterface::class);
        $role->method('isAdmin')->willReturn($id === 'administrator');
        $role->method('getCacheContexts')->willReturn([]);
        $role->method('getCacheTags')->willReturn(['config:user.role.' . $id]);
        $role->method('getCacheMaxAge')->willReturn(-1);
        $loaded[$id] = $role;
      }
      return $loaded;
    });
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('hasDefinition')->willReturnCallback(static fn (string $type): bool => in_array($type, ['group', 'group_content'], TRUE));
    $entities->method('getStorage')->willReturnMap([['group', $groups], ['group_content', $relationships], ['user_role', $roles]]);
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturnCallback(fn (): int => $this->now);
    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->method('invalidateTags')->willReturnCallback(function (array $tags): void { $this->invalidated[] = $tags; });
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('schema');
    $this->cleanup = new GroupNoticeCleanup($database, $entities, $this->markers, $clock, $invalidator);
    $this->policy = new DepartedAuthorAccess($entities, $this->cleanup);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', new CacheContextsManager($container, ['user', 'user.roles', 'user.permissions']));
    \Drupal::setContainer($container);
    return $entity;
  }

  /**
   * Records a departure as the site does, with what was owned in the house.
   *
   * The database is not available here, so the owned list is what the real
   * query returns for this fixture: page 10 sits in house 5 only and is
   * listed when the person leaving owns it. Kernel tests cover the query.
   */
  private function leave(int $uid, int $gid): void {
    $first = $this->cleanup->departureTime($uid, $gid) === NULL;
    $this->cleanup->remove($uid, $gid);
    if ($first) {
      $owned = $gid === 5 && $this->fixtureOwner === $uid ? [10] : [];
      $this->markers->set('authored:' . $uid . ':' . $gid, ['node' => [], 'post' => [], $this->fixtureType => $owned]);
    }
  }

  public static function authoredKinds(): array {
    return [['node'], ['post']];
  }

  /** @dataProvider authoredKinds */
  public function testRecordedFormerAuthorRetainsOnlyReading(string $kind): void {
    $entity = $this->fixture($kind);
    $actor = $this->actor();
    $this->leave(7, 5);
    $this->assertTrue($this->policy->canRetainView($entity, $actor));
    $this->assertTrue($this->policy->deniesParticipation($entity, $actor));
    $this->assertFalse($this->policy->canMention($entity, $actor));
    $this->assertSame(self::LEFT_AT, $this->policy->departureTime(7, 5));
  }

  public function testAuthorshipWithoutRecordedDepartureDoesNotCreateAnException(): void {
    $entity = $this->fixture();
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor()));
  }

  public function testDepartureInAnotherHouseDoesNotUnlockThisPage(): void {
    $entity = $this->fixture();
    $this->leave(7, 6);
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor()));
  }

  public function testCurrentMembershipWinsEvenWhileADepartureMarkerStillExists(): void {
    $entity = $this->fixture();
    $this->leave(7, 5);
    $this->members[7] = TRUE;
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor()));
    $this->assertFalse($this->policy->deniesParticipation($entity, $this->actor()));
    $this->assertTrue($this->policy->canMention($entity, $this->actor()));
  }

  public function testRetainedReadingHasNoArbitraryAgeLimit(): void {
    $entity = $this->fixture();
    $this->markers->set('7:5', self::LEFT_AT - 300 * 86400);
    $this->markers->set('authored:7:5', ['node' => [10], 'post' => []]);
    $this->assertTrue($this->policy->canRetainView($entity, $this->actor()));
    $this->assertSame(self::LEFT_AT - 300 * 86400, $this->policy->departureTime(7, 5));
  }

  public function testAnotherMembersPageDoesNotBecomeReadable(): void {
    $entity = $this->fixture('node', 9);
    $this->leave(7, 5);
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor()));
  }

  public function testAnonymousAndUnsavedPagesCannotUseRetainedReading(): void {
    $entity = $this->fixture();
    $this->leave(7, 5);
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor(7, ['access content'], FALSE)));
    $entity = $this->fixture('node', 7, TRUE, TRUE, TRUE);
    $this->leave(7, 5);
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor()));
  }

  public function testPublicPageUsesNormalAccessWithoutAFormerMemberException(): void {
    $entity = $this->fixture('node', 7, TRUE, FALSE);
    $this->leave(7, 5);
    $this->assertFalse($this->policy->isPrivate($entity));
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor()));
    $this->assertFalse($this->policy->deniesParticipation($entity, $this->actor()));
    $this->assertTrue($this->policy->canMention($entity, $this->actor()));
  }

  public static function unpublishedCases(): array {
    return [
      'node without own-draft permission' => ['node', ['access content'], FALSE],
      'node with own-draft permission' => ['node', ['access content', 'view own unpublished content'], TRUE],
      'post without own-draft permission' => ['post', ['view published post entities', 'view community posts'], FALSE],
      'post with own-draft permission' => ['post', ['view own unpublished post entities'], TRUE],
    ];
  }

  /** @dataProvider unpublishedCases */
  public function testRetainedReadingDoesNotOverridePublicationPermissions(string $kind, array $permissions, bool $expected): void {
    $entity = $this->fixture($kind, 7, FALSE);
    $this->leave(7, 5);
    $this->assertSame($expected, $this->policy->canRetainView($entity, $this->actor(7, $permissions)));
  }

  public static function publishedPostCases(): array {
    return [
      'missing publication access' => [['view community posts'], FALSE],
      'missing audience access' => [['view published post entities'], FALSE],
      'both ordinary grants' => [['view published post entities', 'view community posts'], TRUE],
    ];
  }

  /** @dataProvider publishedPostCases */
  public function testPublishedPostKeepsItsOrdinaryPublicationAndAudienceChecks(array $permissions, bool $expected): void {
    $entity = $this->fixture('post');
    $this->leave(7, 5);
    $this->assertSame($expected, $this->policy->canRetainView($entity, $this->actor(7, $permissions)));
  }

  public function testPublishedNodeStillRequiresOrdinaryContentAccess(): void {
    $entity = $this->fixture();
    $this->leave(7, 5);
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor(7, [])));
  }

  public function testRepliesFollowTheAuthoredRootButCommentingAloneDoesNotQualify(): void {
    $entity = $this->fixture();
    $this->leave(7, 5);
    $reply = $this->createMock(Comment::class);
    $reply->method('getEntityTypeId')->willReturn('comment');
    $reply->method('getCommentedEntity')->willReturn($entity);
    $this->assertSame($entity, $this->policy->root($reply));
    $this->assertTrue($this->policy->canRetainView($reply, $this->actor()));
    $this->assertTrue($this->policy->deniesParticipation($reply, $this->actor()));
    $this->assertFalse($this->policy->canRetainView($reply, $this->actor(9)), 'Writing a reply does not transfer root authorship.');
  }

  public function testEnrollmentParticipationUsesItsActualEventRoot(): void {
    $event = $this->fixture();
    $reference = $this->createMock(FieldItemList::class);
    $reference->method('__get')->with('entity')->willReturn($event);
    $enrollment = $this->createMock(EventEnrollment::class);
    $enrollment->method('getEntityTypeId')->willReturn('event_enrollment');
    $enrollment->method('hasField')->willReturnCallback(static fn (string $name): bool => $name === 'field_event');
    $enrollment->method('get')->with('field_event')->willReturn($reference);
    $this->leave(7, 5);
    $this->assertSame($event, $this->policy->root($enrollment));
    $this->assertTrue($this->policy->deniesParticipation($enrollment, $this->actor()));
    $this->members[7] = TRUE;
    $this->assertFalse($this->policy->deniesParticipation($enrollment, $this->actor()));
  }

  public function testRejoiningRestoresParticipationAndClearsAllDepartureFlags(): void {
    $entity = $this->fixture();
    $this->leave(7, 5);
    $this->cleanup->recordRemoval(7, 5, TRUE);
    $this->members[7] = TRUE;
    $this->cleanup->clearDeparture(7, 5);
    $this->assertNull($this->cleanup->departureTime(7, 5));
    $this->assertFalse($this->cleanup->retainedReadingClosed(7, 5));
    $this->assertFalse($this->cleanup->wasRemoved(7, 5));
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor()), 'A current member uses ordinary house access.');
    $this->assertFalse($this->policy->deniesParticipation($entity, $this->actor()));
    $this->assertTrue($this->policy->canMention($entity, $this->actor()));
  }

  public function testSafetyClosureIsExplicitAndExcludesOnlyThatHouse(): void {
    $entity = $this->fixture();
    $this->leave(7, 5);
    $this->leave(7, 6);
    $this->assertFalse($this->cleanup->retainedReadingClosed(7, 5));
    $this->assertTrue($this->policy->canRetainView($entity, $this->actor()));
    $this->cleanup->recordRemoval(7, 5, TRUE);
    $this->assertTrue($this->cleanup->wasRemoved(7, 5));
    $this->assertFalse($this->policy->canRetainView($entity, $this->actor()));
    $this->assertSame([6], $this->policy->departedGroupIds($this->actor()));
    $this->assertSame(self::LEFT_AT, $this->cleanup->departureTime(7, 5));
  }

  public function testOrdinaryRemovalDoesNotCloseRetainedReading(): void {
    $entity = $this->fixture();
    $this->leave(7, 5);
    $this->cleanup->recordRemoval(7, 5);
    $this->assertTrue($this->cleanup->wasRemoved(7, 5));
    $this->assertFalse($this->cleanup->retainedReadingClosed(7, 5));
    $this->assertTrue($this->policy->canRetainView($entity, $this->actor()));
  }

  public function testDepartureDateIsStableUntilRejoinAndThenStartsANewAbsence(): void {
    $this->fixture();
    $this->leave(7, 5);
    $this->now += 400;
    $this->leave(7, 5);
    $this->assertSame(self::LEFT_AT, $this->cleanup->departureTime(7, 5), 'Retries cannot reset the displayed departure date.');
    $this->cleanup->clearDeparture(7, 5);
    $this->now += 200;
    $this->leave(7, 5);
    $this->assertSame(self::LEFT_AT + 600, $this->cleanup->departureTime(7, 5));
  }

  public function testMarkerEnumerationIgnoresSafetyKeysOtherPeopleAndInvalidDates(): void {
    $this->fixture();
    foreach (['7:5' => self::LEFT_AT, '7:6' => (string) self::LEFT_AT, '7:0' => self::LEFT_AT, '7:8' => 'invalid', '7:9' => 0, '8:5' => self::LEFT_AT, 'read_closed:7:5' => TRUE] as $key => $value) $this->markers->set($key, $value);
    $this->assertSame([5 => self::LEFT_AT, 6 => self::LEFT_AT], $this->cleanup->departedGroups(7));
    $this->assertNull($this->cleanup->departureTime(7, 8));
  }

  public function testDepartureMutationsInvalidateTheSameTagsAsPolicyResults(): void {
    $entity = $this->fixture();
    $this->leave(7, 5);
    $this->cleanup->recordRemoval(7, 5);
    $this->cleanup->closeRetainedReading(7, 5);
    $this->cleanup->clearDeparture(7, 5);
    $this->assertCount(4, $this->invalidated);
    foreach ($this->invalidated as $tags) {
      $this->assertContains('localnodes_departure:7:5', $tags);
      $this->assertContains('group_content_list', $tags);
    }
    $metadata = $this->policy->accessMetadata($entity, $this->actor());
    foreach (['node:10', 'group:5', 'group_content_list', 'localnodes_departure:7:5', 'config:user.role.verified'] as $tag) $this->assertContains($tag, $metadata->getCacheTags());
    foreach (['user', 'user.roles', 'user.permissions'] as $context) $this->assertContains($context, $metadata->getCacheContexts());
  }

  public function testHostAndAdministratorRecoveryDoesNotCreateNormalMemberRights(): void {
    $entity = $this->fixture();
    $this->assertFalse($this->policy->deniesParticipation($entity, $this->actor(1)));
    $this->assertFalse($this->policy->deniesParticipation($entity, $this->actor(9, [], TRUE, TRUE)));
    $this->assertTrue($this->policy->deniesParticipation($entity, $this->actor(9)));
  }

  public static function creationTimes(): array {
    return [
      'node before departure' => ['node', self::LEFT_AT - 1, TRUE],
      'node at departure' => ['node', self::LEFT_AT, TRUE],
      'node after departure' => ['node', self::LEFT_AT + 1, FALSE],
      'post before departure' => ['post', self::LEFT_AT - 1, TRUE],
      'post at departure' => ['post', self::LEFT_AT, TRUE],
      'post after departure' => ['post', self::LEFT_AT + 1, FALSE],
    ];
  }

  /** @dataProvider creationTimes */
  public function testRetainedPageMustExistByTheRecordedDeparture(string $kind, int $created, bool $expected): void {
    $page = $this->fixture($kind, 7, TRUE, TRUE, FALSE, $created);
    $this->leave(7, 5);
    $this->assertSame($expected, $this->policy->canRetainView($page, $this->actor()));
  }

  public function testBlockedAuthorCannotUseRetainedReading(): void {
    $page = $this->fixture();
    $this->leave(7, 5);
    $blocked = $this->actor(7, ['access content'], TRUE, FALSE, FALSE);
    $this->assertFalse($this->policy->canRetainView($page, $blocked));
    $this->assertSame([], $this->policy->departedGroupIds($blocked));
  }

  public function testDepartureEnumerationIsMemoisedAndLocalMutationsRefreshIt(): void {
    $this->fixture();
    $this->leave(7, 5);
    $this->assertSame([5 => self::LEFT_AT], $this->cleanup->departedGroups(7));
    $this->assertSame([5 => self::LEFT_AT], $this->cleanup->departedGroups(7));
    $this->assertSame(1, $this->markers->scans);
    $this->leave(7, 6);
    $this->assertSame([5 => self::LEFT_AT, 6 => self::LEFT_AT], $this->cleanup->departedGroups(7));
    $this->cleanup->clearDeparture(7, 5);
    $this->assertSame([6 => self::LEFT_AT], $this->cleanup->departedGroups(7));
    $this->assertSame([6 => self::LEFT_AT], $this->cleanup->departedGroups(7));
    $this->assertSame(3, $this->markers->scans);
    $this->assertSame([], $this->cleanup->departedGroups(8));
    $this->assertSame([], $this->cleanup->departedGroups(8));
    $this->assertSame(4, $this->markers->scans);
  }

}
