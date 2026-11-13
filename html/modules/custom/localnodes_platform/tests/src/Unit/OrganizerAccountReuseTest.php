<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\Tests\UnitTestCase;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Symfony\Component\Console\Output\BufferedOutput;

/** @group localnodes_platform */
final class OrganizerAccountReuseTest extends UnitTestCase {

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  private function command(int $uid, array $role_flags): array {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn((string) $uid);
    $account->method('getRoles')->willReturn(array_keys($role_flags));
    $account->expects($this->never())->method('save');
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('loadByProperties')->with(['mail' => 'organizer@example.org'])->willReturn([$account]);
    $users->expects($this->never())->method('create');
    $roles = [];
    foreach ($role_flags as $id => $is_admin) {
      $role = $this->createMock(RoleInterface::class);
      $role->method('isAdmin')->willReturn($is_admin);
      $roles[$id] = $role;
    }
    $role_storage = $this->createMock(EntityStorageInterface::class);
    $role_storage->method('loadMultiple')->willReturn($roles);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([['user', $users], ['user_role', $role_storage]]);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entities);
    \Drupal::setContainer($container);
    $output = new BufferedOutput();
    $command = new class extends OrganizerCommands {
      public function existing(): ?UserInterface {
        return $this->loadOrCreateAccount('organizer@example.org', 'Organizer');
      }
    };
    $command->setOutput($output);
    return [$command, $account, $output];
  }

  /** @dataProvider privilegedAccounts */
  public function testPrivilegedAccountCannotBecomeTheOrganizer(int $uid, array $role_flags): void {
    [$command, , $output] = $this->command($uid, $role_flags);
    $this->assertNull($command->existing());
    $this->assertSame('The organizer account cannot be a host or administrator account.', trim($output->fetch()));
  }

  public static function privilegedAccounts(): array {
    return [
      'host uid1' => [1, []],
      'administrator role' => [7, ['administrator' => TRUE]],
      'custom administrator role' => [7, ['support_admin' => TRUE]],
    ];
  }

  public function testOrdinaryAccountReuseDoesNotChangeItsRoles(): void {
    [$command, $account, $output] = $this->command(7, ['sitemanager' => FALSE]);
    $this->assertSame($account, $command->existing());
    $this->assertSame('', $output->fetch());
  }
}
