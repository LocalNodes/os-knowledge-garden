<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\localnodes_platform\Export\ExportAccess;
use Drupal\localnodes_web3\Export\ExportSignatureProof;
use Drupal\localnodes_web3\Export\SilentLoggerFactory;
use Drupal\siwe_login\Service\SiweMessageValidator;
use Drupal\siwe_login\Service\SiweNonceStore;
use Drupal\siwe_login\Siwe\VerifiedSiweLogin;
use Drupal\siwe_login\Service\EnsResolver;
use Drupal\siwe_login\Service\ContractWalletDetector;
use Drupal\siwe_login\Siwe\SignatureVerifier;
use Drupal\siwe_login\Siwe\SiweMessageParser;
use Drupal\Tests\UnitTestCase;
use Elliptic\EC;
use kornrunner\Keccak;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** @group localnodes_platform */
final class ExportSignatureProofTest extends UnitTestCase {
  private const ADDRESS = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';
  private const KEY = 'ac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80';
  private int $now = 1800000000;
  private string $address = self::ADDRESS;
  private bool $eligible = TRUE;
  private bool $busy = FALSE;
  private string $siteId = 'site-a';
  private string $siteName = 'Our House';
  private string $classification = ContractWalletDetector::EOA;
  private ?int $waiting = NULL;
  /** Runs once, inside the signature check, after the signature verified. */
  private ?\Closure $during = NULL;
  private ExportSignatureProof $proof;
  private RequestStack $requests;
  private $config;

  protected function setUp(): void {
    parent::setUp();
    $this->config = $this->getConfigFactoryStub(['system.site' => ['uuid' => 'site-a', 'name' => 'Our House'], 'siwe_login.settings' => ['expected_domain' => 'site.test', 'expected_uri' => 'https://site.test', 'nonce_ttl' => 300, 'message_ttl' => 600, 'chain_ids' => [1]]]);
    $initialConfig = $this->config;
    $site = $this->createMock(\Drupal\Core\Config\ImmutableConfig::class);
    $site->method('get')->willReturnCallback(fn ($key) => $key === 'uuid' ? $this->siteId : $this->siteName);
    $this->config = $this->createMock(\Drupal\Core\Config\ConfigFactoryInterface::class);
    $this->config->method('get')->willReturnCallback(fn ($name) => $name === 'system.site' ? $site : $initialConfig->get($name));
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturnCallback(fn () => $this->now);
    $time->method('getCurrentMicroTime')->willReturnCallback(fn () => (float) $this->now);
    $this->requests = new RequestStack();
    $this->session();
    $stores = $this->createMock(PrivateTempStoreFactory::class);
    $collections = [];
    $stores->method('get')->willReturnCallback(function ($name) use (&$collections) {
      return $collections[$name] ??= new class extends PrivateTempStore {
        private array $data = [];
        public function __construct() {}
        public function get($key) { return $this->data[$key] ?? NULL; }
        public function set($key, $value) { $this->data[$key] = $value; }
        public function delete($key) { unset($this->data[$key]); return TRUE; }
      };
    });
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(fn () => !$this->busy);
    $nonces = new SiweNonceStore($stores, $lock, $time, $this->config, $this->requests);
    $detector = $this->createMock(ContractWalletDetector::class);
    $detector->method('classify')->willReturnCallback(fn () => $this->classification);
    $validator = new class(new SilentLoggerFactory(), $time, $this->config, $this->createMock(EnsResolver::class), new SiweMessageParser(), $nonces, new SignatureVerifier(), $detector) extends SiweMessageValidator {
      public ?\Closure $after = NULL;
      public function validateMessage(string $message, string $signature, string $purpose = SiweNonceStore::PURPOSE_LOGIN, ?int $uid = NULL): VerifiedSiweLogin {
        $verified = parent::validateMessage($message, $signature, $purpose, $uid);
        if ($this->after !== NULL) ($this->after)();
        return $verified;
      }
    };
    $validator->after = function () {
      if ($this->during !== NULL) {
        $during = $this->during;
        $this->during = NULL;
        $during();
      }
    };
    $access = new ExportAccess($this->createMock(\Drupal\Core\State\StateInterface::class), $this->createMock(\Drupal\Core\Entity\EntityTypeManagerInterface::class));
    $this->proof = new class($access, $nonces, $validator, $detector, $stores, $lock, $time, $this->config, $this) extends ExportSignatureProof {
      public function __construct($a, $n, $v, $d, $s, $l, $t, $c, private ExportSignatureProofTest $test) { parent::__construct($a, $n, $v, $d, $s, $l, $t, $c); }
      protected function address(int $uid): string { return $this->test->recorded($uid); }
      public function waitingUntil(int $uid): ?int { return $this->test->waiting(); }
    };
  }

  public function waiting(): ?int { return $this->waiting; }
  public function recorded(int $uid): string { return $this->eligible && $uid === 7 ? $this->address : ''; }
  private function session(): void {
    $request = Request::create('https://site.test/settings/export');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->requests->push($request);
  }
  private function sign(string $text): string {
    $hash = Keccak::hash("\x19Ethereum Signed Message:\n" . strlen($text) . $text, 256);
    $s = (new EC('secp256k1'))->sign($hash, self::KEY, ['canonical' => TRUE]);
    return '0x' . str_pad($s->r->toString(16), 64, '0', STR_PAD_LEFT) . str_pad($s->s->toString(16), 64, '0', STR_PAD_LEFT) . dechex(27 + $s->recoveryParam);
  }

  /** @dataProvider actions */
  public function testEachActionHasAnExactSingleUseProof(string $action, string $id): void {
    $challenge = $this->proof->issue(7, $action, $id);
    $text = $challenge['message'];
    $this->assertStringContainsString('Our House', $text);
    $this->assertStringContainsString('site.test', $text);
    $this->assertStringContainsString($action, $text);
    $this->assertSame(self::ADDRESS, $challenge['expectedAddress']);
    $this->assertTrue($this->proof->confirm(7, $action, $id, $text, $this->sign($text)));
    $this->assertFalse($this->proof->confirm(7, $action, $id, $text, $this->sign($text)));
  }
  public static function actions(): array { return [['request', ''], ['download', str_repeat('a', 32)], ['delete', str_repeat('b', 32)]]; }

  /** @dataProvider mismatches */
  public function testMismatchConsumesTheAttempt(string $kind): void {
    $id = str_repeat('a', 32); $action = 'download'; $uid = 7;
    $c = $this->proof->issue($uid, $action, $id); $text = $c['message']; $signature = $this->sign($text);
    switch ($kind) {
      case 'action': $action = 'delete'; break;
      case 'id': $id = str_repeat('b', 32); break;
      case 'uid': $uid = 8; break;
      case 'text': $text .= '\nchanged'; break;
      case 'signature': $signature = '0xdead'; break;
      case 'expiry': $this->now += 301; break;
      case 'address': $this->address = '0x' . str_repeat('1', 40); break;
      case 'revoked': $this->eligible = FALSE; break;
      case 'session': $this->session(); break;
      case 'site': $this->siteId = 'site-b'; break;
    }
    $this->assertFalse($this->proof->confirm($uid, $action, $id, $text, $signature));
    if ($kind !== 'session') {
      $this->address = self::ADDRESS; $this->eligible = TRUE;
      $this->assertFalse($this->proof->confirm(7, 'download', str_repeat('a', 32), $c['message'], $this->sign($c['message'])));
    }
  }
  public static function mismatches(): array { return array_map(fn ($k) => [$k], ['action', 'id', 'uid', 'text', 'signature', 'expiry', 'address', 'revoked', 'session', 'site']); }
  public function testNewChallengeInvalidatesOldOneAndBusyLockRefuses(): void {
    $a = $this->proof->issue(7, 'request', '');
    $b = $this->proof->issue(7, 'request', '');
    $this->assertNotSame($a['message'], $b['message']);
    $this->busy = TRUE;
    $this->assertFalse($this->proof->confirm(7, 'request', '', $b['message'], $this->sign($b['message'])));
    $this->busy = FALSE;
    $this->assertFalse($this->proof->confirm(7, 'request', '', $a['message'], $this->sign($a['message'])));
  }
  public function testKnownContractUsesHelpWhileUnknownClassificationCanStillVerify(): void {
    $this->classification = ContractWalletDetector::CONTRACT;
    $this->assertFalse($this->proof->available(7));
    $this->classification = ContractWalletDetector::UNKNOWN;
    $this->assertTrue($this->proof->available(7));
    $c = $this->proof->issue(7, 'request', '');
    $this->assertTrue($this->proof->confirm(7, 'request', '', $c['message'], $this->sign($c['message'])));
  }
  /** @dataProvider unsignableNames */
  public function testUnsignableSiteNameUsesTheSignInFallback(string $name): void {
    $this->siteName = $name;
    $c = $this->proof->issue(7, 'request', '');
    $this->assertTrue($this->proof->confirm(7, 'request', '', $c['message'], $this->sign($c['message'])));
    $this->assertStringNotContainsString($name, $c['message']);
    $this->assertStringContainsString('site.test', $c['message']);
  }
  public static function unsignableNames(): array { return [['Our <House>'], ['Our "House"'], ['Our House — Co-op'], ["Our House\nSecond line"]]; }
  public function testSessionAloneNeverConfirms(): void {
    $this->assertFalse($this->proof->confirm(7, 'request', '', '', ''));
  }

  /**
   * A change to the account while the signature is checked refuses it.
   *
   * @dataProvider changesDuringTheCheck
   */
  public function testChangeDuringTheSignatureCheckRefuses(string $kind): void {
    $c = $this->proof->issue(7, 'request', '');
    $this->during = match ($kind) {
      'wallet' => function () { $this->address = '0x' . str_repeat('2', 40); },
      // address() reads '' once the account has a password.
      'password' => function () { $this->eligible = FALSE; },
      'attached' => function () { $this->waiting = $this->now + 86400; },
    };
    $this->assertFalse($this->proof->confirm(7, 'request', '', $c['message'], $this->sign($c['message'])));
    $this->assertNull($this->during, 'The change ran inside the signature check.');
  }
  public static function changesDuringTheCheck(): array { return [['wallet'], ['password'], ['attached']]; }
}
