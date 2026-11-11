<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\symfony_mailer\EmailFactoryInterface;
use Drupal\user\UserInterface;

/**
 * Hands a sign-up site over to its organizer after the first payment (23-35).
 *
 * Tom's billing rule B-1: a sign-up site is installed with its organizer's
 * account blocked (drush localnodes:organizer-setup) and reports "ready". The
 * sign-up app charges the saved card and then calls POST /localnodes/activate,
 * which calls activate() here. It unblocks the account, sends the 23-04
 * welcome mail with the one-time sign-in link, and marks the site activated.
 * A site that is never paid never has a usable organizer login.
 *
 * Order: unblock, then mail, then the activated marker. The sign-in link only
 * works for an active account, so the account is unblocked first. A failed
 * mail leaves the account active and the marker unset, so the next call only
 * sends the mail. The mail keeps 23-04's organizer_mail_sent marker, so a
 * manual-run mail and an activation mail can never both go out.
 *
 * One welcome mail under overlapping calls (23-43 Task 5, T-23-202):
 * - Across PHP processes, the work runs under the Drupal lock
 *   localnodes_organizer_activate with a lease of LOCK_LEASE (180 s). A call
 *   that cannot take it answers 'busy' at once and changes nothing; the
 *   sign-up app tries again later. After taking it, the state cache is reset
 *   and every marker (install complete, activated, mail sent) is read again,
 *   so a call that waited sees what the other one finished.
 * - In the same process, a re-entry guard: the database lock renews a lock its
 *   own backend already holds instead of refusing it, so the lock alone would
 *   let a nested call (from inside the mail send, say) send a second mail. A
 *   call made while another one runs on this object answers 'busy'.
 * - The lease and the mail send. The site's transport (Resend over smtps://,
 *   a symfony/mailer 6.4 dsn transport) has no timeout of its own and its DSN
 *   cannot set one; its socket uses PHP's default_socket_timeout, 60 s in the
 *   image (not overridden), and that bound applies to each socket step
 *   (connect, each SMTP reply), not to the whole send. So the lease is renewed
 *   right before the send, giving the send a full 180 s, three times the
 *   per-step bound. A lease lost by then answers 'busy' with no mail.
 *
 * What this does not cover (a known residual, Codex's SMTP window): a crash
 * between the mail send and the organizer_mail_sent write, or a send slower
 * than the renewed lease, can still let a later call send a second mail.
 *
 * Nothing here logs or returns the address, the uid's mail or the link
 * (T-23-13, T-23-153): the log line is fixed.
 */
class OrganizerActivation {

  /**
   * Set (UTC time) once the organizer is active and the mail went out.
   */
  public const STATE_ACTIVATED = 'localnodes.organizer_activated';

  /**
   * The organizer's uid, written by localnodes:organizer-setup or -welcome.
   */
  public const STATE_ORGANIZER_UID = 'localnodes.organizer_uid';

  /**
   * 23-04's marker: the welcome mail went out.
   */
  public const STATE_MAIL_SENT = 'localnodes.install_step.organizer_mail_sent';

  /**
   * The entrypoint's marker: every post-install step has run.
   */
  public const STATE_INSTALL_COMPLETE = 'localnodes.install_complete';

  /**
   * The Drupal lock every activate() call takes.
   */
  public const LOCK_NAME = 'localnodes_organizer_activate';

  /**
   * The lock's lease in seconds: longer than the unblock plus a mail send.
   */
  public const LOCK_LEASE = 180.0;

  /**
   * TRUE only while activate() saves the unblocked account.
   *
   * Saving a blocked account as active makes core send its "account
   * activated" mail (User::postSave(), and symfony_mailer forces that notice
   * on), which holds a second sign-in link. The welcome mail is the one mail
   * of the hand-over, so localnodes_platform_mailer_user__status_activated_build()
   * skips that notice while this is set.
   */
  protected bool $unblocking = FALSE;

  /**
   * TRUE while activate() runs on this object: the same-process guard.
   */
  protected bool $activating = FALSE;

  public function __construct(
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?EmailFactoryInterface $emailFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Whether the site has been handed over.
   */
  public function isActivated(): bool {
    return (bool) $this->state->get(self::STATE_ACTIVATED);
  }

  /**
   * Whether activate() is saving the unblocked account right now.
   */
  public function isUnblocking(): bool {
    return $this->unblocking;
  }

  /**
   * Unblocks the organizer and sends the welcome mail, once.
   *
   * @return string
   *   'activated' (done now), 'already' (done before; nothing changed),
   *   'not_ready' (the install has not finished or the organizer is missing;
   *   nothing changed), 'mail_failed' (the account is active, the mail did
   *   not go out, the next call retries the mail only) or 'busy' (another
   *   activate() holds the lock, in this process or another; nothing changed
   *   by this call, try again later).
   */
  public function activate(): string {
    if ($this->activating) {
      return 'busy';
    }
    // The cheap read first: a site handed over long ago takes no lock.
    if ($this->isActivated()) {
      return 'already';
    }
    $this->activating = TRUE;
    try {
      if (!$this->lock->acquire(self::LOCK_NAME, self::LOCK_LEASE)) {
        return 'busy';
      }
      try {
        return $this->activateLocked();
      }
      finally {
        $this->lock->release(self::LOCK_NAME);
      }
    }
    finally {
      $this->activating = FALSE;
    }
  }

  /**
   * The hand-over itself, under the lock, from markers read afresh.
   */
  protected function activateLocked(): string {
    // Another process may have written the markers while this one waited;
    // core's State keeps what this request read before. StateInterface has
    // declared resetCache() since Drupal 10.3; the check keeps older cores
    // and other implementations working.
    if (method_exists($this->state, 'resetCache')) {
      $this->state->resetCache();
    }
    if ($this->isActivated()) {
      return 'already';
    }
    if (!$this->state->get(self::STATE_INSTALL_COMPLETE)) {
      return 'not_ready';
    }
    $uid = (int) $this->state->get(self::STATE_ORGANIZER_UID, 0);
    $account = $uid > 0 ? $this->entityTypeManager->getStorage('user')->load($uid) : NULL;
    if (!$account instanceof UserInterface) {
      return 'not_ready';
    }

    if ($account->isBlocked()) {
      $this->unblocking = TRUE;
      try {
        $account->activate()->save();
      }
      finally {
        $this->unblocking = FALSE;
      }
    }

    if (!$this->state->get(self::STATE_MAIL_SENT)) {
      // Renew the lease right before the send (see the class comment). A lease
      // lost by now means another call may be sending: send nothing.
      if (!$this->lock->acquire(self::LOCK_NAME, self::LOCK_LEASE)) {
        return 'busy';
      }
      $sent = $this->emailFactory?->newTypedEmail('localnodes_platform', 'organizer_welcome', $account)->send();
      if ($sent !== TRUE) {
        $this->loggerFactory->get('localnodes_platform')->error('Organizer welcome mail not sent at activation.');
        return 'mail_failed';
      }
      $this->state->set(self::STATE_MAIL_SENT, gmdate('Y-m-d\TH:i:s\Z'));
    }

    // Set last: the account is active and the mail went out.
    $this->state->set(self::STATE_ACTIVATED, gmdate('Y-m-d\TH:i:s\Z'));
    return 'activated';
  }

}
