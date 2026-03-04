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
// Resend SMTP transport for outbound email.
// ---------------------------------------------------------------------------
if ($resend_key = getenv('RESEND_API_KEY')) {
  $config['symfony_mailer.mailer_transport.smtp']['configuration']['pass'] = $resend_key;
}

// ---------------------------------------------------------------------------
// SIWE domain override (instance-specific, derived from FQDN).
// ---------------------------------------------------------------------------
if ($fqdn = getenv('SERVICE_FQDN_OPENSOCIAL')) {
  $config['siwe_login.settings']['expected_domain'] = preg_replace('#^https?://#', '', $fqdn);
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
// DDEV integration (must be at bottom so overrides take precedence).
// ---------------------------------------------------------------------------
if (getenv('IS_DDEV_PROJECT') == 'true' && file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}
