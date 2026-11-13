<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\MemoryBackend;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\State\StateInterface;
use Drupal\localnodes_platform\Export\ExportAccess;
use Drupal\localnodes_platform\Export\ExportIdentity;
use Drupal\localnodes_platform\Export\ExportSignatureProofInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Component\Datetime\Time;

/** @group localnodes_platform */
final class ExportSignatureIdentityTest extends UnitTestCase {
  private function identity(?ExportSignatureProofInterface $signer, string $hash = '', ?MemoryBackend $flood = NULL, ?\Closure $check = NULL): ExportIdentity {
    $user = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods(['getRoles', 'isActive', 'hasPermission', 'getPassword'])->getMock();
    $user->method('getRoles')->willReturn(['sitemanager']);
    $user->method('isActive')->willReturn(TRUE);
    $user->method('hasPermission')->willReturn(TRUE);
    $user->method('getPassword')->willReturn($hash);
    $role = $this->getMockBuilder(Role::class)->disableOriginalConstructor()->onlyMethods(['getPermissions'])->getMock();
    $role->method('getPermissions')->willReturn(['export localnodes site']);
    $users = $this->createMock(EntityStorageInterface::class); $users->method('load')->willReturn($user);
    $roles = $this->createMock(EntityStorageInterface::class); $roles->method('loadMultiple')->willReturn([$role]);
    $entities = $this->createMock(EntityTypeManagerInterface::class); $entities->method('getStorage')->willReturnMap([['user', $users], ['user_role', $roles]]);
    $state = $this->createMock(StateInterface::class); $state->method('get')->willReturn(7);
    $password = $this->createMock(PasswordInterface::class); $password->method('check')->willReturnCallback($check ?? fn ($plain) => $plain === 'correct');
    $stack = new RequestStack();
    return new ExportIdentity(new ExportAccess($state, $entities), $password, $flood ?? new MemoryBackend($stack, new Time($stack)), $signer);
  }
  public function testSharedFiveFailureLimitRefusesAGenuinelyValidSignature(): void {
    $signer = $this->createMock(ExportSignatureProofInterface::class);
    $signer->expects($this->exactly(5))->method('confirm')->willReturnCallback(fn ($uid, $action, $id, $message, $signature) => $signature === 'correct');
    $identity = $this->identity($signer);
    for ($i = 0; $i < 5; $i++) $this->assertFalse($identity->confirm(7, '', TRUE, 'request', '', 'exact message', 'wrong'));
    $this->assertFalse($identity->confirm(7, '', TRUE, 'request', '', 'exact message', 'correct'));
  }
  public function testPasswordTakesPrecedenceAndDoesNotAskSigner(): void {
    $signer = $this->createMock(ExportSignatureProofInterface::class);
    $signer->expects($this->never())->method('confirm');
    $identity = $this->identity($signer, 'stored hash');
    $this->assertFalse($identity->confirm(7, '', TRUE, 'request', '', 'message', 'signature'));
    $this->assertTrue($identity->confirm(7, 'correct', TRUE));
  }
  public function testFreshSignatureAndAcknowledgementRequiredRegardlessOfSession(): void {
    $signer = $this->createMock(ExportSignatureProofInterface::class);
    $signer->method('confirm')->willReturnCallback(fn ($uid, $action, $id, $message, $signature) => $message === 'exact message' && $signature === 'correct');
    $identity = $this->identity($signer);
    $this->assertFalse($identity->confirm(7, '', TRUE));
    $this->assertFalse($identity->confirm(7, '', FALSE, 'request', '', 'exact message', 'correct'));
    $this->assertTrue($identity->confirm(7, '', TRUE, 'request', '', 'exact message', 'correct'));
    $this->assertFalse($this->identity(NULL)->confirm(7, '', TRUE));
  }
  public function testAttemptIsReservedBeforeThePasswordIsChecked(): void {
    // Concurrent tries: each one must already count when its password is
    // checked, or all of them pass the limit before any failure is recorded.
    $stack = new RequestStack();
    $flood = new MemoryBackend($stack, new Time($stack));
    $seen = [];
    $identity = NULL;
    $identity = $this->identity(NULL, 'stored hash', $flood, function ($plain) use ($flood, &$seen, &$identity): bool {
      $seen[] = $flood->isAllowed('localnodes_export_confirm', 1, 3600, '7');
      // A second request arriving while this one is being checked.
      if (count($seen) < 10) $identity->confirm(7, 'wrong', TRUE);
      return FALSE;
    });
    $this->assertFalse($identity->confirm(7, 'wrong', TRUE));
    $this->assertFalse($seen[0], 'The attempt was not registered before the password check.');
    $this->assertCount(5, $seen, 'More than five overlapping attempts reached the password check.');
  }
}
