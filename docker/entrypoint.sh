#!/bin/bash
set -e

SITE_URI="${SERVICE_URL_OPENSOCIAL:-http://localhost}"
DRUSH="/var/www/html/vendor/bin/drush -r /var/www/html/html --uri=$SITE_URI"

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

# --- Qdrant collection setup ---
# Ensure knowledge_garden collection exists with correct dimensions (3072 for gemini-embedding-001).
# The Qdrant provider defaults to 1536 dims, so we must manage this ourselves.
QDRANT_URL="http://${QDRANT_HOST:-qdrant}:${QDRANT_PORT:-6333}"
QDRANT_COLLECTION_INFO=$(curl -sf "$QDRANT_URL/collections/knowledge_garden" 2>/dev/null || true)

if echo "$QDRANT_COLLECTION_INFO" | grep -q '"size":1536'; then
  echo "WARNING: Qdrant collection has wrong dimensions (1536 vs 3072). Recreating..."
  curl -sf -X DELETE "$QDRANT_URL/collections/knowledge_garden" || true
  QDRANT_COLLECTION_INFO=""
fi

if [ -z "$QDRANT_COLLECTION_INFO" ] || ! echo "$QDRANT_COLLECTION_INFO" | grep -q '"size":3072'; then
  echo "Creating Qdrant collection with 3072 dimensions..."
  curl -sf -X PUT "$QDRANT_URL/collections/knowledge_garden" \
    -H 'Content-Type: application/json' \
    -d '{"vectors":{"size":3072,"distance":"Cosine"}}' || true
fi

# --- Ensure file permissions on mounted volumes ---
chown -R www-data:www-data /var/www/html/html/sites/default/files 2>/dev/null || true
chown -R www-data:www-data /var/www/private 2>/dev/null || true

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

  # Load demo content using the correct plugin IDs for the demo module.
  # Each demo module (localnodes_demo, boulder_demo) registers DemoContent plugins
  # with a module-specific prefix (e.g., localnodes_user, boulder_user).
  # The base social_demo uses unprefixed IDs (user, group, etc.).
  echo "Loading demo content for $DEMO_MODULE..."
  case "$DEMO_MODULE" in
    localnodes_demo)
      PLUGIN_PREFIX="localnodes"
      ;;
    boulder_demo)
      PLUGIN_PREFIX="boulder"
      ;;
    portland_demo)
      PLUGIN_PREFIX="portland"
      ;;
    *)
      # Default: use social_demo's unprefixed plugin IDs
      PLUGIN_PREFIX=""
      ;;
  esac

  if [ -n "$PLUGIN_PREFIX" ]; then
    $DRUSH social-demo:add \
      ${PLUGIN_PREFIX}_file \
      ${PLUGIN_PREFIX}_user_terms \
      ${PLUGIN_PREFIX}_event_type \
      ${PLUGIN_PREFIX}_user \
      ${PLUGIN_PREFIX}_group \
      ${PLUGIN_PREFIX}_topic \
      ${PLUGIN_PREFIX}_event \
      ${PLUGIN_PREFIX}_event_enrollment \
      ${PLUGIN_PREFIX}_comment \
      ${PLUGIN_PREFIX}_post \
      ${PLUGIN_PREFIX}_like
  else
    $DRUSH social-demo:add file user_terms event_type user group topic event event_enrollment comment post like
  fi

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

  # Re-import module config so YAML changes take effect on existing installs.
  # Config in config/install and config/optional is only read on first module
  # enable; this ensures redeployments pick up updated config (system prompts,
  # block placement, permissions, etc.) without requiring a full volume wipe.
  echo "Importing module config updates..."
  for CONFIG_DIR in \
    /var/www/html/html/modules/custom/localnodes_platform/config/install \
    /var/www/html/html/modules/custom/localnodes_platform/config/optional \
    /var/www/html/html/modules/custom/social_ai_indexing/config/install \
    /var/www/html/html/modules/custom/social_ai_indexing/config/optional; do
    if [ -d "$CONFIG_DIR" ]; then
      $DRUSH config:import --partial --source="$CONFIG_DIR" -y 2>/dev/null || true
    fi
  done

  # Always re-index to ensure URLs use correct base (--uri flag).
  # Without this, CLI-indexed content gets http://default/node/X URLs.
  echo "Re-indexing content with correct base URL ($SITE_URI)..."
  $DRUSH search-api:reset-tracker || true
  $DRUSH search-api:index || true
  echo "Running cron for vector indexing..."
  $DRUSH cron || true
  sleep 5
  $DRUSH cron || true

  echo "=== EXISTING INSTALL READY ==="
fi

# Ensure chatbot API permissions are granted
# (config/sync permissions aren't applied without drush cim)
$DRUSH role:perm:add anonymous 'access deepchat api' 2>/dev/null || true
$DRUSH role:perm:add authenticated 'access deepchat api' 2>/dev/null || true

# Ensure file permissions after install/content loading
# (Demo content creates files owned by root; Apache/PHP needs www-data)
chown -R www-data:www-data /var/www/html/html/sites/default/files 2>/dev/null || true
chown -R www-data:www-data /var/www/private 2>/dev/null || true

# Clear caches
$DRUSH cr

# Start Apache in foreground
echo "Starting Apache..."
exec apache2-foreground
