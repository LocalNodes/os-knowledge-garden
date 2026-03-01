#!/bin/bash
set -e

DRUSH="/var/www/html/vendor/bin/drush -r /var/www/html/html"

# --- Generate settings.php if needed ---
SETTINGS_FILE="/var/www/html/html/sites/default/settings.php"
if ! grep -q "Added by entrypoint" "$SETTINGS_FILE" 2>/dev/null; then
  # Ensure sites/default is writable for settings.php generation
  chmod 755 /var/www/html/html/sites/default
  touch "$SETTINGS_FILE"
  chmod 644 "$SETTINGS_FILE"

  cat >> "$SETTINGS_FILE" << 'SETTINGS_EOF'

// Added by entrypoint
$databases['default']['default'] = [
  'database' => getenv('DB_NAME') ?: 'opensocial',
  'username' => getenv('DB_USER') ?: 'opensocial',
  'password' => getenv('DB_PASSWORD') ?: 'changeme',
  'host' => getenv('DB_HOST') ?: 'mariadb',
  'port' => getenv('DB_PORT') ?: '3306',
  'driver' => 'mysql',
  'prefix' => '',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: 'change-me';
$settings['file_private_path'] = '/var/www/private';
$settings['config_sync_directory'] = '/var/www/html/html/sites/default/files/config/sync';
$settings['trusted_host_patterns'] = [];

// Add trusted host from SERVICE_FQDN_OPENSOCIAL
$fqdn = getenv('SERVICE_FQDN_OPENSOCIAL');
if ($fqdn) {
  $host = preg_replace('#^https?://#', '', $fqdn);
  $settings['trusted_host_patterns'][] = '^' . preg_quote($host, '/') . '$';
}
$settings['trusted_host_patterns'][] = '^localhost$';

// Reverse proxy (Coolify/Traefik)
if (getenv('DRUPAL_REVERSE_PROXY') === 'true') {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = ['0.0.0.0/0'];
  $settings['reverse_proxy_trusted_headers'] = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;
}

// Solr configuration override
$config['search_api.server.solr']['backend_config']['connector_config']['host'] = getenv('SOLR_HOST') ?: 'solr';
$config['search_api.server.solr']['backend_config']['connector_config']['port'] = getenv('SOLR_PORT') ?: '8983';
$config['search_api.server.solr']['backend_config']['connector_config']['core'] = 'drupal';
$config['search_api.server.solr']['backend_config']['connector_config']['path'] = '/';

// Qdrant configuration override
$config['ai_vdb_provider_qdrant.settings']['hostname'] = getenv('QDRANT_HOST') ?: 'qdrant';
$config['ai_vdb_provider_qdrant.settings']['port'] = (int)(getenv('QDRANT_PORT') ?: '6333');

// Gemini API key via environment
$config['key.key.gemini_api_key']['key_provider_settings']['env_variable'] = 'GEMINI_API_KEY';
SETTINGS_EOF
fi

# --- Wait for services ---
echo "Waiting for MariaDB..."
while ! nc -z "${DB_HOST:-mariadb}" "${DB_PORT:-3306}" 2>/dev/null; do sleep 1; done
echo "MariaDB is available."

echo "Waiting for Solr..."
while ! nc -z "${SOLR_HOST:-solr}" "${SOLR_PORT:-8983}" 2>/dev/null; do sleep 1; done
echo "Solr is available."

echo "Waiting for Qdrant..."
while ! bash -c "echo > /dev/tcp/${QDRANT_HOST:-qdrant}/${QDRANT_PORT:-6333}" 2>/dev/null; do sleep 1; done
echo "Qdrant is available."

# --- Qdrant dimension check ---
# If an existing collection has wrong dimensions (1536 from old model vs 3072 from current),
# delete it so it gets recreated with correct dimensions during indexing.
QDRANT_COLLECTION_INFO=$(curl -sf "http://${QDRANT_HOST:-qdrant}:${QDRANT_PORT:-6333}/collections/knowledge_garden" 2>/dev/null || true)
if echo "$QDRANT_COLLECTION_INFO" | grep -q '"size":1536'; then
  echo "WARNING: Qdrant collection 'knowledge_garden' has wrong dimensions (1536 vs expected 3072). Deleting for recreation..."
  curl -sf -X DELETE "http://${QDRANT_HOST:-qdrant}:${QDRANT_PORT:-6333}/collections/knowledge_garden" || true
  echo "Collection deleted. It will be recreated with correct dimensions during indexing."
fi

# --- Detect install state ---
# Use drush bootstrap check instead of raw MySQL query (more reliable, avoids spurious reinstall on DB connection issues)
INSTALLED=false
if $DRUSH status --field=bootstrap 2>/dev/null | grep -q 'Successful'; then
  INSTALLED=true
fi

if [ "$INSTALLED" = false ]; then
  echo "=== FRESH INSTALL ==="

  # Install Open Social
  $DRUSH site:install social \
    --site-name="${DRUPAL_SITE_NAME:-LocalNodes}" \
    --account-name="${DRUPAL_ADMIN_USER:-admin}" \
    --account-pass="${DRUPAL_ADMIN_PASS:-admin}" \
    --account-mail="${DRUPAL_ADMIN_EMAIL:-admin@example.com}" \
    --db-url="mysql://${DB_USER:-opensocial}:${DB_PASSWORD:-changeme}@${DB_HOST:-mariadb}:${DB_PORT:-3306}/${DB_NAME:-opensocial}" \
    -y

  # Enable LocalNodes platform (AI config, block placement, Solr/Qdrant overrides)
  echo "Enabling localnodes_platform..."
  $DRUSH en localnodes_platform -y

  # Enable instance-specific demo module
  DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"
  echo "Enabling demo module: $DEMO_MODULE..."
  $DRUSH en "$DEMO_MODULE" -y

  # Load demo content
  echo "Loading demo content..."
  $DRUSH social-demo:add file user group topic event event_enrollment comment post like

  # Index Solr
  echo "Indexing content in Solr..."
  $DRUSH search-api:index

  # Run cron 3x for vector embedding queue processing
  echo "Running cron for vector indexing (3 passes)..."
  $DRUSH cron
  sleep 5
  $DRUSH cron
  sleep 5
  $DRUSH cron

  # Enable Web3 modules
  echo "Enabling Web3 modules..."
  $DRUSH en siwe_login safe_smart_accounts group_treasury social_group_treasury -y || echo "Web3 module enable had warnings (non-fatal)"

  # Configure SIWE domain
  FQDN="${SERVICE_FQDN_OPENSOCIAL:-}"
  if [ -n "$FQDN" ]; then
    SIWE_DOMAIN=$(echo "$FQDN" | sed 's#^https\?://##')
    echo "Setting SIWE expected domain to: $SIWE_DOMAIN"
    $DRUSH config:set siwe_login.settings expected_domain "$SIWE_DOMAIN" -y || echo "SIWE domain config skipped"
  fi

  echo "=== FRESH INSTALL COMPLETE ==="
else
  echo "=== EXISTING INSTALL DETECTED ==="

  # Ensure modules are enabled (handles redeployments with changed config)
  $DRUSH en localnodes_platform -y 2>/dev/null || true
  DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"
  $DRUSH en "$DEMO_MODULE" -y 2>/dev/null || true
  $DRUSH en siwe_login safe_smart_accounts group_treasury social_group_treasury -y 2>/dev/null || true

  # Run cron
  $DRUSH cron || true

  echo "=== EXISTING INSTALL READY ==="
fi

# Clear caches
$DRUSH cr

# Start Apache in foreground
echo "Starting Apache..."
exec apache2-foreground
