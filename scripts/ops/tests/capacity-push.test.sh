#!/usr/bin/env bash
# Contract test for scripts/ops/capacity-push.sh (Phase 23, plan 23-17, D-08).
#
# The script reads MemAvailable, free GB on the Docker disk, the 1-minute load and the CPU
# count, and POSTs them with a bearer secret to the sign-up site's /api/capacity-report.
# Here /proc is replaced by fixture files (MEMINFO_PATH, LOADAVG_PATH), and `curl`, `df`
# and `nproc` are fakes on PATH. The fake curl records its arguments, its stdin (curl's
# --config, which carries the bearer) and the body. No network, no VPS, no real secret.
#
# Usage: bash scripts/ops/tests/capacity-push.test.sh
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
SCRIPT="${SCRIPT:-$REPO/scripts/ops/capacity-push.sh}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); echo "ok   - $1"; }
nok()  { FAIL=$((FAIL + 1)); echo "FAIL - $1"; }
check() { if eval "$2"; then ok "$1"; else nok "$1"; fi; }

SECRET="0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0"
URL="https://localnodes.xyz/api/capacity-report"

# --- fakes --------------------------------------------------------------------------
mkdir -p "$WORK/bin" "$WORK/state"
cat > "$WORK/bin/curl" <<'FAKE'
#!/usr/bin/env bash
# Records the call; answers like curl -o /dev/null -w '%{http_code}'.
state="$FAKE_STATE"
printf '%s\n' "$@" > "$state/args"
cat > "$state/config"
prev=""
for a in "$@"; do
  if [ "$prev" = "--data-binary" ] || [ "$prev" = "-d" ] || [ "$prev" = "--data" ]; then printf '%s' "$a" > "$state/body"; fi
  prev="$a"
done
echo called >> "$state/calls"
if [ "${FAKE_CURL_EXIT:-0}" != "0" ]; then
  echo "curl: (22) The requested URL returned error: ${FAKE_HTTP:-401}" >&2
  exit "$FAKE_CURL_EXIT"
fi
printf '%s' "${FAKE_HTTP:-200}"
FAKE
cat > "$WORK/bin/df" <<'FAKE'
#!/usr/bin/env bash
# GNU df -BG --output=avail DIR, with the free space from FAKE_DF_AVAIL.
printf '%s\n' "$*" > "$FAKE_STATE/df-args"
printf ' Avail\n %s\n' "${FAKE_DF_AVAIL:-11G}"
FAKE
cat > "$WORK/bin/nproc" <<'FAKE'
#!/usr/bin/env bash
echo "${FAKE_NPROC:-8}"
FAKE
chmod +x "$WORK/bin/curl" "$WORK/bin/df" "$WORK/bin/nproc"

cat > "$WORK/meminfo" <<'MEM'
MemTotal:       15988000 kB
MemFree:          812344 kB
MemAvailable:    7404544 kB
Buffers:          123456 kB
MEM
echo "0.90 0.83 0.98 2/812 12345" > "$WORK/loadavg"

write_env() {  # write_env MODE [extra lines...]
  local mode="$1"; shift
  {
    echo "# capacity push settings"
    echo "CAPACITY_REPORT_SECRET=$SECRET"
    echo "CAPACITY_REPORT_URL=$URL"
    for l in "$@"; do echo "$l"; done
  } > "$WORK/push.env"
  chmod "$mode" "$WORK/push.env"
}

run() {  # run [args...] -> exit code in RC, stdout in OUT, stderr in ERR
  rm -f "$WORK/state/"*
  PATH="$WORK/bin:$PATH" FAKE_STATE="$WORK/state" MEMINFO_PATH="${MEMINFO_PATH:-$WORK/meminfo}" \
    LOADAVG_PATH="${LOADAVG_PATH:-$WORK/loadavg}" DOCKER_DIR="$WORK" \
    bash "$SCRIPT" "$@" > "$WORK/out" 2> "$WORK/err"
  RC=$?
  OUT="$(cat "$WORK/out")"
  ERR="$(cat "$WORK/err")"
}
called() { [ -f "$WORK/state/calls" ]; }

# --- 1. the script exists and parses -------------------------------------------------
check "capacity-push.sh exists" "[ -f '$SCRIPT' ]"
check "bash -n parses it" "bash -n '$SCRIPT' 2>/dev/null"
check "it runs under set -euo pipefail" "grep -q '^set -euo pipefail' '$SCRIPT' 2>/dev/null"
check "it never turns on xtrace (which would print the secret)" "! grep -qE '^[^#]*set -[a-z]*x' '$SCRIPT' 2>/dev/null"

# --- 2. a good push ------------------------------------------------------------------
write_env 600
run "$WORK/push.env"
check "a good push exits 0" "[ $RC -eq 0 ]"
check "curl was called once" "[ \"\$(wc -l < '$WORK/state/calls' 2>/dev/null)\" -eq 1 ]"
BODY="$(cat "$WORK/state/body" 2>/dev/null || echo '{}')"
check "memAvailableMb is MemAvailable in MB (7404544 kB -> 7231)" "[ \"\$(jq -r '.memAvailableMb' <<<'$BODY')\" = 7231 ]"
check "dockerFreeGb is df's free GB for the Docker dir (11)" "[ \"\$(jq -r '.dockerFreeGb' <<<'$BODY')\" = 11 ]"
check "load1 is the first field of loadavg, as a number" "[ \"\$(jq -r '.load1 | type' <<<'$BODY')\" = number ] && [ \"\$(jq '.load1 == 0.9' <<<'$BODY')\" = true ]"
check "nproc is the CPU count (8)" "[ \"\$(jq -r '.nproc' <<<'$BODY')\" = 8 ]"
check "reportedAt is a UTC time" "jq -r '.reportedAt' <<<'$BODY' | grep -qE '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$'"
check "the body has exactly the five fields" "[ \"\$(jq -c 'keys' <<<'$BODY')\" = '[\"dockerFreeGb\",\"load1\",\"memAvailableMb\",\"nproc\",\"reportedAt\"]' ]"
check "df was asked about the Docker dir in GB" "grep -q -- '-BG' '$WORK/state/df-args' && grep -q -- '$WORK' '$WORK/state/df-args'"
check "it POSTs to the report URL" "grep -qx 'POST' '$WORK/state/args' && grep -qx '$URL' '$WORK/state/args'"
check "curl fails on an HTTP error (--fail)" "grep -qx -- '--fail' '$WORK/state/args'"
check "the body is sent as JSON" "grep -qx 'Content-Type: application/json' '$WORK/state/args'"
check "the Authorization header is 'Bearer <secret>', given to curl on stdin (--config -)" "grep -qx -- '--config' '$WORK/state/args' && grep -qxF 'header = \"Authorization: Bearer $SECRET\"' '$WORK/state/config'"
check "the secret is not in curl's arguments (so not in ps)" "! grep -qF '$SECRET' '$WORK/state/args'"
check "stdout is one status line" "[ \"\$(printf '%s\n' \"\$OUT\" | wc -l)\" -eq 1 ] && printf '%s' \"\$OUT\" | grep -q '^capacity-push: sent'"
check "the status line has the numbers and the HTTP code" "printf '%s' \"\$OUT\" | grep -q 'memAvailableMb=7231' && printf '%s' \"\$OUT\" | grep -q 'HTTP 200'"
check "stdout holds no secret" "! printf '%s' \"\$OUT\" | grep -qF '$SECRET'"
check "stderr holds no secret" "! printf '%s' \"\$ERR\" | grep -qF '$SECRET'"

# A quoted value in the env file is read without its quotes.
cat > "$WORK/push.env" <<EOF
CAPACITY_REPORT_SECRET="$SECRET"
CAPACITY_REPORT_URL="$URL"
EOF
chmod 600 "$WORK/push.env"
run "$WORK/push.env"
check "a double-quoted value in the env file works" "[ $RC -eq 0 ] && grep -qxF 'header = \"Authorization: Bearer $SECRET\"' '$WORK/state/config'"

# The URL defaults to the production report route when the file does not set it.
printf 'CAPACITY_REPORT_SECRET=%s\n' "$SECRET" > "$WORK/push.env"; chmod 600 "$WORK/push.env"
run "$WORK/push.env"
check "with no URL in the file it posts to https://localnodes.xyz/api/capacity-report" "[ $RC -eq 0 ] && grep -qx 'https://localnodes.xyz/api/capacity-report' '$WORK/state/args'"

# --- 3. refusals: nothing is sent ----------------------------------------------------
run
check "no env file argument: usage error (exit 2), nothing sent" "[ $RC -eq 2 ] && ! called"
run "$WORK/does-not-exist.env"
check "a missing env file: exit 2, nothing sent" "[ $RC -eq 2 ] && ! called"
write_env 644
run "$WORK/push.env"
check "an env file others can read (644): refused, nothing sent" "[ $RC -ne 0 ] && ! called"
check "the refusal says the file must be private, without the secret" "printf '%s' \"\$ERR\" | grep -qi 'mode' && ! printf '%s%s' \"\$OUT\" \"\$ERR\" | grep -qF '$SECRET'"
printf 'CAPACITY_REPORT_URL=%s\n' "$URL" > "$WORK/push.env"; chmod 600 "$WORK/push.env"
run "$WORK/push.env"
check "no secret in the file: refused, nothing sent" "[ $RC -ne 0 ] && ! called"
printf 'CAPACITY_REPORT_SECRET=%s\nCAPACITY_REPORT_URL=http://localnodes.xyz/api/capacity-report\n' "$SECRET" > "$WORK/push.env"; chmod 600 "$WORK/push.env"
run "$WORK/push.env"
check "a plain-http URL is refused (the bearer only goes over https), nothing sent" "[ $RC -ne 0 ] && ! called"
write_env 600
printf 'MemTotal: 1 kB\n' > "$WORK/meminfo-bad"
MEMINFO_PATH="$WORK/meminfo-bad" run "$WORK/push.env"
check "a meminfo without MemAvailable: refused, nothing sent" "[ $RC -ne 0 ] && ! called"
echo "garbage" > "$WORK/loadavg-bad"
LOADAVG_PATH="$WORK/loadavg-bad" run "$WORK/push.env"
check "a loadavg that is not a number: refused, nothing sent" "[ $RC -ne 0 ] && ! called"
FAKE_DF_AVAIL="n/a" run "$WORK/push.env"
check "a df answer that is not a size: refused, nothing sent" "[ $RC -ne 0 ] && ! called"

# --- 4. the server says no -----------------------------------------------------------
write_env 600
FAKE_CURL_EXIT=22 FAKE_HTTP=401 run "$WORK/push.env"
check "an HTTP error from the site exits non-zero" "[ $RC -ne 0 ]"
check "the failure line names no secret (stdout and stderr)" "! printf '%s%s' \"\$OUT\" \"\$ERR\" | grep -qF '$SECRET'"
check "the failure is reported on stderr" "printf '%s' \"\$ERR\" | grep -q 'capacity-push: report not sent'"

echo
echo "capacity-push: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
