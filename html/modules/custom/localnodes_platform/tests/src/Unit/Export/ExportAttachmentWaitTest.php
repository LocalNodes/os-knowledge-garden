<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\localnodes_platform\Export\ExportAccess;
use Drupal\localnodes_web3\Export\ExportSignatureProof;
use Drupal\siwe_login\Service\ContractWalletDetector;
use Drupal\siwe_login\Service\SiweMessageValidator;
use Drupal\siwe_login\Service\SiweNonceStore;
use Drupal\Tests\UnitTestCase;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

/** @group localnodes_platform */
final class ExportAttachmentWaitTest extends UnitTestCase {
  public const ADDRESS = '0x1111111111111111111111111111111111111111';
  private int $now = 1800000000;
  private ?int $attached = NULL;
  private array $pending = [];

  private function proof(): ExportSignatureProof {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('7');
    $user->method('isActive')->willReturn(TRUE);
    $user->method('getRoles')->willReturn(['sitemanager']);
    $user->method('hasPermission')->willReturn(TRUE);
    $user->method('hasField')->willReturn(TRUE);
    $user->method('get')->willReturn((object) ['value' => self::ADDRESS]);
    $user->method('getPassword')->willReturn('');
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('load')->willReturn($user);
    $role = $this->createMock(RoleInterface::class);
    $role->method('getPermissions')->willReturn(['export localnodes site']);
    $roles = $this->createMock(EntityStorageInterface::class);
    $roles->method('loadMultiple')->willReturn([$role]);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([['user', $users], ['user_role', $roles]]);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(7);
    $access = new ExportAccess($state, $entities);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn () => $this->now);
    $nonces = $this->createMock(SiweNonceStore::class);
    $nonces->method('currentBinding')->willReturn('original-session');
    $nonces->method('issue')->willReturnCallback(fn () => ['nonce' => str_repeat('a', 32), 'issued' => $this->now, 'expires' => $this->now + 300]);
    $validator = $this->createMock(SiweMessageValidator::class);
    $validator->expects($this->never())->method('validateMessage');
    $detector = $this->createMock(ContractWalletDetector::class);
    $detector->method('classify')->willReturn(ContractWalletDetector::EOA);
    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->willReturnCallback(fn ($key) => $this->pending[$key] ?? NULL);
    $store->method('set')->willReturnCallback(function ($key, $value) { $this->pending[$key] = $value; });
    $store->method('delete')->willReturnCallback(function ($key) { unset($this->pending[$key]); return TRUE; });
    $stores = $this->createMock(PrivateTempStoreFactory::class);
    $stores->method('get')->willReturn($store);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(TRUE);
    $config = $this->getConfigFactoryStub(['system.site' => ['uuid' => 'site-a', 'name' => 'Our House'], 'siwe_login.settings' => ['expected_domain' => 'site.test', 'expected_uri' => 'https://site.test', 'chain_ids' => [1]]]);
    $history = new class($this) {
      public function __construct(private ExportAttachmentWaitTest $test) {}
      public function attachedAt(UserInterface $account, string $address): ?int {
        return (int) $account->id() === 7 && strtolower($address) === ExportAttachmentWaitTest::ADDRESS ? $this->test->attachment() : NULL;
      }
    };
    return new ExportSignatureProof($access, $nonces, $validator, $detector, $stores, $lock, $time, $config, $history);
  }

  public function attachment(): ?int { return $this->attached; }

  public function testNewAttachmentCannotConfirmUntilExactly24Hours(): void {
    $this->attached = $this->now - 86399;
    $proof = $this->proof();
    $this->assertFalse($proof->available(7));
    $this->assertSame($this->attached + 86400, $proof->waitingUntil(7));
    $this->now++;
    $this->assertNull($proof->waitingUntil(7));
    $this->assertTrue($proof->available(7));
    $this->assertNotEmpty($proof->issue(7, 'request', '')['message']);
  }

  /** @dataProvider actions */
  public function testRecentAttachmentCannotObtainAnyExportChallenge(string $action, string $id): void {
    $this->attached = $this->now;
    $this->expectException(\RuntimeException::class);
    $this->proof()->issue(7, $action, $id);
  }
  public static function actions(): array { return [['request', ''], ['download', str_repeat('a', 32)], ['delete', str_repeat('b', 32)]]; }

  public function testWaitSurvivesRebuildingTheProofService(): void {
    $this->attached = $this->now - 30;
    $this->assertFalse($this->proof()->available(7));
    $this->assertFalse($this->proof()->available(7));
  }

  public function testConfirmationRechecksTheAttachmentDate(): void {
    $proof = $this->proof();
    $challenge = $proof->issue(7, 'request', '');
    $this->attached = $this->now;
    $this->assertFalse($proof->confirm(7, 'request', '', $challenge['message'], 'unused'));
  }

  public function testExistingAddressWithNoNewAttachmentRecordKeepsItsPath(): void {
    $proof = $this->proof();
    $this->assertTrue($proof->available(7));
    $this->assertNull($proof->waitingUntil(7));
  }
}
