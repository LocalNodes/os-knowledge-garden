<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_platform\Controller\SiteTermsController;
use Drupal\localnodes_platform\Form\TermsConsent;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * The site terms at /terms and the consent box on the register form (23-26).
 *
 * ONB-05, D-25: every community site serves the terms, and a new member
 * agrees to them on sign-up with a required box that is never pre-ticked.
 * The words are 23-UI-SPEC section 9, word for word: title, intro, six h2
 * sections (the fifth a list of four), the version line, the checkbox label,
 * its error, and the footer link.
 *
 * Kernel tests don't check module dependencies, so localnodes_platform loads
 * here without the AI stack. Open Social's social_user and inline form errors
 * aren't on; the live register form (with both) is checked by
 * tests/a11y/terms.spec.ts.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class SiteTermsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'filter', 'field', 'block', 'localnodes_platform'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  private const SITE_NAME = 'Repair Collective';

  /**
   * UI-SPEC section 9: the intro, with ‹community name› as %s.
   */
  private const INTRO = "These are the rules for using %s's site. They apply to everyone with an account.";

  /**
   * UI-SPEC section 9: the six sections, as [h2, body]. %s is the name.
   */
  private const SECTIONS = [
    ['Who runs this site', '%s is run by its organizers. LocalNodes hosts the site.'],
    ['What you can share', 'Share only what you have the right to share: your own words, openly licensed work, or work you have permission to use. When you import files or web pages, you confirm you have that right.'],
    ['Taking content down', 'If someone tells us a page shouldn\'t be here, the organizers or the host can unpublish it. Use "Report a problem with this page" on any imported page.'],
    ['The AI assistant', 'The AI assistant and the AI overview write answers from pages on this site. They can be wrong. Check the sources they show.'],
    ['Your personal data', NULL],
    ['Changes', 'If these terms change, the date on this page changes and the organizers will tell you.'],
  ];

  /**
   * UI-SPEC section 9: the four personal-data bullets (amended 2026-11-08).
   */
  private const DATA_BULLETS = [
    'What this site keeps: your account name, email address, a password record when a site password is used, and what you post or upload. A quick-poll answer is stored with your account; others in the group see only the totals.',
    'Who can see it: organizers and site managers can see your account details. Other members can\'t see your email unless you choose to show it on your profile. Posts follow their chosen audience; site managers and administrators can also read private-group records. People with access to the host\'s servers or hosting accounts, and tools using those accounts, can reach them too.',
    'The AI assistant: to prepare pages for search, this site sends the text of topics, events, posts and comments, and text from their attached files, to its AI service when they are saved. That includes private groups\' pages, even if nobody asks the assistant anything. The search index itself is kept with this site\'s other data. When you ask the assistant something, your question, the passages used and your recent conversation are sent to the same service. When the AI overview summarizes a search, your search words and the passages used are sent. The service may keep requests and answers for a time to check for abuse. Its rules for this site\'s account, including whether it may use them to improve its products, are being confirmed.',
    'Leaving: ask the site\'s organizers or hello@localnodes.xyz about closing your account or removing records; closing an account does not itself erase this community\'s records, recovery copies or AI-service logs. The host can also make a copy of the whole site, including every member\'s account and private pages, so the community can move it elsewhere. If this site was a three-month pilot that doesn\'t continue, it stays unchanged for 30 days, and the organizers get at least seven days\' written notice before it is removed. Removing the site deletes its pages, files and search data; backup copies and the AI service\'s logs are deleted on their own schedules, not at once.',
  ];

  /**
   * The first bullet on a site with the optional Web3 stack on.
   *
   * There a member can connect a wallet and the site keeps its public
   * address; without the stack the bullet has no wallet word at all.
   */
  private const KEEPS_BULLET_WITH_WALLETS = 'What this site keeps: your account name, email address, a password record when a site password is used, what you post or upload, and the public address of any wallet you connect. A quick-poll answer is stored with your account; others in the group see only the totals.';

  /**
   * UI-SPEC section 9: the checkbox label and its error.
   */
  private const CONSENT_LABEL = 'I agree to the site terms';
  private const CONSENT_ERROR = 'Agree to the site terms to create your account.';

  /**
   * The terms text these pins were taken from.
   *
   * SHA-256 of the template with its Twig comments removed and whitespace
   * folded (see templateTextHash()). Together with TERMS_CHANGED_PIN it
   * makes the version date move with the words: change one without the
   * other and testVersionDateMovesWithTheText fails.
   */
  private const TEMPLATE_TEXT_SHA256 = '30ae15a78a34789e2351ab3c6794626144a23f511e48033b8533785a56a1359a';
  private const TERMS_CHANGED_PIN = '2026-11-12';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'filter']);
    $this->installEntitySchema('user');
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    $this->config('system.site')->set('name', self::SITE_NAME)->save();
    $this->config('user.settings')->set('register', 'visitors')->save();
    // Anonymous (uid 0) and a reserved uid 1 so later users are plain.
    User::create(['uid' => 1, 'name' => 'root-reserved', 'status' => 1])->save();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Renders the controller's page and returns its HTML.
   */
  private function renderTerms(): string {
    $build = SiteTermsController::create($this->container)->page();
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Parses HTML into an XPath over a body.
   */
  private function domXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    return new \DOMXPath($dom);
  }

  /**
   * Folds whitespace so a copy line compares equal to rendered text.
   */
  private function fold(string $text): string {
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * The template's text, comments removed and whitespace folded, hashed.
   */
  private function templateTextHash(): string {
    $path = DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('localnodes_platform')
      . '/templates/localnodes-site-terms.html.twig';
    $text = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));
    return hash('sha256', $this->fold($text));
  }

  /**
   * Builds the register form as the current user sees it.
   */
  private function registerForm(): array {
    return $this->container->get('entity.form_builder')->getForm(User::create(), 'register');
  }

  /**
   * Submits the register form with the given values; returns its errors.
   *
   * @return array<string, mixed>
   *   The form errors, keyed by element name.
   */
  private function submitRegister(array $values): array {
    $form_object = $this->container->get('entity_type.manager')->getFormObject('user', 'register');
    $form_object->setEntity(User::create());
    $form_state = (new FormState())->setValues($values + ['op' => 'Create new account']);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    return $form_state->getErrors();
  }

  /**
   * The route is /terms, titled "Site Terms".
   */
  public function testRouteAndTitle(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('localnodes_platform.terms');
    $this->assertSame('/terms', $route->getPath());
    $this->assertSame('Site Terms', (string) $route->getDefault('_title'));
    $request = Request::create('/terms');
    $this->assertSame('Site Terms', (string) $this->container->get('title_resolver')->getTitle($request, $route));
  }

  /**
   * Anyone may read the terms, before they have an account.
   */
  public function testAnonymousVisitorCanReadTheTerms(): void {
    $anonymous = $this->container->get('current_user');
    $this->assertTrue($anonymous->isAnonymous());
    // No "access content" for anonymous: the terms must still open.
    $this->assertFalse(Role::load('anonymous')->hasPermission('access content'));
    $result = $this->container->get('access_manager')->checkNamedRoute('localnodes_platform.terms', [], $anonymous, TRUE);
    $this->assertTrue($result->isAllowed());

    $response = $this->container->get('http_kernel')->handle(Request::create('/terms'));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString(sprintf(self::INTRO, self::SITE_NAME), html_entity_decode((string) $response->getContent(), ENT_QUOTES));
  }

  /**
   * The intro, the six sections in order, the four bullets, the version line.
   */
  public function testTermsTextWordForWord(): void {
    $xpath = $this->domXpath($this->renderTerms());

    $this->assertSame(0, $xpath->query('//h1')->length, 'The route title is the page h1; the body adds none.');

    $paragraphs = [];
    foreach ($xpath->query('//p') as $node) {
      $paragraphs[] = $this->fold($node->textContent);
    }
    $this->assertSame(sprintf(self::INTRO, self::SITE_NAME), $paragraphs[0], 'The intro opens the page.');

    $headings = [];
    foreach ($xpath->query('//section/h2') as $node) {
      $headings[] = $this->fold($node->textContent);
    }
    $this->assertSame(array_column(self::SECTIONS, 0), $headings, 'Six h2 sections, in the UI-SPEC order.');

    foreach (self::SECTIONS as [$heading, $body]) {
      $section = $xpath->query('//section[h2[normalize-space(.)="' . $heading . '"]]')->item(0);
      $this->assertNotNull($section, "Section \"$heading\" exists.");
      if ($body === NULL) {
        continue;
      }
      $text = $this->fold($xpath->query('.//p', $section)->item(0)?->textContent ?? '');
      $this->assertSame(sprintf($body, self::SITE_NAME), $text, "The body of \"$heading\".");
    }

    $bullets = [];
    foreach ($xpath->query('//section[h2[normalize-space(.)="Your personal data"]]/ul/li') as $node) {
      $bullets[] = $this->fold($node->textContent);
    }
    $this->assertSame(self::DATA_BULLETS, $bullets, 'The personal-data list has the four approved bullets.');
    $this->assertSame(1, $xpath->query('//a[@href="mailto:hello@localnodes.xyz"]')->length, 'The help address is a mail link.');

    $this->assertSame('Draft for review, November 12, 2026', end($paragraphs), 'The version line ends the page.');
    $this->assertSame(1, $xpath->query('(//section)[last()]/following::p[contains(@class, "localnodes-site-terms__version")]')->length, 'The version line comes after the sections.');
  }

  /**
   * With the Web3 stack on, the first bullet names the wallet address.
   *
   * The stack's own dependencies are not loaded here; only whether the
   * module is on decides the sentence, so the test registers it by name.
   */
  public function testWalletAddressBulletWhenWeb3IsOn(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('localnodes_web3'), 'The stack starts off.');
    $this->container->get('module_handler')->addModule('localnodes_web3', 'modules/custom/localnodes_platform/modules/localnodes_web3');

    $xpath = $this->domXpath($this->renderTerms());
    $bullets = [];
    foreach ($xpath->query('//section[h2[normalize-space(.)="Your personal data"]]/ul/li') as $node) {
      $bullets[] = $this->fold($node->textContent);
    }
    $expected = self::DATA_BULLETS;
    $expected[0] = self::KEEPS_BULLET_WITH_WALLETS;
    $this->assertSame($expected, $bullets, 'Only the first bullet changes, to the wallet sentence.');
  }

  /**
   * With the Web3 stack off, no bullet mentions a wallet.
   */
  public function testNoWalletWordWhenWeb3IsOff(): void {
    $text = $this->fold($this->domXpath($this->renderTerms())->query('//body')->item(0)->textContent);
    $this->assertStringNotContainsStringIgnoringCase('wallet', $text);
  }

  /**
   * The community's name prints as text (T-23-75).
   */
  public function testSiteNameIsEscaped(): void {
    $this->config('system.site')->set('name', '<b>Bold</b> & Co')->save();
    $html = $this->renderTerms();
    $this->assertStringNotContainsString('<b>Bold</b>', $html);
    $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; &amp; Co', $html);
  }

  /**
   * The version date is formatted at render time, in the D-30 US format.
   */
  public function testVersionDateFormat(): void {
    $this->assertSame('November 8, 2026', SiteTermsController::formatVersionDate('2026-11-08'));
    $this->assertSame('January 5, 2027', SiteTermsController::formatVersionDate('2027-01-05'));
    // A site west of UTC still prints the declared day, not the day before.
    $this->config('system.date')->set('timezone.default', 'America/Los_Angeles')->save();
    $this->assertSame('November 8, 2026', SiteTermsController::formatVersionDate('2026-11-08'));
    $this->expectException(\InvalidArgumentException::class);
    SiteTermsController::formatVersionDate('8 Nov 2026');
  }

  /**
   * The version date changes when the words change, and only then.
   */
  public function testVersionDateMovesWithTheText(): void {
    $this->assertSame(
      [self::TEMPLATE_TEXT_SHA256, self::TERMS_CHANGED_PIN],
      [$this->templateTextHash(), SiteTermsController::TERMS_CHANGED],
      'The terms text or its date changed without the other. If you changed the words, set SiteTermsController::TERMS_CHANGED to today and update both pins here. If you changed only markup, update the hash pin only.',
    );
  }

  /**
   * The register form has the box: required, unticked, above the button.
   */
  public function testRegisterFormHasUntickedRequiredConsent(): void {
    $form = $this->registerForm();
    $box = $form['account']['terms_consent'] ?? NULL;
    $this->assertIsArray($box, 'The consent box sits in the account fieldset.');
    $this->assertSame('checkbox', $box['#type']);
    $this->assertTrue($box['#required']);
    $this->assertEmpty($box['#default_value'], 'The box is never pre-ticked.');
    $this->assertSame(self::CONSENT_LABEL, (string) $box['#title'], 'The build-time title is plain, so the error summary link has no link inside it.');
    $this->assertSame(self::CONSENT_ERROR, (string) $box['#required_error']);
    $this->assertGreaterThan($form['account']['name']['#weight'] ?? 0, $box['#weight'], 'The box comes after the account fields.');
    $this->assertLessThan(1000, $box['#weight'], 'The box comes before Open Social\'s "Have an account already?" line.');
    $this->assertArrayNotHasKey('terms_consent', $form['actions'] ?? [], 'The box is not in the actions; it renders above them.');

    $html = (string) $this->container->get('renderer')->renderRoot($form);
    $xpath = $this->domXpath($html);
    $input = $xpath->query('//input[@type="checkbox"][@name="terms_consent"]')->item(0);
    $this->assertNotNull($input);
    $this->assertFalse($input->hasAttribute('checked'), 'The rendered box is not ticked.');
    $this->assertSame('required', $input->getAttribute('required'));
    $label = $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->item(0);
    $this->assertNotNull($label);
    $this->assertSame(self::CONSENT_LABEL, $this->fold($label->textContent));
    $link = $xpath->query('.//a', $label)->item(0);
    $this->assertNotNull($link, 'The words "site terms" are a link.');
    $this->assertSame('site terms', $this->fold($link->textContent));
    $this->assertMatchesRegularExpression('#/terms$#', $link->getAttribute('href'));

    $this->assertSame(1, $xpath->query('//input[@name="terms_consent"]/following::*[@id="edit-submit"]')->length, 'The box comes before the Create new account button.');
  }

  /**
   * Submitting without the box fails with the approved message.
   */
  public function testSubmitWithoutConsentFails(): void {
    $errors = $this->submitRegister(['mail' => '', 'name' => '']);
    $this->assertArrayHasKey('terms_consent', $errors);
    $this->assertSame(self::CONSENT_ERROR, (string) $errors['terms_consent']);

    $errors = $this->submitRegister(['mail' => '', 'name' => '', 'terms_consent' => 1]);
    $this->assertArrayNotHasKey('terms_consent', $errors, 'A ticked box raises no consent error.');
  }

  /**
   * The consent error survives a validator that clears the form's errors.
   *
   * Open Social's social_user_register_validate() clears every error when
   * the name or email already exists (an empty name matches the anonymous
   * user), then sets its own. A last validator, added after every form alter
   * has run, puts the consent error back, so an unticked box is always named
   * on a failed submit. The live run with social_user is in terms.spec.ts.
   */
  public function testConsentErrorSurvivesClearedErrors(): void {
    $form = $this->registerForm();
    $validate = $form['#validate'] ?? [];
    $this->assertSame([TermsConsent::class, 'validateConsent'], end($validate), 'The consent check is the last form validator.');

    // As after social_user cleared the errors and set its own.
    $form_state = new FormState();
    $form_state->setValue('terms_consent', 0);
    $form_state->setErrorByName('name', 'The entered username already exists or has an incorrect format. Please try again.');
    TermsConsent::validateConsent($form, $form_state);
    $this->assertSame(self::CONSENT_ERROR, (string) ($form_state->getErrors()['terms_consent'] ?? ''));

    // Already set: not added twice.
    TermsConsent::validateConsent($form, $form_state);
    $this->assertCount(2, $form_state->getErrors());

    $ticked = new FormState();
    $ticked->setValue('terms_consent', 1);
    TermsConsent::validateConsent($form, $ticked);
    $this->assertSame([], $ticked->getErrors(), 'A ticked box raises no error.');
  }

  /**
   * On error, the box points at its message through aria-describedby.
   *
   * 23-UI-SPEC section 9 accessibility row: a screen reader hears the label
   * and, on error, the message. socialbase prints the message in a div with
   * no id, so the label's pre-render wraps it in one and links it.
   */
  public function testErrorMessageIsLinkedToTheBox(): void {
    $element = [
      '#id' => 'edit-terms-consent',
      '#errors' => self::CONSENT_ERROR,
      '#attributes' => ['aria-describedby' => 'something-else'],
    ];
    $element = TermsConsent::preRender($element);

    $this->assertSame('something-else edit-terms-consent--error-message', $element['#attributes']['aria-describedby'], 'The error id is added to what was there.');
    $xpath = $this->domXpath((string) $element['#errors']);
    $message = $xpath->query('//*[@id="edit-terms-consent--error-message"]')->item(0);
    $this->assertNotNull($message, 'The message carries the id.');
    $this->assertSame(self::CONSENT_ERROR, $this->fold($message->textContent));

    // Without an error, nothing is added.
    $clean = TermsConsent::preRender(['#id' => 'edit-terms-consent']);
    $this->assertArrayNotHasKey('aria-describedby', $clean['#attributes'] ?? []);
    $this->assertArrayNotHasKey('#errors', $clean);

    // A message with markup in it stays text.
    $hostile = TermsConsent::preRender(['#id' => 'edit-terms-consent', '#errors' => '<script>x</script>']);
    $this->assertStringNotContainsString('<script>', (string) $hostile['#errors']);
  }

  /**
   * A site manager creating an account doesn't agree on someone's behalf.
   */
  public function testAdminCreateFormHasNoConsentBox(): void {
    $role = Role::create(['id' => 'people_admin', 'label' => 'People admin']);
    $role->grantPermission('administer users')->save();
    $admin = User::create(['name' => 'people-admin', 'mail' => 'people-admin@example.invalid', 'status' => 1, 'roles' => ['people_admin']]);
    $admin->save();
    $this->container->get('current_user')->setAccount($admin);

    $form = $this->registerForm();
    $this->assertArrayNotHasKey('terms_consent', $form['account']);
  }

  /**
   * The footer links the terms after "Open source", with the same middot.
   */
  public function testFooterLinksTheTerms(): void {
    $block = $this->container->get('plugin.manager.block')->createInstance('localnodes_footer');
    $build = $block->build();
    $html = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertMatchesRegularExpression(
      '#>Open source</a>\s*<span aria-hidden="true">&middot;</span>\s*<a href="[^"]*/terms">Site terms</a>#',
      $html,
    );
  }

}
