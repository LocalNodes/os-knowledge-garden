#!/bin/bash
set -e

# The site's address, for every drush call: links in mail the worker sends
# (cron, queues) and the provision callback use it. SERVICE_URL_OPENSOCIAL
# (https://community.example.org) comes first; without it, the first domain in
# SERVICE_FQDN_OPENSOCIAL, with https:// when it has no scheme (Coolify can
# list several, comma-separated). localhost only when neither is set.
site_uri() {
  local url="${SERVICE_URL_OPENSOCIAL:-}" fqdn="${SERVICE_FQDN_OPENSOCIAL:-}"
  url="${url%%,*}"; url="${url// /}"
  if [ -n "$url" ]; then
    printf '%s' "${url%/}"; return
  fi
  fqdn="${fqdn%%,*}"; fqdn="${fqdn// /}"
  if [ -n "$fqdn" ]; then
    case "$fqdn" in
      *://*) printf '%s' "${fqdn%/}" ;;
      *) printf 'https://%s' "${fqdn%/}" ;;
    esac
    return
  fi
  printf 'http://localhost'
}
SITE_URI="$(site_uri)"
if [ "$SITE_URI" = "http://localhost" ]; then
  echo "WARNING: SERVICE_URL_OPENSOCIAL is not set, so links in mail the site sends in the background will point to http://localhost. Set it in .env (docs/SELF-HOST.md, step 2)." >&2
fi
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
# Ensure knowledge_garden collection exists with correct dimensions (3072, the default size of gemini-embedding-2).
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

# --- Sign-up or manual run: the PROVISION pair must agree (23-43, T-23-201) ---
# PROVISION_ID and PROVISION_CALLBACK_TOKEN decide the run: both set is a sign-up run
# (the organizer is made blocked and handed over after the first payment), both empty is
# a manual run (an active organizer and the welcome mail at install). Exactly one of them
# non-empty is a half-configured container: taken as a manual run it would make an active
# organizer on an unpaid site; taken as a sign-up run it could never be handed over. So
# it stops here, before install-state detection and before any drush call or callback,
# on a fresh install and on a redeploy alike. The line names the variables, never a value.
# Test: scripts/ops/tests/install-resume.test.sh (5n)
if { [ -n "${PROVISION_ID:-}" ] && [ -z "${PROVISION_CALLBACK_TOKEN:-}" ]; } \
  || { [ -z "${PROVISION_ID:-}" ] && [ -n "${PROVISION_CALLBACK_TOKEN:-}" ]; }; then
  echo "ERROR: PROVISION_ID and PROVISION_CALLBACK_TOKEN must both be set (a sign-up run) or both be empty (a manual run); only one is set. Stopping before any install step."
  exit 1
fi

# --- Callbacks to the sign-up app (automated provisioning) ---
# Sent only for a sign-up: PROVISION_CALLBACK_URL, PROVISION_ID, PROVISION_ATTEMPT (a
# number) and PROVISION_CALLBACK_TOKEN must all be set (provision-instance.yml sets them
# from the private manifest). Any other instance skips this silently.
# The token is this sign-up's own HMAC, so a site can speak only for itself. A callback
# carries the provision id, the attempt, a status and the site address, or, for a
# failure, one fixed reason code. Never a sign-in address, a key, an email or free text.
# A callback that cannot be sent is a warning, never a stop: the install goes on.
# The body is built by PHP (always in this image, json_encode escapes everything); the
# values reach it through the environment, never through the code string.
send_callback() {  # send_callback STATUS REASON SITE_URL (REASON and SITE_URL may be empty)
  local status="$1" reason="$2" site_url="$3" body
  [ -n "$PROVISION_CALLBACK_URL" ] && [ -n "$PROVISION_ID" ] && [ -n "$PROVISION_ATTEMPT" ] \
    && [ -n "$PROVISION_CALLBACK_TOKEN" ] || return 0
  case "$PROVISION_ATTEMPT" in
    *[!0-9]*)
      echo "Warning: PROVISION_ATTEMPT is not a number; $status callback not sent."
      return 0
      ;;
  esac
  if ! body="$(CB_STATUS="$status" CB_REASON="$reason" CB_SITE_URL="$site_url" php -r '
      $b = ["provision_id" => (string) getenv("PROVISION_ID"), "attempt" => (int) getenv("PROVISION_ATTEMPT"), "status" => (string) getenv("CB_STATUS")];
      if ((string) getenv("CB_REASON") !== "") { $b["reason"] = getenv("CB_REASON"); }
      if ((string) getenv("CB_SITE_URL") !== "") { $b["site_url"] = getenv("CB_SITE_URL"); }
      $b["source"] = "site";
      echo json_encode($b, JSON_UNESCAPED_SLASHES);')"; then
    echo "Warning: $status callback not built (non-fatal)"
    return 0
  fi
  # -o /dev/null: the sign-up app's answer is not logged, whatever it says.
  curl -sf --max-time 15 -o /dev/null -X POST "$PROVISION_CALLBACK_URL/api/provision-callback" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $PROVISION_CALLBACK_TOKEN" \
    -d "$body" \
    || echo "Warning: $status callback failed (non-fatal)"
}
provision_callback() {  # provision_callback STATUS: a progress callback with the site address
  send_callback "$1" "" "$SITE_URI"
}
fail_callback() {       # fail_callback REASON: status failed and a fixed code, nothing else
  send_callback "failed" "$1" ""
}

# --- One failed callback when a fresh install or a resume run dies ---
# FAIL_REASON is set to the code of the step about to run and put back to install_failed
# after it. RUN_KIND is set where the script decides the path: fresh, resume or redeploy.
# On a non-zero exit the trap sends ONE failed callback with the current code, only for
# fresh and resume runs, and only while "ready" has not gone out (READY_SENT; a stop after
# ready, such as the closing cache clear, must not mark a finished site as failed). A
# redeploy sends none: its site is already live. A stop before the path is decided (a state
# read failing right at start-up) sends none either. The exit status is kept. The sign-up
# app treats a failed from the site as provisional; a later ready for the same attempt wins.
# Codes: install_failed (any other step), db_not_empty, platform_enable_failed,
# organizer_failed. The code is a fixed word: no step output, key or address ever goes in it.
# Test: scripts/ops/tests/install-resume.test.sh
FAIL_REASON=install_failed
RUN_KIND=""
READY_SENT=false
on_exit() {
  local rc=$?
  trap - EXIT
  if [ "$rc" -ne 0 ] && [ "$READY_SENT" != "true" ]; then
    case "$RUN_KIND" in
      fresh|resume) fail_callback "$FAIL_REASON" ;;
    esac
  fi
  exit "$rc"
}
trap on_exit EXIT

# Every step after site:install. Each one is safe to run again on resume.
run_post_install() {
  # Enable LocalNodes platform (AI config, block placement, Solr/Qdrant overrides)
  echo "Enabling localnodes_platform..."
  FAIL_REASON=platform_enable_failed
  $DRUSH en localnodes_platform -y
  FAIL_REASON=install_failed

  # Optional OpenRouter AI (the organizer's own key, ONB-07). Off unless AI_PROVIDER is
  # exactly "openrouter". Enabled here, before page types, demo content and indexing, so
  # the site's AI provider is fixed before anything is saved or embedded. The module's
  # install hook does the AI configuration; this only turns it on (D-21).
  if [ "${AI_PROVIDER:-}" = "openrouter" ]; then
    echo "Enabling localnodes_openrouter..."
    $DRUSH en localnodes_openrouter -y
  fi

  # NOTE: We deliberately do NOT run `drush deploy`/`config:import` here.
  # This is a distribution: all platform config lives in module config/install
  # (applied by `drush en localnodes_platform` above) plus Open Social's profile.
  # config/sync was exported from a different site instance, so its site UUID
  # and per-entity UUIDs never match a fresh `site:install`, and cim aborts with
  # "Site UUID ... does not match" + "Entities exist ... must be deleted".
  # A cache rebuild is all that's needed after module enablement.
  echo "Rebuilding caches..."
  $DRUSH cr

  # Page types the organizer confirmed at sign-up (LOCALNODES_TOPIC_TYPES). Drupal does the
  # work and the command is safe to run again, so a resume just repeats it. It prints fixed
  # sentences only. A non-zero exit stops the install here (set -e); the container retries.
  echo "Creating the community's page types..."
  $DRUSH localnodes:topic-types

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

  # Vector indexing is left to the worker loop forked below: the old 3x install-time cron passes mostly indexed nothing (ultimate_cron runs search_api_cron every 15 min).

  # --- Organizer account (and, on a manual run, the welcome mail) ---
  # When ORGANIZER_EMAIL is set, Drupal creates the organizer's admin account and the first
  # group from inside the site. This script only triggers it: it makes no sign-in address and
  # sends no mail, and nothing here names the organizer's address.
  # - Sign-up run (PROVISION_ID and PROVISION_CALLBACK_TOKEN set; 23-35, Tom's billing rule
  #   B-1): the organizer-setup command makes the account BLOCKED and sends no mail. The
  #   sign-up app charges the saved card when it hears "ready" and then calls the site's
  #   POST /localnodes/activate, which unblocks the account and sends the welcome mail. An
  #   unpaid site never has a usable organizer login. The command's step marker
  #   (organizer_setup) is what a resume skips on.
  # - Manual run (no callback variables): localnodes:organizer-welcome, as before (23-04):
  #   an active account and the welcome mail at install. Its marker (organizer_mail_sent)
  #   is what a resume skips on.
  # Either command skips what it already did, so a run that stopped part-way retries.
  if [ -n "$ORGANIZER_EMAIL" ]; then
    ORGANIZER_DONE=true
    SIGNUP_RUN=false
    if [ -n "${PROVISION_ID:-}" ] && [ -n "${PROVISION_CALLBACK_TOKEN:-}" ]; then
      SIGNUP_RUN=true
    fi
    if [ "$SIGNUP_RUN" = "true" ]; then
      if step_done organizer_setup; then
        echo "Organizer account already set up; skipping."
      else
        provision_callback "creating_user"
        # A non-zero exit stops the install (set -e): the container retries.
        FAIL_REASON=organizer_failed
        $DRUSH localnodes:organizer-setup
        FAIL_REASON=install_failed
        echo "Organizer account ready; the welcome mail goes out after the first payment."
      fi
    elif step_done organizer_mail_sent; then
      echo "Organizer already provisioned and emailed; skipping."
    else
      provision_callback "creating_user"
      # A non-zero exit stops the install (set -e): the container retries, and the
      # command skips what it already did.
      FAIL_REASON=organizer_failed
      $DRUSH localnodes:organizer-welcome
      FAIL_REASON=install_failed
      echo "Organizer provisioning complete."
    fi

    # The community's texts from sign-up (LOCALNODES_COMMUNITY_SEED, 23-34): the tagline and
    # About page, the first group's description, the assistant's context and a draft welcome
    # post. They need the organizer's account and the first group, which the step above makes
    # (organizer-setup or organizer-welcome; a blocked account is fine), so this runs after it,
    # outside both "already done" skips, also on a resume where that step had finished. The
    # command keeps its own marker (community_seed): a second run changes nothing, so an
    # organizer's edits are never put back. It needs no sign-in by the organizer, and it prints
    # fixed sentences only, never the value or a text. No closed reason code fits this step, as
    # with topic-types, so a failure reports install_failed; set -e stops the install and the
    # container retries. Direct indexing is off for this one command (as for the demo load): it
    # saves content, and an embedding quota error in search_api's post-request indexing would
    # otherwise end the command non-zero after the work is done. The worker loop indexes it.
    echo "Applying the community's texts..."
    FAIL_REASON=install_failed
    LOCALNODES_DEFER_INDEXING=1 $DRUSH localnodes:seed-community
    FAIL_REASON=install_failed
  fi

  $DRUSH state:set "$INSTALL_MARKER" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  # "ready" goes out only now, with the install marker written, and on a resume too (the
  # first one may never have arrived). It carries the site address and nothing secret. The
  # sign-up app charges the saved card on it and calls /localnodes/activate after the payment
  # (23-35, B-1); a site never sends complete (the sign-up app writes that itself). A manual
  # run sends nothing (it has no callback variables).
  if [ "${ORGANIZER_DONE:-false}" = "true" ] && [ "${SIGNUP_RUN:-false}" = "true" ]; then
    provision_callback "ready"
    READY_SENT=true
  fi
  echo "=== FRESH INSTALL COMPLETE ==="
}

# --- Detect install state ---
# Use drush bootstrap check instead of raw MySQL query (more reliable, avoids spurious reinstall on DB connection issues)
INSTALLED=false
if $DRUSH status --field=bootstrap 2>/dev/null | grep -q 'Successful'; then
  INSTALLED=true
fi

if [ "$INSTALLED" = false ]; then
  RUN_KIND=fresh
  # Drupal did not bootstrap. That alone is not proof of an empty database: a
  # DB hiccup, a bad setting or a PHP fatal would otherwise run site:install
  # over a live site and wipe it. Install only on positive evidence that the
  # database is EMPTY (no tables at all: a damaged site may have lost `users`
  # but still hold node or config tables, which site:install would drop); if
  # the check itself fails, or any table exists, stop so the container retries
  # (and a human looks).
  # Test: scripts/ops/tests/install-resume.test.sh
  # The password goes in a defaults file, not MYSQL_PWD: with MYSQL_PWD the
  # 11.x client counts the login as passwordless and turns off the check of
  # the server's certificate (MariaDB 11.4 zero-configuration TLS).
  if ! DB_TABLES="$(mariadb --defaults-extra-file=<(printf '[client]\npassword=%s\n' "${DB_PASSWORD:-changeme}") -h "${DB_HOST:-mariadb}" -P "${DB_PORT:-3306}" -u "${DB_USER:-opensocial}" -N -B \
      -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()" "${DB_NAME:-opensocial}")"; then
    echo "ERROR: Drupal did not bootstrap and the empty-database check failed; not installing. Stopping so the container retries."
    exit 1
  fi
  if [ "$DB_TABLES" != "0" ]; then
    FAIL_REASON=db_not_empty
    echo "ERROR: Drupal did not bootstrap, but database ${DB_NAME:-opensocial} is not empty (it has '$DB_TABLES' tables). Refusing to run site:install over it; stopping so the container retries. If this is a broken install on a throwaway instance, delete its volumes and redeploy."
    exit 1
  fi

  echo "=== FRESH INSTALL ==="

  # No default admin password. When none is set (or it is the old "admin"),
  # the account gets a random one that is never printed; the first sign-in
  # is a one-time link from `drush user:login` (docs/SELF-HOST.md).
  ADMIN_PASS="${DRUPAL_ADMIN_PASS:-}"
  if [ -z "$ADMIN_PASS" ] || [ "$ADMIN_PASS" = "admin" ]; then
    ADMIN_PASS="$(openssl rand -hex 24)"
    echo "No DRUPAL_ADMIN_PASS set: the admin account gets a random password (not shown). Sign in with: drush user:login"
  fi

  # Install Open Social
  $DRUSH site:install social \
    --site-name="${DRUPAL_SITE_NAME:-LocalNodes}" \
    --account-name="${DRUPAL_ADMIN_USER:-admin}" \
    --account-pass="$ADMIN_PASS" \
    --account-mail="${DRUPAL_ADMIN_EMAIL:-admin@example.com}" \
    --db-url="mysql://${DB_USER:-opensocial}:${DB_PASSWORD:-changeme}@${DB_HOST:-mariadb}:${DB_PORT:-3306}/${DB_NAME:-opensocial}" \
    install_configure_form.enable_update_status_emails=NULL \
    -y

  run_post_install
elif ! install_marker_present; then
  # Installed, but the post-install steps never all finished. This includes
  # installs made before the marker existed: their next start runs the
  # (re-runnable) post-install steps once and writes the marker.
  RUN_KIND=resume
  echo "=== RESUMING INSTALL (no completion marker) ==="
  echo "Running database updates..."
  $DRUSH updatedb -y
  $DRUSH cr
  run_post_install
else
  RUN_KIND=redeploy
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

  # Ensure the OpenRouter submodule matches AI_PROVIDER on redeploy, with the provider flip
  # guard (23-40, D-21: a site's chat and embedding models are fixed at install). Redeploy
  # only: run_post_install enables the module on the fresh and resume paths, where nothing
  # is indexed yet, and reads none of this.
  # - Module already on: nothing to do. So AI_PROVIDER_SWITCH acts only while the module is
  #   off, and a switch left set never clears the index again.
  # - Module off and the vector server's indexes empty: enable it, as the 23-27 toggle did.
  # - Module off and items already indexed (a live site on another provider): enabling
  #   OpenRouter would query its embeddings against the old provider's vectors, and search
  #   answers would go wrong with no error. Refused unless AI_PROVIDER_SWITCH is exactly
  #   "reindex". A refusal logs one REFUSED line and skips: exit 0 and no FAIL_REASON code.
  #   The codes are the sign-up callback contract (closed at four, sent only on fresh and
  #   resume runs), a redeploy sends no callback, and a non-zero exit would crash-loop a
  #   working live site over an env mismatch. The site keeps its current provider.
  # - The switch clears the vector server BEFORE enabling the module. If the clear fails, the
  #   site stays on its current provider with that provider's vectors, which is consistent;
  #   enabling first and then failing to clear would leave OpenRouter querying the old
  #   vectors, the state this guard exists to prevent. The Qdrant provider only logs a
  #   warning when a drop or create fails, so a zero drush exit is not proof: the collection
  #   is read back (0 points at 3072) before the module is enabled.
  # Both reads go through count_query: a read that fails stops the start-up, as every
  # entrypoint read does, and is never taken as "not enabled" or "empty". Every other
  # command here sits in an if or an ||, so set -e cannot end the redeploy.
  # Test: scripts/ops/tests/install-resume.test.sh (section 5l)
  if [ "${AI_PROVIDER:-}" = "openrouter" ]; then
    echo "Ensuring localnodes_openrouter enabled..."
    count_query "the localnodes_openrouter module state" \
      'echo (int) \Drupal::moduleHandler()->moduleExists("localnodes_openrouter");'
    if [ "$COUNT" -gt 0 ]; then
      echo "localnodes_openrouter is already enabled; nothing to change."
    else
      count_query "the vector index items" \
        '$server = \Drupal::entityTypeManager()->getStorage("search_api_server")->load("ai_knowledge_garden"); if (!$server) { echo "no-server"; } else { $sum = 0; foreach ($server->getIndexes() as $index) { $sum += (int) $index->getTrackerInstance()->getIndexedItemsCount(); } echo $sum; }'
      if [ "$COUNT" -eq 0 ]; then
        $DRUSH en localnodes_openrouter -y 2>/dev/null || true
      elif [ "${AI_PROVIDER_SWITCH:-}" = "reindex" ]; then
        echo "AI_PROVIDER_SWITCH=reindex: switching this site to OpenRouter. Clearing its vector index ($COUNT items), then indexing every item again with the new model."
        if ! $DRUSH search-api:server-clear ai_knowledge_garden; then
          echo "ERROR: clearing the vector index failed; localnodes_openrouter was not enabled. The site keeps its current AI provider and indexes again with it."
        else
          QDRANT_AFTER_CLEAR="$(curl -sf "$QDRANT_URL/collections/knowledge_garden" 2>/dev/null || true)"
          POINTS_CLEARED=no
          case "$QDRANT_AFTER_CLEAR" in *'"points_count":0,'*|*'"points_count":0}'*) POINTS_CLEARED=yes ;; esac
          case "$QDRANT_AFTER_CLEAR" in *'"size":3072'*) ;; *) POINTS_CLEARED=no ;; esac
          if [ "$POINTS_CLEARED" != yes ]; then
            echo "ERROR: the Qdrant collection still holds points or could not be read after the clear; localnodes_openrouter was not enabled. The site keeps its current AI provider and indexes again with it."
          elif ! $DRUSH en localnodes_openrouter -y; then
            echo "ERROR: enabling localnodes_openrouter failed after the clear. The site keeps its current AI provider and indexes again with it."
          else
            $DRUSH cr || echo "WARNING: drush cr failed after enabling localnodes_openrouter; continuing."
            soft "search-api:index" $DRUSH search-api:index
            echo "Switched to OpenRouter. Items not indexed yet stay tracked for the worker loop. Unset AI_PROVIDER_SWITCH."
          fi
        fi
      else
        echo "REFUSED: AI_PROVIDER=openrouter, but this site's vector index already holds $COUNT items made with its current embedding model. localnodes_openrouter was not enabled and the site keeps its current AI provider. To switch, set AI_PROVIDER_SWITCH=reindex and redeploy: that clears the vector index and indexes every item again with the new model."
      fi
    fi
  fi

  # Re-send "ready" for a sign-up site that has not been handed over yet (23-35, B-1). The
  # install marker is written before the install's ready callback, so a container stopped
  # between the two would come back on this path and never report: a built site that is
  # never charged. While localnodes.organizer_activated is unset, every redeploy sends ready
  # again; the sign-up app ignores a repeated ready once billing has started (23-32). It sits
  # after the provider flip guard, so a guard read that stops the start-up sends nothing, and
  # read_state stops the start-up too if the read itself fails (never taken as "not
  # activated"). A redeploy sends no failed callback (the trap reports fresh and resume runs
  # only), and a manual site, without the callback variables, sends nothing.
  if [ -n "$ORGANIZER_EMAIL" ] && [ -n "${PROVISION_ID:-}" ] && [ -n "${PROVISION_CALLBACK_TOKEN:-}" ]; then
    read_state localnodes.organizer_activated
    if [ -z "$STATE_VALUE" ]; then
      echo "Organizer not activated yet; telling the sign-up app this site is ready."
      provision_callback "ready"
    fi
  fi

  # Ensure instance-specific demo module is enabled (excluded from config sync)
  DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}"
  if [ "$DEMO_MODULE" != "none" ]; then
    echo "Ensuring demo module enabled: $DEMO_MODULE..."
    $DRUSH en "$DEMO_MODULE" -y 2>/dev/null || true
  fi

  echo "=== EXISTING INSTALL READY ==="
fi

# Ensure file permissions after install/content loading
# (Demo content creates files owned by root; Apache/PHP needs www-data)
chown -R www-data:www-data /var/www/html/html/sites/default/files 2>/dev/null || true
chown -R www-data:www-data /var/www/private 2>/dev/null || true

# Clear caches
$DRUSH cr

# --- Recurring cron + search indexing worker (Solr/vector indexing) ---
# Runs drush cron:run search_api_cron --force and drush cron as www-data every
# WORKER_INTERVAL seconds (default 60). See docker/worker-loop.sh. DRUSH is not
# exported, so pass it explicitly. Set WORKER_ENABLED=false to turn it off.
if [ "${WORKER_ENABLED:-true}" = "true" ]; then
  DRUSH="$DRUSH" /usr/local/bin/worker-loop.sh &
  echo "Worker loop started (pid $!, interval ${WORKER_INTERVAL:-60}s)."
else
  echo "Worker loop disabled (WORKER_ENABLED=${WORKER_ENABLED})."
fi

# Start Apache in foreground
echo "Starting Apache..."
exec apache2-foreground
