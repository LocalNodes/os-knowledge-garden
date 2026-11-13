<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The emailed sign-in link: for when the sign-in button is missing or down.
 *
 * A member asks for it at /user/email-link with their email address. The
 * mail holds /user/email-link/{uid}/{timestamp}/{hash}. Opening that only
 * shows a page with a "Sign in" button (mail scanners open links too); the
 * button signs the member in and takes them home, or to their invitations
 * when they have some. Nothing on the way mentions a password, and nothing
 * asks for one.
 *
 * The link:
 * - works once: the hash covers the account's last sign-in time, which
 *   signing in changes;
 * - ends for good when the account is blocked: the hash covers a value
 *   blocking replaces (revoke()), so unblocking does not bring it back;
 * - works for core's link lifetime (user.settings:password_reset_timeout),
 *   counted from when it was sent, first sign-in or not;
 * - works in any browser, so a phone's mail app can open it;
 * - is its own kind of link: its hash is keyed apart from core's one-time
 *   links, so it can't open core's set-a-password page, and core's links
 *   can't open this one.
 *
 * A session it opens is marked as an emailed-link sign-in
 * (\Drupal\localnodes_platform\SignInMethod): it can read and post, but
 * it proves only the inbox, not the wallet.
 *
 * It is switched off as installed (localnodes_web3.settings:email_link).
 * Off, its pages answer "not found" and the sign-in page does not offer it;
 * nothing else about signing in changes.
 */
final class EmailLink {

  /**
   * Where the session keeps the link between the mail and the button.
   */
  public const SESSION_KEY = 'localnodes_web3.email_link';

  /**
   * The settings that switch the link on.
   */
  public const SETTINGS = 'localnodes_web3.settings';

  /**
   * Whether the emailed link is switched on.
   */
  public static function switchedOn(): bool {
    return \Drupal::config(self::SETTINGS)->get('email_link') === TRUE;
  }

  /**
   * Answers "not found" while the emailed link is switched off.
   */
  public static function requireSwitchedOn(): void {
    if (!self::switchedOn()) {
      throw new NotFoundHttpException();
    }
  }

  /**
   * The user.data name for the value blocking replaces.
   */
  private const GENERATION = 'email_link_generation';

  /**
   * The hash for this account and send time.
   */
  public static function hash(UserInterface $account, int $timestamp): string {
    $data = implode(':', [
      'localnodes_web3.email_link',
      $timestamp,
      (int) $account->getLastLoginTime(),
      (int) $account->id(),
      (string) $account->getEmail(),
      (string) \Drupal::service('user.data')->get('localnodes_web3', (int) $account->id(), self::GENERATION),
    ]);
    $key = Settings::getHashSalt() . \Drupal::service('private_key')->get() . $account->getPassword();
    return Crypt::hmacBase64($data, $key);
  }

  /**
   * Ends every link made for this account so far.
   */
  public static function revoke(UserInterface $account): void {
    \Drupal::service('user.data')->set('localnodes_web3', (int) $account->id(), self::GENERATION, bin2hex(random_bytes(16)));
  }

  /**
   * The link to mail, made now.
   */
  public static function url(UserInterface $account, int $timestamp): string {
    return Url::fromRoute('localnodes_web3.email_link_open', [
      'uid' => (int) $account->id(),
      'timestamp' => $timestamp,
      'hash' => self::hash($account, $timestamp),
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * Whether a link still signs this account in.
   */
  public static function valid(?UserInterface $account, int $timestamp, string $hash, int $now): bool {
    if ($account === NULL || $account->isAnonymous() || !$account->isActive() || !self::canReceive($account)) {
      return FALSE;
    }
    $timeout = (int) \Drupal::config('user.settings')->get('password_reset_timeout');
    return $timestamp <= $now
      && $now - $timestamp <= $timeout
      && $timestamp >= (int) $account->getLastLoginTime()
      && hash_equals(self::hash($account, $timestamp), $hash);
  }

  /**
   * Whether the account has an address a link can reach.
   *
   * An account made by a wallet sign-in before it gave an email has only a
   * made-up address ending in "@ethereum.local". Nothing is sent there: the
   * member is asked for a real address at their next sign-in instead.
   */
  public static function canReceive(UserInterface $account): bool {
    $mail = strtolower(trim((string) $account->getEmail()));
    return $mail !== '' && !str_ends_with($mail, '@ethereum.local');
  }

  /**
   * The active account with this email address, if one can get a link.
   */
  public static function accountFor(string $email): ?UserInterface {
    $email = trim($email);
    if ($email === '') {
      return NULL;
    }
    foreach (self::accountsWithMail($email) as $user) {
      if ($user instanceof UserInterface && $user->isActive() && self::canReceive($user)) {
        return $user;
      }
    }
    return NULL;
  }

  /**
   * The accounts using this email address, however either is capitalised.
   *
   * A LIKE on the escaped address, as siwe_login's own lookups do, so the
   * match does not depend on the database's collation: an equals condition
   * ignores case on MySQL and MariaDB but not on SQLite.
   *
   * @return \Drupal\user\UserInterface[]
   *   The accounts, oldest first.
   */
  public static function accountsWithMail(string $email): array {
    $email = trim($email);
    if ($email === '') {
      return [];
    }
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('mail', \Drupal::database()->escapeLike($email), 'LIKE')
      ->sort('uid')
      ->execute();
    return array_filter($ids ? $storage->loadMultiple($ids) : [], static fn ($account): bool => $account instanceof UserInterface);
  }

}
