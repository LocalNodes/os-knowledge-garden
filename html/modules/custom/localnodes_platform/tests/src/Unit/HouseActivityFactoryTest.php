<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\activity_creator\Entity\Activity;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Activity\HouseActivityFactory;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\message\Entity\Message;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/** @group localnodes_platform */
final class HouseActivityFactoryTest extends UnitTestCase {

  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/src/Activity/HouseActivityFactory.php';
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public static function audiences(): array {
    return [
      'departed personal recipient' => [5, FALSE, FALSE],
      'another house remains eligible' => [6, FALSE, TRUE],
      'explicit report-handler exception' => [5, TRUE, TRUE],
    ];
  }

  /** @dataProvider audiences */
  public function testRealFactoryAppliesRealFinalNoticeEligibility(int $gid, bool $report, bool $reaches_parent): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('id')->willReturn($gid);
    $group->method('getMember')->willReturn(NULL);
    $target = $this->createMock(UserInterface::class);
    $target->method('hasPermission')->with('view inappropriate reports')->willReturn($report);
    // The send-time rule reloads a real, active, signed-in account.
    $target->method('id')->willReturn(7);
    $target->method('isActive')->willReturn(TRUE);
    $target->method('isAuthenticated')->willReturn(TRUE);
    $group->method('access')->willReturn(TRUE);
    $message = $this->getMockBuilder(Message::class)->disableOriginalConstructor()->onlyMethods(['bundle'])->getMock();
    $message->method('bundle')->willReturn('content_reported');
    $message_field = $this->getMockBuilder(FieldItemList::class)->disableOriginalConstructor()->onlyMethods(['__get'])->getMock();
    $message_field->method('__get')->with('entity')->willReturn($message);
    $activity = $this->getMockBuilder(Activity::class)->disableOriginalConstructor()->onlyMethods(['getRelatedEntity', 'hasField', 'get'])->getMock();
    $activity->method('getRelatedEntity')->willReturn($group);
    $activity->method('hasField')->willReturnCallback(static fn ($name) => $report && $name === 'field_activity_message');
    $activity->method('get')->with('field_activity_message')->willReturn($message_field);
    $activities = $this->createMock(EntityStorageInterface::class);
    $activities->method('create')->willReturn($activity);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->willReturn($group);
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->willReturn($target);
    $messages = $this->createMock(EntityStorageInterface::class);
    // This lookup exists only in the upstream parent producer. A removed guard
    // reaches it for the departed recipient and fails this explicit expectation.
    $messages->expects($reaches_parent ? $this->once() : $this->never())->method('load')->with(22)->willReturn(NULL);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([
      ['activity', $activities], ['group', $groups], ['user', $users], ['message', $messages],
    ]);
    $departures = new MemoryStorage('departures');
    $departures->set('7:5', 2000000000);
    $cleanup = new GroupNoticeCleanup($this->createMock(Connection::class), $entities, $departures, $this->createMock(TimeInterface::class));
    $repository = $this->createMock(EntityTypeRepositoryInterface::class);
    $repository->method('getEntityTypeFromClass')->willReturnCallback(static function ($class) {
      if ($class === \Drupal\activity_creator\Entity\Activity::class) return 'activity';
      if ($class === \Drupal\message\Entity\Message::class) return 'message';
      if ($class === \Drupal\user\Entity\User::class) return 'user';
      throw new \LogicException('Unexpected entity class.');
    });
    $container = new ContainerBuilder();
    $container->set('entity_type.repository', $repository);
    $container->set('entity_type.manager', $entities);
    $container->set('localnodes_platform.group_notice_cleanup', $cleanup);
    \Drupal::setContainer($container);
    $expected = $reaches_parent ? ($report ? GroupNoticeCleanup::GENERIC_REPORT : GroupNoticeCleanup::FULL) : GroupNoticeCleanup::DROP;
    $this->assertSame($expected, $cleanup->delivery($activity, 7), 'The real send-time policy decides eligibility.');
    $factory = (new \ReflectionClass(HouseActivityFactory::class))->newInstanceWithoutConstructor();
    $this->assertSame([], $factory->createActivities([
      'mid' => 22, 'related_object' => [['target_type' => 'group', 'target_id' => $gid]],
      'recipient' => [['target_type' => 'user', 'target_id' => 7]],
    ]));
  }

}
