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
$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: 'change-me-in-production';
$settings['config_sync_directory'] = '../config/sync';
$settings['file_private_path'] = '/var/www/private';
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
// Qdrant config overrides.
// ---------------------------------------------------------------------------
$config['ai_vdb_provider_qdrant.settings']['host'] = getenv('QDRANT_HOST') ?: 'qdrant';
$config['ai_vdb_provider_qdrant.settings']['port'] = (int)(getenv('QDRANT_PORT') ?: '6333');

// ---------------------------------------------------------------------------
// Gemini API key via environment.
// ---------------------------------------------------------------------------
$config['key.key.gemini_api_key']['key_provider_settings']['env_variable'] = 'GEMINI_API_KEY';

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
// SIWE domain override (instance-specific, derived from FQDN).
// ---------------------------------------------------------------------------
if ($fqdn = getenv('SERVICE_FQDN_OPENSOCIAL')) {
  $config['siwe_login.settings']['expected_domain'] = preg_replace('#^https?://#', '', $fqdn);
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
// Reverse proxy support (Coolify/Traefik).
// ---------------------------------------------------------------------------
if (getenv('DRUPAL_REVERSE_PROXY') === 'true') {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = ['0.0.0.0/0'];
  $settings['reverse_proxy_trusted_headers'] =
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST |
    \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT |
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
if (getenv('IS_DDEV_PROJECT') == 'true' && file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}

// Local, untracked overrides (DB credentials, hash_salt, per-machine values). Never commit.
if (file_exists(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}
