<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel\Export;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Form\SiteExportForm;
use Drupal\Tests\siwe_login\Traits\SiweTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

/** @group localnodes_platform */
final class ExportSignatureConfirmationTest extends KernelTestBase {
  use SiweTestTrait;
  protected static $modules = ['system', 'user', 'field', 'file', 'siwe_login', 'localnodes_platform', 'localnodes_web3'];
  protected $strictConfigSchema = FALSE;
  private User $owner;
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installSchema('localnodes_platform', ['localnodes_site_export']);
    $this->installConfig(['system', 'siwe_login']);
    $this->config('system.site')->set('uuid', 'proof-site')->set('name', 'Our House')->save();
    $this->config('siwe_login.settings')->set('expected_domain', 'site.test')->set('expected_uri', 'https://site.test')->set('enable_ens_validation', FALSE)->save();
    FieldStorageConfig::create(['field_name' => 'field_ethereum_address', 'entity_type' => 'user', 'type' => 'string', 'settings' => ['max_length' => 42]])->save();
    FieldConfig::create(['field_name' => 'field_ethereum_address', 'entity_type' => 'user', 'bundle' => 'user', 'label' => 'Address'])->save();
    Role::create(['id' => 'sitemanager', 'label' => 'Manager', 'permissions' => ['export localnodes site']])->save();
    User::create(['name' => 'Root', 'status' => 1])->save();
    $this->owner = User::create(['name' => 'Owner', 'status' => 1, 'roles' => ['sitemanager'], 'field_ethereum_address' => strtolower($this->testAddress())]);
    $this->owner->save();
    $this->container->get('state')->set('localnodes.organizer_uid', (int) $this->owner->id());
    $this->container->get('current_user')->setAccount($this->owner);
    $request = Request::create('https://site.test/admin/config/system/site-export');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    // Every unexpected RPC fails closed; no real network request.
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create(new MockHandler())]));
  }

  /** @dataProvider actions */
  public function testRealSignaturesForEachAction(string $action, string $id): void {
    $proof = $this->container->get('localnodes_web3.export_proof');
    $challenge = $proof->issue((int) $this->owner->id(), $action, $id);
    $identity = $this->container->get('localnodes_platform.export_identity');
    $before = $this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(FALSE)->count()->execute();
    $args = [(int) $this->owner->id(), '', TRUE, $action, $id, $challenge['message'], $this->signMessage($challenge['message'])];
    $this->assertTrue($identity->confirm(...$args));
    $this->assertFalse($identity->confirm(...$args));
    $this->assertSame((int) $this->owner->id(), (int) $this->container->get('current_user')->id());
    $this->assertSame($before, $this->container->get('entity_type.manager')->getStorage('user')->getQuery()->accessCheck(FALSE)->count()->execute());
  }
  public static function actions(): array { return [['request', ''], ['download', str_repeat('a', 32)], ['delete', str_repeat('b', 32)]]; }

  public function testSessionAloneAndFiveFailuresUseTheSameFormSentence(): void {
    $uid = (int) $this->owner->id();
    $form = SiteExportForm::create($this->container);
    $page = []; $state = new FormState();
    $state->set('export_operation', 'request')->set('export_id', '');
    $state->setValues(['acknowledged' => TRUE])->setUserInput(['acknowledged' => 1]);
    try {
      $form->validateForm($page, $state);
      $this->assertSame('We could not confirm it is you. Try again or ask for export help.', (string) array_values($state->getErrors())[0]);
    }
    finally { $state->clearErrors(); }
    $proof = $this->container->get('localnodes_web3.export_proof');
    $identity = $this->container->get('localnodes_platform.export_identity');
    $this->container->get('flood')->clear('localnodes_export_confirm', (string) $uid);
    for ($i = 0; $i < 5; $i++) {
      $c = $proof->issue($uid, 'request', '');
      $this->assertFalse($identity->confirm($uid, '', TRUE, 'request', '', $c['message'], '0xdead'));
    }
    $c = $proof->issue($uid, 'request', '');
    $this->assertFalse($identity->confirm($uid, '', TRUE, 'request', '', $c['message'], $this->signMessage($c['message'])));
    $this->assertFalse($this->container->get('flood')->isAllowed('localnodes_export_confirm', 5, 3600, (string) $uid));
  }

  public function testSignaturePanelValidatesAndErasesBothProofFields(): void {
    $uid = (int) $this->owner->id();
    $real = $this->container->get('localnodes_web3.export_proof');
    // Stub only browser-asset attachment; account and proof services are real.
    $signer = new class($real) implements \Drupal\localnodes_platform\Export\ExportSignatureProofInterface {
      public function __construct(private $real) {}
      public function waitingUntil(int $uid): ?int { return $this->real->waitingUntil($uid); }
      public function available(int $uid): bool { return $this->real->available($uid); }
      public function attach(array &$form): bool { return TRUE; }
      public function issue(int $uid, string $action, string $id): array { return $this->real->issue($uid, $action, $id); }
      public function confirm(int $uid, string $action, string $id, string $message, string $signature): bool { return $this->real->confirm($uid, $action, $id, $message, $signature); }
    };
    $identity = new \Drupal\localnodes_platform\Export\ExportIdentity($this->container->get('localnodes_platform.export_access'), $this->container->get('password'), $this->container->get('flood'), $signer);
    $form = new SiteExportForm($this->container->get('localnodes_platform.export_manager'), $this->container->get('localnodes_platform.export_access'), $identity);
    $state = new FormState(); $page = $form->buildForm([], $state, 'request');
    $this->assertTrue($page['acknowledged']['#required']);
    $this->assertArrayNotHasKey('password', $page);
    $this->assertArrayHasKey('actions', $page);
    $c = $signer->issue($uid, 'request', '');
    $values = ['acknowledged' => TRUE, 'proof_message' => $c['message'], 'proof_signature' => $this->signMessage($c['message'])];
    $state->setValues($values)->setUserInput($values);
    $page['proof_message']['#value'] = $values['proof_message'];
    $page['proof_signature']['#value'] = $values['proof_signature'];
    $form->validateForm($page, $state);
    $this->assertFalse($state->hasAnyErrors());
    foreach (['proof_message', 'proof_signature'] as $key) {
      $this->assertSame('', $state->getValue($key));
      $this->assertArrayNotHasKey($key, $state->getUserInput());
      $this->assertSame('', $page[$key]['#value']);
    }
    $this->assertNull($state->getResponse(), 'Validation alone never serves bytes.');
  }

  public function testChallengeRouteAndControllerArePrivateAndRefuseStaleIds(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_web3.export_challenge');
    $this->assertSame(['POST'], $route->getMethods());
    $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'));
    $this->assertTrue($route->getOption('no_cache'));
    $controller = \Drupal\localnodes_web3\Controller\ExportChallengeController::create($this->container);
    $request = Request::create('/challenge', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'request', 'exportId' => '']));
    $response = $controller->challenge($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    $this->assertArrayHasKey('message', json_decode($response->getContent(), TRUE));
    $request = Request::create('/challenge', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'download', 'exportId' => str_repeat('a', 32)]));
    $response = $controller->challenge($request);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(['error' => 'We could not confirm it is you. Try again or ask for export help.'], json_decode($response->getContent(), TRUE));
  }

  public function testNoPasswordOrAddressStillShowsHelpAndPasswordWins(): void {
    $this->owner->set('field_ethereum_address', '')->save();
    $page = SiteExportForm::create($this->container)->buildForm([], new FormState(), 'request');
    $this->assertArrayHasKey('help', $page);
    $this->assertArrayNotHasKey('actions', $page);
    $this->owner->set('field_ethereum_address', strtolower($this->testAddress()))->setPassword('current password')->save();
    $page = SiteExportForm::create($this->container)->buildForm([], new FormState(), 'request');
    $this->assertSame('password', $page['password']['#type']);
    $this->assertArrayNotHasKey('proof_signature', $page);
  }

  public function testConfirmationNeverLogsVerifierOrRpcDiagnostics(): void {
    $records = [];
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger->method('log')->willReturnCallback(function ($level, $message, array $context) use (&$records): void {
      $records[] = ['message' => (string) $message, 'context' => $context];
    });
    $this->container->get('logger.factory')->addLogger($logger);
    $proof = $this->container->get('localnodes_web3.export_proof');
    // The empty RPC queue triggers real detector exceptions, all suppressed
    // by the export-only factory rather than a factory-wide temporary flag.
    $this->assertTrue($proof->available((int) $this->owner->id()));
    $c = $proof->issue((int) $this->owner->id(), 'request', '');
    $this->assertFalse($proof->confirm((int) $this->owner->id(), 'request', '', $c['message'], 'PRIVATE exception or signature'));
    $diagnostics = array_filter($records, fn (array $record) => in_array($record['context']['channel'] ?? '', ['siwe_login', 'export_confirmation'], TRUE)
      || str_contains($record['message'], 'Contract-wallet check failed'));
    $this->assertSame([], $diagnostics, 'No export verifier or RPC diagnostic reaches the shared logger.');
    $this->assertStringNotContainsString('PRIVATE exception or signature', serialize($records));
  }

  public function testAcknowledgementAccountChangesAndRevocationRefuse(): void {
    $uid = (int) $this->owner->id(); $proof = $this->container->get('localnodes_web3.export_proof');
    $identity = $this->container->get('localnodes_platform.export_identity');
    $c = $proof->issue($uid, 'request', '');
    $this->assertFalse($identity->confirm($uid, '', FALSE, 'request', '', $c['message'], $this->signMessage($c['message'])));
    $this->owner->set('field_ethereum_address', '0x' . str_repeat('1', 40))->save();
    $this->assertFalse($identity->confirm($uid, '', TRUE, 'request', '', $c['message'], $this->signMessage($c['message'])));
    $this->owner->set('field_ethereum_address', strtolower($this->testAddress()))->save();
    $c = $proof->issue($uid, 'request', '');
    $this->owner->setPassword('new password')->save();
    $this->assertFalse($identity->confirm($uid, '', TRUE, 'request', '', $c['message'], $this->signMessage($c['message'])));
    $this->assertTrue($identity->confirm($uid, 'new password', TRUE));
    $this->owner->block()->save();
    $this->assertFalse($identity->confirm($uid, 'new password', TRUE));
  }

  public function testRecentAttachmentWaitIsVisibleAndSurvivesANewSession(): void {
    $uid = (int) $this->owner->id();
    $now = time();
    $data = $this->container->get('user.data');
    $data->set('siwe_login', $uid, 'attachment', ['address' => strtolower($this->testAddress()), 'at' => $now]);
    // Exercise the public history API against persistent user data. The
    // installed sign-in version may predate that API on the test host.
    $history = new class($data) {
      public function __construct(private $data) {}
      public function attachedAt(\Drupal\user\UserInterface $account, string $address): ?int {
        $record = $this->data->get('siwe_login', $account->id(), 'attachment');
        return ($record['address'] ?? NULL) === strtolower($address) ? $record['at'] : NULL;
      }
    };
    $clock = new class($now) implements \Drupal\Component\Datetime\TimeInterface {
      public function __construct(public int $now) {}
      public function getCurrentTime() { return $this->now; }
      public function getRequestTime() { return $this->now; }
      public function getCurrentMicroTime() { return (float) $this->now; }
      public function getRequestMicroTime() { return (float) $this->now; }
    };
    $proof = new \Drupal\localnodes_web3\Export\ExportSignatureProof(
      $this->container->get('localnodes_platform.export_access'), $this->container->get('siwe_login.nonce_store'),
      $this->container->get('localnodes_web3.export_validator'), $this->container->get('localnodes_web3.export_detector'),
      $this->container->get('localnodes_web3.export_tempstore'), $this->container->get('lock'), $clock,
      $this->container->get('config.factory'), $history,
    );
    $identity = new \Drupal\localnodes_platform\Export\ExportIdentity($this->container->get('localnodes_platform.export_access'), $this->container->get('password'), $this->container->get('flood'), $proof);
    $form = new SiteExportForm($this->container->get('localnodes_platform.export_manager'), $this->container->get('localnodes_platform.export_access'), $identity);
    $sentence = 'A sign-in added to this account must wait 24 hours before it can confirm an export.';
    $state = new FormState();
    $page = $form->buildForm([], $state);
    $this->assertSame($sentence, (string) $page['screen']['#actions']['unavailable']['#plain_text']);
    $this->assertArrayNotHasKey('request', $page['screen']['#actions']);
    $panel = $form->buildForm([], $state, 'request');
    $this->assertStringContainsString($sentence, (string) $panel['heading']['#markup']);
    $this->assertArrayNotHasKey('actions', $panel);
    $request = Request::create('https://site.test/admin/config/system/site-export');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->assertSame($now + 86400, $proof->waitingUntil($uid));
    $this->assertFalse($proof->available($uid));
    $clock->now += 86400;
    $this->assertNull($proof->waitingUntil($uid));
    $this->assertTrue($proof->available($uid));
  }

}
