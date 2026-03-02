#!/bin/bash
set -e

SITE_URI="${SERVICE_URL_OPENSOCIAL:-http://localhost}"
DRUSH="/var/www/html/vendor/bin/drush -r /var/www/html/html --uri=$SITE_URI"

# --- Auto-generate hash salt if using default sentinel value ---
if [ "$DRUPAL_HASH_SALT" = "generate-random" ] || [ -z "$DRUPAL_HASH_SALT" ]; then
  export DRUPAL_HASH_SALT=$(openssl rand -hex 32)
  echo "Generated random DRUPAL_HASH_SALT."
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

  # Import full config from sync to align with repo state
  echo "Aligning config with repo state..."
  $DRUSH deploy -y || echo "WARNING: drush deploy had issues (may be normal on first install)"

  # Enable instance-specific demo module (set DEMO_MODULE=none for blank instance)
  DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"

  if [ "$DEMO_MODULE" != "none" ]; then
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
  else
    echo "DEMO_MODULE=none — skipping demo content"
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
  echo "=== EXISTING INSTALL ==="

  # Standard Drupal deploy: updb -> cr -> cim -> cr -> deploy:hook
  echo "Running drush deploy..."
  $DRUSH deploy -y

  # Ensure instance-specific modules are enabled (excluded from config sync)
  DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"
  if [ "$DEMO_MODULE" != "none" ]; then
    echo "Ensuring demo module enabled: $DEMO_MODULE..."
    $DRUSH en "$DEMO_MODULE" -y 2>/dev/null || true
  fi

  # Enable Web3 modules (excluded from config sync, enabled per-instance)
  echo "Ensuring Web3 modules enabled..."
  $DRUSH en siwe_login safe_smart_accounts group_treasury social_group_treasury -y 2>/dev/null || true

  echo "=== EXISTING INSTALL READY ==="
fi

# Ensure file permissions after install/content loading
# (Demo content creates files owned by root; Apache/PHP needs www-data)
chown -R www-data:www-data /var/www/html/html/sites/default/files 2>/dev/null || true
chown -R www-data:www-data /var/www/private 2>/dev/null || true

# Clear caches
$DRUSH cr

# Start Apache in foreground
echo "Starting Apache..."
exec apache2-foreground
