#!/usr/bin/env bash
# Regression test: a fresh install must not die on an indexing failure, and an
# install that stopped part-way must be resumed on the next start, not treated
# as a finished site.
#
# What went wrong (staging, 2026-11-06T04:41Z): Gemini's daily embedding quota
# was used up. During `drush social-demo:add`, search_api's post-request
# indexing (social_posts/social_comments index directly) embedded the new items,
# the quota error ended the command non-zero, `set -e` stopped the entrypoint,
# and the container restarted. Drupal now bootstrapped, so the restart took the
# redeploy path: the Solr index and the three vector cron passes never ran, and
# the log still said `=== EXISTING INSTALL READY ===`.
#
# The fix (docker/entrypoint.sh, settings.php):
#   - the demo content load runs with LOCALNODES_DEFER_INDEXING=1, which turns
#     off direct indexing for that one command;
#   - search-api:index and the cron passes are non-fatal (WARNING, continue);
#   - a completion marker (Drupal state localnodes.install_complete) is written
#     only after every post-install step; a site with no marker resumes them,
#     and `=== FRESH INSTALL COMPLETE ===` is printed only with the marker;
#   - steps that must not run twice (demo content, organizer welcome mail) have
#     their own step markers.
#
# Phase 23 (23-12) changed the organizer part. The entrypoint no longer makes a
# sign-in address, mails with curl, or names the organizer's address in a log
# line. After `drush en localnodes_platform` it runs two Drupal commands
# (localnodes:topic-types, then localnodes:organizer-welcome, which creates the
# account and sends the mail from inside Drupal), and it sends progress
# callbacks that carry the site address and nothing secret, authenticated with
# the per-provision token (the last one is `ready` since 23-35). When a fresh install or a resume run dies, an EXIT
# trap sends one `failed` callback with a fixed reason code; a redeploy stays
# silent.
#
# Phase 23 (23-35, Tom's billing rule B-1) changed the sign-up path again. A
# sign-up run (PROVISION_ID and PROVISION_CALLBACK_TOKEN set) runs
# localnodes:organizer-setup instead of localnodes:organizer-welcome: the
# organizer's account is made blocked, with the first group, and no mail goes
# out. The last callback is `ready`, not `complete`: the sign-up app charges
# the saved card on it and calls the site's POST /localnodes/activate after the
# payment, which unblocks the account and sends the welcome mail. A redeploy of
# a sign-up site whose organizer is not activated yet sends `ready` again, so a
# ready that never arrived is not lost. A manual run (no callback variables)
# keeps localnodes:organizer-welcome and sends nothing.
#
# The entrypoint runs for real under bash, with drush and the system tools
# replaced by stubs that keep state in a temp dir and fail on demand.
#
# Usage: bash scripts/ops/tests/install-resume.test.sh
#   IMAGE=ghcr.io/localnodes/os-knowledge-garden:<sha7>  also checks, inside the
#   built image, that settings.php turns direct indexing off only when
#   LOCALNODES_DEFER_INDEXING=1 (needs docker).
set -u
cd "$(dirname "$0")/../../.."
ROOT="$PWD"
ENTRYPOINT="$ROOT/docker/entrypoint.sh"
SETTINGS="$ROOT/html/sites/default/settings.php"

PASS=0; FAIL=0
check() {  # check NAME EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then PASS=$((PASS + 1)); echo "PASS $1"
  else FAIL=$((FAIL + 1)); echo "FAIL $1 (expected $2, got $3)"; fi
}
has() { grep -qF -- "$2" "$1" && echo yes || echo no; }       # has FILE TEXT
calls() { grep -c -- "^$2" "$1/calls" 2>/dev/null || true; }  # calls DIR CMD

TOP="$(mktemp -d)"
trap 'rm -rf "$TOP"' EXIT

new_site() {  # new_site -> prints a fresh state dir with stubs
  local t; t="$(mktemp -d "$TOP/site.XXXXXX")"
  mkdir -p "$t/bin" "$t/state"
  for c in nc chown sleep; do printf '#!/bin/sh\nexit 0\n' > "$t/bin/$c"; done
  # curl: a fake sign-up app. Logs every URL (curlurls). A POST to
  # /api/provision-callback also logs its body (callbacks), its Authorization
  # header (auth) and "callback <status>" in the shared call order (seq).
  # FAIL_CALLBACK=1: the call fails (exit 22), as curl -f does on a 4xx/5xx.
  # A GET (no -X) of the Qdrant collection logs "qdrant read" in seq and prints
  # $SITE/qdrant_info when it exists (23-40). The start-up probe is such a GET,
  # so every run has one "qdrant read" before its drush steps.
  # Anything else (the other Qdrant calls) answers nothing and succeeds.
  cat > "$t/bin/curl" <<'CURL'
#!/bin/sh
url=""; hdr=""; body=""; prev=""; method=GET
for a in "$@"; do
  case "$a" in http*) url="$a" ;; esac
  [ "$prev" = "-H" ] && case "$a" in Authorization:*) hdr="$a" ;; esac
  [ "$prev" = "-d" ] && body="$a"
  [ "$prev" = "-X" ] && method="$a"
  prev="$a"
done
echo "$url" >> "$SITE/curlurls"
case "$url" in
  */api/provision-callback)
    echo "$hdr" >> "$SITE/auth"
    echo "$body" >> "$SITE/callbacks"
    echo "callback $(printf '%s' "$body" | sed -n 's/.*"status":"\([a-z_]*\)".*/\1/p')" >> "$SITE/seq"
    [ "${FAIL_CALLBACK:-0}" = 1 ] && exit 22
    ;;
  */collections/knowledge_garden)
    if [ "$method" = GET ]; then
      echo "qdrant read" >> "$SITE/seq"
      [ -f "$SITE/qdrant_info" ] && cat "$SITE/qdrant_info"
    fi
    ;;
esac
exit 0
CURL
  printf '#!/bin/sh\necho deadbeef\n' > "$t/bin/openssl"
  # Only the Qdrant readiness probe calls `bash -c` by name.
  printf '#!/bin/sh\nexit 0\n' > "$t/bin/bash"
  printf '#!/bin/sh\necho "APACHE STARTED"\n' > "$t/bin/apache2-foreground"
  # mariadb: the empty-database check. Answers the table count (users_table = 1
  # table, other_tables = 3 more); logs its argv and whether the password came
  # through a defaults file (never argv, never MYSQL_PWD: the 11.x client then
  # skips the server certificate check).
  cat > "$t/bin/mariadb" <<'DB'
#!/bin/sh
pf=no
case "$1" in --defaults-extra-file=*) grep -q '^password=.' "${1#--defaults-extra-file=}" && pf=set ;; esac
echo "$* PWD_ENV=${MYSQL_PWD:+set} PWD_FILE=$pf" >> "$SITE/dbcalls"
[ "${FAIL_DB_QUERY:-0}" = 1 ] && { echo "ERROR 2002: Can't connect" >&2; exit 1; }
n=0; [ -f "$SITE/users_table" ] && n=$((n + 1))
# A query that asks only about `users` gets only that; any other gets all tables.
case "$*" in *"table_name = 'users'"*) ;; *) [ -f "$SITE/other_tables" ] && n=$((n + 3)) ;; esac
echo "$n"
DB
  cat > "$t/bin/drush" <<'DRUSH'
#!/bin/sh
# Fake drush. Skips the -r/--uri prefix, logs the command, keeps state in $SITE.
# calls: "<cmd> DEFER=<flag>". seq: "drush <cmd> <args>" (the order of events,
# shared with the curl stub's "callback <status>" lines).
while [ $# -gt 0 ]; do
  case "$1" in -r) shift 2 ;; --uri=*) shift ;; *) break ;; esac
done
cmd="$1"; shift
echo "$cmd DEFER=${LOCALNODES_DEFER_INDEXING:-}" >> "$SITE/calls"
echo "drush $cmd $*" >> "$SITE/seq"
case "$cmd" in
  status) [ "${FAIL_BOOTSTRAP:-0}" = 1 ] && exit 1; [ -f "$SITE/installed" ] && echo "Successful" ;;
  site:install) echo "$*" > "$SITE/siteinstall_args"; [ -n "${FAIL_INSTALL:-}" ] && exit "$FAIL_INSTALL"; touch "$SITE/installed" "$SITE/users_table" ;;
  en)
    [ "${FAIL_EN:-0}" = 1 ] && [ "$1" = localnodes_platform ] && exit 1
    # 23-40: FAIL_EN_OR=1 fails enabling localnodes_openrouter; otherwise it is marked on.
    if [ "$1" = localnodes_openrouter ]; then
      [ "${FAIL_EN_OR:-0}" = 1 ] && exit 1
      touch "$SITE/or_enabled"
    fi ;;
  updatedb) [ "${FAIL_UPDB:-0}" = 1 ] && exit 1 ;;
  cr) [ "${FAIL_FINAL_CR:-0}" = 1 ] && [ -f "$SITE/state/localnodes.install_complete" ] && exit 1 ;;
  state:get)
    [ "${FAIL_STATE:-0}" = 1 ] && exit 1
    # 23-35: FAIL_GET_KEY=<key> fails the read of that one key only.
    [ -n "${FAIL_GET_KEY:-}" ] && [ "$1" = "$FAIL_GET_KEY" ] && exit 1
    [ -f "$SITE/state/$1" ] && cat "$SITE/state/$1" ;;
  state:set) [ -n "${FAIL_SET_KEY:-}" ] && [ "$1" = "$FAIL_SET_KEY" ] && exit 1; printf '%s' "$2" > "$SITE/state/$1" ;;
  php:eval)
    case "$1" in
      *'"node"'*) [ "${FAIL_NODE_COUNT:-0}" = 1 ] && exit 1; cat "$SITE/nodes" 2>/dev/null || echo 0 ;;
      # 23-40 provider flip guard: the module state (1 when $SITE/or_enabled exists)
      # and the vector index item count ($SITE/indexed; INDEXED_OUT overrides it).
      *moduleExists*) [ "${FAIL_OR_READ:-0}" = 1 ] && exit 1; [ -f "$SITE/or_enabled" ] && echo 1 || echo 0 ;;
      *getIndexedItemsCount*)
        [ "${FAIL_INDEXED_READ:-0}" = 1 ] && exit 1
        if [ -n "${INDEXED_OUT:-}" ]; then echo "$INDEXED_OUT"; else cat "$SITE/indexed" 2>/dev/null || echo 0; fi ;;
      *) exit 1 ;;
    esac ;;
  social-demo:add) [ "${FAIL_DEMO:-0}" = 1 ] && { echo 5 > "$SITE/nodes"; exit 1; }; echo 18 > "$SITE/nodes" ;;
  # 23-40: clearing the vector server empties the Qdrant collection (0 points at
  # 3072) unless QDRANT_KEEPS_POINTS=1 (a backend that only logged a warning).
  search-api:server-clear)
    [ "${FAIL_CLEAR:-0}" = 1 ] && exit 1
    [ "${QDRANT_KEEPS_POINTS:-0}" = 1 ] || printf '%s' '{"result":{"status":"green","optimizer_status":"ok","indexed_vectors_count":0,"points_count":0,"segments_count":2,"config":{"params":{"vectors":{"size":3072,"distance":"Cosine"}}}},"status":"ok","time":0.0001}' > "$SITE/qdrant_info" ;;
  search-api:index) [ "${FAIL_INDEX:-0}" = 1 ] && { echo "AiQuotaException: embed_content per day" >&2; exit 3; } ;;
  cron) [ "${FAIL_CRON:-0}" = 1 ] && exit 4 ;;
  localnodes:topic-types) [ "${FAIL_TOPIC:-0}" = 1 ] && exit 1; echo "Added 0 page types." ;;
  localnodes:seed-community)
    # Mirrors 23-34's command: fixed sentences only, never the value or a text; a
    # set marker means "already applied" (exit 0, nothing changes).
    [ "${FAIL_SEED:-0}" = 1 ] && { echo "LOCALNODES_COMMUNITY_SEED is not a community seed."; exit 1; }
    if [ -f "$SITE/state/localnodes.install_step.community_seed" ]; then echo "Community seed already applied."; exit 0; fi
    printf '%s' "now" > "$SITE/state/localnodes.install_step.community_seed"
    echo "Community seed applied." ;;
  localnodes:organizer-setup)
    # Mirrors 23-35's command: the blocked account and the first group, no mail,
    # fixed sentences only; its step marker is what the entrypoint skips on.
    [ "${FAIL_ORGANIZER_SETUP:-0}" = 1 ] && { echo "The sitemanager role does not exist."; exit 1; }
    touch "$SITE/organizer"
    printf '%s' "now" > "$SITE/state/localnodes.install_step.organizer_account"
    printf '%s' "now" > "$SITE/state/localnodes.install_step.organizer_setup"
    echo "Organizer account ready (blocked until the first payment)." ;;
  localnodes:organizer-welcome)
    # Mirrors 23-04's command: fixed sentences only, never the address; a set
    # sent marker means "already sent" (exit 0, no second mail).
    [ "${FAIL_WELCOME:-0}" = 1 ] && { echo "Welcome mail not sent."; exit 1; }
    if [ -f "$SITE/state/localnodes.install_step.organizer_mail_sent" ]; then echo "Welcome mail already sent."; exit 0; fi
    touch "$SITE/organizer"; echo "welcome" >> "$SITE/mail"
    printf '%s' "now" > "$SITE/state/localnodes.install_step.organizer_account"
    printf '%s' "now" > "$SITE/state/localnodes.install_step.organizer_mail_sent"
    echo "Welcome mail sent." ;;
esac
exit 0
DRUSH
  chmod +x "$t/bin/"*
  echo "$t"
}

run_ep() {  # run_ep DIR [VAR=value ...] -> exit code; output in DIR/out
  local t="$1"; shift
  env -i PATH="$t/bin:/usr/bin:/bin" HOME="$t" SITE="$t" LOCALNODES_DRUSH="$t/bin/drush" \
    DRUPAL_HASH_SALT=test DEMO_MODULE=localnodes_demo "$@" \
    /bin/bash "$ENTRYPOINT" > "$t/out" 2>&1
  echo $?
}

# 1. Fresh install, every index step fails (quota): no exit, warnings, marker.
t="$(new_site)"
rc="$(run_ep "$t" FAIL_INDEX=1 FAIL_CRON=1)"
check "fresh + index failures: container starts (exit 0)" 0 "$rc"
check "fresh + index failures: apache started" yes "$(has "$t/out" "APACHE STARTED")"
check "fresh + index failures: index warning logged" yes "$(has "$t/out" "WARNING: search-api:index failed (exit 3)")"
# The install-time cron passes were replaced by docker/worker-loop.sh (Phase 22, 22-06).
check "fresh + index failures: no install-time cron passes" 0 "$(grep -c 'WARNING: cron pass' "$t/out")"
check "fresh: worker loop forked before apache" yes "$(has "$t/out" "Worker loop started")"
check "fresh + index failures: FRESH INSTALL COMPLETE" yes "$(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"
check "fresh + index failures: completion marker written" yes "$([ -s "$t/state/localnodes.install_complete" ] && echo yes || echo no)"
check "fresh: demo content loaded with direct indexing deferred" 1 "$(calls "$t" "social-demo:add DEFER=1")"
check "fresh: no other command runs with indexing deferred" 1 "$(grep -c 'DEFER=1' "$t/calls")"

# 2. Restart of the 2026-11-06 staging state: installed, content present, no
#    markers at all. Must resume (updb, index, cron), not reload demo content.
t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"
rc="$(run_ep "$t")"
check "no marker: exit 0" 0 "$rc"
check "no marker: RESUMING INSTALL" yes "$(has "$t/out" "=== RESUMING INSTALL (no completion marker) ===")"
check "no marker: not the redeploy path" no "$(has "$t/out" "=== EXISTING INSTALL")"
check "no marker: updatedb ran" 1 "$(calls "$t" "updatedb")"
check "no marker: demo content NOT reloaded" 0 "$(calls "$t" "social-demo:add")"
check "no marker: search-api:index ran" 1 "$(calls "$t" "search-api:index")"
check "no marker: no install-time cron passes (worker loop indexes)" 0 "$(calls "$t" "cron")"
check "no marker: FRESH INSTALL COMPLETE" yes "$(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"
check "no marker: completion marker written" yes "$([ -s "$t/state/localnodes.install_complete" ] && echo yes || echo no)"

# 3. Restart with the marker: the plain redeploy path, no indexing.
t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"; echo x > "$t/state/localnodes.install_complete"
rc="$(run_ep "$t")"
check "marker: exit 0" 0 "$rc"
check "marker: EXISTING INSTALL READY" yes "$(has "$t/out" "=== EXISTING INSTALL READY ===")"
check "marker: no resume" no "$(has "$t/out" "RESUMING")"
check "marker: no search-api:index" 0 "$(calls "$t" "search-api:index")"
check "marker: no FRESH INSTALL COMPLETE" no "$(has "$t/out" "FRESH INSTALL COMPLETE")"

# 4. A demo load that fails part-way (5 of 18 nodes) stops the install with no
#    marker; the restart sees demo_content_started without demo_content, warns,
#    and loads again (social-demo:add skips existing UUIDs).
t="$(new_site)"
rc="$(run_ep "$t" FAIL_DEMO=1)"
check "partial demo: install stops (non-zero)" yes "$([ "$rc" != 0 ] && echo yes || echo no)"
check "partial demo: no FRESH INSTALL COMPLETE" no "$(has "$t/out" "FRESH INSTALL COMPLETE")"
check "partial demo: no completion marker" no "$([ -e "$t/state/localnodes.install_complete" ] && echo yes || echo no)"
check "partial demo: started marked, done not marked" "yes no" "$([ -e "$t/state/localnodes.install_step.demo_content_started" ] && echo yes || echo no) $([ -e "$t/state/localnodes.install_step.demo_content" ] && echo yes || echo no)"
rc="$(run_ep "$t")"
check "partial demo, restart: RESUMING INSTALL" yes "$(has "$t/out" "RESUMING INSTALL")"
check "partial demo, restart: loud warning" yes "$(has "$t/out" "WARNING: an earlier demo content load started but did not finish (5 nodes exist)")"
check "partial demo, restart: demo content loaded again" 2 "$(calls "$t" "social-demo:add")"
check "partial demo, restart: demo_content marked, FRESH INSTALL COMPLETE" "yes yes" "$([ -e "$t/state/localnodes.install_step.demo_content" ] && echo yes || echo no) $(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"

# 4b. The node count fails (drush/DB error): stop, load nothing, mark nothing.
t="$(new_site)"; touch "$t/installed"
rc="$(run_ep "$t" FAIL_NODE_COUNT=1)"
check "count failure: stops (non-zero)" yes "$([ "$rc" != 0 ] && echo yes || echo no)"
check "count failure: no demo load" 0 "$(calls "$t" "social-demo:add")"
check "count failure: nothing marked" 0 "$(ls "$t/state" | wc -l | tr -d ' ')"
check "count failure: error logged" yes "$(has "$t/out" "ERROR: counting nodes failed")"

# 4c. A state read fails: stop, do not treat it as "no marker" and reload/resend.
t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" FAIL_STATE=1)"
check "state read failure: stops (non-zero)" yes "$([ "$rc" != 0 ] && echo yes || echo no)"
check "state read failure: no updatedb, no index" "0 0" "$(calls "$t" "updatedb") $(calls "$t" "search-api:index")"

# 5. Organizer. ORG = the organizer variables, CB = a sign-up's callback
#    variables. A manual run has neither callback variable, so it sends none.
ORG="ORGANIZER_EMAIL=organizer@example.org ORGANIZER_NAME=Test"
CB="PROVISION_CALLBACK_URL=http://signup.test PROVISION_ID=prov0123456789abcdefAB PROVISION_ATTEMPT=1 PROVISION_CALLBACK_TOKEN=tokensecret0123456789abcdef SERVICE_URL_OPENSOCIAL=https://riverbend.localnodes.test"
TOKEN="tokensecret0123456789abcdef"
mails() { [ -f "$1/mail" ] && wc -l < "$1/mail" | tr -d ' ' || echo 0; }
cbs() { [ -f "$1/callbacks" ] && wc -l < "$1/callbacks" | tr -d ' ' || echo 0; }          # cbs DIR
cb_statuses() { sed -n 's/.*"status":"\([a-z_]*\)".*/\1/p' "$1/callbacks" 2>/dev/null | tr '\n' ',' | sed 's/,$//'; }
cb_keys() { sed -n "${2}p" "$1/callbacks" | python3 -c 'import json,sys; print(",".join(sorted(json.load(sys.stdin))))'; }   # cb_keys DIR N
cb_get() { sed -n "${2}p" "$1/callbacks" | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin).get(sys.argv[1])))' "$3"; }  # cb_get DIR N KEY
order() { grep -E '^(drush (site:install|en localnodes_platform |localnodes:topic-types|localnodes:organizer-setup|localnodes:organizer-welcome|localnodes:seed-community)|callback )' "$1/seq" | sed -E 's/^drush //; s/^callback /cb:/; s/ .*//' | tr '\n' ',' | sed 's/,$//'; }
marked() { [ -e "$1/state/localnodes.install_step.$2" ] && echo yes || echo no; }          # marked DIR STEP

# 5a. Fresh install with an organizer: the order of events, in one line.
t="$(new_site)"
rc="$(run_ep "$t" $ORG $CB)"
check "organizer fresh: exit 0, install complete" "0 yes" "$rc $(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"
check "organizer fresh: site:install, en platform, topic types, creating_user, organizer-setup, community seed, ready (in that order)" \
  "site:install,en,localnodes:topic-types,cb:creating_user,localnodes:organizer-setup,localnodes:seed-community,cb:ready" "$(order "$t")"
check "organizer fresh: one organizer-setup, no organizer-welcome, no mail, setup marked, mail not marked" "1 0 0 yes no" "$(calls "$t" "localnodes:organizer-setup") $(calls "$t" "localnodes:organizer-welcome") $(mails "$t") $(marked "$t" organizer_setup) $(marked "$t" organizer_mail_sent)"
check "organizer fresh: the script makes no account, no sign-in address, no mail of its own" "0 0 0 0" "$(calls "$t" "user:create") $(calls "$t" "uli") $(calls "$t" "user:role:add") $(grep -c 'resend' "$t/curlurls")"
check "organizer fresh: the only callbacks are creating_user and ready" "creating_user,ready" "$(cb_statuses "$t")"
check "organizer fresh: site:install turns off the update status mail (23-04 hand-off)" yes "$(grep -q -- 'install_configure_form.enable_update_status_emails=NULL' "$t/siteinstall_args" && echo yes || echo no)"

# 5b. The ready callback (23-35; it replaced complete): provision id, attempt as a
#     number, status, the site address and the source; no sign-in field of any
#     name; the creating_user one has the same shape. Every call carries the
#     per-provision token. No callback says complete: the sign-up app writes that.
check "ready callback: exactly the contract keys" "attempt,provision_id,site_url,source,status" "$(cb_keys "$t" 2)"
check "creating_user callback: the same keys" "attempt,provision_id,site_url,source,status" "$(cb_keys "$t" 1)"
check "ready callback: status ready, provision_id, attempt (number 1), site_url, source" '"ready" "prov0123456789abcdefAB" 1 "https://riverbend.localnodes.test" "site"' "$(cb_get "$t" 2 status) $(cb_get "$t" 2 provision_id) $(cb_get "$t" 2 attempt) $(cb_get "$t" 2 site_url) $(cb_get "$t" 2 source)"
check "callbacks: no body says complete" 0 "$(grep -c 'complete' "$t/callbacks")"
check "callbacks: no sign-in field or address in any body" 0 "$(grep -ciE 'login|reset|one-time|password|mail' "$t/callbacks")"
check "callbacks: Authorization carries the per-provision token, every time" "2 2" "$(grep -cxF "Authorization: Bearer $TOKEN" "$t/auth") $(wc -l < "$t/auth" | tr -d ' ')"
check "callbacks: posted to the sign-up app's callback route" "2" "$(grep -cxF 'http://signup.test/api/provision-callback' "$t/curlurls")"

# 5c. Logs hold no address, token or sign-in link.
check "log: no organizer address, no part of it" "0" "$(grep -ciE 'organizer@|example\.org' "$t/out")"
check "log: no token, no sign-in link, no Login URL line" "0" "$(grep -ciE "$TOKEN|/user/reset|Login URL|login_url" "$t/out")"

# 5d. Not a sign-up (no callback variables): the same install, no callback at all.
#     Each variable missing on its own also keeps it silent.
t="$(new_site)"; rc="$(run_ep "$t" $ORG)"
check "no callback variables: installs, sends nothing" "0 yes 0" "$rc $(has "$t/out" "=== FRESH INSTALL COMPLETE ===") $(cbs "$t")"
check "manual run (23-35 unchanged): one organizer-welcome, one mail at install, no organizer-setup" "1 1 0 yes" "$(calls "$t" "localnodes:organizer-welcome") $(mails "$t") $(calls "$t" "localnodes:organizer-setup") $(marked "$t" organizer_mail_sent)"
check "manual run: the welcome step runs before the community seed" "localnodes:organizer-welcome,localnodes:seed-community" "$(order "$t" | tr ',' '\n' | grep -E '^localnodes:(organizer|seed)' | tr '\n' ',' | sed 's/,$//')"
rm -f "$t/state/localnodes.install_complete"
rc="$(run_ep "$t" $ORG)"
check "manual resume after the welcome: skipped, still one welcome command and one mail, no callback" "yes 1 1 0" "$(has "$t/out" "Organizer already provisioned and emailed; skipping.") $(calls "$t" "localnodes:organizer-welcome") $(mails "$t") $(cbs "$t")"
# PROVISION_ID or PROVISION_CALLBACK_TOKEN missing on its own is a partial pair: 5n.
for drop in PROVISION_CALLBACK_URL PROVISION_ATTEMPT; do
  t="$(new_site)"; rc="$(run_ep "$t" $ORG $(echo $CB | tr ' ' '\n' | grep -v "^$drop=" | tr '\n' ' '))"
  check "$drop missing: installs, sends nothing" "0 0" "$rc $(cbs "$t")"
done
t="$(new_site)"; rc="$(run_ep "$t" $ORG $(echo $CB | sed 's/PROVISION_ATTEMPT=1/PROVISION_ATTEMPT=abc/'))"
check "attempt not a number: installs, sends nothing" "0 0" "$rc $(cbs "$t")"

# 5e. A callback that fails (sign-up app down) is a warning, never a stop.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_CALLBACK=1)"
check "callback fails: install still completes" "0 yes" "$rc $(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"
check "callback fails: warning names the status, never the token" "yes 0" "$(has "$t/out" "Warning: creating_user callback failed (non-fatal)") $(grep -c "$TOKEN" "$t/out")"

# 5f. A restart without the completion marker, after organizer-setup finished: the
#     step is skipped by its marker, the seed still runs, and the sign-up app hears
#     "ready" once (it may never have got the first one). Page types are asked for
#     again (idempotent). No mail on either run.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB)"
rm -f "$t/state/localnodes.install_complete"; rm -f "$t/callbacks" "$t/auth"
rc="$(run_ep "$t" $ORG $CB)"
check "resume after organizer-setup: skipped, one setup command, no mail" "yes 1 0" "$(has "$t/out" "Organizer account already set up; skipping.") $(calls "$t" "localnodes:organizer-setup") $(mails "$t")"
check "resume after organizer-setup: the seed still runs (outside the skip)" 2 "$(calls "$t" "localnodes:seed-community")"
check "resume after organizer-setup: ready sent once, no creating_user" "ready" "$(cb_statuses "$t")"
check "resume after the welcome: page types asked for again" 2 "$(calls "$t" "localnodes:topic-types")"

# 5g. organizer-setup fails: the install stops (non-zero), nothing is marked
#     complete, one failed callback (organizer_failed), no ready. The restart
#     resumes, finishes and sends ready once.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_ORGANIZER_SETUP=1)"
check "organizer-setup fails: stops (non-zero), no completion marker, no ready callback" "yes no no" "$([ "$rc" != 0 ] && echo yes || echo no) $([ -e "$t/state/localnodes.install_complete" ] && echo yes || echo no) $(grep -q '"status":"ready"' "$t/callbacks" 2>/dev/null && echo yes || echo no)"
check "organizer-setup fails: creating_user, then one failed with organizer_failed; no seed" "creating_user,failed \"organizer_failed\" 0" "$(cb_statuses "$t") $(cb_get "$t" 2 reason) $(calls "$t" "localnodes:seed-community")"
rm -f "$t/callbacks"
rc="$(run_ep "$t" $ORG $CB)"
check "organizer-setup fails, restart: resumes, no mail, complete, ready once" "yes 0 yes creating_user,ready" "$(has "$t/out" "RESUMING INSTALL") $(mails "$t") $(has "$t/out" "=== FRESH INSTALL COMPLETE ===") $(cb_statuses "$t")"

# 5h. The page types command fails: stop before the organizer step.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_TOPIC=1)"
check "topic types fail: stops (non-zero), no organizer-setup, no creating_user" "yes 0 no" "$([ "$rc" != 0 ] && echo yes || echo no) $(calls "$t" "localnodes:organizer-setup") $(grep -q 'creating_user' "$t/callbacks" 2>/dev/null && echo yes || echo no)"

# 5i. No organizer address (a manual demo run): no organizer commands, no
#     organizer callbacks, but page types still run (the command no-ops on an
#     empty list).
t="$(new_site)"; rc="$(run_ep "$t" $CB)"
check "no organizer: installs, no welcome, no setup, no callbacks" "0 0 0 0" "$rc $(calls "$t" "localnodes:organizer-welcome") $(calls "$t" "localnodes:organizer-setup") $(cbs "$t")"
check "no organizer: page types command still runs once" 1 "$(calls "$t" "localnodes:topic-types")"

# 5j. The community's texts (23-34, T-2). The seed command runs after the organizer step
#     (the account and the first group exist) and before ready, also on a resume where
#     organizer-setup had already finished. Its marker makes a second run a no-op.
SEEDVAL="c2VlZHRleHQwMTIzNDU2Nzg5LWFib3V0LXRoZS1jb21tdW5pdHk"
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB LOCALNODES_COMMUNITY_SEED=$SEEDVAL)"
check "seed fresh: one seed command, after organizer-setup and before ready" "0 1" "$rc $(calls "$t" "localnodes:seed-community")"
check "seed fresh: runs with direct indexing off (its saves must not fail the install on an embedding quota)" 1 "$(grep -c '^localnodes:seed-community DEFER=1$' "$t/calls")"
check "seed fresh: marked, and the seed value is never in the log, a callback or a request" "yes 0 0 0" "$(marked "$t" community_seed) $(grep -c "$SEEDVAL" "$t/out") $(grep -c "$SEEDVAL" "$t/callbacks") $(grep -c "$SEEDVAL" "$t/curlurls")"
check "seed fresh: ready is the last callback and the seed step ran before it" "creating_user,ready" "$(cb_statuses "$t")"
rm -f "$t/state/localnodes.install_complete"; rm -f "$t/callbacks" "$t/auth"
rc="$(run_ep "$t" $ORG $CB LOCALNODES_COMMUNITY_SEED=$SEEDVAL)"
check "seed resume after organizer-setup: the command runs again and says it is already applied" "yes 2 ready" "$(has "$t/out" "Community seed already applied.") $(calls "$t" "localnodes:seed-community") $(cb_statuses "$t")"
check "seed resume: still no mail and one marker" "0 yes" "$(mails "$t") $(marked "$t" community_seed)"
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB LOCALNODES_COMMUNITY_SEED=$SEEDVAL FAIL_SEED=1)"
check "seed fails: stops (non-zero), no completion marker, no ready callback" "yes no no" "$([ "$rc" != 0 ] && echo yes || echo no) $([ -e "$t/state/localnodes.install_complete" ] && echo yes || echo no) $(grep -q '"status":"ready"' "$t/callbacks" 2>/dev/null && echo yes || echo no)"
check "seed fails: one failed callback, reason install_failed" "creating_user,failed \"install_failed\"" "$(cb_statuses "$t") $(cb_get "$t" 2 reason)"
check "seed fails: the value is not in the log or a callback" "0 0" "$(grep -c "$SEEDVAL" "$t/out") $(grep -c "$SEEDVAL" "$t/callbacks")"
rc="$(run_ep "$t" $ORG $CB LOCALNODES_COMMUNITY_SEED=$SEEDVAL)"
check "seed fails, restart: resumes, applies the seed once, completes" "yes 1 yes" "$(has "$t/out" "RESUMING INSTALL") $(marked "$t" community_seed | sed 's/yes/1/;s/no/0/') $(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"
t="$(new_site)"; rc="$(run_ep "$t" $CB LOCALNODES_COMMUNITY_SEED=$SEEDVAL)"
check "no organizer: the seed command does not run (it needs the organizer and the first group)" "0 0" "$rc $(calls "$t" "localnodes:seed-community")"
t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"; printf x > "$t/state/localnodes.install_complete"
rc="$(run_ep "$t" $ORG $CB LOCALNODES_COMMUNITY_SEED=$SEEDVAL)"
check "redeploy: the seed command does not run" "0 0" "$rc $(calls "$t" "localnodes:seed-community")"

# 5k. The AI provider toggle (23-27, ONB-07, D-21). AI_PROVIDER=openrouter (exact) enables
#     localnodes_openrouter once: on a fresh install after localnodes_platform and before
#     localnodes:topic-types (so the provider is fixed before anything is saved or indexed),
#     and on a redeploy (an already-enabled module is a no-op). Unset, gemini or any other
#     spelling: never. `calls` logs no module names, so these read seq.
or_en() { grep -c '^drush en localnodes_openrouter' "$1/seq" 2>/dev/null || true; }    # or_en DIR
line_of() { grep -n -m1 -- "^$2" "$1/seq" 2>/dev/null | cut -d: -f1; }                  # line_of DIR PREFIX
or_between() {  # or_between DIR: yes if en platform < en openrouter < topic-types in seq
  local p o k; p="$(line_of "$1" 'drush en localnodes_platform ')"; o="$(line_of "$1" 'drush en localnodes_openrouter')"; k="$(line_of "$1" 'drush localnodes:topic-types')"
  [ -n "$p" ] && [ -n "$o" ] && [ -n "$k" ] && [ "$p" -lt "$o" ] && [ "$o" -lt "$k" ] && echo yes || echo no
}
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB AI_PROVIDER=openrouter)"
check "openrouter fresh: installs, enabled once" "0 yes 1" "$rc $(has "$t/out" "=== FRESH INSTALL COMPLETE ===") $(or_en "$t")"
check "openrouter fresh: after en localnodes_platform, before localnodes:topic-types" yes "$(or_between "$t")"
check "openrouter fresh: before any demo content or index step" yes "$(o="$(line_of "$t" 'drush en localnodes_openrouter')"; d="$(line_of "$t" 'drush social-demo:add')"; i="$(line_of "$t" 'drush search-api:index')"; [ -n "$o" ] && [ -n "$d" ] && [ -n "$i" ] && [ "$o" -lt "$d" ] && [ "$o" -lt "$i" ] && echo yes || echo no)"
check "openrouter fresh: the 23-12/23-34 event order is unchanged" \
  "site:install,en,localnodes:topic-types,cb:creating_user,localnodes:organizer-setup,localnodes:seed-community,cb:ready" "$(order "$t")"
rm -f "$t/state/localnodes.install_complete"; : > "$t/seq"
rc="$(run_ep "$t" $ORG $CB AI_PROVIDER=openrouter)"
check "openrouter resume: enabled again before topic types, still no mail" "0 1 yes 0" "$rc $(or_en "$t") $(or_between "$t") $(mails "$t")"
t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"; printf x > "$t/state/localnodes.install_complete"
rc="$(run_ep "$t" AI_PROVIDER=openrouter)"
check "openrouter redeploy: enabled once, no install steps, no indexing" "0 yes 1 0 0" \
  "$rc $(has "$t/out" "=== EXISTING INSTALL READY ===") $(or_en "$t") $(calls "$t" "localnodes:topic-types") $(calls "$t" "search-api:index")"
for v in "" gemini OpenRouter " openrouter" openrouter-x; do
  t="$(new_site)"; rc="$(run_ep "$t" "AI_PROVIDER=$v")"
  check "AI_PROVIDER='$v' fresh: never enabled" "0 0" "$rc $(or_en "$t")"
  t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"; printf x > "$t/state/localnodes.install_complete"
  rc="$(run_ep "$t" "AI_PROVIDER=$v")"
  check "AI_PROVIDER='$v' redeploy: never enabled" "0 0" "$rc $(or_en "$t")"
done
t="$(new_site)"; rc="$(run_ep "$t")"
check "AI_PROVIDER not set at all: never enabled" "0 0" "$rc $(or_en "$t")"

# 5l. Provider flip guard (23-40, D-21: chat and embedding models are fixed at install).
#     On a redeploy with AI_PROVIDER=openrouter, a site whose localnodes_openrouter is off
#     and whose ai_knowledge_garden indexes hold items is refused (log and skip, exit 0)
#     unless AI_PROVIDER_SWITCH is exactly "reindex". The switch clears the vector server,
#     reads the Qdrant collection back (0 points at 3072), enables the module and indexes
#     again; once the module is on, it never clears again. The fresh and resume paths never
#     read the guard. The fixture is a live Gemini site: 40 indexed items, 40 points at 3072.
QINFO40='{"result":{"status":"green","optimizer_status":"ok","indexed_vectors_count":40,"points_count":40,"segments_count":2,"config":{"params":{"vectors":{"size":3072,"distance":"Cosine"}}}},"status":"ok","time":0.0001}'
gsite() {  # gsite [INDEXED] -> a redeploy fixture holding INDEXED (default 40) items
  local t; t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"; printf x > "$t/state/localnodes.install_complete"
  echo "${1:-40}" > "$t/indexed"; printf '%s' "$QINFO40" > "$t/qdrant_info"; echo "$t"
}
clears() { grep -c '^drush search-api:server-clear ai_knowledge_garden$' "$1/seq" 2>/dev/null || true; }   # clears DIR
guard_reads() { grep -cE '^drush php:eval .*(moduleExists|getIndexedItemsCount)' "$1/seq" 2>/dev/null || true; }
refused() { grep -c '^REFUSED: AI_PROVIDER=openrouter' "$1/out" 2>/dev/null || true; }     # refused DIR
errors() { grep -c '^ERROR' "$1/out" 2>/dev/null || true; }                                # errors DIR
indexes() { calls "$1" "search-api:index"; }                                               # indexes DIR
t="$(gsite)"; rc="$(run_ep "$t" AI_PROVIDER=openrouter)"
check "flip guard, refused: starts (exit 0, EXISTING INSTALL READY), not enabled, no clear, no index" "0 yes 0 0 0" \
  "$rc $(has "$t/out" "=== EXISTING INSTALL READY ===") $(or_en "$t") $(clears "$t") $(indexes "$t")"
check "flip guard, refused: one REFUSED line naming the 40 items and AI_PROVIDER_SWITCH=reindex" "1 yes yes" \
  "$(refused "$t") $(grep '^REFUSED: AI_PROVIDER=openrouter' "$t/out" | grep -qw 40 && echo yes || echo no) $(grep '^REFUSED: AI_PROVIDER=openrouter' "$t/out" | grep -q 'AI_PROVIDER_SWITCH=reindex' && echo yes || echo no)"
check "flip guard, refused: still prints the 23-27 'Ensuring' line" yes "$(has "$t/out" "Ensuring localnodes_openrouter enabled...")"
check "flip guard, refused: no callback, no ERROR line" "0 0" "$(cbs "$t") $(errors "$t")"
t="$(gsite 0)"; rc="$(run_ep "$t" AI_PROVIDER=openrouter)"
check "flip guard, empty index: enabled once, no clear, no REFUSED line" "0 1 0 0" "$rc $(or_en "$t") $(clears "$t") $(refused "$t")"
for sw in "" reindex; do
  t="$(gsite)"; touch "$t/or_enabled"; rc="$(run_ep "$t" AI_PROVIDER=openrouter "AI_PROVIDER_SWITCH=$sw")"
  check "flip guard, already enabled (switch '$sw'): no en, no clear, no index, no REFUSED, says so" "0 0 0 0 0 yes" \
    "$rc $(or_en "$t") $(clears "$t") $(indexes "$t") $(refused "$t") $(has "$t/out" "already enabled")"
done
t="$(gsite)"; rc="$(run_ep "$t" AI_PROVIDER=openrouter AI_PROVIDER_SWITCH=reindex)"
check "flip guard, switch: exit 0, one clear of ai_knowledge_garden, one en, one index, no REFUSED, no ERROR" "0 1 1 1 0 0" \
  "$rc $(clears "$t") $(or_en "$t") $(indexes "$t") $(refused "$t") $(errors "$t")"
check "flip guard, switch: the log names the switch and the 40 items" yes \
  "$(grep '^AI_PROVIDER_SWITCH=reindex' "$t/out" | grep -qw 40 && echo yes || echo no)"
# The start-up probe is also a "qdrant read", so the read-back is the first one after the clear.
check "flip guard, switch: server-clear < qdrant read-back < en < search-api:index" yes "$(
  c="$(line_of "$t" 'drush search-api:server-clear')"
  q="$(grep -n '^qdrant read' "$t/seq" | cut -d: -f1 | awk -v c="${c:-0}" '$1 > c' | head -1)"
  e="$(line_of "$t" 'drush en localnodes_openrouter')"; i="$(line_of "$t" 'drush search-api:index')"
  [ -n "$c" ] && [ -n "$q" ] && [ -n "$e" ] && [ -n "$i" ] && [ "$c" -lt "$q" ] && [ "$q" -lt "$e" ] && [ "$e" -lt "$i" ] && echo yes || echo no)"
rc="$(run_ep "$t" AI_PROVIDER=openrouter AI_PROVIDER_SWITCH=reindex)"
check "flip guard, switch left set, second redeploy: no second clear, no second en, no second index" "0 1 1 1 yes" \
  "$rc $(clears "$t") $(or_en "$t") $(indexes "$t") $(has "$t/out" "already enabled")"
t="$(gsite)"; rc="$(run_ep "$t" AI_PROVIDER=openrouter AI_PROVIDER_SWITCH=reindex QDRANT_KEEPS_POINTS=1)"
check "flip guard, switch, Qdrant keeps its points: cleared, never enabled, no index, an ERROR line, exit 0" "0 1 0 0 1" \
  "$rc $(clears "$t") $(or_en "$t") $(indexes "$t") $(errors "$t")"
t="$(gsite)"; rc="$(run_ep "$t" AI_PROVIDER=openrouter AI_PROVIDER_SWITCH=reindex FAIL_CLEAR=1)"
check "flip guard, switch, clear fails: no en, no index, an ERROR line, exit 0" "0 1 0 0 1" \
  "$rc $(clears "$t") $(or_en "$t") $(indexes "$t") $(errors "$t")"
t="$(gsite)"; rc="$(run_ep "$t" AI_PROVIDER=openrouter AI_PROVIDER_SWITCH=reindex FAIL_EN_OR=1)"
check "flip guard, switch, enable fails: one en attempt, no index, an ERROR line, exit 0" "0 1 1 0 1" \
  "$rc $(clears "$t") $(or_en "$t") $(indexes "$t") $(errors "$t")"
for sw in Reindex " reindex" "reindex " yes true; do
  t="$(gsite)"; rc="$(run_ep "$t" AI_PROVIDER=openrouter "AI_PROVIDER_SWITCH=$sw")"
  check "flip guard, AI_PROVIDER_SWITCH='$sw' is not the switch: refused, no clear, not enabled" "0 1 0 0" \
    "$rc $(refused "$t") $(clears "$t") $(or_en "$t")"
done
for p in gemini ""; do
  t="$(gsite)"; rc="$(run_ep "$t" "AI_PROVIDER=$p" AI_PROVIDER_SWITCH=reindex)"
  check "flip guard, AI_PROVIDER='$p' with the switch: no clear, no en, no guard read" "0 0 0 0" \
    "$rc $(clears "$t") $(or_en "$t") $(guard_reads "$t")"
done
t="$(gsite)"; rc="$(run_ep "$t" AI_PROVIDER_SWITCH=reindex)"
check "flip guard, AI_PROVIDER unset with the switch: no clear, no en, no guard read" "0 0 0 0" \
  "$rc $(clears "$t") $(or_en "$t") $(guard_reads "$t")"
# A read that fails is never taken as "not enabled" or "empty" (count_query stops the start-up);
# a redeploy sends no callback (10g).
for f in FAIL_OR_READ=1 FAIL_INDEXED_READ=1 INDEXED_OUT=no-server; do
  t="$(gsite)"; rc="$(run_ep "$t" $ORG $CB AI_PROVIDER=openrouter AI_PROVIDER_SWITCH=reindex "$f")"
  check "flip guard, read fails ($f): stops (non-zero), no en, no clear, no callback" "yes 0 0 0" \
    "$([ "$rc" != 0 ] && echo yes || echo no) $(or_en "$t") $(clears "$t") $(cbs "$t")"
done
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB AI_PROVIDER=openrouter AI_PROVIDER_SWITCH=reindex)"
check "flip guard, fresh install: complete, enabled once, no clear, no guard read" "0 yes 1 0 0" \
  "$rc $(has "$t/out" "=== FRESH INSTALL COMPLETE ===") $(or_en "$t") $(clears "$t") $(guard_reads "$t")"
check "flip guard, fresh install: the 23-12/23-34 event order is unchanged" \
  "site:install,en,localnodes:topic-types,cb:creating_user,localnodes:organizer-setup,localnodes:seed-community,cb:ready" "$(order "$t")"
rm -f "$t/state/localnodes.install_complete"; : > "$t/seq"
rc="$(run_ep "$t" $ORG $CB AI_PROVIDER=openrouter AI_PROVIDER_SWITCH=reindex)"
check "flip guard, resume: no clear, no guard read" "0 yes 0 0" \
  "$rc $(has "$t/out" "RESUMING INSTALL") $(clears "$t") $(guard_reads "$t")"

# 5m. The ready re-send on redeploy (23-35, B-1). A sign-up site whose organizer is not
#     activated yet sends ready again on every redeploy, so a ready that never arrived (the
#     container stopped between the install marker and the callback) is not lost; the
#     sign-up app ignores a repeated ready once billing started (23-32). An activated site,
#     a manual site and a site without the callback variables send nothing. A failed state
#     read stops the start-up, as every entrypoint read does.
rsite() {  # rsite -> an installed sign-up site (marker set), organizer not activated
  local t; t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"; printf x > "$t/state/localnodes.install_complete"
  printf now > "$t/state/localnodes.install_step.organizer_setup"; echo "$t"
}
t="$(rsite)"; rc="$(run_ep "$t" $ORG $CB)"
check "redeploy, not activated: exit 0, EXISTING INSTALL READY, exactly one callback: ready" "0 yes ready" "$rc $(has "$t/out" "=== EXISTING INSTALL READY ===") $(cb_statuses "$t")"
check "redeploy ready: the contract keys, status ready, the per-provision token" "attempt,provision_id,site_url,source,status \"ready\" 1" "$(cb_keys "$t" 1) $(cb_get "$t" 1 status) $(grep -cxF "Authorization: Bearer $TOKEN" "$t/auth")"
check "redeploy ready: no install step, no organizer command, no mail" "0 0 0 0" "$(calls "$t" "localnodes:topic-types") $(calls "$t" "localnodes:organizer-setup") $(calls "$t" "localnodes:organizer-welcome") $(mails "$t")"
check "redeploy ready: the log has no token and no address" "0 0" "$(grep -c "$TOKEN" "$t/out") $(grep -ciE 'organizer@|example\.org' "$t/out")"
rc="$(run_ep "$t" $ORG $CB)"
check "redeploy again, still not activated: ready again (two in all)" "0 ready,ready" "$rc $(cb_statuses "$t")"
t="$(rsite)"; printf '2026-11-08T12:00:00Z' > "$t/state/localnodes.organizer_activated"
rc="$(run_ep "$t" $ORG $CB)"
check "redeploy, activated: exit 0, no callback" "0 0" "$rc $(cbs "$t")"
t="$(rsite)"; rc="$(run_ep "$t" $ORG)"
check "redeploy of a manual site (no callback variables): no callback, no state read of the activation" "0 0 0" "$rc $(cbs "$t") $(grep -c '^drush state:get localnodes.organizer_activated' "$t/seq")"
t="$(rsite)"; rc="$(run_ep "$t" $CB)"
check "redeploy without ORGANIZER_EMAIL: no callback" "0 0" "$rc $(cbs "$t")"
# A redeploy with only one of PROVISION_ID and PROVISION_CALLBACK_TOKEN: 5n.
t="$(rsite)"; rc="$(run_ep "$t" $ORG $CB FAIL_GET_KEY=localnodes.organizer_activated)"
check "redeploy, the activation read fails: stops (non-zero), no callback, says why" "yes 0 yes" "$([ "$rc" != 0 ] && echo yes || echo no) $(cbs "$t") $(has "$t/out" "ERROR: drush state:get localnodes.organizer_activated failed")"
# The re-send comes after the 23-40 provider flip guard (a guard read that stops the start-up
# sends nothing; see 5l) and after the WEB3 toggle.
t="$(rsite)"; echo 0 > "$t/indexed"; rc="$(run_ep "$t" $ORG $CB AI_PROVIDER=openrouter)"
check "redeploy with the openrouter toggle: guard reads, then en, then the ready callback" yes "$(
  g="$(grep -n '^drush php:eval .*getIndexedItemsCount' "$t/seq" | head -1 | cut -d: -f1)"; e="$(line_of "$t" 'drush en localnodes_openrouter')"; c="$(line_of "$t" 'callback ready')"
  [ -n "$g" ] && [ -n "$e" ] && [ -n "$c" ] && [ "$g" -lt "$e" ] && [ "$e" -lt "$c" ] && echo yes || echo no)"
check "redeploy with the openrouter toggle: still exactly one ready" "ready" "$(cb_statuses "$t")"
t="$(rsite)"; rc="$(run_ep "$t" $ORG $CB WEB3_ENABLED=true)"
check "redeploy with WEB3_ENABLED: en localnodes_web3 before the ready callback" yes "$(
  w="$(line_of "$t" 'drush en localnodes_web3')"; c="$(line_of "$t" 'callback ready')"
  [ -n "$w" ] && [ -n "$c" ] && [ "$w" -lt "$c" ] && echo yes || echo no)"

# 5n. A partial PROVISION pair (23-43 Task 5, T-23-201). Exactly one of PROVISION_ID and
#     PROVISION_CALLBACK_TOKEN non-empty is a half-configured container: as a manual run it
#     would make an ACTIVE organizer and send the welcome mail on an unpaid site, as a
#     sign-up run it could never be handed over. The entrypoint stops with a non-zero exit
#     before any install step, drush call or callback, on a fresh install and on a redeploy
#     alike, and prints one fixed line that names the two variables and no value. Both empty
#     (a manual run, 5d) and both set (a sign-up run, 5a and 5m) behave as before.
PAIR_LINE="ERROR: PROVISION_ID and PROVISION_CALLBACK_TOKEN must both be set (a sign-up run) or both be empty (a manual run); only one is set. Stopping before any install step."
PID_VALUE="prov0123456789abcdefAB"
no_drush() { [ ! -s "$1/calls" ] && echo yes || echo no; }                              # no_drush DIR
nz() { [ "$1" != 0 ] && echo yes || echo no; }                                           # nz RC
partial() {  # partial LABEL DIR VAR=value... : the refusal, checked in one line
  local label="$1" t="$2" rc; shift 2
  rc="$(run_ep "$t" "$@")"
  check "partial pair, $label: non-zero exit, no drush call, no callback, no mail, the fixed line" \
    "yes yes 0 0 yes" "$(nz "$rc") $(no_drush "$t") $(cbs "$t") $(mails "$t") $(has "$t/out" "$PAIR_LINE")"
  check "partial pair, $label: the log holds no token, no provision id, no organizer address" \
    "0 0 0" "$(grep -c "$TOKEN" "$t/out") $(grep -c "$PID_VALUE" "$t/out") $(grep -ciE 'organizer@|example\.org' "$t/out")"
  check "partial pair, $label: no site:install, no database check, no request to the sign-up app" \
    "0 no 0" "$(n="$(calls "$t" "site:install")"; echo "${n:-0}") $([ -s "$t/dbcalls" ] && echo yes || echo no) $(n="$(grep -c 'signup.test' "$t/curlurls" 2>/dev/null)"; echo "${n:-0}")"
}
for drop in PROVISION_ID PROVISION_CALLBACK_TOKEN; do
  rest="$(echo $CB | tr ' ' '\n' | grep -v "^$drop=" | tr '\n' ' ')"
  t="$(new_site)"; partial "fresh install, $drop missing" "$t" $ORG $rest
  t="$(new_site)"; partial "fresh install, $drop empty" "$t" $ORG $rest "$drop="
  t="$(rsite)"; partial "redeploy, $drop missing" "$t" $ORG $rest
  check "partial pair, redeploy, $drop missing: the activation state is not read" 0 "$(grep -c '^drush state:get localnodes.organizer_activated' "$t/seq" 2>/dev/null || true)"
  t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"
  partial "resume (no marker), $drop missing" "$t" $ORG $rest
done
# Without ORGANIZER_EMAIL the pair is still checked: the variables alone are the mismatch.
t="$(new_site)"; partial "no organizer, only PROVISION_ID" "$t" "PROVISION_ID=$PID_VALUE"
t="$(new_site)"; partial "no organizer, only the token" "$t" "PROVISION_CALLBACK_TOKEN=$TOKEN"
# Both empty is a manual run, as before; both set is a sign-up run, as before.
t="$(new_site)"; rc="$(run_ep "$t" $ORG PROVISION_ID= PROVISION_CALLBACK_TOKEN=)"
check "both empty: a manual run as before (exit 0, one organizer-welcome, no callback, no pair line)" \
  "0 1 0 no" "$rc $(calls "$t" "localnodes:organizer-welcome") $(cbs "$t") $(has "$t/out" "$PAIR_LINE")"
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB)"
check "both set: a sign-up run as before (exit 0, organizer-setup, creating_user then ready, no pair line)" \
  "0 1 creating_user,ready no" "$rc $(calls "$t" "localnodes:organizer-setup") $(cb_statuses "$t") $(has "$t/out" "$PAIR_LINE")"
check "entrypoint: the pair check comes before install-state detection (the first drush call)" yes "$(
  p="$(grep -n 'must both be set (a sign-up run)' "$ENTRYPOINT" | head -1 | cut -d: -f1)"
  d="$(grep -n '^if \$DRUSH status --field=bootstrap' "$ENTRYPOINT" | head -1 | cut -d: -f1)"
  [ -n "$p" ] && [ -n "$d" ] && [ "$p" -lt "$d" ] && echo yes || echo no)"

# 9. Empty-database guard before site:install.
t="$(new_site)"
rc="$(run_ep "$t")"
check "empty DB: installs; password via a defaults file, not argv or MYSQL_PWD" "0 1 yes no no" "$rc $(calls "$t" "site:install") $(grep -q 'PWD_FILE=set' "$t/dbcalls" && echo yes || echo no) $(grep -q 'PWD_ENV=set' "$t/dbcalls" && echo yes || echo no) $(grep -qE -- ' -p|--password' "$t/dbcalls" && echo yes || echo no)"
t="$(new_site)"; touch "$t/installed" "$t/users_table"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" FAIL_BOOTSTRAP=1)"
check "bootstrap fails on a live DB: refuses site:install, stops" "yes 0" "$([ "$rc" != 0 ] && echo yes || echo no) $(calls "$t" "site:install")"
check "bootstrap fails on a live DB: says why" yes "$(has "$t/out" "is not empty (it has '1' tables)")"
t="$(new_site)"; touch "$t/other_tables"
rc="$(run_ep "$t")"
check "damaged DB (no users table, other tables): refuses site:install, stops" "yes 0 yes" "$([ "$rc" != 0 ] && echo yes || echo no) $(calls "$t" "site:install") $(has "$t/out" "is not empty (it has '3' tables)")"
t="$(new_site)"
rc="$(run_ep "$t" FAIL_DB_QUERY=1)"
check "empty-DB check fails: no site:install, stops" "yes 0" "$([ "$rc" != 0 ] && echo yes || echo no) $(calls "$t" "site:install")"

# 10. One failed callback, with a fixed reason, when a fresh install or a resume
#     run dies; none for a redeploy. Auth is the same per-provision token. The
#     body has the contract keys only: no free-text error field.
failed_count() { grep -c '"status":"failed"' "$1/callbacks" 2>/dev/null || true; }   # failed_count DIR
nonzero() { [ "$1" != 0 ] && echo yes || echo no; }
FAILED_KEYS="attempt,provision_id,reason,source,status"
# 10a. site:install dies with exit 7: reason install_failed, the exit status is kept.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_INSTALL=7)"
check "install fails: exit status kept, one failed callback" "7 1" "$rc $(failed_count "$t")"
check "install fails: reason install_failed, source site, attempt 1" '"install_failed" "site" 1' "$(cb_get "$t" 1 reason) $(cb_get "$t" 1 source) $(cb_get "$t" 1 attempt)"
check "install fails: body has the contract keys only (no error text, no site_url)" "$FAILED_KEYS" "$(cb_keys "$t" 1)"
check "install fails: token header, nothing secret in the log" "1 0" "$(grep -cxF "Authorization: Bearer $TOKEN" "$t/auth") $(grep -c "$TOKEN" "$t/out")"
# A failed callback that itself fails does not change the exit status.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_INSTALL=7 FAIL_CALLBACK=1)"
check "install fails and the callback fails: same exit status" 7 "$rc"

# 10b. A live database that did not bootstrap: db_not_empty.
t="$(new_site)"; touch "$t/other_tables"
rc="$(run_ep "$t" $ORG $CB)"
check "db not empty: exit 1, one failed callback, reason db_not_empty" "1 1 \"db_not_empty\"" "$rc $(failed_count "$t") $(cb_get "$t" 1 reason)"
# The empty-database check itself failing is an install failure, not db_not_empty.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_DB_QUERY=1)"
check "empty-DB check fails: one failed callback, reason install_failed" "1 \"install_failed\"" "$(failed_count "$t") $(cb_get "$t" 1 reason)"

# 10c. drush en localnodes_platform fails: platform_enable_failed.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_EN=1)"
check "platform enable fails: stops, one failed callback, reason platform_enable_failed" "yes 1 \"platform_enable_failed\"" "$(nonzero "$rc") $(failed_count "$t") $(cb_get "$t" 1 reason)"
check "platform enable fails: no page types, no organizer-setup" "0 0" "$(calls "$t" "localnodes:topic-types") $(calls "$t" "localnodes:organizer-setup")"

# 10d. organizer-setup fails: organizer_failed, after creating_user.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_ORGANIZER_SETUP=1)"
check "organizer-setup fails: creating_user then one failed, reason organizer_failed" "creating_user,failed \"organizer_failed\"" "$(cb_statuses "$t") $(cb_get "$t" 2 reason)"
# A restart that succeeds ends with ready; the sign-up app lets ready win.
rc="$(run_ep "$t" $ORG $CB)"
check "organizer-setup fails, restart: later callbacks end with ready" "ready" "$(cb_statuses "$t" | sed 's/.*,//')"
# A manual run's welcome failing is still organizer_failed: no callback variables, so nothing is sent.
t="$(new_site)"; rc="$(run_ep "$t" $ORG FAIL_WELCOME=1)"
check "manual welcome fails: stops (non-zero), no callback" "yes 0" "$(nonzero "$rc") $(cbs "$t")"

# 10e. Page types fail: no code of its own in the closed reason list, so install_failed.
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_TOPIC=1)"
check "topic types fail: one failed callback, reason install_failed" "1 \"install_failed\"" "$(failed_count "$t") $(cb_get "$t" 1 reason)"

# 10f. A resume run that dies: reported once.
t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" $ORG $CB FAIL_UPDB=1)"
check "resume fails: one failed callback, reason install_failed" "1 \"install_failed\"" "$(failed_count "$t") $(cb_get "$t" 1 reason)"

# 10g. A redeploy that dies sends nothing (the site is already live).
t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"; echo x > "$t/state/localnodes.install_complete"
rc="$(run_ep "$t" $ORG $CB FAIL_UPDB=1)"
check "redeploy fails: non-zero, no callback at all" "yes 0" "$(nonzero "$rc") $(cbs "$t")"

# 10h. After "ready" went out, a later stop (the closing cache clear) must not
#      send "failed": the sign-up app would mark a finished site as failed
#      (READY_SENT guards the trap, 23-35).
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB FAIL_FINAL_CR=1)"
check "stop after ready: non-zero, callbacks end at ready, no failed" "yes creating_user,ready 0" "$(nonzero "$rc") $(cb_statuses "$t") $(failed_count "$t")"

# 10i. Not a sign-up: a dying install sends nothing and keeps its exit status.
t="$(new_site)"; rc="$(run_ep "$t" $ORG FAIL_INSTALL=7)"
check "not a sign-up, install fails: exit status kept, no callback" "7 0" "$rc $(cbs "$t")"

# 10j. The failure is decided before the path is: a state read that fails right
#      at start-up is a "stop so the container retries" with no callback (the run
#      is not yet known to be a fresh install or a resume).
t="$(new_site)"; touch "$t/installed"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" $ORG $CB FAIL_STATE=1)"
check "state read fails at start: non-zero, no callback" "yes 0" "$(nonzero "$rc") $(cbs "$t")"

# 10k. A clean start sends no failed callback (success path and redeploy path).
t="$(new_site)"; rc="$(run_ep "$t" $ORG $CB)"
check "clean fresh install: no failed callback" 0 "$(failed_count "$t")"
check "entrypoint carries an EXIT trap and the four reason codes" "yes yes yes yes yes" "$(grep -qE 'trap .* EXIT' "$ENTRYPOINT" && echo yes || echo no) $(for c in install_failed db_not_empty platform_enable_failed organizer_failed; do grep -q "$c" "$ENTRYPOINT" && echo yes || echo no; done | tr '\n' ' ' | sed 's/ $//')"

# 6. settings.php defers exactly the directly-indexed indexes.
direct="$(grep -l 'index_directly: true' html/modules/custom/*/config/install/search_api.index.*.yml 2>/dev/null \
  | sed 's#.*/search_api.index.##; s#\.yml$##' | sort | tr '\n' ' ' | sed 's/ $//')"
listed="$(sed -n "s/.*foreach (\[\(.*\)\] as \$index_id).*/\1/p" "$SETTINGS" | tr -d "' " | tr ',' '\n' | sort | tr '\n' ' ' | sed 's/ $//')"
check "settings.php defers every index_directly index in config/install" "$direct" "$listed"

if [ -n "${IMAGE:-}" ]; then
  probe='$app_root="/var/www/html/html"; $site_path="sites/default"; $settings=[]; $config=[]; $databases=[];
    require "/var/www/html/html/sites/default/settings.php";
    echo var_export($config["search_api.index.social_posts"]["options"]["index_directly"] ?? "unset", TRUE), " ",
         var_export($config["search_api.index.social_comments"]["options"]["index_directly"] ?? "unset", TRUE);'
  on="$(docker run --rm -e LOCALNODES_DEFER_INDEXING=1 --entrypoint php "$IMAGE" -r "$probe" 2>&1)"
  off="$(docker run --rm --entrypoint php "$IMAGE" -r "$probe" 2>&1)"
  check "image: LOCALNODES_DEFER_INDEXING=1 turns direct indexing off" "false false" "$on"
  check "image: without it, settings.php leaves direct indexing alone" "'unset' 'unset'" "$off"
  ep="$(docker run --rm --entrypoint grep "$IMAGE" -c 'localnodes.install_complete' /usr/local/bin/entrypoint.sh 2>&1)"
  check "image: entrypoint carries the completion marker" yes "$([ "${ep:-0}" -gt 0 ] 2>/dev/null && echo yes || echo no)"
else
  echo "SKIP image checks (set IMAGE to run them)"
fi

echo "passed $PASS, failed $FAIL"
[ "$FAIL" -eq 0 ]
