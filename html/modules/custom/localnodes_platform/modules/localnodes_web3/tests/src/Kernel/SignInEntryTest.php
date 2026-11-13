<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\block\Entity\Block;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_web3\EmailLink;
use Drupal\localnodes_web3\SignIn;
use Drupal\user\Entity\User;

/**
 * Signed-out visitors meet the sign-in button, not "Sign up" and "Login".
 *
 * With the sign-in on (siwe_login_waap):
 * - the log-in page (core's user_login_form, or Open Social's
 *   social_user_login_form) and the sign-up page lead with a "Sign in"
 *   button, the help link, "Email me a sign-in link" and "Or use a
 *   password", above the password form, which stays as it is;
 * - the front-page welcome block shows "Sign in" and quiet "Use a password"
 *   and "Email me a sign-in link" links in place of its stored "Sign up" and
 *   "Login" buttons.
 * Without it, every form and the welcome block are left as they are.
 *
 * With siwe_login_waap's show_siwe_wallet_button on, which this module's
 * install turns on, both places add a quiet "Use a browser wallet" below the
 * main button, and the top-bar block is marked so its own wallet button is
 * hidden there.
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
    // The email link and the "or" line sit outside the hidden wrapper: a page
    // whose top-bar sign-in never loads still shows them.
    $this->assertSame(0, $xpath->query('//*[@data-ln-signin-wrap]//*[contains(@class, "ln-signin__email")]')->length);
    $email = $xpath->query('//*[contains(@class, "ln-signin-entry")]/*[contains(@class, "ln-signin__email")]//a');
    $this->assertSame(1, $email->length);
    $this->assertSame('Email me a sign-in link', trim($email->item(0)->textContent));
    $this->assertStringEndsWith('/user/email-link', $email->item(0)->getAttribute('href'));
    $this->assertSame(1, $xpath->query('//*[contains(@class, "ln-signin-entry")]/p[normalize-space(.)="Or use a password"]')->length);
    $this->assertSame(0, $xpath->query('//*[@data-ln-signin-wrap]//p[normalize-space(.)="Or use a password"]')->length);
    // In that order: button, email link, "or" line.
    $this->assertSame(1, $xpath->query('//*[@data-ln-signin-wrap]/following-sibling::*[contains(@class, "ln-signin__email")]/following-sibling::p[contains(@class, "ln-signin__or")]')->length);
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
    // Every form varies by route and role for the proven-session mark.
    unset($form['#cache']);
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
    // Every form varies by route and role for the proven-session mark.
    unset($form['#cache']);
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
   * With the emailed link off, neither place offers it; nothing else changes.
   */
  public function testEmailLinkOffIsNotOffered(): void {
    $this->signInOn();
    $this->config(EmailLink::SETTINGS)->set('email_link', FALSE)->save();

    $form = $this->container->get('form_builder')->getForm('Drupal\user\Form\UserLoginForm');
    $xpath = $this->dom($this->container->get('renderer')->renderInIsolation($form));
    $this->assertSame(0, $xpath->query('//a[contains(@href, "/user/email-link")]')->length, 'No emailed link while it is off.');
    $this->assertContains('config:' . EmailLink::SETTINGS, $form['ln_signin']['email_link']['#cache']['tags'], 'Switching it on shows the link on the next render.');
    $this->assertSame(0, $xpath->query('//*[contains(@class, "ln-signin__email")]')->length);
    $this->assertSame(1, $xpath->query('//*[@data-ln-signin-wrap]//button[@data-ln-signin]')->length, 'The button stays.');
    $this->assertSame(1, $xpath->query('//*[@data-ln-signin-wrap]/following-sibling::p[contains(@class, "ln-signin__or")]')->length, 'The "or" line follows the button.');
    $this->assertSame(1, $xpath->query('//input[@name="name"]')->length, 'The password form stays.');

    $xpath = $this->dom($this->renderWelcome());
    $links = $xpath->query('//*[contains(@class, "hero-cta-wrapper")]//a');
    $this->assertSame(['Sign in', 'Use a password'], array_map(fn ($a) => trim($a->textContent), iterator_to_array($links)));
  }

  /**
   * The welcome block: "Sign in" and two quiet links, no "Sign up".
   */
  public function testWelcomeBlockShowsSignIn(): void {
    $this->signInOn();
    $xpath = $this->dom($this->renderWelcome());

    $links = $xpath->query('//*[contains(@class, "hero-cta-wrapper")]//a');
    $this->assertSame(3, $links->length);
    $this->assertSame('Sign in', trim($links->item(0)->textContent));
    $this->assertTrue($links->item(0)->hasAttribute('data-ln-signin'), 'Sign in opens the top-bar sign-in.');
    $this->assertStringEndsWith('/user/login', $links->item(0)->getAttribute('href'), 'Without the script it still leads to the log-in page.');
    $this->assertStringContainsString('btn-primary', $links->item(0)->getAttribute('class'));

    $this->assertSame('Use a password', trim($links->item(1)->textContent));
    $this->assertFalse($links->item(1)->hasAttribute('data-ln-signin'), 'The password link is a plain link.');
    $this->assertStringEndsWith('/user/login', $links->item(1)->getAttribute('href'));
    $this->assertStringNotContainsString('btn', $links->item(1)->getAttribute('class'), 'A quiet text link, not a button.');

    $this->assertSame('Email me a sign-in link', trim($links->item(2)->textContent));
    $this->assertFalse($links->item(2)->hasAttribute('data-ln-signin'));
    $this->assertStringEndsWith('/user/email-link', $links->item(2)->getAttribute('href'));
    $this->assertStringNotContainsString('btn', $links->item(2)->getAttribute('class'));

    $this->assertSame(0, $xpath->query('//a[contains(@href, "/user/register")]')->length, 'No Sign up.');
    // The stored links are kept, for when the sign-in is off.
    $stored = BlockContent::load(1)->get('field_call_to_action_link')->getValue();
    $this->assertSame('internal:/user/register', $stored[0]['uri']);
  }

  /**
   * The install turns the wallet button on, once, and only where it exists.
   */
  public function testInstallShowsTheWalletButton(): void {
    $this->enableModules(['localnodes_platform', 'localnodes_web3']);
    $this->container->get('module_handler')->loadInclude('localnodes_web3', 'install');
    // No siwe_login_waap settings yet: nothing is made up.
    _localnodes_web3_show_wallet_button();
    $this->assertTrue($this->config('siwe_login_waap.settings')->isNew());

    $this->signInOn();
    $this->assertFalse($this->config('siwe_login_waap.settings')->get('show_siwe_wallet_button'), 'siwe_login_waap ships it off.');
    _localnodes_web3_show_wallet_button();
    $this->assertTrue($this->config('siwe_login_waap.settings')->get('show_siwe_wallet_button'));
    $this->assertSame('production', $this->config('siwe_login_waap.settings')->get('environment'), 'Its other settings are kept.');
    _localnodes_web3_show_wallet_button();
    $this->assertTrue($this->config('siwe_login_waap.settings')->get('show_siwe_wallet_button'));
  }

  /**
   * Switched off, there is no wallet choice on the log-in page or the block.
   */
  public function testNoWalletChoiceWhenSwitchedOff(): void {
    $this->signInOn();
    $form = $this->container->get('form_builder')->getForm('Drupal\user\Form\UserLoginForm');
    $html = (string) $this->container->get('renderer')->renderInIsolation($form);
    $this->assertStringNotContainsString('data-ln-wallet', $html);
    $this->assertStringNotContainsString('Use a browser wallet', $html);
    $this->assertStringNotContainsString('Use a browser wallet', $this->renderWelcome());
  }

  /**
   * The log-in page: "Use a browser wallet" under the button, quiet.
   */
  public function testWalletChoiceOnTheLoginPage(): void {
    $this->signInOn();
    $this->walletButtonOn();
    $form = $this->container->get('form_builder')->getForm('Drupal\user\Form\UserLoginForm');
    $xpath = $this->dom($this->container->get('renderer')->renderInIsolation($form));

    $wrap = $xpath->query('//*[@data-ln-wallet-wrap]');
    $this->assertSame(1, $wrap->length);
    $this->assertTrue($wrap->item(0)->hasAttribute('hidden'), 'Hidden until the script finds the wallet button.');
    $button = $xpath->query('//*[@data-ln-wallet-wrap]//button[@data-ln-wallet]');
    $this->assertSame(1, $button->length);
    $this->assertSame('Use a browser wallet', trim($button->item(0)->textContent));
    $this->assertSame('button', $button->item(0)->getAttribute('type'), 'It never submits the password form.');
    $this->assertStringNotContainsString('btn', $button->item(0)->getAttribute('class'), 'A quiet text button.');
    $note = $xpath->query('//*[@data-ln-wallet-wrap]//p[@data-ln-wallet-note]');
    $this->assertSame(1, $note->length);
    $this->assertSame('status', $note->item(0)->getAttribute('role'));
    $this->assertSame('', trim($note->item(0)->textContent));
    // After the main button and the email link, before the "or" line: it
    // never pushes Sign in down.
    $this->assertSame(1, $xpath->query('//*[@data-ln-signin-wrap]/following-sibling::*[contains(@class, "ln-signin__email")]/following-sibling::*[@data-ln-wallet-wrap]/following-sibling::p[contains(@class, "ln-signin__or")]')->length);
  }

  /**
   * The welcome block: "Use a browser wallet" after the other choices.
   */
  public function testWalletChoiceInTheWelcomeBlock(): void {
    $this->signInOn();
    $this->walletButtonOn();
    $xpath = $this->dom($this->renderWelcome());
    $this->assertSame(3, $xpath->query('//*[contains(@class, "hero-cta-wrapper")]//a')->length, 'The three links are unchanged.');
    $button = $xpath->query('//*[contains(@class, "hero-cta-wrapper")]/*[@data-ln-wallet-wrap][preceding-sibling::a[contains(@class, "ln-signin-hero__email")]]//button[@data-ln-wallet]');
    $this->assertSame(1, $button->length);
    $this->assertSame('Use a browser wallet', trim($button->item(0)->textContent));
  }

  /**
   * In the top bar the block is marked, so its wallet button is hidden there.
   */
  public function testTopBarHidesItsWalletButton(): void {
    $this->signInOn();
    $this->walletButtonOn();
    $this->installConfig(['block']);
    $this->container->get('theme_installer')->install(['stark']);
    foreach (['header' => 1, 'content' => 0] as $region => $marked) {
      $block = Block::create([
        'id' => 'siwe_login_' . $region,
        'plugin' => 'siwe_login_block',
        'theme' => 'stark',
        'region' => $region,
        'settings' => ['label' => 'SIWE Login', 'label_display' => FALSE, 'provider' => 'siwe_login'],
      ]);
      $block->save();
      $build = $this->container->get('entity_type.manager')->getViewBuilder('block')->view($block);
      $xpath = $this->dom($this->container->get('renderer')->renderInIsolation($build));
      $this->assertSame(1, $xpath->query('//*[@id="siwe-login-button"]')->length, "$region: the wallet button stays in the page.");
      $this->assertSame($marked, $xpath->query('//*[contains(concat(" ", @class, " "), " ln-signin-topbar ")]//*[@id="siwe-login-button"]')->length, $region);
    }
  }

  /**
   * Turns siwe_login_waap's wallet button on.
   */
  private function walletButtonOn(): void {
    $this->config('siwe_login_waap.settings')->set('show_siwe_wallet_button', TRUE)->save();
  }

  /**
   * Turns the sign-in on, in the order a site lists the modules.
   *
   * The emailed link is switched on too, as the other tests here expect;
   * testEmailLinkOffIsNotOffered switches it off again.
   */
  private function signInOn(): void {
    $this->enableModules(['localnodes_platform', 'localnodes_web3', 'siwe_login_waap']);
    $this->installConfig(['siwe_login_waap']);
    $this->config(EmailLink::SETTINGS)->set('email_link', TRUE)->save();
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
