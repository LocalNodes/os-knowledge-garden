<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Export;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\localnodes_platform\Export\ExportAccess;
use Drupal\localnodes_platform\Export\ExportSignatureProofInterface;
use Drupal\siwe_login\Service\ContractWalletDetector;
use Drupal\siwe_login\Service\SiweMessageValidator;
use Drupal\siwe_login\Service\SiweNonceStore;
use Drupal\siwe_login\Siwe\ChainIds;
use Drupal\siwe_login\Siwe\SiweMessage;
use Drupal\siwe_login\Siwe\SiweMessageParser;
use Drupal\siwe_login\Siwe\SiweMessageSerializer;

/** One exact message per browser session; never authenticates or links users. */
class ExportSignatureProof implements ExportSignatureProofInterface {
  public function __construct(
    private readonly ExportAccess $access,
    private readonly SiweNonceStore $nonces,
    private readonly SiweMessageValidator $validator,
    private readonly ContractWalletDetector $detector,
    private readonly PrivateTempStoreFactory $stores,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $config,
    // Optional for sign-in module versions that predate attachment history.
    private readonly ?object $attachments = NULL,
  ) {}

  protected function address(int $uid): string {
    if (!$this->access->allows($uid)) return '';
    $user = $this->access->account($uid);
    if (!$user || $user->getPassword() || !$user->hasField('field_ethereum_address')) return '';
    $address = (string) $user->get('field_ethereum_address')->value;
    return preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) ? SiweMessageParser::toChecksumAddress($address) : '';
  }

  public function available(int $uid): bool {
    if ($this->waitingUntil($uid) !== NULL) return FALSE;
    $address = $this->address($uid);
    // An unavailable classifier is not proof of a contract account. An EOA
    // signature can still verify offline, exactly as it does at sign-in.
    return $address !== '' && $this->detector->classify($address) !== ContractWalletDetector::CONTRACT;
  }

  public function waitingUntil(int $uid): ?int {
    if ($this->attachments === NULL) return NULL;
    $address = $this->address($uid);
    if ($address === '') return NULL;
    $account = $this->access->account($uid);
    if ($account === NULL) return NULL;
    $attached = $this->attachments->attachedAt($account, $address);
    return is_int($attached) && $attached + 86400 > $this->time->getCurrentTime() ? $attached + 86400 : NULL;
  }

  public function attach(array &$form): bool {
    if (!class_exists(\Drupal\siwe_login\SignerAttachments::class)) return FALSE;
    return \Drupal\siwe_login\SignerAttachments::attach($form);
  }

  public function issue(int $uid, string $action, string $exportId): array {
    return ExportProofLogs::quiet(fn () => $this->issueChallenge($uid, $action, $exportId));
  }

  private function issueChallenge(int $uid, string $action, string $exportId): array {
    if ($this->waitingUntil($uid) !== NULL) throw new \RuntimeException('Export confirmation unavailable.');
    $address = $this->address($uid);
    if ($address === '' || !$this->contextValid($action, $exportId)) throw new \RuntimeException('Export confirmation unavailable.');
    $config = $this->config->get('siwe_login.settings');
    $domain = trim(explode(',', (string) $config->get('expected_domain'))[0]);
    $uri = trim(explode(',', (string) $config->get('expected_uri'))[0]);
    if ($uri === '') $uri = 'https://' . $domain;
    if ($domain === '') throw new \RuntimeException('Export confirmation unavailable.');
    $nonce = $this->nonces->issue($address, 'export:' . $action, $uid);
    $key = $this->key();
    if ($key === NULL || !$this->lock->acquire('export-proof:' . $key, 10)) throw new \RuntimeException('Export confirmation unavailable.');
    try {
      $expires = min($nonce['expires'], $this->time->getCurrentTime() + 300);
      $site = $this->site();
      // SIWE statements are single-line ASCII. Keep the real site name in
      // the stored context even when its printable signing label needs a fallback.
      $name = preg_match("#^[A-Za-z0-9\\-._~:/?\\#\\[\\]@!$&'()*+,;= ]+$#D", $site['name']) ? $site['name'] : $domain;
      $words = ['request' => 'request a copy of', 'download' => 'download a copy of', 'delete' => 'delete only the prepared download from'];
      $message = (new SiweMessageSerializer())->serialize(new SiweMessage(NULL, $domain, $address,
        'Confirm you want to ' . $words[$action] . ' ' . $name . '. Includes private records from every group.',
        $uri, '1', ChainIds::defaultChainId($config), $nonce['nonce'], gmdate('Y-m-d\TH:i:s\Z', $nonce['issued']), gmdate('Y-m-d\TH:i:s\Z', $expires), NULL,
        'export-' . $action . '-' . $uid . '-' . ($exportId ?: 'new'),
      ));
      $this->stores->get('localnodes_export_signature')->set($key, compact('uid', 'action', 'exportId', 'address', 'site', 'message', 'expires'));
      return ['message' => $message, 'expectedAddress' => $address];
    }
    finally { $this->lock->release('export-proof:' . $key); }
  }

  public function confirm(int $uid, string $action, string $exportId, string $message, string $signature): bool {
    return ExportProofLogs::quiet(fn () => $this->checkChallenge($uid, $action, $exportId, $message, $signature));
  }

  private function checkChallenge(int $uid, string $action, string $exportId, string $message, string $signature): bool {
    $key = $this->key();
    if ($key === NULL || !$this->lock->acquire('export-proof:' . $key, 10)) return FALSE;
    try {
      $store = $this->stores->get('localnodes_export_signature');
      $pending = $store->get($key);
      $store->delete($key);
      if (!is_array($pending) || $this->waitingUntil($uid) !== NULL || strlen($message) > 8192 || strlen($signature) > 2048
        || $pending['uid'] !== $uid || $pending['action'] !== $action || $pending['exportId'] !== $exportId
        || $pending['site'] !== $this->site() || $pending['expires'] <= $this->time->getCurrentTime()
        || !hash_equals($pending['message'], $message) || $pending['address'] !== $this->address($uid)) return FALSE;
      $verified = $this->validator->validateMessage($message, $signature, 'export:' . $action, $uid);
      // The wallet may have changed, a password been set or a new signer
      // attached while the signature was checked: read the account again.
      return strcasecmp($verified->address, $pending['address']) === 0
        && $pending['address'] === $this->address($uid)
        && $this->waitingUntil($uid) === NULL;
    }
    catch (\Throwable) { return FALSE; }
    finally { $this->lock->release('export-proof:' . $key); }
  }

  private function key(): ?string {
    $binding = $this->nonces->currentBinding();
    return $binding === NULL ? NULL : hash('sha256', $binding);
  }
  private function site(): array {
    $site = $this->config->get('system.site');
    $settings = $this->config->get('siwe_login.settings');
    return ['uuid' => (string) $site->get('uuid'), 'name' => (string) $site->get('name'), 'domain' => (string) $settings->get('expected_domain'), 'uri' => (string) $settings->get('expected_uri')];
  }
  private function contextValid(string $action, string $id): bool {
    return $action === 'request' ? $id === '' : in_array($action, ['download', 'delete'], TRUE) && (bool) preg_match('/^[a-f0-9]{32}$/D', $id);
  }
}
