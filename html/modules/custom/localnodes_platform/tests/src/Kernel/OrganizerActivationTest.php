<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\localnodes_platform\OrganizerActivation;
use Drupal\symfony_mailer\EmailFactoryInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Reference;

/**
 * OrganizerActivation::activate(): the hand-over after the first payment.
 *
 * Phase 23 plan 35 (Tom's billing rule B-1). Drives the service and the
 * organizer commands directly, through the 22.1-07 mail set-up (stub theme,
 * symfony_mailer_test null transport): the not-ready paths change nothing, a
 * failed mail is retried on the next call and only then marked, a site whose
 * mail went out at install (a manual run) gets no second mail, and a site
 * already handed over changes nothing.
 *
 * SITE_NAME is not set, so the commands make no first group here (the group
 * module is not enabled); CommunitySeedTest covers the group and the seed with
 * a blocked organizer.
 *
 * 23-43 Task 5: two activate calls at once send one welcome mail. The kernel
 * test container normally swaps the lock service for NullLockBackend, which
 * always says yes; register() puts the real DatabaseLockBackend back, so the
 * service's '@lock' wiring is what is under test. A second DatabaseLockBackend
 * has its own lock id and stands in for another PHP process.
 *
 * @group localnodes_platform
 * @group phase23
 */
final class OrganizerActivationTest extends LocalNodesEmailKernelTestBase {

  /**
   * The Drupal lock activate() takes (a literal here, so the test reads the
   * name the service really uses).
   */
  private const LOCK_NAME = 'localnodes_organizer_activate';

  /**
   * The organizer's address: fictional, on a domain the mail filter lets pass.
   */
  private const ORGANIZER_EMAIL = 'sam.activation@mail.localnodes.xyz';

  /**
   * An address the module's recipient filter drops (a reserved test domain).
   */
  private const DROPPED_EMAIL = 'sam.activation@example.com';

  /**
   * {@inheritdoc}
   *
   * The real database lock instead of the kernel test's NullLockBackend.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('lock', DatabaseLockBackend::class)
      ->addArgument(new Reference('database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Role::create(['id' => 'sitemanager', 'label' => 'Site manager'])->save();
    // Provisioning runs after the installed site has reserved uid1 for its host.
    User::create(['uid' => 1, 'name' => 'host', 'mail' => 'host@mail.localnodes.xyz', 'status' => 1])->save();
    if (function_exists('_localnodes_platform_block_host_account')) {
      _localnodes_platform_block_host_account();
      $this->assertTrue(User::load(1)->isBlocked());
    }
    _localnodes_platform_configure_email();
    putenv('ORGANIZER_EMAIL=' . self::ORGANIZER_EMAIL);
    putenv('ORGANIZER_NAME=Sam');
    putenv('SITE_NAME');
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
   * Runs one organizer command.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything the command printed.
   */
  private function runCommand(string $method): array {
    $output = new BufferedOutput();
    $command = new OrganizerCommands();
    $command->setOutput($output);
    $code = $command->$method();
    return [$code, $output->fetch()];
  }

  /**
   * The activation service.
   */
  private function activation(): OrganizerActivation {
    return $this->container->get('localnodes_platform.organizer_activation');
  }

  /**
   * The organizer's account, read fresh, or NULL.
   */
  private function organizer(string $email = self::ORGANIZER_EMAIL): ?UserInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache();
    $found = $storage->loadByProperties(['mail' => $email]);
    return $found ? reset($found) : NULL;
  }

  /**
   * Marks the install finished, as the entrypoint does before "ready".
   */
  private function finishInstall(): void {
    $this->container->get('state')->set('localnodes.install_complete', '2026-11-08T12:00:00Z');
  }

  /**
   * A state value.
   */
  private function stateValue(string $key): mixed {
    return $this->container->get('state')->get($key);
  }

  /**
   * Before the install marker, activate() answers not_ready and does nothing.
   */
  public function testNotReadyBeforeTheInstallFinished(): void {
    $this->runCommand('organizerSetup');

    $this->assertSame('not_ready', $this->activation()->activate());
    $this->assertTrue($this->organizer()->isBlocked(), 'The organizer stays blocked.');
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_ACTIVATED));
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_MAIL_SENT));
    $this->noMail();
  }

  /**
   * With no organizer uid (or one that names no account), not_ready.
   */
  public function testNotReadyWithoutTheOrganizer(): void {
    $this->finishInstall();
    $this->assertSame('not_ready', $this->activation()->activate());

    $this->container->get('state')->set(OrganizerActivation::STATE_ORGANIZER_UID, 987654);
    $this->assertSame('not_ready', $this->activation()->activate());
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_ACTIVATED));
    $this->noMail();
  }

  /**
   * The happy path, then a second call: 'already', nothing changes.
   */
  public function testActivatesOnceThenAnswersAlready(): void {
    $this->runCommand('organizerSetup');
    $this->finishInstall();

    $this->assertSame('activated', $this->activation()->activate());
    $this->assertTrue($this->organizer()->isActive());
    $this->readMail();
    $this->assertSubject('Welcome to ' . static::SITE_NAME);
    $activated_at = $this->stateValue(OrganizerActivation::STATE_ACTIVATED);
    $this->assertNotEmpty($activated_at);

    $this->assertTrue($this->activation()->isActivated());
    $this->assertSame('already', $this->activation()->activate());
    $this->noMail();
    $this->assertSame($activated_at, $this->stateValue(OrganizerActivation::STATE_ACTIVATED));
  }

  /**
   * A failed send: mail_failed, the organizer active, not marked; the next
   * call sends exactly one mail and answers activated.
   */
  public function testFailedMailIsRetriedOnTheNextCall(): void {
    // The account is made at an address the recipient filter drops, so the
    // mailer reports that nothing went out.
    putenv('ORGANIZER_EMAIL=' . self::DROPPED_EMAIL);
    $this->runCommand('organizerSetup');
    $this->finishInstall();

    $this->assertSame('mail_failed', $this->activation()->activate());
    $organizer = $this->organizer(self::DROPPED_EMAIL);
    $this->assertTrue($organizer->isActive(), 'The organizer stays active after a failed mail.');
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_ACTIVATED));
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_MAIL_SENT));
    // Whatever the mailer recorded for the dropped send is not a mail out.
    $this->container->get('symfony_mailer.test')->getEmails();

    // The address is fixed; the retry reads the account at send time.
    $organizer->setEmail(self::ORGANIZER_EMAIL)->save();
    $this->assertSame('activated', $this->activation()->activate());
    $this->readMail();
    $recipients = array_map(fn ($address) => $address->getEmail(), $this->email->getTo());
    $this->assertSame([self::ORGANIZER_EMAIL], $recipients);
    $this->assertNotEmpty($this->stateValue(OrganizerActivation::STATE_ACTIVATED));
    $this->assertNotEmpty($this->stateValue(OrganizerActivation::STATE_MAIL_SENT));

    $this->assertSame('already', $this->activation()->activate());
    $this->noMail();
  }

  /**
   * After a manual organizer-welcome run (mail sent at install), activate()
   * sends no second mail and answers activated.
   */
  public function testManualRunGetsNoSecondMail(): void {
    [$code] = $this->runCommand('organizerWelcome');
    $this->assertSame(0, $code);
    $this->readMail();
    $this->finishInstall();
    $organizer = $this->organizer();
    $this->assertTrue($organizer->isActive());
    $changed = $organizer->getChangedTime();

    $this->assertSame('activated', $this->activation()->activate());
    $this->noMail();
    $this->assertSame($changed, $this->organizer()->getChangedTime(), 'The active account is not saved again.');
    $this->assertNotEmpty($this->stateValue(OrganizerActivation::STATE_ACTIVATED));
  }

  /**
   * organizer-setup twice: one account, still blocked, no mail, no address
   * printed; the uid and the step marker are kept.
   */
  public function testSetupTwiceKeepsOneBlockedAccountAndSendsNoMail(): void {
    [$first_code, $first] = $this->runCommand('organizerSetup');
    [$second_code, $second] = $this->runCommand('organizerSetup');

    $this->assertSame([0, 0], [$first_code, $second_code]);
    $count = $this->container->get('entity_type.manager')->getStorage('user')
      ->getQuery()->accessCheck(FALSE)->condition('mail', self::ORGANIZER_EMAIL)->count()->execute();
    $this->assertSame(1, (int) $count);
    $organizer = $this->organizer();
    $this->assertTrue($organizer->isBlocked());
    $this->assertSame(['sitemanager'], array_values($organizer->getRoles(TRUE)));
    $this->assertSame((int) $organizer->id(), (int) $this->stateValue(OrganizerActivation::STATE_ORGANIZER_UID));
    $this->assertNotEmpty($this->stateValue('localnodes.install_step.organizer_setup'));
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_MAIL_SENT));
    $this->noMail();
    foreach ([$first, $second] as $printed) {
      $this->assertStringContainsString('Organizer account ready (blocked until the first payment).', $printed);
      $this->assertFalse(str_contains($printed, 'sam.activation'), 'The output holds no part of the address.');
      $this->assertFalse(str_contains($printed, 'http'), 'The output holds no link.');
    }
  }

  /**
   * A missing or unusable variable stops organizer-setup: nothing is made,
   * the step marker stays unset, and only the variable's name is printed.
   */
  public function testSetupRefusesMissingVariables(): void {
    putenv('ORGANIZER_NAME');
    [$code, $printed] = $this->runCommand('organizerSetup');
    $this->assertSame(1, $code);
    $this->assertStringContainsString('ORGANIZER_NAME', $printed);

    putenv('ORGANIZER_NAME=Sam');
    putenv('ORGANIZER_EMAIL=not-an-address-at-all');
    [$code, $printed] = $this->runCommand('organizerSetup');
    $this->assertSame(1, $code);
    $this->assertFalse(str_contains($printed, 'not-an-address-at-all'));

    $this->assertNull($this->organizer());
    $this->assertEmpty($this->stateValue('localnodes.install_step.organizer_setup'));
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_ORGANIZER_UID));
  }


  /**
   * An activation service built by hand, with any part swapped for a double.
   *
   * It replaces the container's service, because the module's hook that
   * skips core's "account activated" notice asks the service whether it is
   * unblocking the organizer right now.
   */
  private function activationWith(?LockBackendInterface $lock = NULL, ?EmailFactoryInterface $mail = NULL, ?StateInterface $state = NULL): OrganizerActivation {
    $activation = new OrganizerActivation(
      $state ?? $this->container->get('state'),
      $this->container->get('entity_type.manager'),
      $mail ?? $this->container->get('email_factory'),
      $this->container->get('logger.factory'),
      $lock ?? $this->container->get('lock'),
    );
    $this->container->set('localnodes_platform.organizer_activation', $activation);
    return $activation;
  }

  /**
   * Writes a state value behind the State service's back, as another PHP
   * process would: only a resetCache() and a fresh read can see it.
   */
  private function writeStateElsewhere(string $key, string $value): void {
    $this->container->get('keyvalue')->get('state')->set($key, $value);
  }

  /**
   * A lock double: acquire() answers from $answers in turn (the last one
   * repeats) and runs $onFirstAcquire once, before its first answer.
   *
   * @param bool[] $answers
   *   What each acquire() call returns.
   * @param callable|null $onFirstAcquire
   *   Runs during the first acquire() (another process finishing meanwhile).
   */
  private function lockDouble(array $answers, ?callable $onFirstAcquire = NULL): LockBackendInterface {
    return new class($answers, $onFirstAcquire) implements LockBackendInterface {

      /**
       * Every acquire() call: [name, timeout].
       */
      public array $acquired = [];

      /**
       * Every release() call: the name.
       */
      public array $released = [];

      public function __construct(private array $answers, private $onFirstAcquire) {}

      public function acquire($name, $timeout = 30.0) {
        $this->acquired[] = [$name, $timeout];
        if (count($this->acquired) === 1 && $this->onFirstAcquire) {
          ($this->onFirstAcquire)();
        }
        return count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
      }

      public function lockMayBeAvailable($name) {
        return TRUE;
      }

      public function wait($name, $delay = 30) {
        return FALSE;
      }

      public function release($name) {
        $this->released[] = $name;
      }

      public function releaseAll($lockId = NULL) {}

      public function getLockId() {
        return 'double';
      }

    };
  }

  /**
   * 23-43 Task 5: while another owner (another PHP process) holds the lock,
   * activate() answers busy and changes nothing; after the release one call
   * sends exactly one mail.
   */
  public function testBusyWhileAnotherOwnerHoldsTheLock(): void {
    $this->runCommand('organizerSetup');
    $this->finishInstall();
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->acquire(self::LOCK_NAME, 180), 'The other owner takes the lock.');

    $this->assertSame('busy', $this->activation()->activate());
    $this->assertTrue($this->organizer()->isBlocked(), 'busy leaves the organizer blocked.');
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_ACTIVATED));
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_MAIL_SENT));
    $this->noMail();

    $other->release(self::LOCK_NAME);
    $this->assertSame('activated', $this->activation()->activate());
    $this->assertTrue($this->organizer()->isActive());
    $this->readMail();
    $this->assertSubject('Welcome to ' . static::SITE_NAME);
    $this->assertSame('already', $this->activation()->activate());
    $this->noMail();
    // The service let go of its lock: another owner can take it at once.
    $this->assertTrue($other->acquire(self::LOCK_NAME, 1), 'activate() released the lock.');
    $other->release(self::LOCK_NAME);
  }

  /**
   * 23-43 Task 5, review round 1 (Codex MEDIUM 8): a second activate() in the
   * same process while the first is sending the mail answers busy from the
   * re-entry guard (the database lock would renew for its own backend, not
   * refuse), and exactly one welcome mail exists at the end.
   */
  public function testReentryWhileTheMailIsSendingAnswersBusy(): void {
    $this->runCommand('organizerSetup');
    $this->finishInstall();

    // What the call made during the send answered, and the service it calls.
    $box = new \stdClass();
    $box->inner = [];
    $box->activation = NULL;
    // A mail collector that calls activate() again from inside the first send.
    $collector = new class($this->container->get('email_factory'), $box) implements EmailFactoryInterface {

      public function __construct(private EmailFactoryInterface $real, private \stdClass $box) {}

      public function newTypedEmail(string $type, string $sub_type, ...$params) {
        $email = $this->real->newTypedEmail($type, $sub_type, ...$params);
        return new class($email, $this->box) {

          public function __construct(private $email, private \stdClass $box) {}

          public function send() {
            // Once only: a second send must not call in again.
            if ($this->box->inner === []) {
              $this->box->inner[] = 'sending';
              $this->box->inner = [$this->box->activation->activate()];
            }
            return $this->email->send();
          }

        };
      }

      public function sendTypedEmail(string $type, string $sub_type, ...$params) {
        return $this->newTypedEmail($type, $sub_type, ...$params)->send();
      }

      public function newEntityEmail($entity, string $sub_type, ...$params) {
        return $this->real->newEntityEmail($entity, $sub_type, ...$params);
      }

      public function sendEntityEmail($entity, string $sub_type, ...$params) {
        return $this->real->sendEntityEmail($entity, $sub_type, ...$params);
      }

    };
    $activation = $this->activationWith(NULL, $collector);
    $box->activation = $activation;

    $this->assertSame('activated', $activation->activate());
    // readMail() asserts exactly one mail went out (checked first, so a missing
    // guard shows up as the second mail it sends).
    $this->readMail();
    $this->assertSubject('Welcome to ' . static::SITE_NAME);
    $this->assertSame(['busy'], $box->inner, 'The call made during the send answered busy.');
    $this->assertNotEmpty($this->stateValue(OrganizerActivation::STATE_ACTIVATED));
    // The guard is cleared after the call: the next one answers already.
    $this->assertSame('already', $activation->activate());
    $this->noMail();
  }

  /**
   * 23-43 Task 5: the markers are read again after the lock is taken, from a
   * reset state cache. Another process that finished the hand-over while this
   * call waited (its writes are invisible to this request's state cache) is
   * seen, so no second mail goes out; the lock is released afterwards.
   */
  public function testMarkersAreReadAgainAfterTheLock(): void {
    $this->runCommand('organizerSetup');
    $this->finishInstall();
    $lock = $this->lockDouble([TRUE], function () {
      $this->writeStateElsewhere(OrganizerActivation::STATE_ACTIVATED, '2026-11-09T10:00:00Z');
    });

    $this->assertSame('already', $this->activationWith($lock)->activate());
    $this->assertTrue($this->organizer()->isBlocked(), 'Nothing is unblocked here: the other process did the hand-over.');
    $this->noMail();
    $this->assertSame(self::LOCK_NAME, $lock->acquired[0][0]);
    $this->assertSame([self::LOCK_NAME], $lock->released, 'The lock is released.');

    // The same with only the mail marker written meanwhile (a manual-run mail
    // or another call that sent it): active, marked, no second mail.
    $this->container->get('state')->delete(OrganizerActivation::STATE_ACTIVATED);
    $this->container->get('state')->resetCache();
    $lock = $this->lockDouble([TRUE], function () {
      $this->writeStateElsewhere(OrganizerActivation::STATE_MAIL_SENT, '2026-11-09T10:00:00Z');
    });
    $this->assertSame('activated', $this->activationWith($lock)->activate());
    $this->noMail();

    // And the install marker, first missing in this request's cache, then
    // written by the entrypoint while this call waited: it is read again too.
    $this->container->get('state')->deleteMultiple([
      OrganizerActivation::STATE_ACTIVATED,
      OrganizerActivation::STATE_MAIL_SENT,
      OrganizerActivation::STATE_INSTALL_COMPLETE,
    ]);
    $this->container->get('state')->resetCache();
    $state = $this->container->get('state');
    $this->assertNull($state->get(OrganizerActivation::STATE_INSTALL_COMPLETE), 'This request has cached the missing marker.');
    $lock = $this->lockDouble([TRUE], function () {
      $this->writeStateElsewhere(OrganizerActivation::STATE_INSTALL_COMPLETE, '2026-11-09T10:00:00Z');
    });
    $this->assertSame('activated', $this->activationWith($lock)->activate());
    $this->readMail();
  }

  /**
   * 23-43 Task 5: the lease is renewed right before the send; a lease lost by
   * then answers busy, sends no mail and sets no marker (the account is
   * active; the holder of the lock, or the next call, sends the mail).
   */
  public function testLostLeaseBeforeTheSendAnswersBusy(): void {
    $this->runCommand('organizerSetup');
    $this->finishInstall();
    $lock = $this->lockDouble([TRUE, FALSE]);

    $this->assertSame('busy', $this->activationWith($lock)->activate());
    $this->noMail();
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_MAIL_SENT));
    $this->assertEmpty($this->stateValue(OrganizerActivation::STATE_ACTIVATED));
    $this->assertSame([[self::LOCK_NAME, 180.0], [self::LOCK_NAME, 180.0]], array_map(fn ($c) => [$c[0], (float) $c[1]], $lock->acquired), 'Taken with a 180 s lease, then renewed with the same lease.');

    // The next call sends the one mail.
    $this->assertSame('activated', $this->activationWith($this->lockDouble([TRUE]))->activate());
    $this->readMail();
  }

  /**
   * 23-43 Task 5 (Argon's review note): a StateInterface that is not core's
   * State still works. On Drupal 10.6 the interface declares resetCache(), so
   * every double has it; the method_exists() guard is kept for older cores.
   */
  public function testWorksWithAStateDouble(): void {
    $this->runCommand('organizerSetup');
    $uid = (int) $this->stateValue(OrganizerActivation::STATE_ORGANIZER_UID);
    $state = new class() implements StateInterface {

      public array $values = [];

      public function get($key, $default = NULL) {
        return $this->values[$key] ?? $default;
      }

      public function getMultiple(array $keys) {
        return array_intersect_key($this->values, array_flip($keys));
      }

      public function set($key, $value) {
        $this->values[$key] = $value;
      }

      public function setMultiple(array $data) {
        $this->values = $data + $this->values;
      }

      public function delete($key) {
        unset($this->values[$key]);
      }

      public function deleteMultiple(array $keys) {
        $this->values = array_diff_key($this->values, array_flip($keys));
      }

      public function resetCache() {}

    };
    $state->set(OrganizerActivation::STATE_ORGANIZER_UID, $uid);
    $state->set(OrganizerActivation::STATE_INSTALL_COMPLETE, '2026-11-09T10:00:00Z');

    $this->assertSame('activated', $this->activationWith(NULL, NULL, $state)->activate());
    $this->readMail();
    $this->assertNotEmpty($state->get(OrganizerActivation::STATE_ACTIVATED));
  }

}
