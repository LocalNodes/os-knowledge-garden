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
  printf '#!/bin/sh\nexit 0\n' > "$t/bin/curl"
  printf '#!/bin/sh\necho deadbeef\n' > "$t/bin/openssl"
  # Only the Qdrant readiness probe calls `bash -c` by name.
  printf '#!/bin/sh\nexit 0\n' > "$t/bin/bash"
  printf '#!/bin/sh\necho "APACHE STARTED"\n' > "$t/bin/apache2-foreground"
  cat > "$t/bin/drush" <<'DRUSH'
#!/bin/sh
# Fake drush. Skips the -r/--uri prefix, logs the command, keeps state in $SITE.
while [ $# -gt 0 ]; do
  case "$1" in -r) shift 2 ;; --uri=*) shift ;; *) break ;; esac
done
cmd="$1"; shift
echo "$cmd DEFER=${LOCALNODES_DEFER_INDEXING:-}" >> "$SITE/calls"
case "$cmd" in
  status) [ -f "$SITE/installed" ] && echo "Successful" ;;
  site:install) touch "$SITE/installed" ;;
  state:get) [ -f "$SITE/state/$1" ] && cat "$SITE/state/$1" ;;
  state:set) printf '%s' "$2" > "$SITE/state/$1" ;;
  php:eval) cat "$SITE/nodes" 2>/dev/null || echo 0 ;;
  social-demo:add) [ "${FAIL_DEMO:-0}" = 1 ] && exit 1; echo 18 > "$SITE/nodes" ;;
  search-api:index) [ "${FAIL_INDEX:-0}" = 1 ] && { echo "AiQuotaException: embed_content per day" >&2; exit 3; } ;;
  cron) [ "${FAIL_CRON:-0}" = 1 ] && exit 4 ;;
  user:information) [ -f "$SITE/organizer" ] || exit 1 ;;
  user:create) touch "$SITE/organizer" ;;
  uli) echo "http://localhost/user/reset/1/x/y/login" ;;
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

# 4. A real demo-content failure still stops the install (no marker), and the
#    restart loads the demo content and finishes.
t="$(new_site)"
rc="$(run_ep "$t" FAIL_DEMO=1)"
check "demo failure: install stops (non-zero)" yes "$([ "$rc" != 0 ] && echo yes || echo no)"
check "demo failure: no FRESH INSTALL COMPLETE" no "$(has "$t/out" "FRESH INSTALL COMPLETE")"
check "demo failure: no marker" no "$([ -e "$t/state/localnodes.install_complete" ] && echo yes || echo no)"
rc="$(run_ep "$t")"
check "demo failure, restart: RESUMING INSTALL" yes "$(has "$t/out" "RESUMING INSTALL")"
check "demo failure, restart: demo content loaded" 2 "$(calls "$t" "social-demo:add")"
check "demo failure, restart: FRESH INSTALL COMPLETE" yes "$(has "$t/out" "=== FRESH INSTALL COMPLETE ===")"

# 5. Organizer: provisioned once. A resumed install (step marked) and an
#    install from before the markers (account exists) send no second email.
t="$(new_site)"
rc="$(run_ep "$t" ORGANIZER_EMAIL=organizer@example.org ORGANIZER_NAME=Test FAIL_INDEX=1)"
check "organizer fresh: account created once" 1 "$(calls "$t" "user:create")"
rm -f "$t/state/localnodes.install_complete"
rc="$(run_ep "$t" ORGANIZER_EMAIL=organizer@example.org ORGANIZER_NAME=Test)"
check "organizer resume: skipped (step marked)" yes "$(has "$t/out" "Organizer already provisioned; skipping.")"
check "organizer resume: no second account or login link" "1 0" "$(calls "$t" "user:create") $(grep -c '^uli' "$t/calls" | awk '{print $1-1}')"
t="$(new_site)"; touch "$t/installed" "$t/organizer"; echo 18 > "$t/nodes"
rc="$(run_ep "$t" ORGANIZER_EMAIL=organizer@example.org)"
check "organizer legacy: existing account detected" yes "$(has "$t/out" "Organizer account already exists; not provisioning again.")"
check "organizer legacy: no user:create, no uli" "0 0" "$(calls "$t" "user:create") $(calls "$t" "uli")"

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
