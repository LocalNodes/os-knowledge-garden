<?php

/**
 * @file
 * Reads one group payment (safe_transaction) on the lane site fresh3-e2e (21-43). Read-only.
 *
 * Run through tests/e2e/helpers/site.ts siteScript():
 *   ddev drush php:script tests/e2e/.state/scripts/read-payment.php -- <payment id>
 *
 * Prints one machine-readable line, LN_E2E_PAYMENT={json}: id, safe_account, status,
 * to_address, value (wei, as stored), nonce, blockchain_tx_hash, safe_tx_hash and the stored
 * approvals (signer addresses only, never the signature bytes). It loads the entity through
 * its own storage and changes nothing.
 */

$args = array_values(array_filter($extra ?? [], static fn ($a) => $a !== '--'));
if (count($args) !== 1 || !ctype_digit((string) $args[0])) {
  throw new \RuntimeException('read-payment.php needs one payment id.');
}

$tx = \Drupal::entityTypeManager()->getStorage('safe_transaction')->load((int) $args[0]);
$out = NULL;
if ($tx) {
  $signers = [];
  foreach ($tx->getSignatures() as $key => $signature) {
    $signer = is_array($signature) ? ($signature['signer'] ?? $key) : $key;
    $signers[] = strtolower((string) $signer);
  }
  $nonce = $tx->get('nonce')->value;
  $out = [
    'id' => (int) $tx->id(),
    'safe_account' => (int) $tx->get('safe_account')->target_id,
    'status' => (string) $tx->getStatus(),
    'to_address' => (string) $tx->getToAddress(),
    'value' => $tx->getValue(),
    'nonce' => $nonce === NULL ? NULL : (int) $nonce,
    'blockchain_tx_hash' => (string) $tx->get('blockchain_tx_hash')->value,
    'safe_tx_hash' => (string) $tx->get('safe_tx_hash')->value,
    'approvals' => $signers,
  ];
}

print 'LN_E2E_PAYMENT=' . json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
