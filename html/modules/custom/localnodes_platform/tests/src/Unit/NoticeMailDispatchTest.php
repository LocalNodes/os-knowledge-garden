<?php

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\activity_creator\Entity\Activity;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\localnodes_platform\Mail\NoticeDeferredFrequency;
use Drupal\localnodes_platform\Mail\NoticeImmediateFrequency;
use Drupal\localnodes_platform\Mail\NoticeDigestWorker;
use Drupal\message\Entity\Message;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Final mail processors do not revive notices after a recorded departure.
 *
 * @group localnodes_platform
 */
final class NoticeMailDispatchTest extends UnitTestCase {

  public function testDefinitionAltersOnlyTheThreeFrequenciesAndDigestWorker(): void {
    require_once $this->root . '/modules/custom/localnodes_platform/localnodes_platform.module';
    $definitions = [
      'immediately' => ['class' => 'original_immediate', 'interval' => 0],
      'daily' => ['class' => 'original_daily', 'interval' => 86400],
      'weekly' => ['class' => 'original_weekly', 'interval' => 604800],
      'none' => ['class' => 'original_none', 'interval' => -1],
    ];
    localnodes_platform_emailfrequency_info_alter($definitions);
    $this->assertSame(['class' => NoticeImmediateFrequency::class, 'interval' => 0], $definitions['immediately']);
    $this->assertSame(['class' => NoticeDeferredFrequency::class, 'interval' => 86400], $definitions['daily']);
    $this->assertSame(['class' => NoticeDeferredFrequency::class, 'interval' => 604800], $definitions['weekly']);
    $this->assertSame(['class' => 'original_none', 'interval' => -1], $definitions['none']);
    $queues = ['activity_digest_worker' => ['class' => 'original_digest', 'cron' => ['time' => 60]], 'other' => ['class' => 'untouched']];
    localnodes_platform_queue_info_alter($queues);
    $this->assertSame(['class' => NoticeDigestWorker::class, 'cron' => ['time' => 60]], $queues['activity_digest_worker']);
    $this->assertSame(['class' => 'untouched'], $queues['other']);
  }

  /**
   * @dataProvider mailStages
   */
  public function testSuppressionReturnsBeforeTheParentCanSendOrStore(string $stage): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('id')->willReturn(5);
    $group->method('access')->willReturn(TRUE);
    $activity = $this->getMockBuilder(Activity::class)->disableOriginalConstructor()->onlyMethods(['id', 'getRelatedEntity', 'hasField', '__isset'])->getMock();
    $activity->method('id')->willReturn(10);
    $activity->method('getRelatedEntity')->willReturn($group);
    $activity->method('hasField')->willReturn(FALSE);
    $cleanup = $this->cleanup($activity, $group, TRUE);
    $container = new ContainerBuilder();
    $container->set('localnodes_platform.group_notice_cleanup', $cleanup);
    \Drupal::setContainer($container);
    $target = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods(['id', 'isBlocked'])->getMock();
    $target->method('id')->willReturn(7);
    $target->expects($this->never())->method('isBlocked');
    $message = $this->getMockBuilder(Message::class)->disableOriginalConstructor()->getMock();
    if ($stage === 'digest') {
      $worker = (new \ReflectionClass(NoticeDigestWorker::class))->newInstanceWithoutConstructor();
      $worker->processItem(['uid' => 7, 'frequency' => 'daily', 'activities' => [10]]);
    }
    elseif ($stage === 'immediately') {
      $processor = (new \ReflectionClass(NoticeImmediateFrequency::class))->newInstanceWithoutConstructor();
      $processor->processItem($activity, $message, $target);
    }
    else {
      (new NoticeDeferredFrequency([], $stage, []))->processItem($activity, $message, $target);
    }
    $this->assertFalse($cleanup->allows($activity, 7));
  }

  public function testAllowedRecipientReachesTheImmediateParent(): void {
    $group = $this->createMock(GroupInterface::class);
    $group->method('getEntityTypeId')->willReturn('group');
    $group->method('id')->willReturn(5);
    $group->method('access')->willReturn(TRUE);
    $activity = $this->getMockBuilder(Activity::class)->disableOriginalConstructor()->onlyMethods(['id', 'getRelatedEntity', 'hasField', '__isset'])->getMock();
    $activity->method('id')->willReturn(10);
    $activity->method('getRelatedEntity')->willReturn($group);
    $activity->method('hasField')->willReturn(FALSE);
    $activity->method('__isset')->willReturn(FALSE);
    $cleanup = $this->cleanup($activity, $group, FALSE);
    $container = new ContainerBuilder();
    $container->set('localnodes_platform.group_notice_cleanup', $cleanup);
    \Drupal::setContainer($container);
    $target = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods(['id', 'isBlocked'])->getMock();
    $target->method('id')->willReturn(7);
    // This method is called only inside the actual upstream parent. The
    // parent then exits because this unit fixture has no body field.
    $target->expects($this->once())->method('isBlocked')->willReturn(FALSE);
    $message = $this->getMockBuilder(Message::class)->disableOriginalConstructor()->getMock();
    $processor = (new \ReflectionClass(NoticeImmediateFrequency::class))->newInstanceWithoutConstructor();
    $processor->processItem($activity, $message, $target);
    $this->assertTrue($cleanup->allows($activity, 7));
  }

  private function cleanup(Activity $activity, GroupInterface $group, bool $departed): GroupNoticeCleanup {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($activity);
    $groups = $this->createMock(EntityStorageInterface::class);
    $groups->method('load')->willReturn($group);
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn('7');
    $account->method('isActive')->willReturn(TRUE);
    $account->method('isAuthenticated')->willReturn(TRUE);
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->willReturn($account);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturnMap([['activity', $storage], ['group', $groups], ['user', $users]]);
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);
    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $departures = new MemoryStorage('departures');
    if ($departed) $departures->set('7:5', time());
    return new GroupNoticeCleanup($database, $manager, $departures, $this->clock());
  }

  private function clock(): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn(time());
    return $time;
  }

  public function mailStages(): array {
    return ['immediate' => ['immediately'], 'daily' => ['daily'], 'weekly' => ['weekly'], 'queued digest' => ['digest']];
  }

}
