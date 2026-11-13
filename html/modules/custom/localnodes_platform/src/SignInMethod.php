<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * How the current session signed in, and when.
 *
 * Recorded on every sign-in (localnodes_platform_user_login()) in the
 * session under KEY, as ['method' => one of the constants, 'at' => time].
 * Screens that need proof of the account holder, rather than of an inbox,
 * read it here:
 *
 * - SIGNATURE: a signed sign-in message from the member's wallet, checked by
 *   siwe_login at the moment of sign-in (siwe_login.verify).
 * - PASSWORD: the site's own log-in form (user.login).
 * - EMAIL_LINK: a link mailed to the account's address: the sign-in link
 *   (localnodes_web3) or core's one-time link (user.reset.login, which
 *   drush uli uses too). It proves access to the inbox, nothing more.
 * - OTHER: anything else, such as finishing a sign-in from a confirmation
 *   mail, whose signature may be a day old.
 *
 * Unknown counts as not proven: a session with no record, or a method this
 * class does not list, is OTHER.
 *
 * The record also keeps the account that signed in, its wallet address at
 * that moment (lowercase, '' for none), a random id for this sign-in and a
 * hash of the session id it was made in ('' when the session had no id yet:
 * a request without a session cookie). signedInBySignature() needs all of
 * them to match the account and session as they are now. recordId() lets a
 * proof made later in the session (localnodes_web3's SignatureSession) die
 * with the sign-in it was made in, since the next sign-in gets a new id; the
 * proof binds itself to the session id it was made under.
 */
final class SignInMethod {

  public const KEY = 'localnodes_platform.signed_in_by';

  public const SIGNATURE = 'signature';
  public const PASSWORD = 'password';
  public const EMAIL_LINK = 'email_link';
  public const OTHER = 'other';

  /**
   * The routes whose sign-ins are recorded as something other than OTHER.
   */
  private const ROUTES = [
    'siwe_login.verify' => self::SIGNATURE,
    'user.login' => self::PASSWORD,
    'user.reset.login' => self::EMAIL_LINK,
    'localnodes_web3.email_link_sign_in' => self::EMAIL_LINK,
  ];

  /**
   * The method a sign-in on this route counts as.
   */
  public static function forRoute(?string $route): string {
    return self::ROUTES[$route ?? ''] ?? self::OTHER;
  }

  /**
   * Records a sign-in that just happened on this route.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session that signed in.
   * @param string|null $route
   *   The route the sign-in happened on.
   * @param int $time
   *   The sign-in time, Unix seconds.
   * @param int|null $uid
   *   The account that signed in.
   * @param string|null $address
   *   That account's wallet address now, any case; NULL or '' for none.
   */
  public static function record(SessionInterface $session, ?string $route, int $time, ?int $uid = NULL, ?string $address = NULL): void {
    // A request that came with a session cookie has a real id by now (core
    // regenerated it). One that came without has none until the session is
    // saved, after this: its record keeps sid '' and never counts as a
    // signature sign-in; it can still prove in place (recordId()).
    if (!$session->isStarted()) {
      $session->start();
    }
    $session->set(self::KEY, [
      'method' => self::forRoute($route),
      'at' => $time,
      'uid' => $uid,
      'address' => self::cleanAddress($address),
      'id' => bin2hex(random_bytes(16)),
      'sid' => self::sessionHash($session),
    ]);
  }

  /**
   * Whether this session signed in with this account's wallet, as it is now.
   *
   * TRUE only when the sign-in was a signature, by this account, with this
   * same address. Any other method, another account, an address that has
   * changed or was never there, and a record from before the account and
   * address were kept, are not.
   *
   * @param \Symfony\Component\HttpFoundation\Session\SessionInterface $session
   *   The session.
   * @param int $uid
   *   The account the session is acting as.
   * @param string $address
   *   That account's wallet address now, any case.
   */
  public static function signedInBySignature(SessionInterface $session, int $uid, string $address): bool {
    $record = $session->get(self::KEY);
    $address = self::cleanAddress($address);
    return $uid > 0
      && $address !== ''
      && is_array($record)
      && ($record['method'] ?? NULL) === self::SIGNATURE
      && is_int($record['at'] ?? NULL)
      && $record['at'] > 0
      && ($record['uid'] ?? NULL) === $uid
      && is_string($record['address'] ?? NULL)
      && hash_equals($address, $record['address'])
      && self::signInId($session) !== NULL;
  }

  /**
   * The random id of this session's latest sign-in, or NULL if none is kept.
   *
   * NULL too for a record made in another session (copied, or from before a
   * new session id), and for one made before the session had an id.
   */
  public static function signInId(SessionInterface $session): ?string {
    $id = self::recordId($session);
    $record = $session->get(self::KEY);
    $sid = $record['sid'] ?? NULL;
    $current = self::sessionHash($session);
    return $id !== NULL && $current !== '' && is_string($sid) && hash_equals($current, $sid) ? $id : NULL;
  }

  /**
   * The random id of the latest sign-in kept in this session, or NULL.
   *
   * Not bound to the session id: whatever relies on it binds itself to the
   * session id it sees (as the in-place proof does).
   */
  public static function recordId(SessionInterface $session): ?string {
    $record = $session->get(self::KEY);
    $id = is_array($record) ? ($record['id'] ?? NULL) : NULL;
    return is_string($id) && preg_match('/^[0-9a-f]{32}$/D', $id) ? $id : NULL;
  }

  /**
   * The sha256 of the session's id, or '' when it has none yet.
   */
  public static function sessionHash(SessionInterface $session): string {
    $id = $session->getId();
    return $id === '' ? '' : hash('sha256', $id);
  }

  /**
   * A wallet address in lowercase, or '' when it is not one.
   */
  private static function cleanAddress(?string $address): string {
    $address = strtolower(trim((string) $address));
    return preg_match('/^0x[0-9a-f]{40}$/D', $address) ? $address : '';
  }

  /**
   * How this session signed in: ['method' => string, 'at' => int].
   *
   * A missing or malformed record reads as OTHER at time 0.
   */
  public static function get(SessionInterface $session): array {
    $record = $session->get(self::KEY);
    $method = is_array($record) && in_array($record['method'] ?? NULL, [self::SIGNATURE, self::PASSWORD, self::EMAIL_LINK], TRUE)
      ? $record['method']
      : self::OTHER;
    $at = is_array($record) && is_int($record['at'] ?? NULL) ? $record['at'] : 0;
    return ['method' => $method, 'at' => $method === self::OTHER ? 0 : $at];
  }

  /**
   * Whether this session signed in with the wallet or the site password.
   *
   * An emailed link, or anything unknown, is not.
   */
  public static function provedHolder(SessionInterface $session): bool {
    return in_array(self::get($session)['method'], [self::SIGNATURE, self::PASSWORD], TRUE);
  }

}
