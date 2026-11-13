<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\localnodes_platform\SignInMethod;
use Drupal\siwe_login\Service\SiweMessageValidator;
use Drupal\siwe_login\Service\SiweNonceStore;
use Drupal\siwe_login\Siwe\ChainIds;
use Drupal\siwe_login\Siwe\SiweMessage;
use Drupal\siwe_login\Siwe\SiweMessageParser;
use Drupal\siwe_login\Siwe\SiweMessageSerializer;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Whether this session is proven by the account's own wallet, until sign-out.
 *
 * A session is proven when either:
 * - it signed in with a signature from the account's wallet address, and
 *   the account still has that address (SignInMethod::signedInBySignature());
 * - or, signed in some other way (password, emailed link), it later signed
 *   a message this class issued (challenge(), then prove()). That proof is
 *   kept in the session, tied to the account, its address and this sign-in:
 *   a new sign-in on the same session, another account, or a changed
 *   address ends it.
 *
 * Changing an account's address ends every proof made before the change,
 * by either way, even if the address is later changed back
 * (addressChanged(), called from hook_user_update()). Within the same
 * second as a change, a sign-in counts as before it: it fails closed. A
 * message issued before a change cannot prove the session afterwards either:
 * it records when the address last changed, and prove() refuses it once that
 * has moved on, even if the address is back to the one it was issued for.
 * The proof keeps that time as well, so a change while the signature is
 * being checked, or any time after, ends it.
 *
 * A password or an emailed link alone is never proof. Anything missing,
 * malformed or unexpected reads as not proven. Proof lasts until sign-out;
 * there is no time limit.
 *
 * This class never signs anyone in, never links a wallet, never authorizes
 * a payment and never casts a vote. Routes ask for proof with the route
 * option ROUTE_OPTION (see SignatureGuard); other code asks isProven().
 */
class SignatureSession {

  /**
   * The route option that makes a route's changing requests need proof.
   */
  public const ROUTE_OPTION = '_localnodes_signature_proof';

  /**
   * The route option that makes a marked route's GET and HEAD need proof too.
   *
   * For a route whose read changes something. It counts only together with
   * ROUTE_OPTION.
   */
  public const READ_OPTION = '_localnodes_signature_proof_reads';

  /**
   * The session key holding an in-place proof.
   */
  public const SESSION_KEY = 'localnodes_web3.signature_proof';

  /**
   * The nonce purpose: a sign-in or connect nonce is refused, and the reverse.
   */
  public const PURPOSE = 'session_proof';

  /**
   * The private tempstore collection holding the issued message.
   */
  private const COLLECTION = 'localnodes_session_proof';

  /**
   * The user.data name for when the account's address last changed.
   */
  private const CHANGED = 'address_changed';

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly SiweNonceStore $nonces,
    private readonly SiweMessageValidator $validator,
    private readonly PrivateTempStoreFactory $stores,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $config,
  ) {}

  /**
   * Whether the current session is proven for this account.
   */
  public function isProven(AccountInterface $account): bool {
    try {
      $session = $this->session();
      $uid = (int) $account->id();
      $address = $this->address($uid);
      if ($session === NULL || $address === '') {
        return FALSE;
      }
      $changed = $this->changedAt($uid);
      if (SignInMethod::signedInBySignature($session, $uid, $address) && SignInMethod::get($session)['at'] > $changed) {
        return TRUE;
      }
      $proof = $session->get(self::SESSION_KEY);
      $signIn = SignInMethod::recordId($session);
      $sid = SignInMethod::sessionHash($session);
      return $signIn !== NULL
        && $sid !== ''
        && is_array($proof)
        && is_string($proof['sid'] ?? NULL)
        && hash_equals($sid, $proof['sid'])
        && is_float($proof['at'] ?? NULL)
        && $proof['at'] > $changed
        && is_float($proof['changed'] ?? NULL)
        && $proof['changed'] === $changed
        && ($proof['uid'] ?? NULL) === $uid
        && is_string($proof['address'] ?? NULL)
        && hash_equals(strtolower($address), $proof['address'])
        && is_string($proof['signIn'] ?? NULL)
        && hash_equals($signIn, $proof['signIn']);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Whether this account has a wallet address that could prove the session.
   */
  public function canProve(AccountInterface $account): bool {
    return $this->address((int) $account->id()) !== '';
  }

  /**
   * Issues the exact message this account's wallet signs to prove the session.
   *
   * Replaces this session's pending sign-in, connect or export nonce: the
   * nonce store holds one per session.
   *
   * @return array
   *   'message' (the text to sign, unchanged) and 'expectedAddress'
   *   (checksummed).
   *
   * @throws \RuntimeException
   *   When the account has no usable address, the session has no sign-in
   *   id, the site is not configured, or the session store is busy.
   */
  public function challenge(AccountInterface $account): array {
    $uid = (int) $account->id();
    $address = $this->address($uid);
    $session = $this->session();
    $signIn = $session === NULL ? NULL : SignInMethod::recordId($session);
    $sid = $session === NULL ? '' : SignInMethod::sessionHash($session);
    if ($address === '' || $signIn === NULL || $sid === '') {
      throw new \RuntimeException('Session proof unavailable.');
    }
    $config = $this->config->get('siwe_login.settings');
    $domain = trim(explode(',', (string) $config->get('expected_domain'))[0]);
    $uri = trim(explode(',', (string) $config->get('expected_uri'))[0]);
    if ($domain === '') {
      throw new \RuntimeException('Session proof unavailable.');
    }
    if ($uri === '') {
      $uri = 'https://' . $domain;
    }
    $nonce = $this->nonces->issue($address, self::PURPOSE, $uid);
    $key = $this->key();
    if ($key === NULL || !$this->lock->acquire('session-proof:' . $key, 10)) {
      throw new \RuntimeException('Session proof unavailable.');
    }
    try {
      // When the address last changed, as prove() will read it again.
      $changed = $this->changedAt($uid);
      $expires = min($nonce['expires'], $this->time->getCurrentTime() + 300);
      $site = (string) $this->config->get('system.site')->get('name');
      // SIWE statements are one line of plain ASCII.
      $name = preg_match("#^[A-Za-z0-9\\-._~:/?\\#\\[\\]@!$&'()*+,;= ]+$#D", $site) ? $site : $domain;
      $message = (new SiweMessageSerializer())->serialize(new SiweMessage(NULL, $domain, $address,
        'Confirm it is you on ' . $name . '. This lets this browser change group funds and votes until you sign out. It moves no money and casts no vote.',
        $uri, '1', ChainIds::defaultChainId($config), $nonce['nonce'], gmdate('Y-m-d\TH:i:s\Z', $nonce['issued']), gmdate('Y-m-d\TH:i:s\Z', $expires), NULL,
        'session-proof-' . $uid,
      ));
      $this->stores->get(self::COLLECTION)->set($key, compact('uid', 'address', 'signIn', 'sid', 'message', 'expires', 'changed'));
      return ['message' => $message, 'expectedAddress' => $address];
    }
    finally {
      $this->lock->release('session-proof:' . $key);
    }
  }

  /**
   * Checks the signed message; on success the session is proven.
   *
   * The issued message is used up whatever the outcome.
   *
   * @return bool
   *   TRUE when the session is now proven.
   */
  public function prove(AccountInterface $account, string $message, string $signature): bool {
    $key = $this->key();
    if ($key === NULL || !$this->lock->acquire('session-proof:' . $key, 10)) {
      return FALSE;
    }
    try {
      $uid = (int) $account->id();
      $session = $this->session();
      $store = $this->stores->get(self::COLLECTION);
      $pending = $store->get($key);
      $store->delete($key);
      $signIn = $session === NULL ? NULL : SignInMethod::recordId($session);
      $sid = $session === NULL ? '' : SignInMethod::sessionHash($session);
      if (!is_array($pending) || $signIn === NULL || $sid === '' || strlen($message) > 8192 || strlen($signature) > 2048
        || ($pending['uid'] ?? NULL) !== $uid
        || ($pending['signIn'] ?? NULL) !== $signIn
        || !is_string($pending['sid'] ?? NULL) || !hash_equals($pending['sid'], $sid)
        || !is_int($pending['expires'] ?? NULL) || $pending['expires'] <= $this->time->getCurrentTime()
        || !is_string($pending['message'] ?? NULL) || !hash_equals($pending['message'], $message)
        || ($pending['address'] ?? NULL) !== $this->address($uid)
        || !is_float($pending['changed'] ?? NULL) || $pending['changed'] !== $this->changedAt($uid)) {
        return FALSE;
      }
      $verified = $this->validator->validateMessage($message, $signature, self::PURPOSE, $uid);
      // The address may have changed, even away and back, while the
      // signature was checked: read both again.
      if (strcasecmp($verified->address, $pending['address']) !== 0
        || $pending['address'] !== $this->address($uid)
        || $pending['changed'] !== $this->changedAt($uid)) {
        return FALSE;
      }
      // The proof keeps the change time it was issued under; isProven()
      // requires it to be the current one, so a change after this line ends
      // the proof too.
      $session->set(self::SESSION_KEY, ['uid' => $uid, 'address' => strtolower($pending['address']), 'signIn' => $signIn, 'sid' => $sid, 'changed' => $pending['changed'], 'at' => $this->time->getCurrentMicroTime()]);
      return TRUE;
    }
    catch (\Throwable) {
      return FALSE;
    }
    finally {
      $this->lock->release('session-proof:' . $key);
    }
  }

  /**
   * Ends every proof of this account made so far. Called on address change.
   */
  public function addressChanged(int $uid): void {
    $this->userData->set('localnodes_web3', $uid, self::CHANGED, $this->time->getCurrentMicroTime());
  }

  /**
   * When the account's address last changed, Unix seconds; 0.0 for never.
   */
  private function changedAt(int $uid): float {
    $changed = $this->userData->get('localnodes_web3', $uid, self::CHANGED);
    return is_numeric($changed) ? (float) $changed : 0.0;
  }

  /**
   * The account's wallet address, checksummed, or '' when it cannot prove.
   *
   * Only an active, signed-in account with a well-formed address can.
   */
  private function address(int $uid): string {
    if ($uid <= 0) {
      return '';
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface || !$user->isActive() || !$user->hasField('field_ethereum_address')) {
      return '';
    }
    $address = (string) $user->get('field_ethereum_address')->value;
    return preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) ? SiweMessageParser::toChecksumAddress($address) : '';
  }

  /**
   * The current request's session, or NULL.
   */
  private function session(): ?SessionInterface {
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL && $request->hasSession() ? $request->getSession() : NULL;
  }

  /**
   * The tempstore key for this browser session, or NULL without one.
   */
  private function key(): ?string {
    $binding = $this->nonces->currentBinding();
    return $binding === NULL ? NULL : hash('sha256', $binding);
  }

}
