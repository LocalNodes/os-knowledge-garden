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
#   - steps that must not run twice (demo content, organizer welcome email) have
#     their own step markers, and installs from before the markers are detected
#     by their content/account.
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
  # curl: a fake Resend. Logs each call's Idempotency-Key (keys), and mail it
  # accepted (mail), and a hash of each request body (bodies). A key it already
  # accepted gets 409 invalid_idempotent_request (Resend's answer to a reused
  # key with a different body). CONCURRENT=N: the first N calls get 409
  # concurrent_idempotent_requests. MAIL_CODE=500 (or 429): refused.
  # LOSE_RESPONSE=1: accepts the mail but the caller sees no response (000).
  cat > "$t/bin/curl" <<'CURL'
#!/bin/sh
case "$*" in *api.resend.com*) ;; *) exit 0 ;; esac
key=""; prev=""
for a in "$@"; do
  [ "$prev" = "-H" ] && case "$a" in Idempotency-Key:*) key="${a#Idempotency-Key: }" ;; esac
  prev="$a"
done
echo "${key:-<none>}" >> "$SITE/keys"
prev=""; for a in "$@"; do [ "$prev" = "-d" ] && printf '%s' "$a" | cksum | cut -d' ' -f1 >> "$SITE/bodies"; prev="$a"; done
n=$(cat "$SITE/calls_resend" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$SITE/calls_resend"
if [ "$n" -le "${CONCURRENT:-0}" ]; then printf '{"statusCode":409,"name":"concurrent_idempotent_requests"}\n409\n'; exit 0; fi
if [ -n "${MAIL_CODE:-}" ]; then printf '{"statusCode":%s,"name":"rate_limit_exceeded"}\n%s\n' "$MAIL_CODE" "$MAIL_CODE"; exit 0; fi
if [ -n "$key" ] && grep -qxF "$key" "$SITE/accepted" 2>/dev/null; then printf '{"statusCode":409,"name":"invalid_idempotent_request","message":"x"}\n409\n'; exit 0; fi
echo "$key" >> "$SITE/accepted"; echo "resend" >> "$SITE/mail"
if [ "${LOSE_RESPONSE:-0}" = 1 ]; then printf '\n000\n'; exit 0; fi
printf '{}\n200\n'
exit 0
CURL
  printf '#!/bin/sh\necho deadbeef\n' > "$t/bin/openssl"
  # Only the Qdrant readiness probe calls `bash -c` by name.
  printf '#!/bin/sh\nexit 0\n' > "$t/bin/bash"
  printf '#!/bin/sh\necho "APACHE STARTED"\n' > "$t/bin/apache2-foreground"
  # mariadb: the empty-database check. Answers the users-table count; logs its
  # argv and whether the password came through MYSQL_PWD (never argv).
  cat > "$t/bin/mariadb" <<'DB'
#!/bin/sh
echo "$* PWD_ENV=${MYSQL_PWD:+set}" >> "$SITE/dbcalls"
[ "${FAIL_DB_QUERY:-0}" = 1 ] && { echo "ERROR 2002: Can't connect" >&2; exit 1; }
[ -f "$SITE/users_table" ] && echo 1 || echo 0
DB
  cat > "$t/bin/drush" <<'DRUSH'
#!/bin/sh
# Fake drush. Skips the -r/--uri prefix, logs the command, keeps state in $SITE.
while [ $# -gt 0 ]; do
  case "$1" in -r) shift 2 ;; --uri=*) shift ;; *) break ;; esac
done
cmd="$1"; shift
echo "$cmd DEFER=${LOCALNODES_DEFER_INDEXING:-}" >> "$SITE/calls"
case "$cmd" in
  status) [ "${FAIL_BOOTSTRAP:-0}" = 1 ] && exit 1; [ -f "$SITE/installed" ] && echo "Successful" ;;
  site:install) touch "$SITE/installed" "$SITE/users_table" ;;
  state:get) [ "${FAIL_STATE:-0}" = 1 ] && exit 1; [ -f "$SITE/state/$1" ] && cat "$SITE/state/$1" ;;
  state:set) [ -n "${FAIL_SET_KEY:-}" ] && [ "$1" = "$FAIL_SET_KEY" ] && exit 1; printf '%s' "$2" > "$SITE/state/$1" ;;
  php:eval)
    case "$1" in
      *'"node"'*) [ "${FAIL_NODE_COUNT:-0}" = 1 ] && exit 1; cat "$SITE/nodes" 2>/dev/null || echo 0 ;;
      *'"user"'*) [ "${FAIL_USER_LOOKUP:-0}" = 1 ] && exit 1; [ -f "$SITE/organizer" ] && echo 1 || echo 0 ;;
      *) exit 1 ;;
    esac ;;
  social-demo:add) [ "${FAIL_DEMO:-0}" = 1 ] && { echo 5 > "$SITE/nodes"; exit 1; }; echo 18 > "$SITE/nodes" ;;
  search-api:index) [ "${FAIL_INDEX:-0}" = 1 ] && { echo "AiQuotaException: embed_content per day" >&2; exit 3; } ;;
  cron) [ "${FAIL_CRON:-0}" = 1 ] && exit 4 ;;
  user:information) [ -f "$SITE/organizer" ] || exit 1 ;;
  user:create) touch "$SITE/organizer" ;;
  uli) [ "${FAIL_ULI:-0}" = 1 ] && exit 1; echo "http://localhost/user/reset/1/x/y/login" ;;
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
check "fresh + index failures: cron warnings logged (3)" 3 "$(grep -c 'WARNING: cron pass' "$t/out")"
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
check "no marker: 3 cron passes ran" 3 "$(calls "$t" "cron")"
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

# 5. Organizer. ORG = the organizer variables for every run below.
ORG="ORGANIZER_EMAIL=organizer@example.org ORGANIZER_NAME=Test RESEND_API_KEY=test"
mails() { [ -f "$1/mail" ] && wc -l < "$1/mail" | tr -d ' ' || echo 0; }
# 5a. Fresh: one account, one email; then a restart without the completion
#     marker sends nothing more.
t="$(new_site)"
rc="$(run_ep "$t" $ORG FAIL_INDEX=1)"
check "organizer fresh: one account, one email" "1 1" "$(calls "$t" "user:create") $(mails "$t")"
check "organizer fresh: both steps marked" "yes yes" "$([ -e "$t/state/localnodes.install_step.organizer_account" ] && echo yes || echo no) $([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no)"
rm -f "$t/state/localnodes.install_complete"
rc="$(run_ep "$t" $ORG)"
check "organizer resume: skipped" yes "$(has "$t/out" "Organizer already provisioned and emailed; skipping.")"
check "organizer resume: still one account, one email, one uli" "1 1 1" "$(calls "$t" "user:create") $(mails "$t") $(calls "$t" "uli")"
# 5b. The account lookup fails: stop, no account, no login link, no email.
t="$(new_site)"; touch "$t/installed" "$t/organizer"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" $ORG FAIL_USER_LOOKUP=1)"
check "lookup failure: stops (non-zero)" yes "$([ "$rc" != 0 ] && echo yes || echo no)"
check "lookup failure: no user:create, no uli, no email" "0 0 0" "$(calls "$t" "user:create") $(calls "$t" "uli") $(mails "$t")"
check "lookup failure: organizer steps and completion unmarked" "no no no" "$([ -e "$t/state/localnodes.install_step.organizer_account" ] && echo yes || echo no) $([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no) $([ -e "$t/state/localnodes.install_complete" ] && echo yes || echo no)"
# 5c. This entrypoint created the account, then stopped before the email (uli
#     failed): the restart retries the login link and the email, once.
t="$(new_site)"
rc="$(run_ep "$t" $ORG FAIL_ULI=1)"
check "account without mail: first run stops before the email" "yes 1 0" "$([ "$rc" != 0 ] && echo yes || echo no) $(calls "$t" "user:create") $(mails "$t")"
rc="$(run_ep "$t" $ORG)"
check "account without mail, restart: warns and retries" yes "$(has "$t/out" "stopped before the welcome email; sending the login link and the email now")"
check "account without mail, restart: no second account, one email" "1 1" "$(calls "$t" "user:create") $(mails "$t")"
check "account without mail, restart: mail_sent marked, COMPLETE" "yes yes" "$([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no) $(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"
# 5d. An install from before the markers (account, no markers): adopted, no email.
t="$(new_site)"; touch "$t/installed" "$t/organizer"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" $ORG)"
check "organizer legacy: adopted" yes "$(has "$t/out" "Organizer account exists from an install made before the step markers; adopting it")"
check "organizer legacy: no user:create, no uli, no email" "0 0 0" "$(calls "$t" "user:create") $(calls "$t" "uli") $(mails "$t")"
check "organizer legacy: demo content adopted, not reloaded" "0 yes" "$(calls "$t" "social-demo:add") $(has "$t/out" "adopting it as loaded")"

# 5e. Adoption writes organizer_mail_sent first: if the organizer_account write
#     then fails, the next start skips the organizer, with no email.
t="$(new_site)"; touch "$t/installed" "$t/organizer"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" $ORG FAIL_SET_KEY=localnodes.install_step.organizer_account)"
check "adoption, second write fails: stops (non-zero), mail_sent already marked" "yes yes" "$([ "$rc" != 0 ] && echo yes || echo no) $([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no)"
rc="$(run_ep "$t" $ORG)"
check "adoption, restart: skipped, no uli, no email" "yes 0 0" "$(has "$t/out" "Organizer already provisioned and emailed; skipping.") $(calls "$t" "uli") $(mails "$t")"

# 5f. The welcome POST carries an Idempotency-Key that is stable across runs.
#     Resend accepted the mail but the mail_sent write failed: the restart sends
#     again with the SAME key, gets 409, marks it sent; one email in total.
t="$(new_site)"
rc="$(run_ep "$t" $ORG FAIL_SET_KEY=localnodes.install_step.organizer_mail_sent)"
check "lost marker: first run stops after Resend accepted (one email)" "yes 1" "$([ "$rc" != 0 ] && echo yes || echo no) $(mails "$t")"
rc="$(run_ep "$t" $ORG)"
k1="$(sed -n 1p "$t/keys")"; k2="$(sed -n 2p "$t/keys")"
check "idempotency key present on the welcome POST (welcome-<sha256>)" yes "$(printf '%s' "$k1" | grep -qE '^welcome-[0-9a-f]{64}$' && echo yes || echo "no ($k1)")"
check "idempotency key stable across runs" "$k1" "$k2"
check "lost marker, restart: Resend 409, marked sent, still one email" "yes yes 1" "$(has "$t/out" "already accepted by Resend under this idempotency key (409 invalid_idempotent_request)") $([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no) $(mails "$t")"
t2="$(new_site)"; rc="$(run_ep "$t2" ORGANIZER_EMAIL=other@example.org ORGANIZER_NAME=Test RESEND_API_KEY=test)"
check "idempotency key differs for another organizer" yes "$([ "$(sed -n 1p "$t2/keys")" != "$k1" ] && echo yes || echo no)"

# 7. Resend 409 concurrent_idempotent_requests: retried with the same key and
#    the same body, then sent; not counted as sent by itself.
t="$(new_site)"
rc="$(run_ep "$t" $ORG CONCURRENT=1)"
check "concurrent 409 once: retried, then sent (2 POSTs, 1 email, marked)" "2 1 yes" "$(wc -l < "$t/keys" | tr -d ' ') $(mails "$t") $([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no)"
check "concurrent 409 once: same key and same body on the retry" "1 1" "$(sort -u "$t/keys" | wc -l | tr -d ' ') $(sort -u "$t/bodies" | wc -l | tr -d ' ')"
t="$(new_site)"
rc="$(run_ep "$t" $ORG CONCURRENT=9)"
check "concurrent 409 always: 3 attempts, not marked, install completes" "3 no yes" "$(wc -l < "$t/keys" | tr -d ' ') $([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no) $(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"
check "concurrent 409 always: warning logged" yes "$(has "$t/out" "still reports the welcome email in progress after 3 attempts")"

# 8. A failed welcome send (Resend 429/5xx) is retried on later starts: once
#    per start, until it goes out; then never again.
t="$(new_site)"
rc="$(run_ep "$t" $ORG MAIL_CODE=500)"
check "failed send: install completes, mail not marked" "0 yes no" "$rc $(has "$t/out" "=== FRESH INSTALL COMPLETE ===") $([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no)"
check "failed send: warning names the status" yes "$(has "$t/out" "Welcome email failed (HTTP 500, rate_limit_exceeded); not marked as sent")"
rc="$(run_ep "$t" $ORG MAIL_CODE=429)"
check "later start, still failing: one attempt, startup continues" "0 2 yes" "$rc $(wc -l < "$t/keys" | tr -d ' ') $(has "$t/out" "=== EXISTING INSTALL READY ===")"
rc="$(run_ep "$t" $ORG)"
check "later start, Resend back: pending email sent once, marked" "yes 1 yes" "$(has "$t/out" "Organizer welcome email is pending") $(mails "$t") $([ -e "$t/state/localnodes.install_step.organizer_mail_sent" ] && echo yes || echo no)"
rc="$(run_ep "$t" $ORG)"
check "start after that: no further POST" 3 "$(wc -l < "$t/keys" | tr -d ' ')"
t="$(new_site)"; touch "$t/installed" "$t/organizer"; echo 18 > "$t/nodes"; echo x > "$t/state/localnodes.install_complete"
rc="$(run_ep "$t" $ORG)"
check "pre-marker site with the marker: no pending retry, no POST" "no 0" "$(has "$t/out" "pending") $([ -f "$t/keys" ] && wc -l < "$t/keys" | tr -d ' ' || echo 0)"

# 9. Empty-database guard before site:install.
t="$(new_site)"
rc="$(run_ep "$t")"
check "empty DB: installs; password via MYSQL_PWD, not argv" "0 1 yes no" "$rc $(calls "$t" "site:install") $(grep -q 'PWD_ENV=set' "$t/dbcalls" && echo yes || echo no) $(grep -qE -- ' -p|--password' "$t/dbcalls" && echo yes || echo no)"
t="$(new_site)"; touch "$t/installed" "$t/users_table"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" FAIL_BOOTSTRAP=1)"
check "bootstrap fails on a live DB: refuses site:install, stops" "yes 0" "$([ "$rc" != 0 ] && echo yes || echo no) $(calls "$t" "site:install")"
check "bootstrap fails on a live DB: says why" yes "$(has "$t/out" "already has a users table")"
t="$(new_site)"
rc="$(run_ep "$t" FAIL_DB_QUERY=1)"
check "empty-DB check fails: no site:install, stops" "yes 0" "$([ "$rc" != 0 ] && echo yes || echo no) $(calls "$t" "site:install")"

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
