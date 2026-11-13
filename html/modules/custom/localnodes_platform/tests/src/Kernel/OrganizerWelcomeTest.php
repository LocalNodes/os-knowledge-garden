<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The organizer welcome mail, sent by drush localnodes:organizer-welcome (23-04).
 *
 * Proves, through the 22.1-07 mail set-up (stub theme, symfony_mailer_test null
 * transport, no real mail): one mail, the approved subject, the LocalNodes
 * wrapper, a one-time sign-in link inside the body, the sitemanager role, an
 * idempotent second run, a failed send that exits non-zero, and a command that
 * prints neither the address nor the sign-in link (T-23-13).
 *
 * The sign-in link is only ever checked for presence. No assertion message,
 * output capture or failure text carries the link or the organizer's address.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class OrganizerWelcomeTest extends LocalNodesEmailKernelTestBase {

  /**
   * The organizer's address: fictional, on a domain the mail filter lets pass.
   */
  private const ORGANIZER_EMAIL = 'jordan.organizer@mail.localnodes.xyz';

  /**
   * The state key the command sets once the mail went out.
   */
  private const SENT_KEY = 'localnodes.install_step.organizer_mail_sent';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Open Social ships this role; the command gives it to the new account.
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager'])->save();
    // A real installed site reserves uid1 for the host before provisioning.
    User::create(['uid' => 1, 'name' => 'host', 'mail' => 'host@mail.localnodes.xyz', 'status' => 1])->save();
    if (function_exists('_localnodes_platform_block_host_account')) {
      _localnodes_platform_block_host_account();
      $this->assertTrue(User::load(1)->isBlocked());
    }
    _localnodes_platform_configure_email();
    putenv('ORGANIZER_EMAIL=' . self::ORGANIZER_EMAIL);
    putenv('ORGANIZER_NAME=' . static::SITE_NAME);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('ORGANIZER_EMAIL');
    putenv('ORGANIZER_NAME');
    parent::tearDown();
  }

  /**
   * Runs the command once.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything the command printed.
   */
  private function runCommand(): array {
    $output = new BufferedOutput();
    $command = new OrganizerCommands();
    $command->setOutput($output);
    $code = $command->organizerWelcome();
    return [$code, $output->fetch()];
  }

  /**
   * Loads the organizer's account, or NULL.
   */
  private function organizerAccount(): ?User {
    $accounts = $this->container->get('entity_type.manager')->getStorage('user')
      ->loadByProperties(['mail' => self::ORGANIZER_EMAIL]);
    return $accounts ? reset($accounts) : NULL;
  }

  /**
   * One mail, in the LocalNodes wrapper, with the approved subject and words.
   */
  public function testSendsOneTemplatedWelcomeMail(): void {
    [$code] = $this->runCommand();
    $this->assertSame(0, $code);

    // readMail() asserts there is exactly one mail.
    $this->readMail();
    $this->assertSubject('Welcome to ' . static::SITE_NAME);
    $recipients = array_map(fn ($address) => $address->getEmail(), $this->email->getTo());
    $this->assertSame([self::ORGANIZER_EMAIL], $recipients, 'The mail goes to the organizer only.');

    $html = (string) $this->email->getHtmlBody();
    $this->assertStringContainsString('<!-- localnodes-email -->', $html);
    // The theme's own wrapper lost to the module's suggestion.
    $this->assertStringNotContainsString('stock-socialbase-wrapper', $html);
    // UI-SPEC "Mail copy": first line, button, heading, steps, guide link.
    foreach ([
      // It says what the site is ready for: the organizer's documents.
      "Your community's site is ready for you to add your documents.",
      'Use the button to sign in. It works once. It opens a page where you choose a password for your next visits.',
      'Sign in</a>',
      'Your first three steps',
      'Sign in.',
      'Open your group.',
      'Upload your first files.',
      'Read the organizer guide',
      '/guide',
    ] as $words) {
      $this->assertStringContainsString($words, str_replace('&#039;', "'", $html), "The mail says: $words");
    }
    // The greeting names the organizer's account.
    $this->assertStringContainsString($this->organizerAccount()->getDisplayName(), $html);
    // Presence only: the one-time sign-in link is in the body.
    $this->assertTrue(str_contains($html, '/user/reset/'), 'The mail body holds a one-time sign-in link.');
  }

  /**
   * The new account is active and a site manager, nothing more.
   */
  public function testCreatesTheOrganizerAsSiteManager(): void {
    $this->runCommand();
    $account = $this->organizerAccount();

    $this->assertNotNull($account, 'The organizer account exists.');
    $this->assertTrue($account->isActive());
    $this->assertSame(['sitemanager'], array_values($account->getRoles(TRUE)));
    $this->assertNotEmpty($this->container->get('state')->get('localnodes.install_step.organizer_account'));
  }

  /**
   * A manual run's account is active, and its uid is kept for activation.
   *
   * 23-35: a manual run sends the mail at install; the uid lets a later call
   * to POST /localnodes/activate find the same account (and send nothing).
   */
  public function testManualRunAccountIsActiveAndItsUidIsKept(): void {
    [$code] = $this->runCommand();
    $this->assertSame(0, $code);
    $this->readMail();

    $account = $this->organizerAccount();
    $this->assertSame(1, (int) $account->get('status')->value);
    $this->assertSame((int) $account->id(), (int) $this->container->get('state')->get('localnodes.organizer_uid'));
  }

  /**
   * The account name comes from ORGANIZER_NAME, with a number on a clash.
   */
  public function testAccountNameFromOrganizerNameWithSuffixOnCollision(): void {
    User::create(['name' => static::SITE_NAME . ' Admin', 'mail' => 'someone.else@mail.localnodes.xyz', 'status' => 1])->save();

    $this->runCommand();
    $this->assertSame(static::SITE_NAME . ' Admin 2', $this->organizerAccount()->getAccountName());
  }

  /**
   * A name with characters Drupal refuses in user names still works.
   */
  public function testUnsafeCharactersInTheNameAreDropped(): void {
    putenv('ORGANIZER_NAME=<b>Repair</b>  & Co ');
    [$code] = $this->runCommand();

    $this->assertSame(0, $code);
    $account = $this->organizerAccount();
    $this->assertNotNull($account);
    $this->assertSame('bRepairb Co Admin', $account->getAccountName());
    $this->assertCount(0, $account->validate(), 'The generated name passes Drupal validation.');
  }

  /**
   * An existing account is reused: no second account, same single mail.
   */
  public function testExistingAccountIsReused(): void {
    $existing = User::create(['name' => 'already-here', 'mail' => self::ORGANIZER_EMAIL, 'status' => 1]);
    $existing->save();

    $this->runCommand();
    $this->readMail();
    $count = $this->container->get('entity_type.manager')->getStorage('user')
      ->getQuery()->accessCheck(FALSE)->condition('mail', self::ORGANIZER_EMAIL)->count()->execute();
    $this->assertSame(1, (int) $count);
    // An account that was already there keeps the roles it had (T-23-14).
    $this->assertFalse($this->organizerAccount()->hasRole('sitemanager'));
  }

  /** @dataProvider privilegedExistingAccounts */
  public function testPrivilegedAccountIsNeverWelcomedAsOrganizer(string $kind): void {
    if ($kind === 'host') {
      $existing = User::load(1);
      $existing->setEmail(self::ORGANIZER_EMAIL)->save();
    }
    else {
      $role_id = $kind === 'administrator' ? 'administrator' : 'custom_administrator';
      Role::create(['id' => $role_id, 'label' => 'Host administrator', 'is_admin' => TRUE])->save();
      $existing = User::create(['name' => 'host-administrator', 'mail' => self::ORGANIZER_EMAIL, 'status' => 1, 'roles' => [$role_id]]);
      $existing->save();
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    // Compare SQL-loaded representations on both sides, including scalar types.
    $before = $storage->loadUnchanged($existing->id())->toArray();
    [$code, $printed] = $this->runCommand();
    $this->assertSame(1, $code);
    $this->assertSame('The organizer account cannot be a host or administrator account.', trim($printed));
    $this->noMail();
    $this->assertSame($before, $storage->loadUnchanged($existing->id())->toArray());
    $this->assertEmpty($this->container->get('state')->get(self::SENT_KEY));
    $this->assertEmpty($this->container->get('state')->get('localnodes.organizer_uid'));
  }

  public static function privilegedExistingAccounts(): array {
    return [['host'], ['administrator'], ['custom administrator']];
  }

  /**
   * After a successful send, a second run says so and sends nothing.
   */
  public function testSecondRunSendsNothing(): void {
    [$firstCode, $first] = $this->runCommand();
    $this->readMail();
    $this->assertSame(0, $firstCode);
    $this->assertSame('Welcome mail sent.', trim($first));
    $this->assertNotEmpty($this->container->get('state')->get(self::SENT_KEY));

    [$secondCode, $second] = $this->runCommand();
    $this->assertSame(0, $secondCode);
    $this->assertSame('Welcome mail already sent.', trim($second));
    // noMail() fails when any mail was captured since the first read.
    $this->noMail();
  }

  /**
   * The command prints neither the address nor the sign-in link.
   */
  public function testPrintsNeitherAddressNorSignInLink(): void {
    [, $first] = $this->runCommand();
    [, $second] = $this->runCommand();
    $this->readMail();

    foreach ([$first, $second] as $printed) {
      $this->assertFalse(str_contains($printed, self::ORGANIZER_EMAIL), 'The output holds no address.');
      $this->assertFalse(str_contains($printed, 'jordan.organizer'), 'The output holds no part of the address.');
      $this->assertFalse(str_contains($printed, '/user/reset/'), 'The output holds no sign-in link.');
      $this->assertFalse(str_contains($printed, 'http'), 'The output holds no link at all.');
    }
  }

  /**
   * A send that does not happen exits non-zero and leaves the marker unset.
   *
   * A reserved test domain is dropped by the module's recipient filter, so the
   * mailer reports that nothing went out, the same result a transport failure
   * gives. The next start retries because the marker stays unset (D-17).
   */
  public function testFailedSendExitsNonZeroAndLeavesNoMarker(): void {
    putenv('ORGANIZER_EMAIL=organizer@example.com');
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertSame('Welcome mail not sent.', trim($printed));
    $this->assertEmpty($this->container->get('state')->get(self::SENT_KEY));
  }

  /**
   * A missing variable stops the command with a message that names it.
   *
   * @dataProvider missingVariableProvider
   */
  public function testMissingVariableExitsNonZero(string $variable): void {
    putenv($variable);
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertStringContainsString($variable, $printed);
    $this->assertNull($this->organizerAccount(), 'Nothing is created without both variables.');
    $this->assertFalse(str_contains($printed, self::ORGANIZER_EMAIL));
  }

  /**
   * Both variables are required.
   */
  public static function missingVariableProvider(): array {
    return [
      'no email' => ['ORGANIZER_EMAIL'],
      'no name' => ['ORGANIZER_NAME'],
    ];
  }

  /**
   * A value that is not an email address stops the command, unprinted.
   */
  public function testInvalidEmailIsRefusedWithoutEchoingIt(): void {
    putenv('ORGANIZER_EMAIL=not-an-address-at-all');
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertStringContainsString('ORGANIZER_EMAIL', $printed);
    $this->assertFalse(str_contains($printed, 'not-an-address-at-all'));
  }

}
