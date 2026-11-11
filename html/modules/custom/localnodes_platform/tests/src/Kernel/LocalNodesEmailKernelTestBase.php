<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\symfony_mailer_test\MailerTestTrait;
use Drupal\user\Entity\User;

/**
 * Shared set-up for the LocalNodes email Kernel tests (22.1-07).
 *
 * See LocalNodesEmailTest for what the module list is and why.
 */
abstract class LocalNodesEmailKernelTestBase extends KernelTestBase {

  use MailerTestTrait;

  /**
   * The community's name in these tests.
   */
  protected const SITE_NAME = 'Repair Collective';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'filter', 'field', 'symfony_mailer', 'symfony_mailer_test',
    'social_swiftmail', 'localnodes_platform',
  ];

  /**
   * {@inheritdoc}
   *
   * The stub theme has no config schema for the bootstrap settings the real
   * themes carry; schema checking adds nothing to what is proven here.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'filter', 'symfony_mailer']);
    $this->installEntitySchema('user');
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    $this->config('system.site')
      ->set('name', self::SITE_NAME)
      ->set('mail', 'noreply@localnodes.xyz')
      ->save();
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
  }

  /**
   * Sends one account mail to a fictional member and reads it.
   *
   * @param string $op
   *   The user mail key, such as password_reset or register_admin_created.
   */
  protected function sendUserMail(string $op): void {
    static $count = 0;
    $count++;
    $account = User::create([
      'name' => 'seed-member-' . $count,
      'mail' => 'seed-member-' . $count . '@mail.localnodes.xyz',
      'status' => 1,
    ]);
    $account->save();
    // The mail manager, not _user_mail_notify(): that one skips the status
    // mails that are switched off in user.settings, and every family is wanted.
    $this->container->get('plugin.manager.mail')->mail('user', $op, $account->getEmail(), 'en', ['account' => $account], 'noreply@localnodes.xyz');
    $this->readMail();
  }

  /**
   * Sends the password-reset mail to a fictional member and reads it.
   */
  protected function sendReset(): void {
    $this->sendUserMail('password_reset');
  }

  /**
   * The email colour roles from the generated token file.
   *
   * @return array<string, string>
   *   Role name to hex.
   */
  protected function emailTokens(): array {
    $path = $this->container->get('extension.list.module')->getPath('localnodes_platform') . '/tokens/ln-tokens.flat.json';
    $tokens = json_decode((string) file_get_contents($path), TRUE);
    return $tokens['email'];
  }

}
