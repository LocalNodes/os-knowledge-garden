<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\localnodes_platform\Export\ExportAccess;
use Drupal\localnodes_platform\Export\ExportManager;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/** @group localnodes_platform */
final class ExportNoticeRetryTest extends UnitTestCase {

  public function testThreeWorkerPassesRetryOnlyTheFailedRecipient(): void {
    $base = sys_get_temp_dir() . '/export-notice-retry-' . bin2hex(random_bytes(8));
    mkdir($base, 0700);
    try { Settings::getInstance(); $settings = Settings::getAll(); }
    catch (\BadMethodCallException) { $settings = []; }
    new Settings(['file_private_path' => $base] + $settings);
    $previous = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    $container = new ContainerBuilder();
    $container->set('account_switcher', $this->createMock(AccountSwitcherInterface::class));
    \Drupal::setContainer($container);
    try {
      $query = $this->createMock(QueryInterface::class);
      $query->method('accessCheck')->willReturnSelf();
      $query->method('condition')->willReturnSelf();
      $query->method('execute')->willReturn([11, 12, 13]);
      $users = $this->createMock(EntityStorageInterface::class);
      $users->method('getQuery')->willReturn($query);
      $users->method('load')->willReturnCallback(function ($uid) {
        $user = $this->createMock(UserInterface::class);
        $user->method('id')->willReturn($uid);
        return $user;
      });
      $entities = $this->createMock(EntityTypeManagerInterface::class);
      $entities->method('getStorage')->willReturn($users);
      $mail = new class {
        public array $attempts = [];
        public array $delivered = [];
        public function newTypedEmail(string $type, string $event, $user, array $job): object {
          return new class($this, (int) $user->id()) {
            public function __construct(private object $mail, private int $uid) {}
            public function send(): bool {
              $this->mail->attempts[$this->uid] = ($this->mail->attempts[$this->uid] ?? 0) + 1;
              if ($this->uid === 11) return FALSE;
              $this->mail->delivered[$this->uid] = ($this->mail->delivered[$this->uid] ?? 0) + 1;
              return TRUE;
            }
          };
        }
      };
      $channel = $this->createMock(LoggerChannelInterface::class);
      $channel->expects($this->never())->method('warning');
      $logger = $this->createMock(LoggerChannelFactoryInterface::class);
      $logger->method('get')->willReturn($channel);
      $job = ['id' => str_repeat('a', 32), 'status' => 'queued', 'notices' => ['requested' => []]];
      $manager = $this->getMockBuilder(ExportManager::class)->disableOriginalConstructor()->onlyMethods(['current', 'save'])->getMock();
      $manager->method('current')->willReturnCallback(function () use (&$job) { return $job; });
      $manager->method('save')->willReturnCallback(function ($key, array $value) use (&$job): void { $job = $value; });
      foreach (['entities' => $entities, 'access' => new ExportAccess($this->createMock(StateInterface::class), $entities), 'emailFactory' => $mail, 'logger' => $logger] as $name => $value) {
        (new \ReflectionProperty(ExportManager::class, $name))->setValue($manager, $value);
      }
      $notify = new \ReflectionMethod($manager, 'notify');
      for ($pass = 0; $pass < 3; $pass++) {
        // A waiting worker checks notices before and after its capture attempt.
        $notify->invoke($manager);
        $notify->invoke($manager);
      }
      $this->assertSame([11 => 6, 12 => 1, 13 => 1], $mail->attempts);
      $this->assertSame([12 => 1, 13 => 1], $mail->delivered);
      $this->assertSame([12 => TRUE, 13 => TRUE], $job['notices']['requested']);
    }
    finally {
      if ($previous) \Drupal::setContainer($previous);
      else \Drupal::unsetContainer();
      new Settings($settings);
      foreach (glob($base . '/.localnodes/*') ?: [] as $file) unlink($file);
      @rmdir($base . '/.localnodes');
      @rmdir($base);
    }
  }

}
