<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;


/**
 * Every mail goes out in the one LocalNodes template (22.1-07, D-11).
 *
 * Proves, for the password-reset mail: the subject Tom approved, the
 * LocalNodes wrapper (marker comment) outranking a theme's own email.html.twig,
 * the community's name, and no Open Social link, slogan or sub-slogan.
 *
 * Module list (the spike in 22.1-07 Task 1): system, user, filter, field,
 * symfony_mailer, symfony_mailer_test, social_swiftmail and
 * localnodes_platform. localnodes_platform's own dependencies (the AI stack)
 * are not enabled; none of its mail code needs them, and a Kernel test does
 * not check module dependencies. social_swiftmail runs its real preprocess, so
 * the branding flag is exercised. The default theme is a stub theme that
 * ships a plain email.html.twig, the way socialbase does. The real socialblue
 * cannot boot here: socialbase's base theme (bootstrap) registers a shutdown
 * function that needs the container after the test has torn it down. The real
 * theme is covered on the running site by scripts/check_branded_mail.sh.
 *
 * No mail is sent: symfony_mailer_test forces the null transport and stores
 * each message in state. Recipients are on a non-reserved domain, because
 * localnodes_platform drops mail to example.com and friends.
 *
 * @group localnodes_platform
 * @group phase22_1
 */
final class LocalNodesEmailTest extends LocalNodesEmailKernelTestBase {

  /**
   * The helper sets the branding flag and the approved subjects, once.
   */
  public function testInstallHelperSetsFlagAndSubjects(): void {
    _localnodes_platform_configure_email();

    $this->assertTrue($this->config('social_swiftmail.settings')->get('remove_open_social_branding'));
    $mail = $this->config('user.mail');
    $this->assertSame('Reset your password for [site:name]', $mail->get('password_reset.subject'));
    $this->assertSame('Welcome to [site:name]', $mail->get('register_admin_created.subject'));
    $this->assertSame('Welcome to [site:name]', $mail->get('register_no_approval_required.subject'));

    // Idempotent: a second call writes nothing, so the config object stays
    // exactly as it was.
    $before = $this->config('user.mail')->getRawData();
    _localnodes_platform_configure_email();
    $this->assertSame($before, $this->config('user.mail')->getRawData());
  }

  /**
   * The reset mail renders in the LocalNodes wrapper with the community name.
   */
  public function testResetMailUsesLocalNodesTemplate(): void {
    _localnodes_platform_configure_email();
    $this->sendReset();

    $this->assertSubject('Reset your password for ' . static::SITE_NAME);
    $html = (string) $this->email->getHtmlBody();
    $this->assertStringContainsString('<!-- localnodes-email -->', $html);
    $this->assertStringContainsString(static::SITE_NAME, $html);
    // The theme's own wrapper lost to the module's suggestion.
    $this->assertStringNotContainsString('stock-socialbase-wrapper', $html);
    // Open Social's footer, slogan and sub-slogan are gone.
    $this->assertStringNotContainsString('getopensocial.com', $html);
    $this->assertStringNotContainsString('Open Social', $html);
    $this->assertStringNotContainsString('Create digital spaces', $html);
    $this->assertStringNotContainsString('MadeToShare', $html);
  }

  /**
   * Colours come from the token file's email map, not from socialbase.
   */
  public function testLookComesFromTokens(): void {
    _localnodes_platform_configure_email();
    $this->sendReset();
    $html = strtolower((string) $this->email->getHtmlBody());

    $ln = $this->emailTokens();
    foreach (['button', 'button-text', 'link', 'footer-text', 'footer-bg', 'body', 'card', 'text'] as $role) {
      $this->assertStringContainsString($ln[$role], $html, "The $role colour ({$ln[$role]}) is in the mail.");
    }
    // None of socialbase's own button, footer or canvas colours.
    foreach (['#784288', '#adadad', '#34495e', '#f6f6f6', '#4d4d4d'] as $stock) { // raw-color-allow: test input, socialbase's stock colours the mail must not contain
      $this->assertStringNotContainsString($stock, $html, "Socialbase's $stock is gone.");
    }
    // Card 5: the mail carries no images, so it reads the same with images off.
    $this->assertStringNotContainsString('<img', $html);
  }

  /**
   * The footer reads the way Tom approved, with the community's name.
   */
  public function testFooterWording(): void {
    _localnodes_platform_configure_email();
    $this->sendReset();
    $this->assertBodyContains('Sent by ' . static::SITE_NAME . ' · a community on LocalNodes');
  }

  /**
   * A site name that holds markup is shown as text (T-22.1-19).
   */
  public function testSiteNameIsEscaped(): void {
    $this->config('system.site')->set('name', '<script>alert(1)</script>Co-op')->save();
    _localnodes_platform_configure_email();
    $this->sendReset();
    $html = (string) $this->email->getHtmlBody();

    $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Co-op', $html);
  }

  /**
   * With Web3 off, the organizer-created mail has no wallet line.
   */
  public function testNoWalletLineWhenWeb3IsOff(): void {
    _localnodes_platform_configure_email();
    $this->sendUserMail('register_admin_created');

    $this->assertSubject('Welcome to ' . static::SITE_NAME);
    $this->assertStringNotContainsString('connect a wallet', (string) $this->email->getHtmlBody());
  }

  /**
   * The nine account mails Open Social sends all use the one template.
   *
   * @dataProvider userMailProvider
   */
  public function testEveryAccountMailUsesTheTemplate(string $op): void {
    _localnodes_platform_configure_email();
    $this->sendUserMail($op);

    $html = (string) $this->email->getHtmlBody();
    $this->assertStringContainsString('<!-- localnodes-email -->', $html, "$op uses the wrapper.");
    $this->assertStringContainsString(static::SITE_NAME, $html, "$op names the community.");
    $this->assertStringContainsString('Sent by ' . static::SITE_NAME . ' · a community on LocalNodes', $html);
    $this->assertStringNotContainsString('stock-socialbase-wrapper', $html);
    foreach (['getopensocial.com', 'Open Social', 'Create digital spaces', 'MadeToShare'] as $gone) {
      $this->assertStringNotContainsString($gone, $html, "$op has no '$gone'.");
    }
  }

  /**
   * The user mail keys (core's user.mail, as social_swiftmail builds them).
   *
   * @return array<string, array{string}>
   *   Mail key by name.
   */
  public static function userMailProvider(): array {
    $keys = [
      'cancel_confirm', 'password_reset', 'register_admin_created',
      'register_no_approval_required', 'register_pending_approval',
      'register_pending_approval_admin', 'status_activated', 'status_blocked',
      'status_canceled',
    ];
    return array_combine($keys, array_map(static fn (string $key): array => [$key], $keys));
  }

  /**
   * The subjects Tom approved are in place for the reset and both welcomes.
   */
  public function testApprovedSubjectsReachTheRecipient(): void {
    _localnodes_platform_configure_email();
    foreach (['password_reset' => 'Reset your password for', 'register_admin_created' => 'Welcome to', 'register_no_approval_required' => 'Welcome to'] as $op => $start) {
      $this->sendUserMail($op);
      $this->assertSubject($start . ' ' . static::SITE_NAME);
    }
  }

}
