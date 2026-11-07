<?php

/**
 * @file
 * Reads a group's linked group fund on the lane site fresh3-e2e (21-42). Read-only.
 *
 * Run through tests/e2e/helpers/site.ts siteScript():
 *   ddev drush php:script tests/e2e/.state/scripts/read-fund.php -- <group id>
 *
 * Prints one machine-readable line, LN_E2E_FUND={json}: the linked safe_account's id,
 * safe_address, network, status, threshold and deployment_tx_hash, or null when the group
 * has no group fund. It goes through group_treasury's own service (the
 * group_safe_account:safe_account relationship), so no table name is guessed. It changes
 * nothing.
 */

$args = array_values(array_filter($extra ?? [], static fn ($a) => $a !== '--'));
if (count($args) !== 1 || !ctype_digit((string) $args[0])) {
  throw new \RuntimeException('read-fund.php needs one group id.');
}

$group = \Drupal::entityTypeManager()->getStorage('group')->load((int) $args[0]);
if (!$group) {
  throw new \RuntimeException("No group {$args[0]}.");
}

$fund = \Drupal::service('group_treasury.treasury_service')->getTreasury($group);
$out = NULL;
if ($fund) {
  $out = [
    'id' => (int) $fund->id(),
    'safe_address' => (string) $fund->get('safe_address')->value,
    'network' => (string) $fund->get('network')->value,
    'status' => (string) $fund->get('status')->value,
    'threshold' => (int) $fund->get('threshold')->value,
    'deployment_tx_hash' => (string) $fund->get('deployment_tx_hash')->value,
  ];
}

print 'LN_E2E_FUND=' . json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
