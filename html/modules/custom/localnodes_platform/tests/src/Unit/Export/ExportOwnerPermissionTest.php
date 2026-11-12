<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\localnodes_platform\Export\ExportAccess;
use Drupal\Tests\UnitTestCase;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/** @group localnodes_platform */
final class ExportOwnerPermissionTest extends UnitTestCase {

  /** @dataProvider grants */
  public function testAdministrativeBypassDoesNotReplaceAnExplicitGrant(int $uid, array $permissions, bool $api, bool $expected): void {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn($uid);
    $user = $this->createMock(UserInterface::class);
    $user->method('isActive')->willReturn(TRUE);
    $user->method('getRoles')->willReturn(['authenticated', 'sitemanager', 'administrator']);
    $user->method('hasPermission')->willReturn($api);
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->willReturn($user);
    $role = $this->createMock(RoleInterface::class);
    $role->method('getPermissions')->willReturn($permissions);
    $roles = $this->createMock(EntityStorageInterface::class);
    $roles->method('loadMultiple')->willReturn(['sitemanager' => $role]);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnCallback(fn ($type) => $type === 'user' ? $users : $roles);
    $this->assertSame($expected, (new ExportAccess($state, $entities))->allows($uid));
  }

  public static function grants(): array {
    return [
      [1, [], TRUE, FALSE],
      [7, [], TRUE, FALSE],
      [1, ['export localnodes site'], TRUE, TRUE],
      [7, ['export localnodes site'], TRUE, TRUE],
      [7, ['export localnodes site'], FALSE, FALSE],
    ];
  }

}
