<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_web3\Controller\EmailLinkController;
use Drupal\localnodes_web3\EmailLink;
use Drupal\localnodes_web3\Form\EmailLinkRequestForm;
use Drupal\localnodes_web3\Form\EmailLinkSignInForm;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The emailed sign-in link ships switched off.
 *
 * Off: /user/email-link, /user/email-link/sign-in and
 * /user/email-link/{uid}/{timestamp}/{hash} answer "not found", for a
 * signed-in visitor too. On: they answer as before. Whether the sign-in page
 * offers the link is in SignInEntryTest; how the link itself works is in
 * EmailLinkTest.
 *
 * @group localnodes_web3
 */
final class EmailLinkSwitchTest extends KernelTestBase {

  use SchemaCheckTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'siwe_login'];

  /**
   * The three pages, with a link that is not valid for anyone.
   */
  private const PATHS = [
    '/user/email-link',
    '/user/email-link/sign-in',
    '/user/email-link/2/1700000000/not-a-hash',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'siwe_login']);
    FieldStorageConfig::create([
      'field_name' => 'field_ethereum_address',
      'entity_type' => 'user',
      'type' => 'string',
      'settings' => ['max_length' => 42, 'case_sensitive' => FALSE, 'is_ascii' => TRUE],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ethereum_address',
      'entity_type' => 'user',
      'bundle' => 'user',
    ])->save();
    $this->enableModules(['localnodes_platform', 'localnodes_web3']);
    $this->container->get('router.builder')->rebuild();
    User::create(['name' => 'admin', 'mail' => 'admin@example.com', 'status' => 1])->save();
    User::create(['name' => 'ana', 'mail' => 'ana@example.com', 'status' => 1])->save();
  }

  /**
   * The installed setting is off, and schema-valid.
   */
  public function testInstalledOff(): void {
    $path = $this->container->get('extension.list.module')->getPath('localnodes_web3');
    $installed = Yaml::decode((string) file_get_contents($path . '/config/install/' . EmailLink::SETTINGS . '.yml'));
    $this->assertSame(['email_link' => FALSE], $installed);
    $this->assertConfigSchema($this->container->get('config.typed'), EmailLink::SETTINGS, $installed);

    $this->config(EmailLink::SETTINGS)->setData($installed)->save();
    $this->assertFalse(EmailLink::switchedOn());
    // A site with no setting at all is off too.
    $this->config(EmailLink::SETTINGS)->delete();
    $this->assertFalse(EmailLink::switchedOn());
  }

  /**
   * Off, the three pages are not found.
   */
  public function testOffPagesAreNotFound(): void {
    $this->switchLink(FALSE);
    foreach (self::PATHS as $path) {
      foreach (['GET', 'POST'] as $method) {
        $response = $this->container->get('http_kernel')->handle(Request::create($path, $method));
        $this->assertSame(404, $response->getStatusCode(), "$method $path");
      }
    }
  }

  /**
   * Off, a signed-in visitor gets "not found" too, not "access denied".
   */
  public function testOffSignedInIsNotFound(): void {
    $this->switchLink(FALSE);
    $this->setCurrentUser(User::load(2));
    foreach (self::PATHS as $path) {
      $response = $this->container->get('http_kernel')->handle(Request::create($path));
      $this->assertSame(404, $response->getStatusCode(), $path);
    }
  }

  /**
   * The switch is checked before routing.
   *
   * Two of the pages are for signed-out visitors only, and routing checks
   * that. The switch is checked before routing, so it answers first.
   */
  public function testOffAnswersBeforeRouting(): void {
    $listeners = $this->container->get('event_dispatcher')->getListeners(KernelEvents::REQUEST);
    $priority = fn (string $class) => current(array_filter(array_map(
      fn ($listener) => is_array($listener) && $listener[0] instanceof $class
        ? $this->container->get('event_dispatcher')->getListenerPriority(KernelEvents::REQUEST, $listener)
        : NULL,
      $listeners,
    ), 'is_int'));
    $switch = $priority('Drupal\localnodes_web3\EventSubscriber\EmailLinkSwitch');
    $router = $priority('Symfony\Component\HttpKernel\EventListener\RouterListener');
    $this->assertIsInt($switch);
    $this->assertIsInt($router);
    $this->assertGreaterThan($router, $switch);
  }

  /**
   * Off, the pages refuse on their own too, however they are reached.
   */
  public function testOffPagesRefuseThemselves(): void {
    $this->switchLink(FALSE);
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $refusals = 0;
    foreach ([EmailLinkRequestForm::class, EmailLinkSignInForm::class] as $form) {
      try {
        $form_state = new FormState();
        $this->container->get('form_builder')->buildForm($form, $form_state);
      }
      catch (NotFoundHttpException) {
        $refusals++;
      }
    }
    try {
      EmailLinkController::create($this->container)->open($request, 2, 1700000000, 'not-a-hash');
    }
    catch (NotFoundHttpException) {
      $refusals++;
    }
    $this->assertSame(3, $refusals);
  }

  /**
   * On, the pages answer as before.
   */
  public function testOnPagesAnswer(): void {
    $this->switchLink(TRUE);
    $kernel = $this->container->get('http_kernel');

    $response = $kernel->handle(Request::create('/user/email-link'));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('Your email address', (string) $response->getContent());

    // No link in the session: back to the request page.
    $response = $kernel->handle(Request::create('/user/email-link/sign-in'));
    $this->assertSame(302, $response->getStatusCode());
    $this->assertStringEndsWith('/user/email-link', $response->headers->get('Location'));

    // A link that is not valid: back to the request page.
    $response = $kernel->handle(Request::create('/user/email-link/2/1700000000/not-a-hash'));
    $this->assertSame(302, $response->getStatusCode());
    $this->assertStringEndsWith('/user/email-link', $response->headers->get('Location'));
  }

  /**
   * Switches the emailed link on or off.
   */
  private function switchLink(bool $on): void {
    $this->config(EmailLink::SETTINGS)->set('email_link', $on)->save();
  }

}
