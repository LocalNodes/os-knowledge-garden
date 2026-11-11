<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

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
    'social_swiftmail', 'localnodes_platform', 'localnodes_web3',
  ];

  /**
   * The organizer-created welcome mail carries the line on a Web3 site.
   */
  public function testAdminCreatedMailHasWalletLine(): void {
    _localnodes_platform_configure_email();
    $this->sendUserMail('register_admin_created');

    $this->assertSubject('Welcome to ' . static::SITE_NAME);
    $this->assertBodyContains(self::LINE);
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
