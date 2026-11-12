<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_web3\SignIn;
use Drupal\user\Entity\User;

/**
 * Signed-out visitors meet the sign-in button, not "Sign up" and "Login".
 *
 * With the sign-in on (siwe_login_waap):
 * - the log-in page (core's user_login_form, or Open Social's
 *   social_user_login_form) and the sign-up page lead with a "Sign in"
 *   button, the help link and "Or use a password", above the password form,
 *   which stays as it is;
 * - the front-page welcome block shows "Sign in" and a quiet "Use a
 *   password" link in place of its stored "Sign up" and "Login" buttons.
 * Without it, every form and the welcome block are left as they are.
 *
 * localnodes_web3, localnodes_platform and siwe_login_waap are added with
 * enableModules(), as in SafetyHelpLinksTest: their .info.yml dependencies
 * pull in Open Social, which a Kernel test can't boot.
 *
 * @group localnodes_web3
 */
final class SignInEntryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'text',
    'filter',
    'block',
    'block_content',
    'siwe_login',
  ];

  /**
   * The help link's words.
   */
  private const HELP = 'Hacked or phished? Get help';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'filter']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->installSchema('user', ['users_data']);

    // The welcome block's shape in social_core: a link field with the two
    // stored buttons.
    BlockContentType::create(['id' => 'hero_call_to_action_block', 'label' => 'Hero call to action block'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_call_to_action_link',
      'entity_type' => 'block_content',
      'type' => 'link',
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_call_to_action_link',
      'entity_type' => 'block_content',
      'bundle' => 'hero_call_to_action_block',
      'label' => 'Call to action',
    ])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'hero_call_to_action_block',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_call_to_action_link', ['type' => 'link', 'label' => 'hidden'])->save();
    BlockContent::create([
      'type' => 'hero_call_to_action_block',
      'info' => 'Welcome',
      'field_call_to_action_link' => [
        ['uri' => 'internal:/user/register', 'title' => 'Sign up'],
        ['uri' => 'internal:/user/login', 'title' => 'Login'],
      ],
    ])->save();
  }

  /**
   * The log-in page leads with the button; the password form is untouched.
   */
  public function testLoginFormLeadsWithTheButton(): void {
    $this->signInOn();
    $form = $this->container->get('form_builder')->getForm('Drupal\user\Form\UserLoginForm');
    $this->assertArrayHasKey('ln_signin', $form);
    $this->assertArrayHasKey('name', $form, 'The username field stays.');
    $this->assertArrayHasKey('pass', $form, 'The password field stays.');
    $this->assertTrue($form['actions']['submit']['#access'] ?? TRUE, 'The Log in button stays.');

    $xpath = $this->dom($this->container->get('renderer')->renderInIsolation($form));
    $wrap = $xpath->query('//*[@data-ln-signin-wrap]');
    $this->assertSame(1, $wrap->length);
    $this->assertTrue($wrap->item(0)->hasAttribute('hidden'), 'Hidden until the script finds the top-bar sign-in.');
    $button = $xpath->query('//*[@data-ln-signin-wrap]//button[@data-ln-signin]');
    $this->assertSame(1, $button->length);
    $this->assertSame('Sign in', trim($button->item(0)->textContent));
    $this->assertSame('button', $button->item(0)->getAttribute('type'), 'It never submits the password form.');
    $this->assertSame(1, $xpath->query('//*[@data-ln-signin-wrap]//a[normalize-space(.)="' . self::HELP . '"]')->length);
    $this->assertSame(1, $xpath->query('//*[@data-ln-signin-wrap]//p[normalize-space(.)="Or use a password"]')->length);
    // The block comes first: before the username field.
    $this->assertSame(1, $xpath->query('//*[@data-ln-signin-wrap]/following::input[@name="name"]')->length);
    $this->assertSame(0, $xpath->query('//*[@id="siwe-login-waap"]')->length, 'No second sign-in mount on the page body.');
  }

  /**
   * Open Social's log-in form and the sign-up form get it too.
   */
  public function testOpenSocialLoginAndSignUpForms(): void {
    $this->signInOn();
    $this->assertSame(['user_login_form', 'social_user_login_form', 'user_register_form'], SignIn::FORM_IDS);
    foreach (['social_user_login_form', 'user_register_form'] as $id) {
      $form = ['pass' => ['#type' => 'password']];
      localnodes_web3_form_alter($form, new FormState(), $id);
      $this->assertArrayHasKey('ln_signin', $form, $id);
      $this->assertArrayHasKey('pass', $form, $id);
    }
    $form = [];
    localnodes_web3_form_alter($form, new FormState(), 'user_pass');
    $this->assertSame([], $form, 'Other forms are left alone.');
  }

  /**
   * An administrator adding an account gets no button on the sign-up form.
   */
  public function testAdminAddingAnAccountGetsNoButton(): void {
    $this->signInOn();
    $admin = User::create(['name' => 'admin', 'status' => 1]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);
    $form = [];
    localnodes_web3_form_alter($form, new FormState(), 'user_register_form');
    $this->assertSame([], $form);
  }

  /**
   * Without the sign-in, the forms are left as they are.
   */
  public function testNoSignInNoButton(): void {
    $this->enableModules(['localnodes_platform', 'localnodes_web3']);
    $this->container->get('router.builder')->rebuild();
    $form = $this->container->get('form_builder')->getForm('Drupal\user\Form\UserLoginForm');
    $this->assertArrayNotHasKey('ln_signin', $form);
    $this->assertStringContainsString('Sign up', $this->renderWelcome());
    $this->assertStringContainsString('/user/register', $this->renderWelcome());
  }

  /**
   * The welcome block: "Sign in" and "Use a password", no "Sign up".
   */
  public function testWelcomeBlockShowsSignIn(): void {
    $this->signInOn();
    $xpath = $this->dom($this->renderWelcome());

    $links = $xpath->query('//*[contains(@class, "hero-cta-wrapper")]//a');
    $this->assertSame(2, $links->length);
    $this->assertSame('Sign in', trim($links->item(0)->textContent));
    $this->assertTrue($links->item(0)->hasAttribute('data-ln-signin'), 'Sign in opens the top-bar sign-in.');
    $this->assertStringEndsWith('/user/login', $links->item(0)->getAttribute('href'), 'Without the script it still leads to the log-in page.');
    $this->assertStringContainsString('btn-primary', $links->item(0)->getAttribute('class'));

    $this->assertSame('Use a password', trim($links->item(1)->textContent));
    $this->assertFalse($links->item(1)->hasAttribute('data-ln-signin'), 'The password link is a plain link.');
    $this->assertStringEndsWith('/user/login', $links->item(1)->getAttribute('href'));
    $this->assertStringNotContainsString('btn', $links->item(1)->getAttribute('class'), 'A quiet text link, not a button.');

    $this->assertSame(0, $xpath->query('//a[contains(@href, "/user/register")]')->length, 'No Sign up.');
    // The stored links are kept, for when the sign-in is off.
    $stored = BlockContent::load(1)->get('field_call_to_action_link')->getValue();
    $this->assertSame('internal:/user/register', $stored[0]['uri']);
  }

  /**
   * Turns the sign-in on, in the order a site lists the modules.
   */
  private function signInOn(): void {
    $this->enableModules(['localnodes_platform', 'localnodes_web3', 'siwe_login_waap']);
    $this->installConfig(['siwe_login_waap']);
    $this->container->get('router.builder')->rebuild();
    $this->assertTrue(SignIn::available());
  }

  /**
   * Renders the welcome block the way its block plugin does.
   */
  private function renderWelcome(): string {
    $build = $this->container->get('entity_type.manager')->getViewBuilder('block_content')->view(BlockContent::load(1));
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * An XPath over an HTML fragment.
   */
  private function dom(string|\Stringable $html): \DOMXPath {
    $doc = new \DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
    return new \DOMXPath($doc);
  }

}
