#!/bin/bash
set -e

SITE_URI="${SERVICE_URL_OPENSOCIAL:-http://localhost}"
DRUSH="${LOCALNODES_DRUSH:-/var/www/html/vendor/bin/drush} -r /var/www/html/html --uri=$SITE_URI"

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

# --- Install completion marker ---
# A Drupal state key, so it lives in the database and goes with the install.
# It is written only after every post-install step below has run. A database
# that bootstraps but has no marker is an install that stopped part-way (for
# example the container was killed or a step failed): the next start resumes
# the post-install steps instead of taking the redeploy path, which would skip
# them for good and still print READY (staging, 2026-11-06).
# Test: scripts/ops/tests/install-resume.test.sh
INSTALL_MARKER="localnodes.install_complete"
# Reads never guess: if drush cannot answer, the entrypoint stops (non-zero),
# nothing is marked, and the container's restart tries again. A failed read must
# not look like "not done" (re-sending mail) or "empty site" (reloading content).
read_state() {     # read_state KEY: sets STATE_VALUE ('' when the key is unset)
  if ! STATE_VALUE="$($DRUSH state:get "$1")"; then
    echo "ERROR: drush state:get $1 failed; stopping so the container retries."
    exit 1
  fi
}
install_marker_present() {
  read_state "$INSTALL_MARKER"
  [ -n "$STATE_VALUE" ]
}
step_done() {      # step_done NAME: the step finished on an earlier run
  read_state "localnodes.install_step.$1"
  [ -n "$STATE_VALUE" ]
}
mark_step() {      # mark_step NAME
  $DRUSH state:set "localnodes.install_step.$1" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
count_query() {    # count_query LABEL PHP: sets COUNT, or stops the entrypoint
  if ! COUNT="$($DRUSH php:eval "$2")"; then
    echo "ERROR: counting $1 failed; stopping so the container retries (nothing loaded, created or sent)."
    exit 1
  fi
  case "$COUNT" in
    ''|*[!0-9]*)
      echo "ERROR: counting $1 returned '$COUNT', not a number; stopping so the container retries."
      exit 1
      ;;
  esac
}
# Index steps are allowed to fail: an AI quota or a slow Solr must not stop the
# install or the container. Items that did not index stay tracked, and cron
# indexes them later.
soft() {           # soft LABEL CMD...
  local label="$1" rc=0; shift
  "$@" || rc=$?
  [ "$rc" -eq 0 ] && return 0
  echo "WARNING: $label failed (exit $rc); continuing. Unindexed items stay tracked for cron."
}

load_demo_content() {
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

  # LOCALNODES_DEFER_INDEXING=1 turns off direct (post-request) indexing for
  # this one command (settings.php), so no embedding call runs inside it.
  if [ -n "$PLUGIN_PREFIX" ]; then
    LOCALNODES_DEFER_INDEXING=1 $DRUSH social-demo:add \
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
    LOCALNODES_DEFER_INDEXING=1 $DRUSH social-demo:add file user_terms event_type user group topic event event_enrollment comment post like
  fi
  mark_step demo_content
}

# Callback helper (automated provisioning)
provision_callback() {
  local STATUS="$1"
  shift
  if [ -n "$PROVISION_CALLBACK_URL" ] && [ -n "$STRIPE_SESSION_ID" ] && [ -n "$PROVISION_CALLBACK_SECRET" ]; then
    curl -sf -X POST "$PROVISION_CALLBACK_URL/api/provision-callback" \
      -H "Content-Type: application/json" \
      -H "Authorization: Bearer $PROVISION_CALLBACK_SECRET" \
      -d "{\"session_id\":\"$STRIPE_SESSION_ID\",\"status\":\"$STATUS\"$*}" \
      || echo "Warning: $STATUS callback failed (non-fatal)"
  fi
}

# --- Organizer welcome email ---
welcome_login_url() {  # sets LOGIN_URL; non-zero if drush uli fails
  LOGIN_URL="$($DRUSH uli --name="$ORGANIZER_USERNAME" 2>&1)" || return 1
  echo "Login URL: $LOGIN_URL"
}
# Sends the welcome email (uses LOGIN_URL) and marks organizer_mail_sent when
# Resend accepted it. Never stops the entrypoint: a failed send is logged, left
# unmarked, and retried on the next start (retry_pending_welcome).
#
# Idempotency-Key: Resend drops a second send with the same key for 24 h. The
# key is stable per site and organizer, so a retry after a lost response or a
# failed marker write cannot mail twice. Resend answers a reused key with 409:
#   invalid_idempotent_request      the key was used with a different body (a
#                                   retry carries a new login link): the first
#                                   email went out, and its link still works.
#   concurrent_idempotent_requests  the first request is still running: retry
#                                   later with the same key AND the same body.
# Any other 409 or error is not counted as sent.
send_welcome_email() {
  if [ -z "$RESEND_API_KEY" ]; then
    echo "WARNING: RESEND_API_KEY is not set; welcome email not sent."
    return 0
  fi
  local key body response code name attempt=1
  key="welcome-$(printf '%s\n%s' "$SITE_URI" "$ORGANIZER_EMAIL" | { sha256sum 2>/dev/null || shasum -a 256; } | cut -d' ' -f1)"
  body="{
    \"from\": \"LocalNodes <${MAIL_FROM:-hello@localnodes.xyz}>\",
    \"to\": [\"$ORGANIZER_EMAIL\"],
    \"subject\": \"Your knowledge garden is ready!\",
    \"html\": \"<h1>Your knowledge garden is ready!</h1><p>Your community's knowledge garden at <a href='$SITE_URI'>$SITE_URI</a> is live and waiting for you.</p><h2>Getting started</h2><ol><li><strong>Set your password</strong> &mdash; click the button below (single-use, works once).</li><li><strong>Explore your garden</strong> &mdash; visit Groups, Events, and Topics to see the demo content.</li><li><strong>Invite your community</strong> &mdash; use the Groups section to add members.</li><li><strong>Try the AI assistant</strong> &mdash; ask the chat widget anything about your community's content.</li></ol><p><a href='$LOGIN_URL' style='background:#14b8a6;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;'>Set Password &amp; Log In</a></p><p style='color:#888;font-size:0.85em;'>This link is single-use and will expire. If it expires, visit <a href='$SITE_URI/user/password'>$SITE_URI/user/password</a> to request a new one.</p><p>Happy gardening,<br>The LocalNodes Team</p>\"
  }"
  while :; do
    response="$(curl -s -w "\n%{http_code}" -X POST "https://api.resend.com/emails" \
      -H "Authorization: Bearer $RESEND_API_KEY" \
      -H "Idempotency-Key: $key" \
      -H "Content-Type: application/json" \
      -d "$body")" || true
    code="$(printf '%s\n' "$response" | tail -1)"
    name="$(printf '%s\n' "$response" | sed '$d' | grep -o '"name"[[:space:]]*:[[:space:]]*"[a-z_]*"' | head -1 | sed 's/.*"\([a-z_]*\)"$/\1/')"
    case "$code:$name" in
      200:*)
        echo "Welcome email sent to $ORGANIZER_EMAIL"
        mark_step organizer_mail_sent
        return 0
        ;;
      409:invalid_idempotent_request)
        echo "Welcome email already accepted by Resend under this idempotency key (409 invalid_idempotent_request); not sending again."
        mark_step organizer_mail_sent
        return 0
        ;;
      409:concurrent_idempotent_requests)
        if [ "$attempt" -lt 3 ]; then
          echo "Resend is still processing a welcome email with this key (409 concurrent_idempotent_requests); retrying in 5 s with the same key and body ($attempt/3)."
          attempt=$((attempt + 1))
          sleep 5
          continue
        fi
        echo "WARNING: Resend still reports the welcome email in progress after 3 attempts; not marked as sent, the next start retries it."
        return 0
        ;;
      *)
        echo "WARNING: Welcome email failed (HTTP $code${name:+, $name}); not marked as sent, the next start retries it."
        return 0
        ;;
    esac
  done
}
# On every later start: an account this entrypoint created whose welcome email
# never went out (a 429/5xx, a lost response, a crash) gets one more attempt.
retry_pending_welcome() {
  [ -n "$ORGANIZER_EMAIL" ] || return 0
  step_done organizer_mail_sent && return 0
  step_done organizer_account || return 0
  ORGANIZER_USERNAME="${ORGANIZER_NAME:-Community} Admin"
  echo "Organizer welcome email is pending (account created, email not sent); trying once on this start."
  if welcome_login_url; then
    send_welcome_email
  else
    echo "WARNING: drush uli failed; the welcome email stays pending for the next start."
  fi
}

# Every step after site:install. Each one is safe to run again on resume.
run_post_install() {
  # Enable LocalNodes platform (AI config, block placement, Solr/Qdrant overrides)
  echo "Enabling localnodes_platform..."
  $DRUSH en localnodes_platform -y

  # NOTE: We deliberately do NOT run `drush deploy`/`config:import` here.
  # This is a distribution: all platform config lives in module config/install
  # (applied by `drush en localnodes_platform` above) plus Open Social's profile.
  # config/sync was exported from a different site instance, so its site UUID
  # and per-entity UUIDs never match a fresh `site:install`, and cim aborts with
  # "Site UUID ... does not match" + "Entities exist ... must be deleted".
  # A cache rebuild is all that's needed after module enablement.
  echo "Rebuilding caches..."
  $DRUSH cr

  # Optional Web3 stack (SIWE login, Safe smart accounts, group treasuries).
  # Off by default; set WEB3_ENABLED=true to enable on this instance.
  if [ "${WEB3_ENABLED:-false}" = "true" ]; then
    echo "Enabling localnodes_web3..."
    $DRUSH en localnodes_web3 -y
  fi

  # Enable instance-specific demo module (set DEMO_MODULE=none for blank instance)
  DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"

  if [ "$DEMO_MODULE" != "none" ]; then
    echo "Enabling demo module: $DEMO_MODULE..."
    $DRUSH en "$DEMO_MODULE" -y

    # Demo content loads once. demo_content_started is marked before the load
    # and demo_content after it, so a load that stopped part-way is told apart
    # from a site installed before these markers (content, no markers).
    if step_done demo_content; then
      echo "Demo content already loaded; skipping."
    else
      count_query "nodes" 'echo \Drupal::entityQuery("node")->accessCheck(FALSE)->count()->execute();'
      if step_done demo_content_started; then
        echo "WARNING: an earlier demo content load started but did not finish ($COUNT nodes exist). Loading again; social-demo:add skips every entity whose UUID already exists."
        load_demo_content
      elif [ "$COUNT" -gt 0 ]; then
        echo "Demo content: $COUNT nodes from an install made before the step markers; adopting it as loaded."
        mark_step demo_content
      else
        mark_step demo_content_started
        load_demo_content
      fi
    fi
  else
    echo "DEMO_MODULE=none — skipping demo content"
  fi

  # Index Solr (and the vector indexes). Non-fatal: see soft() above.
  echo "Indexing content in Solr..."
  soft "search-api:index" $DRUSH search-api:index

  # Run cron 3x for vector embedding queue processing
  echo "Running cron for vector indexing (3 passes)..."
  soft "cron pass 1" $DRUSH cron
  sleep 5
  soft "cron pass 2" $DRUSH cron
  sleep 5
  soft "cron pass 3" $DRUSH cron

  # --- Organizer account provisioning ---
  # When ORGANIZER_EMAIL is set (automated provisioning), create the organizer's admin account,
  # generate a one-time login link, send callbacks, and send the welcome email.
  # organizer_account (marked just before user:create) and organizer_mail_sent
  # are separate: a run that created the account but stopped before the email
  # retries the login link and the email; an account with neither marker comes
  # from an install made before the markers and is adopted, with no second email.
  if [ -n "$ORGANIZER_EMAIL" ]; then
    ORGANIZER_USERNAME="${ORGANIZER_NAME:-Community} Admin"
    if step_done organizer_mail_sent; then
      echo "Organizer already provisioned and emailed; skipping."
    else
      # The address reaches PHP through the environment, never through the code string.
      count_query "organizer accounts" 'echo count(\Drupal::entityTypeManager()->getStorage("user")->loadByProperties(["mail" => getenv("ORGANIZER_EMAIL")]));'
      if [ "$COUNT" -gt 0 ] && ! step_done organizer_account; then
        echo "Organizer account exists from an install made before the step markers; adopting it (no second welcome email)."
        # mail_sent first: if the second write fails, the next start skips
        # (mail_sent is marked) instead of resending.
        mark_step organizer_mail_sent
        mark_step organizer_account
      else
        if [ "$COUNT" -eq 0 ]; then
          provision_callback "creating_user" ",\"site_url\":\"$SITE_URI\""
          # Marked BEFORE user:create: from here on an existing account is this
          # entrypoint's own, so a crash anywhere below retries the email
          # instead of adopting the account as a pre-marker one.
          mark_step organizer_account
          PASSWORD=$(openssl rand -hex 16)
          $DRUSH user:create "$ORGANIZER_USERNAME" --mail="$ORGANIZER_EMAIL" --password="$PASSWORD" 2>&1
        else
          echo "WARNING: the organizer account was created by an earlier run that stopped before the welcome email; sending the login link and the email now."
        fi
        $DRUSH user:role:add sitemanager "$ORGANIZER_USERNAME" 2>&1

        # One-time login URL; if drush cannot make it, stop so the container
        # retries (organizer_account is marked, so the retry sends the email).
        if ! welcome_login_url; then
          echo "ERROR: drush uli failed; stopping so the container retries."
          exit 1
        fi

        # Send welcome email via Resend
        provision_callback "sending_email" ",\"site_url\":\"$SITE_URI\",\"login_url\":\"$LOGIN_URL\""
        send_welcome_email

        provision_callback "complete" ",\"site_url\":\"$SITE_URI\",\"login_url\":\"$LOGIN_URL\""
        echo "Organizer provisioning complete for $ORGANIZER_EMAIL"
      fi
    fi
  fi

  $DRUSH state:set "$INSTALL_MARKER" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "=== FRESH INSTALL COMPLETE ==="
}

# --- Detect install state ---
# Use drush bootstrap check instead of raw MySQL query (more reliable, avoids spurious reinstall on DB connection issues)
INSTALLED=false
if $DRUSH status --field=bootstrap 2>/dev/null | grep -q 'Successful'; then
  INSTALLED=true
fi

if [ "$INSTALLED" = false ]; then
  # Drupal did not bootstrap. That alone is not proof of an empty database: a
  # DB hiccup, a bad setting or a PHP fatal would otherwise run site:install
  # over a live site and wipe it. Install only on positive evidence that the
  # database has no Drupal `users` table; if the check itself fails, or the
  # table exists, stop so the container retries (and a human looks).
  # Test: scripts/ops/tests/install-resume.test.sh
  if ! USERS_TABLES="$(MYSQL_PWD="${DB_PASSWORD:-changeme}" mariadb -h "${DB_HOST:-mariadb}" -P "${DB_PORT:-3306}" -u "${DB_USER:-opensocial}" -N -B \
      -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'" "${DB_NAME:-opensocial}")"; then
    echo "ERROR: Drupal did not bootstrap and the empty-database check failed; not installing. Stopping so the container retries."
    exit 1
  fi
  if [ "$USERS_TABLES" != "0" ]; then
    echo "ERROR: Drupal did not bootstrap, but database ${DB_NAME:-opensocial} already has a users table (check returned '$USERS_TABLES'). Refusing to run site:install over it; stopping so the container retries. If this is a broken install on a throwaway instance, delete its volumes and redeploy."
    exit 1
  fi

  echo "=== FRESH INSTALL ==="

  # Install Open Social
  $DRUSH site:install social \
    --site-name="${DRUPAL_SITE_NAME:-LocalNodes}" \
    --account-name="${DRUPAL_ADMIN_USER:-admin}" \
    --account-pass="${DRUPAL_ADMIN_PASS:-admin}" \
    --account-mail="${DRUPAL_ADMIN_EMAIL:-admin@example.com}" \
    --db-url="mysql://${DB_USER:-opensocial}:${DB_PASSWORD:-changeme}@${DB_HOST:-mariadb}:${DB_PORT:-3306}/${DB_NAME:-opensocial}" \
    -y

  run_post_install
elif ! install_marker_present; then
  # Installed, but the post-install steps never all finished. This includes
  # installs made before the marker existed: their next start runs the
  # (re-runnable) post-install steps once and writes the marker.
  echo "=== RESUMING INSTALL (no completion marker) ==="
  echo "Running database updates..."
  $DRUSH updatedb -y
  $DRUSH cr
  run_post_install
else
  echo "=== EXISTING INSTALL ==="

  # Apply pending DB updates and rebuild caches. We intentionally do NOT run
  # `config:import` (i.e. not `drush deploy`): config/sync carries a foreign
  # site UUID and per-entity UUIDs, so cim aborts on every instance — including
  # boulder/portland if they were ever redeployed. Platform config ships in
  # module config/install and updates via module install/update hooks instead.
  echo "Running database updates..."
  $DRUSH updatedb -y
  $DRUSH cr

  # Ensure optional Web3 stack matches WEB3_ENABLED on redeploy.
  if [ "${WEB3_ENABLED:-false}" = "true" ]; then
    echo "Ensuring localnodes_web3 enabled..."
    $DRUSH en localnodes_web3 -y 2>/dev/null || true
  fi

  # Ensure instance-specific demo module is enabled (excluded from config sync)
  DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"
  if [ "$DEMO_MODULE" != "none" ]; then
    echo "Ensuring demo module enabled: $DEMO_MODULE..."
    $DRUSH en "$DEMO_MODULE" -y 2>/dev/null || true
  fi

  retry_pending_welcome

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
