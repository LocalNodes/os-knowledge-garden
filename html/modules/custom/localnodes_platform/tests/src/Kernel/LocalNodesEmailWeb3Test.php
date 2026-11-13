<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\user\Entity\Role;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The wallet line in the organizer-created welcome mail (22.1-07, card 6).
 *
 * Tom (2026-11-06, card 6): the admin-created mail gets the most care, and on
 * sites with Web3 on it carries one optional line, "You can connect a wallet
 * later from your account page." No other mail gets it. Web3 is on when the
 * localnodes_web3 submodule is enabled, so this class adds it to the module
 * list; LocalNodesEmailTest covers Web3 off.
 *
 * @group localnodes_platform
 * @group phase22_1
 */
final class LocalNodesEmailWeb3Test extends LocalNodesEmailKernelTestBase {

  private const LINE = 'You can connect a wallet later from your account page.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'filter', 'field', 'symfony_mailer', 'symfony_mailer_test',
    'social_swiftmail', 'localnodes_platform', 'siwe_login', 'localnodes_web3',
  ];

  /**
   * The organizer-created welcome mail carries the line on a Web3 site.
   */
  public function testAdminCreatedMailHasWalletLine(): void {
    _localnodes_platform_configure_email();
    $this->sendUserMail('register_admin_created');

    $this->assertSubject('Welcome to ' . static::SITE_NAME);
    $this->assertBodyContains(self::LINE);
    // The line sits above the sign-off, not after it.
    $html = (string) $this->email->getHtmlBody();
    $this->assertLessThan(strrpos($html, '-- ' . static::SITE_NAME), strpos($html, self::LINE), 'The wallet line comes before the sign-off.');
  }

  /**
   * The organizer welcome mail also tells the Sign in button way.
   */
  public function testOrganizerWelcomeTellsTheSignInButton(): void {
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager'])->save();
    _localnodes_platform_configure_email();
    putenv('ORGANIZER_EMAIL=organizer@mail.localnodes.xyz');
    putenv('ORGANIZER_NAME=' . static::SITE_NAME);
    try {
      $command = new OrganizerCommands();
      $command->setOutput(new BufferedOutput());
      $this->assertSame(0, $command->organizerWelcome());
    }
    finally {
      putenv('ORGANIZER_EMAIL');
      putenv('ORGANIZER_NAME');
    }
    $this->readMail();
    $html = str_replace('&#039;', "'", (string) $this->email->getHtmlBody());
    $this->assertStringContainsString("You can also use the site's Sign in button with this same email address. We then email you a link that adds that way of signing in to this account.", $html);
  }

  /**
   * No other mail carries it, so it stays a welcome-mail line.
   */
  public function testOtherMailsHaveNoWalletLine(): void {
    _localnodes_platform_configure_email();
    $this->sendReset();

    $this->assertStringNotContainsString(self::LINE, (string) $this->email->getHtmlBody());
  }

}
