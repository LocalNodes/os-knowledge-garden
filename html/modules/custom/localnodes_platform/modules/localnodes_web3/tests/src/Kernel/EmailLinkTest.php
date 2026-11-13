<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\SignInMethod;
use Drupal\localnodes_web3\Controller\EmailLinkController;
use Drupal\localnodes_web3\EmailLink;
use Drupal\localnodes_web3\EmailLinkMailer;
use Drupal\localnodes_web3\Form\EmailLinkRequestForm;
use Drupal\localnodes_web3\Form\EmailLinkSignInForm;
use Drupal\localnodes_web3\SignInEmail;
use Drupal\siwe_login\Service\SiweHandoffStore;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * "Email me a sign-in link", the email step after the button, and the marker.
 *
 * - The link: made for one account and send time; it stops working after a
 *   day, after the account signs in, for a blocked account (for good, even
 *   once unblocked), and for an account with only a made-up address.
 * - Every limit on these mails is checked and taken in one step: two
 *   requests at once never share the last try.
 * - The request page answers the same whether or not an account uses the
 *   address, and mails only an account that can get a link.
 * - The page the link opens signs no one in until its button is pressed;
 *   the session is then marked as signed in by email link, which is not
 *   proof of the account holder.
 * - The install asks every member for a real address, and words siwe_login's
 *   two mails without wallet words.
 * - siwe_login's email form is worded for members; a blocked account's inbox
 *   is told when someone gives its address, and no account is made or merged.
 * - An account whose made-up address becomes real gets its event invitations.
 *
 * localnodes_platform and localnodes_web3 are added with enableModules(), as
 * in SignInEntryTest: their .info.yml dependencies pull in Open Social.
 *
 * @group localnodes_web3
 */
final class EmailLinkTest extends KernelTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'siwe_login'];

  /**
   * What the request page says, whatever the address.
   */
  private const SAME_ANSWER = 'If an account here uses that address, a sign-in link is on its way to it. Open the link on any device; it works once, for one day.';

  /**
   * The current request, with a session of its own.
   */
  private Request $request;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user', 'siwe_login']);
    $this->config('system.site')->set('name', 'Repair Collective')->save();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
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
    // Switched off as installed; EmailLinkSwitchTest covers that.
    $this->config(EmailLink::SETTINGS)->set('email_link', TRUE)->save();
    $this->container->get('router.builder')->rebuild();
    $this->container->get('module_handler')->loadInclude('localnodes_web3', 'install');
    // User 1 is taken, so no test account is the site's first.
    User::create(['name' => 'admin', 'mail' => 'admin@example.com', 'status' => 1])->save();
    $this->pushRequest('/');
  }

  /**
   * A link works for its account, and stops working when it should.
   */
  public function testLinkChecks(): void {
    $now = $this->now();
    $member = $this->member('ana', 'ana@example.com');
    $other = $this->member('ben', 'ben@example.com');
    $hash = EmailLink::hash($member, $now);

    $this->assertTrue(EmailLink::valid($member, $now, $hash, $now));
    $this->assertTrue(EmailLink::valid($member, $now, $hash, $now + 86400), 'It works for one day.');
    $this->assertFalse(EmailLink::valid($member, $now, $hash, $now + 86401), 'Not after a day.');
    $this->assertFalse(EmailLink::valid($member, $now + 10, EmailLink::hash($member, $now + 10), $now), 'Not from the future.');
    $this->assertFalse(EmailLink::valid($member, $now, $hash . 'x', $now), 'Not with a changed hash.');
    $this->assertFalse(EmailLink::valid($other, $now, $hash, $now), 'Not for another account.');
    $this->assertFalse(EmailLink::valid(NULL, $now, $hash, $now));

    $member->setLastLoginTime($now + 5)->save();
    $this->assertFalse(EmailLink::valid($member, $now, $hash, $now + 10), 'Used up once the account signs in.');

    $blocked = $this->member('cy', 'cy@example.com', FALSE);
    $this->assertFalse(EmailLink::valid($blocked, $now, EmailLink::hash($blocked, $now), $now), 'Not for a blocked account.');

    $placeholder = $this->member('0xabcd...ef01', '0xabcdef@ethereum.local');
    $this->assertFalse(EmailLink::valid($placeholder, $now, EmailLink::hash($placeholder, $now), $now), 'Not for a made-up address.');
    $this->assertFalse(EmailLink::canReceive($placeholder));
    $this->assertNull(EmailLink::accountFor('0xabcdef@ethereum.local'));
    $this->assertNull(EmailLink::accountFor('cy@example.com'), 'A blocked account gets no link.');
    $this->assertSame((int) $member->id(), (int) EmailLink::accountFor(' ana@example.com ')->id());

    $url = EmailLink::url($member, $now);
    $this->assertStringContainsString('/user/email-link/' . $member->id() . '/' . $now . '/', $url);
  }

  /**
   * Blocking an account ends its links; unblocking does not revive one.
   */
  public function testBlockingEndsLinksForGood(): void {
    $now = $this->now();
    $member = $this->member('ana', 'ana@example.com');
    $other = $this->member('ben', 'ben@example.com');
    $hash = EmailLink::hash($member, $now);
    $other_hash = EmailLink::hash($other, $now);
    $this->assertTrue(EmailLink::valid($member, $now, $hash, $now));

    $member->block()->save();
    $member->activate()->save();
    $member = User::load($member->id());
    $this->assertTrue($member->isActive());
    $this->assertFalse(EmailLink::valid($member, $now, $hash, $now + 1), 'A link sent before the block stays dead.');
    $this->assertTrue(EmailLink::valid($member, $now + 1, EmailLink::hash($member, $now + 1), $now + 1), 'A link sent after it works.');
    $this->assertTrue(EmailLink::valid($other, $now, $other_hash, $now + 1), "Another account's links are untouched.");

    // A save that does not block changes nothing.
    $hash = EmailLink::hash($member, $now + 1);
    $member->set('name', 'ana2')->save();
    $this->assertTrue(EmailLink::valid(User::load($member->id()), $now + 1, $hash, $now + 1));
  }

  /**
   * The request page: one answer for every address, mail only where it helps.
   */
  public function testRequestPageAnswersTheSame(): void {
    $member = $this->member('ana', 'ana@example.com');
    $this->member('0xabcd...ef01', '0xabcdef@ethereum.local');
    $this->member('cy', 'cy@example.com', FALSE);

    $form = $this->container->get('form_builder')->getForm(EmailLinkRequestForm::class);
    $this->assertSame('Your email address', (string) $form['mail']['#title']);
    $this->assertSame('Email me a link', (string) $form['actions']['submit']['#value']);

    foreach (['nobody@example.com', '0xabcdef@ethereum.local', 'cy@example.com', 'ana@example.com'] as $address) {
      $state = (new FormState())->setValues(['mail' => $address, 'op' => 'Email me a link']);
      $this->container->get('form_builder')->submitForm(EmailLinkRequestForm::class, $state);
      $this->assertSame([], $state->getErrors(), $address);
      $this->assertSame([self::SAME_ANSWER], $this->takeStatus(), $address);
      $this->assertSame('localnodes_web3.email_link', $this->redirectRoute($state), $address);
    }

    // Nothing is looked up or sent while the request answers.
    $this->assertCount(0, $this->getMails(['key' => 'email_link']), 'The mail waits until after the response.');
    $this->sendQueued();
    $mails = $this->getMails(['key' => 'email_link']);
    $this->assertCount(1, $mails, 'Only the active account with a real address gets mail.');
    $this->assertSame('ana@example.com', $mails[0]['to']);
    $this->assertSame('Your sign-in link for Repair Collective', $mails[0]['subject']);
    $body = preg_replace('/\s+/', ' ', $mails[0]['body']);
    $this->assertStringContainsString('/user/email-link/' . $member->id() . '/', $body);
    $this->assertStringContainsString('It works once, for one day, on any device.', $body);
    $this->assertStringNotContainsStringIgnoringCase('password', $mails[0]['subject'] . $body);
    $this->assertStringNotContainsStringIgnoringCase('wallet', $body);
  }

  /**
   * The address finds its account however it is capitalised, on any database.
   *
   * A wildcard typed in the address matches nothing.
   */
  public function testRequestPageAddressInAnyCase(): void {
    $this->member('ana', 'ana@example.com');
    foreach (['an_@example.com', 'a%@example.com', 'ANA@Example.com'] as $address) {
      $state = (new FormState())->setValues(['mail' => $address, 'op' => 'Email me a link']);
      $this->container->get('form_builder')->submitForm(EmailLinkRequestForm::class, $state);
      $this->assertSame([self::SAME_ANSWER], $this->takeStatus(), $address);
    }
    $this->sendQueued();
    $mails = $this->getMails(['key' => 'email_link']);
    $this->assertCount(1, $mails, 'Only the address in other capitals finds the account.');
    $this->assertSame('ana@example.com', $mails[0]['to'], 'The mail goes to the address the account holds.');
  }

  /**
   * Each paragraph of the two mails is its own block, with the name escaped.
   *
   * The site's mailer pastes translated parts in as they are, so without the
   * wrapper the greeting and the next sentence run together.
   */
  public function testMailParagraphsAreBlocks(): void {
    $account = User::create(['name' => 'Bo <b>&', 'mail' => 'bo@example.com', 'status' => 1]);
    $account->save();
    foreach (['email_link', 'not_switched_on'] as $key) {
      $message = ['langcode' => 'en', 'subject' => '', 'body' => []];
      localnodes_web3_mail($key, $message, ['account' => $account, 'url' => 'https://example.com/x']);
      $parts = array_filter($message['body'], fn ($part) => $part instanceof MarkupInterface);
      $this->assertCount(4, $parts, $key);
      foreach ($parts as $part) {
        $this->assertMatchesRegularExpression('#^<p>[^<]*</p>$#', (string) $part, $key);
      }
      $this->assertSame('<p>Bo &lt;b&gt;&amp;,</p>', (string) reset($parts), $key);
    }
  }

  /**
   * Past the per-account limit nothing is sent, and the answer is the same.
   */
  public function testPerAccountLimit(): void {
    $this->member('ana', 'ana@example.com');
    $this->config('user.flood')->set('user_limit', 2)->save();
    for ($i = 0; $i < 3; $i++) {
      $state = (new FormState())->setValues(['mail' => 'ana@example.com', 'op' => 'Email me a link']);
      $this->container->get('form_builder')->submitForm(EmailLinkRequestForm::class, $state);
      $this->assertSame([self::SAME_ANSWER], $this->takeStatus());
      $this->sendQueued();
    }
    $this->assertCount(2, $this->getMails(['key' => 'email_link']));
  }

  /**
   * Opening the link signs no one in; the button does, and marks the session.
   */
  public function testOpeningThenSigningIn(): void {
    $now = $this->now();
    $member = $this->member('ana', 'ana@example.com');
    $hash = EmailLink::hash($member, $now);

    $controller = EmailLinkController::create($this->container);
    $response = $controller->open($this->request, (int) $member->id(), $now, $hash);
    $this->assertStringEndsWith('/user/email-link/sign-in', $response->getTargetUrl());
    $this->assertTrue($this->container->get('current_user')->isAnonymous(), 'Opening the link signs no one in.');

    // A mail scanner or a reload builds the page; it still signs no one in.
    $form = $this->container->get('form_builder')->getForm(EmailLinkSignInForm::class);
    $this->assertSame('Sign in to Repair Collective', (string) $form['#title']);
    $this->assertStringContainsString('This link signs you in as', (string) $form['message']['#value']);
    $this->assertSame('Sign in', (string) $form['actions']['submit']['#value']);
    $this->assertTrue($this->container->get('current_user')->isAnonymous());

    // The button, on the sign-in page's route.
    $this->pushRequest('/user/email-link/sign-in', 'localnodes_web3.email_link_sign_in', $this->request->getSession());
    $state = (new FormState())->setValues(['op' => 'Sign in']);
    $this->container->get('form_builder')->submitForm(EmailLinkSignInForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame((int) $member->id(), (int) $this->container->get('current_user')->id());
    $this->assertSame('<front>', $this->redirectRoute($state), 'No invitations: the front page.');
    $this->assertNull($this->request->getSession()->get(EmailLink::SESSION_KEY), 'The link is used up in this session.');

    $session = $this->request->getSession();
    $this->assertSame(SignInMethod::EMAIL_LINK, SignInMethod::get($session)['method']);
    $this->assertFalse(SignInMethod::provedHolder($session), 'An inbox is not proof of the account holder.');

    // The same link again: the account has signed in since.
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $member = User::load($member->id());
    $this->assertFalse(EmailLink::valid($member, $now, $hash, $now + 1));
  }

  /**
   * While another request holds the account's sign-in, the button refuses.
   *
   * Two browsers with the same link: the one that does not get the lock is
   * sent back with the "already used" message and is not signed in.
   */
  public function testSecondBrowserWaitingOnTheLockIsRefused(): void {
    $now = $this->now();
    $member = $this->member('ana', 'ana@example.com');
    EmailLinkController::create($this->container)->open($this->request, (int) $member->id(), $now, EmailLink::hash($member, $now));
    $this->pushRequest('/user/email-link/sign-in', 'localnodes_web3.email_link_sign_in', $this->request->getSession());

    // Kernel tests run with a lock that always grants; use the real one here.
    $this->container->set('lock', new DatabaseLockBackend($this->container->get('database')));
    // The other browser's request, holding the lock.
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire('localnodes_web3.email_link:' . $member->id(), 30.0));

    $state = (new FormState())->setValues(['op' => 'Sign in']);
    $this->container->get('form_builder')->submitForm(EmailLinkSignInForm::class, $state);
    $this->assertTrue($this->container->get('current_user')->isAnonymous(), 'Not signed in.');
    $this->assertSame('localnodes_web3.email_link', $this->redirectRoute($state));
    $errors = array_map('strval', $this->container->get('messenger')->deleteByType('error'));
    $this->assertSame(['This sign-in link has expired or has already been used. You can ask for a new one here.'], $errors);
    $other->releaseAll();
  }

  /**
   * Two browsers: the second page was built before the first signed in.
   *
   * The second request loaded the account while building its page, before
   * the first browser signed in with the same link. The button checks the
   * link against the account as stored now, not that earlier copy, so the
   * second browser is refused.
   */
  public function testSecondBrowserBuiltBeforeTheFirstSignedInIsRefused(): void {
    $now = $this->now();
    $member = $this->member('ana', 'ana@example.com');
    EmailLinkController::create($this->container)->open($this->request, (int) $member->id(), $now, EmailLink::hash($member, $now));
    $this->pushRequest('/user/email-link/sign-in', 'localnodes_web3.email_link_sign_in', $this->request->getSession());

    // The second browser's page: building it loads the account.
    $form = $this->container->get('form_builder')->getForm(EmailLinkSignInForm::class);
    $this->assertSame('Sign in', (string) $form['actions']['submit']['#value']);

    // The first browser signs in, in another process: the stored last
    // sign-in time changes and that save clears the shared entity cache,
    // while this request's loaded copy stays as it was.
    $this->container->get('database')->update('users_field_data')
      ->fields(['login' => $now, 'access' => $now])
      ->condition('uid', $member->id())
      ->execute();
    $this->container->get('cache.entity')->delete('values:user:' . $member->id());

    $state = (new FormState())->setValues(['op' => 'Sign in']);
    $this->container->get('form_builder')->submitForm(EmailLinkSignInForm::class, $state);
    $this->assertTrue($this->container->get('current_user')->isAnonymous(), 'Not signed in.');
    $this->assertSame('localnodes_web3.email_link', $this->redirectRoute($state));
    $errors = array_map('strval', $this->container->get('messenger')->deleteByType('error'));
    $this->assertSame(['This sign-in link has expired or has already been used. You can ask for a new one here.'], $errors);
  }

  /**
   * A link mail that fails to send is logged as failed, never as mailed.
   */
  public function testFailedLinkMailIsLoggedAsFailed(): void {
    $member = $this->member('ana', 'ana@example.com');
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())->method('mail')->willReturn(['result' => FALSE]);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('info');
    $logger->expects($this->once())->method('error')->with(
      'Sign-in link mail failed for uid @uid.',
      ['@uid' => (string) $member->id()],
    );
    $mailer = new EmailLinkMailer(
      $this->container->get('localnodes_web3.flood_allowance'),
      $this->container->get('config.factory'),
      $mail,
      $this->container->get('datetime.time'),
      $logger,
    );
    $mailer->queue('ana@example.com');
    $mailer->destruct();
  }

  /**
   * A bad link goes back to the request page with a plain message.
   */
  public function testBadLinkGoesBackToTheRequestPage(): void {
    $member = $this->member('ana', 'ana@example.com');
    $controller = EmailLinkController::create($this->container);
    $response = $controller->open($this->request, (int) $member->id(), $this->now(), 'nope');
    $this->assertStringEndsWith('/user/email-link', $response->getTargetUrl());
    $errors = $this->container->get('messenger')->deleteByType('error');
    $this->assertSame('This sign-in link has expired or has already been used. You can ask for a new one here.', (string) $errors[0]);
    $this->assertNull($this->request->getSession()->get(EmailLink::SESSION_KEY));

    // The sign-in page without a link in the session.
    try {
      $this->container->get('form_builder')->getForm(EmailLinkSignInForm::class);
      $this->fail('The page sends the visitor on.');
    }
    catch (EnforcedResponseException $e) {
      $this->assertInstanceOf(RedirectResponse::class, $e->getResponse());
      $this->assertStringEndsWith('/user/email-link', $e->getResponse()->getTargetUrl());
    }
  }

  /**
   * Each way of signing in gets its mark; only two prove the holder.
   */
  public function testSignInMethodMarks(): void {
    $this->assertSame(SignInMethod::SIGNATURE, SignInMethod::forRoute('siwe_login.verify'));
    $this->assertSame(SignInMethod::PASSWORD, SignInMethod::forRoute('user.login'));
    $this->assertSame(SignInMethod::EMAIL_LINK, SignInMethod::forRoute('user.reset.login'));
    $this->assertSame(SignInMethod::EMAIL_LINK, SignInMethod::forRoute('localnodes_web3.email_link_sign_in'));
    $this->assertSame(SignInMethod::OTHER, SignInMethod::forRoute('siwe_login.email_verification_confirm'));
    $this->assertSame(SignInMethod::OTHER, SignInMethod::forRoute(NULL));

    $session = new Session(new MockArraySessionStorage());
    $this->assertSame(['method' => SignInMethod::OTHER, 'at' => 0], SignInMethod::get($session), 'No record reads as not proven.');
    $this->assertFalse(SignInMethod::provedHolder($session));
    $session->set(SignInMethod::KEY, ['method' => 'made-up', 'at' => 5]);
    $this->assertSame(['method' => SignInMethod::OTHER, 'at' => 0], SignInMethod::get($session));
    $routes = [
      'siwe_login.verify' => TRUE,
      'user.login' => TRUE,
      'user.reset.login' => FALSE,
      'localnodes_web3.email_link_sign_in' => FALSE,
      'siwe_login.email_verification_confirm' => FALSE,
    ];
    foreach ($routes as $route => $proved) {
      SignInMethod::record($session, $route, 1234);
      $this->assertSame($proved, SignInMethod::provedHolder($session), $route);
      $this->assertSame(SignInMethod::forRoute($route) === SignInMethod::OTHER ? 0 : 1234, SignInMethod::get($session)['at'], $route);
    }
  }

  /**
   * The install asks for a real address and words siwe_login's two mails.
   */
  public function testInstallAsksForEmailAndWordsTheMails(): void {
    $this->assertFalse($this->config('siwe_login.settings')->get('require_email_verification'), 'siwe_login ships it off.');
    _localnodes_web3_require_member_email();
    $this->assertTrue($this->config('siwe_login.settings')->get('require_email_verification'));

    $organizer = $this->member('organizer', 'organizer@example.com');
    $url = 'https://example.com/siwe/email-verification/2/1700000000/abc';
    $address = '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266';
    foreach (['email_verification', 'account_link'] as $key) {
      $this->container->get('plugin.manager.mail')->mail('siwe_login', $key, 'organizer@example.com', 'en', [
        'account' => $organizer,
        'verification_url' => $url,
        'siwe_data' => ['address' => $address],
      ]);
    }
    $verification = $this->getMails(['key' => 'email_verification'])[0];
    $link = $this->getMails(['key' => 'account_link'])[0];
    $this->assertSame('Confirm your email address for Repair Collective', $verification['subject']);
    $this->assertSame('Add a sign-in to your Repair Collective account', $link['subject']);
    foreach ([$verification, $link] as $mail) {
      $text = preg_replace('/\s+/', ' ', $mail['subject'] . ' ' . $mail['body']);
      $this->assertStringContainsString($url, $text);
      $this->assertStringContainsString('same browser', $text);
      $this->assertStringContainsString('works once, for one day', $text);
      foreach (['wallet', 'Ethereum', $address, 'password', '[site:', '[siwe:', '[user:'] as $word) {
        $this->assertStringNotContainsStringIgnoringCase($word, $text, $mail['key'] . ": no '$word'");
      }
    }
    $this->assertStringContainsString('organizer,', $link['body'], 'The link mail greets the account it goes to.');

    // A second run changes nothing.
    $before = $this->config('siwe_login.mail')->getRawData();
    _localnodes_web3_require_member_email();
    $this->assertSame($before, $this->config('siwe_login.mail')->getRawData());
  }

  /**
   * The siwe_login email form, in members' words.
   */
  public function testEmailFormWording(): void {
    $form = [
      'email' => ['#type' => 'email', '#title' => 'Email Address'],
      'actions' => ['submit' => ['#type' => 'submit', '#value' => 'Verify and Continue']],
      '#submit' => ['::submitForm'],
    ];
    localnodes_web3_form_siwe_email_verification_form_alter($form, new FormState(), 'siwe_email_verification_form');
    $this->assertSame('Your email address', (string) $form['#title']);
    $this->assertSame('Email address', (string) $form['email']['#title']);
    $this->assertSame('Where should we send notices, and a sign-in link if signing in this way ever fails? We will email you a link to confirm it. Open it in this browser.', (string) $form['email']['#description']);
    $this->assertSame('Send the link', (string) $form['actions']['submit']['#value']);
    $this->assertSame(['::submitForm', [SignInEmail::class, 'submitNotSwitchedOn']], $form['#submit'], "Runs after siwe_login's own handler.");
  }

  /**
   * The organizer's address: a mail to add the sign-in, never a new account.
   */
  public function testOrganizerAddressGetsAnAddMail(): void {
    _localnodes_web3_require_member_email();
    $organizer = $this->member('organizer', 'organizer@example.com');
    $this->pendingSignIn();

    $this->submitEmailForm('organizer@example.com');

    $mails = $this->getMails(['key' => 'account_link']);
    $this->assertCount(1, $mails);
    $this->assertSame('organizer@example.com', $mails[0]['to']);
    $this->assertCount(0, $this->getMails(['key' => 'email_verification']));
    $this->assertCount(1, User::loadMultiple(\Drupal::entityQuery('user')->accessCheck(FALSE)->condition('mail', 'organizer@example.com')->execute()), 'No second account with that address.');
    $this->assertSame('', (string) User::load($organizer->id())->get('field_ethereum_address')->value, 'Nothing changes until the link is opened.');
    $this->assertCount(0, $this->getMails(['key' => 'not_switched_on']));
  }

  /**
   * A blocked account's inbox is told; the visitor sees what anyone would.
   */
  public function testBlockedAccountInboxIsTold(): void {
    _localnodes_web3_require_member_email();
    $blocked = $this->member('cy', 'cy@example.com', FALSE);
    $this->pendingSignIn();
    $users_before = count(User::loadMultiple());

    $status = $this->submitEmailForm('cy@example.com');

    $mails = $this->getMails(['key' => 'not_switched_on']);
    $this->assertCount(1, $mails);
    $this->assertSame('cy@example.com', $mails[0]['to']);
    $this->assertSame('Your account on Repair Collective is not switched on yet', $mails[0]['subject']);
    $this->assertStringNotContainsStringIgnoringCase('wallet', $mails[0]['body']);
    $this->assertCount(0, $this->getMails(['key' => 'account_link']));
    $this->assertSame($users_before, count(User::loadMultiple()), 'No account is made.');
    $this->assertFalse(User::load($blocked->id())->isActive());

    // The visitor's message is the one any address gets.
    $this->pendingSignIn();
    $this->assertSame($status, $this->submitEmailForm('somebody-new@example.com'));

    // Without a sign-in waiting in this browser, nothing is sent.
    $this->pushRequest('/');
    $state = (new FormState())->setValues(['email' => 'cy@example.com']);
    $form = [];
    SignInEmail::submitNotSwitchedOn($form, $state);
    $this->assertCount(1, $this->getMails(['key' => 'not_switched_on']));
  }

  /**
   * The email step sends at most LIMIT mails per IP address in a window.
   *
   * Each try is a new waiting sign-in, so only the IP limit applies; the
   * sixth try in the window is refused and sends nothing.
   */
  public function testEmailStepIpLimit(): void {
    _localnodes_web3_require_member_email();
    $this->member('cy', 'cy@example.com', FALSE);
    for ($i = 0; $i < SignInEmail::LIMIT; $i++) {
      $this->pendingSignIn();
      $this->submitEmailForm('cy@example.com');
    }
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'not_switched_on']));

    $this->pendingSignIn();
    $this->assertSame(['Too many links were asked for here. Please try again later.'], $this->submitRefused('new-member@example.com'));
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'not_switched_on']));
    $this->assertCount(0, $this->getMails(['key' => 'email_verification']), 'The sixth try sends nothing at all.');
  }

  /**
   * The email step sends at most LIMIT mails for one waiting sign-in.
   *
   * The IP count is cleared between tries, so only the per-sign-in limit
   * applies.
   */
  public function testEmailStepPendingSignInLimit(): void {
    _localnodes_web3_require_member_email();
    $this->member('cy', 'cy@example.com', FALSE);
    $this->pendingSignIn();
    for ($i = 0; $i < SignInEmail::LIMIT; $i++) {
      $this->container->get('flood')->clear('localnodes_web3.signin_email_ip');
      $this->submitEmailForm('cy@example.com');
    }
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'not_switched_on']));

    $this->container->get('flood')->clear('localnodes_web3.signin_email_ip');
    $this->assertSame(['Too many links were asked for here. Please try again later.'], $this->submitRefused('cy@example.com'));
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'not_switched_on']), 'The sixth try sends nothing.');
  }

  /**
   * The email step sends at most LIMIT mails to one address in a window.
   *
   * Each try is a new waiting sign-in from a fresh IP count, so only the
   * address limit applies. It counts the address however it is typed, and
   * whichever mail it would get: siwe_login's own or the not-switched-on note.
   */
  public function testEmailStepRecipientLimit(): void {
    _localnodes_web3_require_member_email();
    $this->member('cy', 'cy@example.com', FALSE);
    $refusal = ['Too many links were sent to that address. Please try again later.'];
    $typed = [
      'new-member@example.com',
      'New-Member@example.com',
      'NEW-MEMBER@EXAMPLE.COM',
      'new-member@Example.com',
      'new-member@example.COM',
    ];
    foreach ($typed as $address) {
      $this->pendingSignIn();
      $this->container->get('flood')->clear('localnodes_web3.signin_email_ip');
      $this->submitEmailForm($address);
    }
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'email_verification']));
    $this->pendingSignIn();
    $this->container->get('flood')->clear('localnodes_web3.signin_email_ip');
    $this->assertSame($refusal, $this->submitRefused('New-member@example.com'));
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'email_verification']), 'The sixth try sends nothing.');

    for ($i = 0; $i < SignInEmail::LIMIT; $i++) {
      $this->pendingSignIn();
      $this->container->get('flood')->clear('localnodes_web3.signin_email_ip');
      $this->submitEmailForm($i % 2 ? 'CY@example.com' : 'cy@example.com');
    }
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'not_switched_on']));
    $this->pendingSignIn();
    $this->container->get('flood')->clear('localnodes_web3.signin_email_ip');
    $this->assertSame($refusal, $this->submitRefused('cy@example.com'));
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'not_switched_on']), 'Nor to a blocked account.');
    $this->assertCount(SignInEmail::LIMIT, $this->getMails(['key' => 'email_verification']));
  }

  /**
   * While another request takes the email step's last try, this one waits.
   *
   * The other request holds the lock on the address's count, as it does
   * between reading the count and adding its try; this one does not send.
   */
  public function testEmailStepTryInFlightIsNotShared(): void {
    _localnodes_web3_require_member_email();
    $this->requestServices(new DatabaseLockBackend($this->container->get('database')));
    $other = new DatabaseLockBackend($this->container->get('database'));
    $key = 'localnodes_web3.flood:' . hash('sha256', "localnodes_web3.signin_email_recipient\0" . hash('sha256', 'new-member@example.com'));
    $this->assertTrue($other->acquire($key, 30.0));

    $this->pendingSignIn();
    $this->assertSame(['Too many links were asked for here. Please try again later.'], $this->submitRefused('new-member@example.com'));
    $this->assertCount(0, $this->getMails(['key' => 'email_verification']));
    $other->releaseAll();

    $this->submitEmailForm('new-member@example.com');
    $this->assertCount(1, $this->getMails(['key' => 'email_verification']), 'Once it is free, the try goes ahead.');
  }

  /**
   * Two link requests at once from one address get one try, not two.
   *
   * The limit is one. The second request runs while the first sits between
   * reading the count and adding its try.
   */
  public function testLinkRequestsAtOnceShareNoTry(): void {
    $this->member('ana', 'ana@example.com');
    $this->config('user.flood')->set('ip_limit', 1)->set('user_limit', 10)->save();
    $flood = $this->interleave();
    $flood->between = function (): void {
      $this->otherRequest(function (): void {
        $state = (new FormState())->setValues(['mail' => 'ana@example.com', 'op' => 'Email me a link']);
        $this->container->get('form_builder')->submitForm(EmailLinkRequestForm::class, $state);
        $this->assertSame(['mail' => 'Too many sign-in links were asked for from here. Please try again later.'], array_map('strval', $state->getErrors()));
        $this->sendQueued();
      });
    };
    $state = (new FormState())->setValues(['mail' => 'ana@example.com', 'op' => 'Email me a link']);
    $this->container->get('form_builder')->submitForm(EmailLinkRequestForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertNull($flood->between, 'The second request ran.');
    $this->sendQueued();
    $this->assertCount(1, $this->getMails(['key' => 'email_link']));
  }

  /**
   * Two link mails at once for one account get one try, not two.
   *
   * The per-account limit is one. The second request's mail runs while the
   * first sits between reading the account's count and adding its try.
   */
  public function testLinkMailsAtOnceShareNoTry(): void {
    $this->member('ana', 'ana@example.com');
    $this->config('user.flood')->set('ip_limit', 10)->set('user_limit', 1)->save();
    $flood = $this->interleave();
    $state = (new FormState())->setValues(['mail' => 'ana@example.com', 'op' => 'Email me a link']);
    $this->container->get('form_builder')->submitForm(EmailLinkRequestForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $flood->between = function (): void {
      $this->otherRequest(function (): void {
        $state = (new FormState())->setValues(['mail' => 'ana@example.com', 'op' => 'Email me a link']);
        $this->container->get('form_builder')->submitForm(EmailLinkRequestForm::class, $state);
        $this->sendQueued();
      });
    };
    $this->sendQueued();
    $this->assertNull($flood->between, 'The second request ran.');
    $this->assertCount(1, $this->getMails(['key' => 'email_link']));
  }

  /**
   * A made-up address becoming real claims that address's event invitations.
   */
  public function testRealAddressClaimsEventInvites(): void {
    $helper = new class() {

      /**
       * Addresses asked for.
       *
       * @var string[]
       */
      public array $asked = [];

      /**
       * Enrollments to hand back.
       *
       * @var object[]
       */
      public array $enrollments = [];

      /**
       * As social_event.status_helper's method.
       */
      public function getAllUserEventEnrollments(string $mail): array {
        $this->asked[] = $mail;
        return $this->enrollments;
      }

    };
    $enrollment = new class() {

      /**
       * Values set.
       *
       * @var array<string, mixed>
       */
      public array $values = [];

      /**
       * Saves.
       */
      public int $saved = 0;

      /**
       * As an entity's set().
       */
      public function set(string $field, mixed $value): static {
        $this->values[$field] = $value;
        return $this;
      }

      /**
       * As an entity's save().
       */
      public function save(): int {
        $this->saved++;
        return 1;
      }

    };
    $helper->enrollments = [$enrollment];
    $this->container->set('social_event.status_helper', $helper);
    $this->container->get('module_handler')->addModule('social_event_invite', 'profiles/contrib/social/modules/social_features/social_event/modules/social_event_invite');

    $member = $this->member('0xabcd...ef01', '0xabcdef@ethereum.local');
    // social_event_invite's own hook_user_insert asked on creation.
    $helper->asked = [];
    $enrollment->values = [];
    $enrollment->saved = 0;
    $member->set('name', '0xabcd...ef02')->save();
    $this->assertSame([], $helper->asked, 'A save that keeps the made-up address claims nothing.');

    $member->setEmail('dee@example.com')->save();
    $this->assertSame(['dee@example.com'], $helper->asked);
    $this->assertSame(['field_email' => '', 'field_account' => $member->id(), 'user_id' => $member->id()], $enrollment->values);
    $this->assertSame(1, $enrollment->saved);

    $member->setEmail('dee2@example.com')->save();
    $this->assertSame(['dee@example.com'], $helper->asked, 'A real address changing again claims nothing: Open Social never did.');
  }

  /**
   * Real locks and a flood that can run another request mid-check.
   *
   * Kernel tests run with a lock that always grants; the real one is used
   * here. The flood's $between runs once, right after a count is read and
   * before the caller adds its try.
   */
  private function interleave(): object {
    $flood = new class($this->container->get('flood')) implements FloodInterface {

      /**
       * Runs once after the next count is read.
       *
       * @var callable|null
       */
      public $between = NULL;

      public function __construct(private readonly FloodInterface $inner) {}

      /**
       * {@inheritdoc}
       */
      public function register($name, $window = 3600, $identifier = NULL) {
        $this->inner->register($name, $window, $identifier);
      }

      /**
       * {@inheritdoc}
       */
      public function clear($name, $identifier = NULL) {
        $this->inner->clear($name, $identifier);
      }

      /**
       * {@inheritdoc}
       */
      public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL) {
        $allowed = $this->inner->isAllowed($name, $threshold, $window, $identifier);
        if ($this->between !== NULL) {
          $between = $this->between;
          $this->between = NULL;
          $between();
        }
        return $allowed;
      }

      /**
       * {@inheritdoc}
       */
      public function garbageCollection() {
        $this->inner->garbageCollection();
      }

    };
    $this->container->set('flood', $flood);
    $this->requestServices(new DatabaseLockBackend($this->container->get('database')));
    return $flood;
  }

  /**
   * Runs $request as another request would: its own lock and services.
   */
  private function otherRequest(callable $request): void {
    $ids = ['lock', 'localnodes_web3.flood_allowance', 'localnodes_web3.email_link_mailer'];
    $kept = [];
    foreach ($ids as $id) {
      $kept[$id] = $this->container->has($id) ? $this->container->get($id) : NULL;
    }
    $lock = new DatabaseLockBackend($this->container->get('database'));
    $this->requestServices($lock);
    try {
      $request();
    }
    finally {
      $lock->releaseAll();
      // Core remembers a form error for the rest of a request; the other
      // request's refusal must not stop this one's form from submitting.
      (new FormState())->clearErrors();
      foreach ($kept as $id => $service) {
        $this->container->set($id, $service);
      }
    }
  }

  /**
   * One request's lock, flood allowance and link mailer, built afresh.
   */
  private function requestServices(DatabaseLockBackend $lock): void {
    $this->container->set('lock', $lock);
    $limiter = $this->container->get('flood');
    $class = 'Drupal\\localnodes_web3\\FloodAllowance';
    if (class_exists($class)) {
      $limiter = new $class($limiter, $lock, $this->container->get('request_stack'));
      $this->container->set('localnodes_web3.flood_allowance', $limiter);
    }
    $this->container->set('localnodes_web3.email_link_mailer', new EmailLinkMailer(
      $limiter,
      $this->container->get('config.factory'),
      $this->container->get('plugin.manager.mail'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.localnodes_web3'),
    ));
  }

  /**
   * A saved member.
   */
  private function member(string $name, string $mail, bool $active = TRUE): UserInterface {
    $account = User::create(['name' => $name, 'mail' => $mail, 'status' => (int) $active]);
    $account->save();
    return $account;
  }

  /**
   * The request time.
   */
  private function now(): int {
    return (int) $this->container->get('datetime.time')->getRequestTime();
  }

  /**
   * Makes a request current, with a session, and optionally a route.
   */
  private function pushRequest(string $path, ?string $route = NULL, ?Session $session = NULL): void {
    $request = Request::create($path);
    $request->setSession($session ?? new Session(new MockArraySessionStorage()));
    if ($route !== NULL) {
      $request->attributes->set('_route', $route);
      $request->attributes->set('_route_object', $this->container->get('router.route_provider')->getRouteByName($route));
    }
    $this->container->get('request_stack')->push($request);
    $this->request = $request;
  }

  /**
   * The route a submitted form would go to.
   *
   * A programmed submission turns redirects off, so getRedirect() is FALSE;
   * the stored target is read instead.
   */
  private function redirectRoute(FormState $state): ?string {
    $url = (fn () => $this->redirect)->call($state);
    return $url instanceof Url ? $url->getRouteName() : NULL;
  }

  /**
   * The status messages so far, as strings, then clears them.
   *
   * @return string[]
   *   The messages.
   */
  private function takeStatus(): array {
    return array_map('strval', $this->container->get('messenger')->deleteByType('status'));
  }

  /**
   * A button sign-in waiting for its email in this browser.
   */
  private function pendingSignIn(): void {
    $this->pushRequest('/siwe/email-verification');
    $this->request->getSession()->set('siwe_login.binding', bin2hex(random_bytes(32)));
    (function (): void {
      $this->token(TRUE);
    })->call($this->container->get('siwe_login.nonce_store'));
    $this->container->get('siwe_login.handoff_store')->set('pending_siwe_data', [
      'address' => '0x' . bin2hex(random_bytes(20)),
      'ensName' => NULL,
    ], SiweHandoffStore::STAGE_EMAIL);
  }

  /**
   * Sends what the request page queued, as kernel terminate would.
   */
  private function sendQueued(): void {
    $this->container->get('localnodes_web3.email_link_mailer')->destruct();
  }

  /**
   * Submits siwe_login's email form and expects it refused.
   *
   * @return string[]
   *   The form errors.
   */
  private function submitRefused(string $email): array {
    $state = (new FormState())->setValues(['email' => $email, 'op' => 'Send the link']);
    $this->container->get('form_builder')->submitForm('Drupal\siwe_login\Form\EmailVerificationForm', $state);
    return array_values(array_map('strval', $state->getErrors()));
  }

  /**
   * Submits siwe_login's email form, altered as on the site.
   *
   * @return string[]
   *   The status messages the visitor sees.
   */
  private function submitEmailForm(string $email): array {
    $state = (new FormState())->setValues(['email' => $email, 'op' => 'Send the link']);
    $this->container->get('form_builder')->submitForm('Drupal\siwe_login\Form\EmailVerificationForm', $state);
    $this->assertSame([], $state->getErrors());
    return $this->takeStatus();
  }

}
