#!/bin/bash
# Shell test for docker/worker-loop.sh (Phase 22, plan 22-06, D-12 / KNOW-01).
#
# Runs the worker loop against a stub drush (and a stub su) in a temp folder, so
# no container, database or Drupal is needed. Output is TAP; a failing check
# prints "not ok N - <name>" with a "message: 'FAIL'" diagnostic. Exit status is non-zero when any check fails.
#
# Usage: bash scripts/tests/worker-loop.test.sh

set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKER="$REPO_ROOT/docker/worker-loop.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAILED=0
N=0
TOTAL=11

echo "TAP version 13"
echo "1..$TOTAL"

ok() {
  N=$((N + 1))
  PASS=$((PASS + 1))
  echo "ok $N - $1"
}

# Single-quoted YAML scalar body: double any single quote.
yaml_quote() {
  printf '%s' "$1" | sed "s/'/''/g"
}

not_ok() {
  N=$((N + 1))
  FAILED=$((FAILED + 1))
  echo "not ok $N - $1"
  echo "  ---"
  echo "  message: 'FAIL'"
  echo "  expected: '$(yaml_quote "$2")'"
  echo "  actual: '$(yaml_quote "$3")'"
  echo "  ..."
}

assert_eq() {
  local name="$1" expected="$2" actual="$3"
  if [ "$expected" = "$actual" ]; then ok "$name"; else not_ok "$name" "$expected" "$actual"; fi
}

# --- Stub drush: logs its arguments, prints a fake content title on stdout and
# stderr (must never reach the worker log), and fails on its second call. ---
STUB_DRUSH="$TMP/drush"
DRUSH_LOG="$TMP/drush.log"
DRUSH_COUNT="$TMP/drush.count"
cat > "$STUB_DRUSH" <<STUB
#!/bin/bash
echo "\$*" >> "$DRUSH_LOG"
n=\$(cat "$DRUSH_COUNT" 2>/dev/null || echo 0)
n=\$((n + 1))
echo "\$n" > "$DRUSH_COUNT"
echo "MEMBERS-ONLY-TITLE indexed"
echo "MEMBERS-ONLY-TITLE warning" >&2
[ "\$n" -eq 2 ] && exit 1
exit 0
STUB
chmod +x "$STUB_DRUSH"

# The entrypoint's DRUSH is a multi-word string; the test mirrors that shape.
DRUSH_CMD="$STUB_DRUSH -r /x --uri=http://y"

# Guard against a loop that never ends: use timeout(1) when present.
run_bounded() {
  if command -v timeout >/dev/null 2>&1; then timeout 20 "$@"; else "$@"; fi
}

count_lines() {
  # count_lines <exact line> <file>
  grep -cxF -- "$1" "$2" 2>/dev/null || true
}

# --- Scenario A: direct mode (WORKER_RUN_AS empty), stub fails on call 2 ---
OUT_A="$TMP/out-a.log"
: > "$DRUSH_LOG"
rm -f "$DRUSH_COUNT"
DRUSH="$DRUSH_CMD" WORKER_RUN_AS="" WORKER_INTERVAL=0 WORKER_MAX_ITERATIONS=3 \
  run_bounded bash "$WORKER" > "$OUT_A" 2>&1
RC_A=$?

assert_eq "loop exits 0 after WORKER_MAX_ITERATIONS=3 despite a failing drush call" "0" "$RC_A"
assert_eq "exactly one cron:unlock --all call" "1" \
  "$(count_lines '-r /x --uri=http://y cron:unlock --all' "$DRUSH_LOG")"
assert_eq "cron:unlock --all is the first drush call" "-r /x --uri=http://y cron:unlock --all" \
  "$(head -n 1 "$DRUSH_LOG" 2>/dev/null)"
assert_eq "three cron:run search_api_cron --force calls" "3" \
  "$(count_lines '-r /x --uri=http://y cron:run search_api_cron --force' "$DRUSH_LOG")"
assert_eq "three cron calls" "3" \
  "$(count_lines '-r /x --uri=http://y cron' "$DRUSH_LOG")"
assert_eq "seven drush calls in total (1 unlock + 3 x 2)" "7" \
  "$(wc -l < "$DRUSH_LOG" 2>/dev/null | tr -d ' ')"

if grep -q 'MEMBERS-ONLY-TITLE' "$OUT_A" 2>/dev/null; then
  not_ok "drush output bodies never reach the worker log (T-22-12)" "no MEMBERS-ONLY-TITLE in output" "found in output"
else
  ok "drush output bodies never reach the worker log (T-22-12)"
fi

if grep -Eq '^\[worker\] [0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z .*cron:run search_api_cron --force exit=1$' "$OUT_A" 2>/dev/null; then
  ok "failing call is logged with a UTC timestamp and its exit code"
else
  not_ok "failing call is logged with a UTC timestamp and its exit code" \
    "[worker] <UTC ts> ... cron:run search_api_cron --force exit=1" \
    "$(head -n 3 "$OUT_A" 2>/dev/null | tr '\n' '|')"
fi

# --- Scenario B: default WORKER_RUN_AS (www-data) goes through su with HOME=/tmp ---
STUB_BIN="$TMP/bin"
mkdir -p "$STUB_BIN"
SU_LOG="$TMP/su.log"
cat > "$STUB_BIN/su" <<STUB
#!/bin/bash
printf '%s|' "\$@" >> "$SU_LOG"
echo >> "$SU_LOG"
# su -s <shell> <user> -c <command>: run the command so the stub drush is hit.
exec bash -c "\$5"
STUB
chmod +x "$STUB_BIN/su"
: > "$SU_LOG"
: > "$DRUSH_LOG"
rm -f "$DRUSH_COUNT"
OUT_B="$TMP/out-b.log"
( unset WORKER_RUN_AS
  PATH="$STUB_BIN:$PATH" DRUSH="$DRUSH_CMD" WORKER_INTERVAL=0 WORKER_MAX_ITERATIONS=1 \
    run_bounded bash "$WORKER" > "$OUT_B" 2>&1 )
RC_B=$?

assert_eq "default run-as goes through su -s /bin/bash www-data -c with HOME=/tmp" \
  "-s|/bin/bash|www-data|-c|HOME=/tmp $DRUSH_CMD cron:unlock --all|" \
  "$(head -n 1 "$SU_LOG" 2>/dev/null)"
assert_eq "su mode runs unlock + one iteration (3 su calls) and exits 0" "3 0" \
  "$(wc -l < "$SU_LOG" 2>/dev/null | tr -d ' ') $RC_B"

# --- Scenario C: DRUSH empty is a configuration error ---
DRUSH="" WORKER_RUN_AS="" WORKER_INTERVAL=0 WORKER_MAX_ITERATIONS=1 \
  run_bounded bash "$WORKER" > /dev/null 2>&1
assert_eq "empty DRUSH exits 2" "2" "$?"

echo "# pass $PASS"
echo "# fail $FAILED"
[ "$FAILED" -eq 0 ] && [ "$N" -eq "$TOTAL" ]
