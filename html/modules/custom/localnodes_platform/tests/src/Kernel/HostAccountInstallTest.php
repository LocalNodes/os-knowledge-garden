<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\social_user\Form\SocialUserPasswordForm;
use Drupal\user\Controller\UserController;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\Form\UserLoginForm;
use Drupal\user\Form\UserPasswordForm;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The first install account is reserved for deliberate server recovery.
 *
 * @group localnodes_platform
 * @group host_account_install
 */
final class HostAccountInstallTest extends KernelTestBase {

  use AssertMailTrait;

  protected static $modules = [
    'system', 'user', 'field', 'filter', 'role_delegation',
    'social_user', 'localnodes_platform',
  ];

  protected $strictConfigSchema = FALSE;

  private UserInterface $host;
  private Request $request;

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'filter']);
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->config('system.site')->set('name', 'Example community')->set('mail', 'noreply@example.org')->save();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager'])->save();
    // Deliberately renamed: protecting only the literal name admin is insufficient.
    $this->host = User::create([
      'uid' => 1, 'name' => 'bootstrap-owner', 'mail' => 'bootstrap@example.org',
      'status' => 1, 'pass' => 'fictional-random-install-password',
    ]);
    $this->host->save();
    $this->request = Request::create('http://localhost/user/password');
    $this->request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($this->request);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->container->get('router.builder')->rebuild();
  }

  private function finishInstall(): void {
    // Running the unchanged distribution is a behavioral red, not missing code.
    if (function_exists('_localnodes_platform_block_host_account')) {
      _localnodes_platform_block_host_account();
    }
    $this->host = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged(1);
  }

  public function testInstallBlocksUidOneWithoutChangingItsCredentials(): void {
    $before = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged(1)->toArray();
    $this->finishInstall();
    $this->assertTrue($this->host->isBlocked());
    $after = $this->host->toArray();
    unset($before['status'], $after['status']);
    $this->assertSame($before, $after);
    $this->finishInstall();
    $this->assertTrue($this->host->isBlocked(), 'Repeating install protection leaves the host blocked.');
  }

  public static function resetFormsAndIdentifiers(): array {
    return [
      'core by name' => [UserPasswordForm::class, 'bootstrap-owner'],
      'core by email' => [UserPasswordForm::class, 'bootstrap@example.org'],
      'Social by name' => [SocialUserPasswordForm::class, 'bootstrap-owner'],
      'Social by email' => [SocialUserPasswordForm::class, 'bootstrap@example.org'],
    ];
  }

  /** @dataProvider resetFormsAndIdentifiers */
  public function testBlockedHostCannotRequestResetMail(string $class, string $identifier): void {
    $this->finishInstall();
    $form = [];
    $state = (new FormState())->setValue('name', $identifier);
    $handler = $class::create($this->container);
    $handler->validateForm($form, $state);
    $handler->submitForm($form, $state);
    $this->assertCount(0, $this->getMails(), 'Neither reset form mails a blocked bootstrap account.');
  }

  public static function resetFormClasses(): array {
    return [[UserPasswordForm::class], [SocialUserPasswordForm::class]];
  }

  /** @dataProvider resetFormClasses */
  public function testOrdinaryMemberCanStillRequestResetMail(string $class): void {
    $this->finishInstall();
    $member = User::create(['name' => 'ordinary-member', 'mail' => 'ordinary@example.org', 'status' => 1]);
    $member->save();
    $form = [];
    $state = (new FormState())->setValue('name', $member->getEmail());
    $handler = $class::create($this->container);
    $handler->validateForm($form, $state);
    $handler->submitForm($form, $state);
    $this->assertCount(1, $this->getMails(['to' => $member->getEmail(), 'key' => 'password_reset']));
  }

  public function testPreviouslyGeneratedOneTimeLoginIsRejected(): void {
    $timestamp = $this->container->get('datetime.time')->getRequestTime();
    $hash = user_pass_rehash($this->host, $timestamp);
    $this->finishInstall();
    $this->assertTrue($this->host->isBlocked());
    $this->expectException(AccessDeniedHttpException::class);
    UserController::create($this->container)->resetPassLogin(1, $timestamp, $hash, $this->request);
  }

  public function testKnownInstallPasswordCannotPassTheRealLoginForm(): void {
    $this->finishInstall();
    $form = [];
    $state = (new FormState())->setValues([
      'name' => 'bootstrap-owner', 'pass' => 'fictional-random-install-password',
    ]);
    $state->setValidationEnforced();
    UserLoginForm::create($this->container)->validateAuthentication($form, $state);
    $this->assertTrue($state->hasAnyErrors());
    $this->assertEmpty($state->get('uid'));
  }

  public function testTrustedLaterInstallOperationsDoNotNeedBrowserSignIn(): void {
    $this->finishInstall();
    $this->assertTrue($this->host->isBlocked());
    // The actual demo importer uses this same explicit server-side account switch.
    $this->container->get('current_user')->setAccount($this->host);
    $this->assertTrue($this->host->hasPermission('administer users'));
    $member = User::create(['name' => 'later-provisioned-member', 'mail' => 'later@example.org', 'status' => 1, 'roles' => ['sitemanager']]);
    $member->save();
    $this->config('system.site')->set('slogan', 'Written by later setup')->save();
    $this->assertGreaterThan(1, (int) $member->id());
    $this->assertTrue(User::load($member->id())->isActive());
    $this->assertSame('Written by later setup', $this->config('system.site')->get('slogan'));
    $this->assertTrue(User::load(1)->isBlocked());
  }

}
