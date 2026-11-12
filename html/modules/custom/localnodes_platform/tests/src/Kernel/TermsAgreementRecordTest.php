<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Controller\SiteTermsController;
use Drupal\localnodes_platform\Form\TermsConsent;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * The terms version a member agreed to is kept on their account (23-41).
 *
 * ONB-05, D-25, T-23-41: since 23-26 nobody joins without ticking "I agree
 * to the site terms". A self-registration with the box ticked stores, in user.data under
 * localnodes_platform, terms_agreed_version (SiteTermsController::
 * TERMS_CHANGED) and terms_agreed_at (the request time). Nothing the member
 * sees changes.
 *
 * Kernel tests don't check module dependencies, so localnodes_platform loads
 * here without the AI stack, as in SiteTermsTest. verify_mail stays TRUE (the
 * installed default): with FALSE, RegisterForm::save() logs the new member in
 * through user_login_finalize(), which a Kernel test has no session for.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class TermsAgreementRecordTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'filter', 'field', 'block', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The handler the register form alter appends to the button's #submit.
   */
  private const HANDLER = [TermsConsent::class, 'recordAgreement'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'filter']);
    $this->installEntitySchema('user');
    // user.data's table; SiteTermsTest needs only the entity schema.
    $this->installSchema('user', ['users_data']);
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    $this->config('system.site')->set('name', 'Repair Collective')->save();
    $this->config('user.settings')->set('register', 'visitors')->save();
    // Anonymous (uid 0) and a reserved uid 1 so later users are plain.
    User::create(['uid' => 1, 'name' => 'root-reserved', 'status' => 1])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Builds the register form as the current user sees it.
   */
  private function registerForm(): array {
    return $this->container->get('entity.form_builder')->getForm(User::create(), 'register');
  }

  /**
   * Submits the register form with the given values; returns its state.
   */
  private function submitRegister(array $values): FormStateInterface {
    $form_object = $this->container->get('entity_type.manager')->getFormObject('user', 'register');
    $form_object->setEntity(User::create());
    $form_state = (new FormState())->setValues($values + ['op' => 'Create new account']);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    return $form_state;
  }

  /**
   * Loads the one account with the given name, or NULL.
   */
  private function loadByName(string $name): ?User {
    $accounts = $this->container->get('entity_type.manager')->getStorage('user')->loadByProperties(['name' => $name]);
    return $accounts ? reset($accounts) : NULL;
  }

  /**
   * A sign-up with the box ticked stores the terms date and the time.
   */
  public function testSelfRegistrationRecordsTheTermsVersion(): void {
    $form_state = $this->submitRegister([
      'name' => 'new-member',
      'mail' => 'new-member@mail.localnodes.xyz',
      'terms_consent' => 1,
    ]);
    $after = time();

    $this->assertSame([], $form_state->getErrors());
    $account = $this->loadByName('new-member');
    $this->assertNotNull($account, 'The account was created.');
    $uid = (int) $account->id();
    $this->assertSame($uid, (int) $form_state->getValue('uid'));

    $user_data = $this->container->get('user.data');
    $this->assertSame(
      SiteTermsController::TERMS_CHANGED,
      $user_data->get('localnodes_platform', $uid, 'terms_agreed_version'),
      'The stored version is the terms date the /terms page shows.',
    );
    // The handler stores an int; user.data keeps scalars unserialized, so
    // the database hands it back as a string of digits.
    // The Kernel test's request started at boot, before the submit, so the
    // stored time is pinned to the request time and bounded by $after.
    $agreed_at = $user_data->get('localnodes_platform', $uid, 'terms_agreed_at');
    $this->assertMatchesRegularExpression('/^\d+$/', (string) $agreed_at, 'The time is a Unix timestamp.');
    $this->assertSame($this->container->get('datetime.time')->getRequestTime(), (int) $agreed_at, 'The time is the request time.');
    $this->assertGreaterThan(0, (int) $agreed_at);
    $this->assertLessThanOrEqual($after, (int) $agreed_at);
  }

  /**
   * The record handler runs after '::save', so the account has an id.
   */
  public function testRecordHandlerRunsAfterSave(): void {
    $submit = $this->registerForm()['actions']['submit']['#submit'] ?? [];
    $save = array_search('::save', $submit, TRUE);
    $record = array_search(self::HANDLER, $submit, TRUE);
    $this->assertNotFalse($save, "The button runs '::save'.");
    $this->assertNotFalse($record, 'The button runs recordAgreement().');
    $this->assertGreaterThan($save, $record, "recordAgreement() runs after '::save'.");
  }

  /**
   * A site manager's Add user form records nothing, even with a forged box.
   *
   * The manager doesn't agree on the new member's behalf (T-23-134): the
   * handler is attached only after the alter's admin gate.
   */
  public function testAdminCreatedAccountRecordsNothing(): void {
    $role = Role::create(['id' => 'people_admin', 'label' => 'People admin']);
    $role->grantPermission('administer users')->save();
    $admin = User::create(['name' => 'people-admin', 'mail' => 'people-admin@mail.localnodes.xyz', 'status' => 1, 'roles' => ['people_admin']]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    $submit = $this->registerForm()['actions']['submit']['#submit'] ?? [];
    $this->assertContains('::save', $submit, 'This is the create form with its save handler.');
    $this->assertNotContains(self::HANDLER, $submit, 'The Add user form never runs recordAgreement().');

    $form_state = $this->submitRegister([
      'name' => 'added-member',
      'mail' => 'added-member@mail.localnodes.xyz',
      'pass' => ['pass1' => 'a-long-test-password', 'pass2' => 'a-long-test-password'],
      'status' => 1,
      // Forged: the admin form has no box.
      'terms_consent' => 1,
    ]);
    $this->assertSame([], $form_state->getErrors());
    $account = $this->loadByName('added-member');
    $this->assertNotNull($account, 'The site manager created the account.');
    $this->assertSame([], $this->container->get('user.data')->get('localnodes_platform', (int) $account->id()));
  }

  /**
   * A sign-up that fails validation creates no account and no record.
   */
  public function testFailedSignUpRecordsNothing(): void {
    $form_state = $this->submitRegister([
      'name' => 'not-agreed',
      'mail' => 'not-agreed@mail.localnodes.xyz',
      // Box left unticked: a browser sends no value. Checkbox's value
      // callback reads any value that is sent, 0 included, as ticked.
    ]);
    $this->assertArrayHasKey('terms_consent', $form_state->getErrors());
    $this->assertNull($this->loadByName('not-agreed'), 'No account was created.');
    $this->assertSame([], $this->container->get('user.data')->get('localnodes_platform'), 'No terms record for anyone.');
  }

  /**
   * The handler itself needs a saved account and a ticked box.
   */
  public function testHandlerNeedsTheTickedBoxAndAnAccount(): void {
    $account = User::create(['name' => 'direct-member', 'mail' => 'direct-member@mail.localnodes.xyz', 'status' => 1]);
    $account->save();
    $uid = (int) $account->id();
    $user_data = $this->container->get('user.data');
    $form = [];

    $unticked = (new FormState())->setValues(['uid' => $uid, 'terms_consent' => 0]);
    TermsConsent::recordAgreement($form, $unticked);
    $this->assertSame([], $user_data->get('localnodes_platform'), 'An unticked box stores nothing.');

    $no_account = (new FormState())->setValues(['uid' => 0, 'terms_consent' => 1]);
    TermsConsent::recordAgreement($form, $no_account);
    $this->assertSame([], $user_data->get('localnodes_platform'), 'No saved account, nothing stored (not even under uid 0).');

    $ticked = (new FormState())->setValues(['uid' => $uid, 'terms_consent' => 1]);
    TermsConsent::recordAgreement($form, $ticked);
    $stored = $user_data->get('localnodes_platform', $uid);
    $keys = array_keys($stored);
    sort($keys);
    $this->assertSame(['terms_agreed_at', 'terms_agreed_version'], $keys, 'A ticked box with an account stores both keys.');
    $this->assertSame(SiteTermsController::TERMS_CHANGED, $stored['terms_agreed_version']);
  }

}
