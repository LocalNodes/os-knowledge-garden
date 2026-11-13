<?php

/**
 * @file
 * Template settings.php for Open Social AI Knowledge Gardens.
 *
 * This file is committed to git and used in both DDEV (local) and Docker
 * (production) environments. All environment-specific values are read from
 * environment variables via getenv() with sensible defaults.
 *
 * DDEV integration is included at the bottom so settings.ddev.php can
 * override database settings for local development.
 */

// phpcs:ignoreFile

// ---------------------------------------------------------------------------
// Database configuration via environment variables.
// ---------------------------------------------------------------------------
$databases['default']['default'] = [
  'database' => getenv('DB_NAME') ?: 'opensocial',
  'username' => getenv('DB_USER') ?: 'opensocial',
  'password' => getenv('DB_PASSWORD') ?: 'changeme',
  'host' => getenv('DB_HOST') ?: 'mariadb',
  'port' => getenv('DB_PORT') ?: '3306',
  'driver' => 'mysql',
  'prefix' => '',
];

// ---------------------------------------------------------------------------
// Core settings.
// ---------------------------------------------------------------------------
$configured_hash_salt = getenv('DRUPAL_HASH_SALT');
if ($configured_hash_salt !== FALSE && $configured_hash_salt !== '' && $configured_hash_salt !== 'generate-random') {
  $settings['hash_salt'] = $configured_hash_salt;
}
$settings['config_sync_directory'] = '../config/sync';
$settings['file_private_path'] = '/var/www/private';
// While a nightly backup or a restore holds the pause file, every web request
// gets a 503 from here, before Drupal starts. A restore empties the database
// before loading the copy; Drupal cannot start on an empty database, so its
// own pause check would not run and visitors would be sent to the installer.
// Otherwise the request holds a shared lock until it ends, so a backup or
// restore can wait for requests already running before it copies or deletes.
if (PHP_SAPI !== 'cli' && is_file($app_root . '/modules/custom/localnodes_platform/src/Backup/BackupPause.php')) {
  require_once $app_root . '/modules/custom/localnodes_platform/src/Backup/BackupPause.php';
  $localnodes_pause_answer = (new \Drupal\localnodes_platform\Backup\BackupPause($settings['file_private_path']))->enterWebRequest($_SERVER);
  if ($localnodes_pause_answer !== NULL) {
    http_response_code($localnodes_pause_answer[0]);
    foreach ($localnodes_pause_answer[1] as $name => $value) {
      header("$name: $value");
    }
    echo $localnodes_pause_answer[2];
    exit;
  }
}
$settings['update_free_access'] = FALSE;
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';

// ---------------------------------------------------------------------------
// Exclude per-instance demo modules from config sync.
// Web3 modules (siwe_login, safe_smart_accounts, group_treasury,
// social_group_treasury) are core platform — managed via config sync.
// ---------------------------------------------------------------------------
$settings['config_exclude_modules'] = [
  'localnodes_demo',
  'boulder_demo',
  'portland_demo',
  'social_demo',
];

// ---------------------------------------------------------------------------
// Solr config overrides (runtime layer, not stored in DB).
// ---------------------------------------------------------------------------
$config['search_api.server.social_solr']['backend_config']['connector_config']['host'] = getenv('SOLR_HOST') ?: 'solr';
$config['search_api.server.social_solr']['backend_config']['connector_config']['port'] = getenv('SOLR_PORT') ?: '8983';
$config['search_api.server.social_solr']['backend_config']['connector_config']['core'] = 'drupal';
$config['search_api.server.social_solr']['backend_config']['connector_config']['path'] = '/';

// ---------------------------------------------------------------------------
// Defer direct indexing for one process (the install's demo content load).
// social_posts and social_comments (localnodes_platform config/install) index
// directly: each entity save queues post-request indexing, which embeds the
// item with Gemini when the request or drush command ends. During the demo
// content load that is hundreds of embedding calls inside
// `drush social-demo:add`, and an AI quota error there ends the command
// non-zero, which stopped the staging install on 2026-11-06. The entrypoint
// sets LOCALNODES_DEFER_INDEXING=1 for that one command only; the items stay
// tracked, and the install's explicit, non-fatal index steps embed them.
// Nothing is written to config. Test: scripts/ops/tests/install-resume.test.sh
// ---------------------------------------------------------------------------
if (getenv('LOCALNODES_DEFER_INDEXING') === '1') {
  foreach (['social_posts', 'social_comments'] as $index_id) {
    $config["search_api.index.$index_id"]['options']['index_directly'] = FALSE;
  }
}

// ---------------------------------------------------------------------------
// Qdrant config overrides.
// ---------------------------------------------------------------------------
$config['ai_vdb_provider_qdrant.settings']['host'] = getenv('QDRANT_HOST') ?: 'qdrant';
$config['ai_vdb_provider_qdrant.settings']['port'] = (int)(getenv('QDRANT_PORT') ?: '6333');

// ---------------------------------------------------------------------------
// Gemini API key via environment.
// ---------------------------------------------------------------------------
$config['key.key.gemini_api_key']['key_provider_settings']['env_variable'] = 'GEMINI_API_KEY';

// ---------------------------------------------------------------------------
// Gemini data tier (AI-SPEC 1b, guardrail G8). Google may use content sent
// with an unpaid key and have people review it, so members' content must only
// go to a key from a Cloud project with billing on. Declare it per instance
// with GEMINI_KEY_TIER=paid. localnodes_platform_requirements() shows a
// status-report error when a non-demo site hasn't declared it (no runtime
// block: Tom's warn-and-provision decision, 2026-11-05).
// - DDEV is 'local' (fictional fixtures only).
// - Off DDEV, only 'paid' counts; any other value becomes 'unpaid', so
//   'local' can't be declared on a public instance. Unset stays ''.
// ---------------------------------------------------------------------------
if (getenv('IS_DDEV_PROJECT') == 'true') {
  $settings['localnodes_ai_data_tier'] = 'local';
}
else {
  $gemini_key_tier = strtolower(trim((string) getenv('GEMINI_KEY_TIER')));
  $settings['localnodes_ai_data_tier'] = $gemini_key_tier === 'paid' ? 'paid' : ($gemini_key_tier === '' ? '' : 'unpaid');
}
// Demo sites (fictional content only) are exempt from the tier check. Same
// value and default as docker/entrypoint.sh: DEMO_MODULE="${DEMO_MODULE:-none}",
// so an unset or empty DEMO_MODULE is a real (blank) community site, as 'none' is.
$settings['localnodes_demo_module'] = getenv('DEMO_MODULE') ?: 'none';

// ---------------------------------------------------------------------------
// Resend SMTP transport for outbound email (created by localnodes_platform at
// install as a `dsn` transport). smtps:// on 2465 = implicit TLS, mandatory
// before AUTH; 465 is blocked outbound on the VPS (Hetzner). Keep in sync with
// _localnodes_platform_configure_mailer().
// ---------------------------------------------------------------------------
if ($resend_key = getenv('RESEND_API_KEY')) {
  $config['symfony_mailer.mailer_transport.smtp']['configuration']['dsn'] =
    'smtps://resend:' . rawurlencode($resend_key) . '@smtp.resend.com:2465';
}

// ---------------------------------------------------------------------------
// Phase 23 per-instance values, env only (set per instance by the runbook or by
// provision-instance.yml; docker-compose.yaml lists each one). Each is set only
// when the variable is non-empty, so an unset one leaves the module default.
// - HOST_REPORT_EMAIL: the host's address for "Report a problem" mails
//   (read by localnodes_import's report form).
// - AI_BACKUP_PROVIDER / AI_BACKUP_MODEL: the chat-only failover backup that
//   AiFailoverSubscriber reads from localnodes_ai_backup (provider, model).
// - OPENROUTER_BASE_URL: the OpenRouter provider's base_url, to point it at
//   another OpenAI-compatible gateway. http(s) URLs only. Inert while the
//   ai_provider_openrouter module is absent.
// ---------------------------------------------------------------------------
if (($host_report_email = trim((string) getenv('HOST_REPORT_EMAIL'))) !== '') {
  $settings['localnodes_host_report_email'] = $host_report_email;
}
foreach (['provider' => 'AI_BACKUP_PROVIDER', 'model' => 'AI_BACKUP_MODEL'] as $backup_key => $backup_env) {
  if (($backup_value = trim((string) getenv($backup_env))) !== '') {
    $settings['localnodes_ai_backup'][$backup_key] = $backup_value;
  }
}
if (($openrouter_base_url = trim((string) getenv('OPENROUTER_BASE_URL'))) !== '' && preg_match('#^https?://#i', $openrouter_base_url)) {
  $config['ai_provider_openrouter.settings']['base_url'] = $openrouter_base_url;
}
unset($host_report_email, $backup_key, $backup_env, $backup_value, $openrouter_base_url);

// Sign-up identity (23-35): the activation route compares the bearer with
// callback_token (hash_equals). Empty on demo and manual instances, where the
// route answers 404. Never exported to config, never shown on a page.
$settings['localnodes_provision'] = [
  'id' => (string) (getenv('PROVISION_ID') ?: ''),
  'callback_token' => (string) (getenv('PROVISION_CALLBACK_TOKEN') ?: ''),
];

// ---------------------------------------------------------------------------
// SIWE domain/URI override (instance-specific, derived from FQDN).
// SERVICE_FQDN_OPENSOCIAL may list several domains, comma-separated (Coolify
// does this when an app has more than one domain). Each entry is normalised:
// scheme, path and trailing slash dropped, host lowercased, port kept.
// Entries with no host are skipped; if none is left, neither key is set.
// siwe_login reads both keys as comma-separated lists.
// ---------------------------------------------------------------------------
if ($fqdn = getenv('SERVICE_FQDN_OPENSOCIAL')) {
  $siwe_hosts = [];
  foreach (explode(',', $fqdn) as $fqdn_entry) {
    $fqdn_entry = trim($fqdn_entry);
    if ($fqdn_entry === '') {
      continue;
    }
    $fqdn_parts = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $fqdn_entry) ? $fqdn_entry : 'https://' . $fqdn_entry);
    if (!empty($fqdn_parts['host'])) {
      $siwe_hosts[] = strtolower($fqdn_parts['host']) . (isset($fqdn_parts['port']) ? ':' . $fqdn_parts['port'] : '');
    }
  }
  $siwe_hosts = array_values(array_unique($siwe_hosts));
  if ($siwe_hosts) {
    $config['siwe_login.settings']['expected_domain'] = implode(',', $siwe_hosts);
    $config['siwe_login.settings']['expected_uri'] = implode(',', array_map(fn ($h) => 'https://' . $h, $siwe_hosts));
  }
}

// SIWE chain the signed message must carry (default 1, mainnet).
// siwe_login 1.0.0-beta9 reads the allow-list `chain_ids` (first = default)
// and ignores the legacy `chain_id` when the list is set, so the override
// must set the list. The legacy key is kept in sync for older code paths.
if (ctype_digit((string) getenv('SIWE_CHAIN_ID'))) {
  $config['siwe_login.settings']['chain_ids'] = [(int) getenv('SIWE_CHAIN_ID')];
  $config['siwe_login.settings']['chain_id'] = (int) getenv('SIWE_CHAIN_ID');
}

// RPC for that chain (contract-wallet detection only; never accepts a login).
if ($siwe_rpc = getenv('SIWE_CHAIN_RPC_URL')) {
  $config['siwe_login.settings']['chain_rpc_url'] = $siwe_rpc;
}

// ---------------------------------------------------------------------------
// Web3 RPC endpoint override (optional, localnodes_web3).
// Defaults to Pocket Network's keyless public portal (set in
// localnodes_web3_install()). Override per-instance with a dedicated provider
// (Alchemy/Infura/Grove/etc.) by setting ETHEREUM_PROVIDER_URL.
// ---------------------------------------------------------------------------
if ($eth_rpc = getenv('ETHEREUM_PROVIDER_URL')) {
  $config['siwe_login.settings']['ethereum_provider_url'] = $eth_rpc;
}

// ---------------------------------------------------------------------------
// Safe network registry RPC overrides (optional, localnodes_web3).
// The registry entries ship in localnodes_web3 config/install. Set one of
// these to send that network's reads to a dedicated provider. The URL
// REPLACES the entry's whole stored RPC list: no shipped public URL stays on
// as a fallback (SafeNetwork::getRpcUrls() reads $settings['safe_rpc_urls']).
// This is a setting, not a $config override, because Drupal merges a config
// list by position: an override of one URL replaced only the first stored URL
// and left the rest in use (21-25 fix). The stored config is never changed.
// ---------------------------------------------------------------------------
if ($safe_rpc_ethereum_sepolia = getenv('SAFE_RPC_URL_ETHEREUM_SEPOLIA')) {
  $settings['safe_rpc_urls']['ethereum_sepolia'] = [$safe_rpc_ethereum_sepolia];
}
if ($safe_rpc_base_sepolia = getenv('SAFE_RPC_URL_BASE_SEPOLIA')) {
  $settings['safe_rpc_urls']['base_sepolia'] = [$safe_rpc_base_sepolia];
}

// ---------------------------------------------------------------------------
// Network fee sponsor (optional, gas_sponsor; D-01).
// gas_sponsor asks the community's relayer container to pay network fees.
// The relayer's URL and its bearer token come from the environment only:
// they are settings, never config, so they never appear in a config export.
// The relayer's sending key stays in the relayer container and never reaches
// Drupal. With the URL or the token unset, gas_sponsor reports that no
// network fee account is set up. The low-balance floor, when set, is the
// same whole number of wei the relayer uses for its own low-balance alert.
// ---------------------------------------------------------------------------
if (($sponsor_relayer_url = trim((string) getenv('SPONSOR_RELAYER_URL'))) !== '') {
  $settings['gas_sponsor']['relayer_url'] = $sponsor_relayer_url;
}
if (($sponsor_relayer_token = trim((string) getenv('SPONSOR_RELAYER_TOKEN'))) !== '') {
  $settings['gas_sponsor']['relayer_token'] = $sponsor_relayer_token;
}
if (ctype_digit($sponsor_low_balance_wei = trim((string) getenv('SPONSOR_RELAYER_LOW_BALANCE_WEI')))) {
  $settings['gas_sponsor']['low_balance_wei'] = $sponsor_low_balance_wei;
}

// ---------------------------------------------------------------------------
// Local e2e chain routing (DDEV only, .test hosts; 21-40).
// The e2e lane's Safes exist only on local anvil chains (tests/e2e/fork), so a
// request to a .test host on DDEV must read those chains, never the real ones.
// Both conditions are required: production never sets IS_DDEV_PROJECT, and no
// public instance answers a .test host. CLI runs without --uri have no
// HTTP_HOST, so they read the shipped RPCs.
// ---------------------------------------------------------------------------
if (getenv('IS_DDEV_PROJECT') == 'true') {
  $e2e_host = preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')));
  if (str_ends_with($e2e_host, '.test')) {
    // A full replacement, like the overrides above: no stored public URL is
    // left as a fallback to the real chain.
    foreach (['ethereum_sepolia', 'base_sepolia'] as $e2e_network_id) {
      $settings['safe_rpc_urls'][$e2e_network_id] = ['http://anvil-' . str_replace('_', '-', $e2e_network_id) . ':8545'];
    }
  }
}

// ---------------------------------------------------------------------------
// WaaP sign-in (siwe_login_waap): per-instance environment and Reown /
// WalletConnect project ID. Inert unless siwe_login_waap is enabled.
// ---------------------------------------------------------------------------
if ($waap_env = getenv('WAAP_ENVIRONMENT')) {
  $config['siwe_login_waap.settings']['environment'] = $waap_env;
}
if ($walletconnect_project_id = getenv('WALLETCONNECT_PROJECT_ID')) {
  $config['siwe_login_waap.settings']['walletconnect_project_id'] = $walletconnect_project_id;
}

// ---------------------------------------------------------------------------
// Reverse proxy support (Coolify: Cloudflare -> Traefik -> Drupal).
// Only Cloudflare's edge and Traefik on this app's own network may set
// X-Forwarded-For/-Proto; see trusted-proxies.php.
// ---------------------------------------------------------------------------
if (getenv('DRUPAL_REVERSE_PROXY') === 'true') {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = require __DIR__ . '/trusted-proxies.php';
  // Only the client address and the scheme come from the proxy. The host
  // and port come from the request itself (trusted_host_patterns guards
  // the host).
  $settings['reverse_proxy_trusted_headers'] =
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;
}

// ---------------------------------------------------------------------------
// Trusted host patterns from environment.
// ---------------------------------------------------------------------------
$fqdn = getenv('SERVICE_FQDN_OPENSOCIAL');
if ($fqdn) {
  $host = preg_replace('#^https?://#', '', $fqdn);
  $settings['trusted_host_patterns'][] = '^' . preg_quote($host, '/') . '$';
}
$settings['trusted_host_patterns'][] = '^localhost$';

// ---------------------------------------------------------------------------
// AI link filter: allow the instance's own host.
// drupal/ai >= 1.4 strips every Markdown link in an AI answer whose host is not
// in ai.settings:allowed_hosts (empty by default), keeping only the link text.
// The chatbot cites sources as links to this site's own content, so the
// instance host must be allowed or every citation loses its link. Other hosts
// stay blocked. DDEV hostnames are added for local development.
// ---------------------------------------------------------------------------
// Each comma-separated entry may be a bare host or a URL with a scheme, port or
// path; only the host name is kept (parse_url needs a scheme to find it).
$ai_host_sources = $fqdn ?: '';
if (getenv('IS_DDEV_PROJECT') == 'true' && ($ddev_hosts = getenv('DDEV_HOSTNAME'))) {
  $ai_host_sources .= ',' . $ddev_hosts;
}
$ai_allowed_hosts = [];
foreach (explode(',', $ai_host_sources) as $ai_host_entry) {
  $ai_host_entry = trim($ai_host_entry);
  if ($ai_host_entry === '') {
    continue;
  }
  $ai_host = parse_url(str_contains($ai_host_entry, '://') ? $ai_host_entry : 'https://' . $ai_host_entry, PHP_URL_HOST);
  if (is_string($ai_host) && $ai_host !== '') {
    $ai_allowed_hosts[] = strtolower($ai_host);
  }
}
if ($ai_allowed_hosts) {
  $config['ai.settings']['allowed_hosts'] = array_values(array_unique($ai_allowed_hosts));
}

// ---------------------------------------------------------------------------
// DDEV integration (must be at bottom so overrides take precedence).
// ---------------------------------------------------------------------------
// Test-only modules are visible on local DDEV, so the Phase 21 a11y RPC
// fixture module can be enabled there (D-18). Production never matches.
if (getenv('IS_DDEV_PROJECT') == 'true') { $settings['extension_discovery_scan_tests'] = TRUE; }
if (getenv('IS_DDEV_PROJECT') == 'true' && file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}

// ---------------------------------------------------------------------------
// Local-only settings (gitignored, never committed): DB credentials,
// hash_salt, per-machine values. A real site has none. The e2e and a11y lane
// test sites get theirs from tests/e2e/site/site.sh. Last, so a local value
// wins.
// ---------------------------------------------------------------------------
if (file_exists(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}

// Local/DDEV overrides above still take precedence. Deployed sites with no
// explicit salt must use the same private file that startup prepared.
if (!isset($settings['hash_salt']) || $settings['hash_salt'] === '') {
  require_once __DIR__ . '/hash-salt.php';
  $settings['hash_salt'] = localnodes_hash_salt($settings['file_private_path']);
}
