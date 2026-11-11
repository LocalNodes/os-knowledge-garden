#!/usr/bin/env bash
#
# LocalNodes capacity push (Phase 23, plan 23-17, D-08)
#
# Reads how much room the VPS has and POSTs it to the sign-up site, which stores it
# for 15 minutes and refuses new sign-ups when there is no room or no fresh reading:
#   memAvailableMb  MemAvailable from /proc/meminfo, in MB
#   dockerFreeGb    free GB on the Docker disk (df -BG /var/lib/docker)
#   load1           the 1-minute load from /proc/loadavg
#   nproc           the CPU count
#   reportedAt      the UTC time of the reading
#
# Usage:
#   capacity-push.sh /etc/localnodes/capacity-push.env
#
# The env file must belong to the user running the script and be mode 600 or 400
# (root-only on the VPS). It holds:
#   CAPACITY_REPORT_SECRET=<the sign-up site's NUXT_CAPACITY_REPORT_SECRET>
#   CAPACITY_REPORT_URL=https://localnodes.xyz/api/capacity-report   (optional; this is the default)
# The file is read, never sourced. The bearer goes to curl on stdin (--config -), so it is
# not in the process list, and it is never printed.
#
# Installing it (a systemd timer every 5 minutes) is a runbook step that needs Tom's OK
# (23-TOM-ASKS.md TA-7); see .planning/gtm/LAUNCH-RUNBOOK.md, "Server room".
#
# Overrides, for the contract test (scripts/ops/tests/capacity-push.test.sh):
#   MEMINFO_PATH (default /proc/meminfo), LOADAVG_PATH (default /proc/loadavg),
#   DOCKER_DIR (default /var/lib/docker).
#
# Output: one status line on stdout. Exit status: 0 sent, 1 not sent, 2 bad usage or set-up.
# Prerequisites: curl, jq, GNU df, nproc.
#
set -euo pipefail

MEMINFO_PATH="${MEMINFO_PATH:-/proc/meminfo}"
LOADAVG_PATH="${LOADAVG_PATH:-/proc/loadavg}"
DOCKER_DIR="${DOCKER_DIR:-/var/lib/docker}"
DEFAULT_URL="https://localnodes.xyz/api/capacity-report"

fail() {  # fail CODE MESSAGE
  echo "capacity-push: $2" >&2
  exit "$1"
}

ENV_FILE="${1:-}"
[ -n "$ENV_FILE" ] || { echo "Usage: $0 /path/to/capacity-push.env" >&2; exit 2; }
[ -f "$ENV_FILE" ] || fail 2 "env file not found: $ENV_FILE"

# The file holds a secret: it must be private to the user running this.
mode="$(stat -c '%a' "$ENV_FILE")"
owner="$(stat -c '%u' "$ENV_FILE")"
case "$mode" in
  600|400) ;;
  *) fail 2 "env file must be mode 600 or 400 (it is $mode)" ;;
esac
[ "$owner" = "$(id -u)" ] || fail 2 "env file must belong to the user running this script"

read_key() {  # read_key NAME -> the value of NAME= in the env file, without surrounding quotes
  local line
  line="$(grep -m1 -E "^$1=" "$ENV_FILE" || true)"
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

SECRET="$(read_key CAPACITY_REPORT_SECRET)"
URL="$(read_key CAPACITY_REPORT_URL)"
URL="${URL:-$DEFAULT_URL}"
[ -n "$SECRET" ] || fail 2 "CAPACITY_REPORT_SECRET is not set in the env file"
# Only characters that are safe inside curl's quoted config value (openssl rand -hex 32 is).
[[ "$SECRET" =~ ^[A-Za-z0-9._~+/=-]{16,256}$ ]] || fail 2 "CAPACITY_REPORT_SECRET has characters this script does not accept"
[[ "$URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9/._-]*$ ]] || fail 2 "CAPACITY_REPORT_URL must be an https URL"

# --- the reading -------------------------------------------------------------------
mem_kb="$(awk '/^MemAvailable:/ { print $2; exit }' "$MEMINFO_PATH" 2>/dev/null || true)"
[[ "$mem_kb" =~ ^[0-9]+$ ]] || fail 1 "could not read MemAvailable from $MEMINFO_PATH"
mem_mb=$(( mem_kb / 1024 ))

docker_free="$(df -BG --output=avail "$DOCKER_DIR" 2>/dev/null | tail -n 1 | tr -d ' G' || true)"
[[ "$docker_free" =~ ^[0-9]+$ ]] || fail 1 "could not read free disk for $DOCKER_DIR"

load1="$(cut -d' ' -f1 "$LOADAVG_PATH" 2>/dev/null || true)"
[[ "$load1" =~ ^[0-9]+(\.[0-9]+)?$ ]] || fail 1 "could not read the load from $LOADAVG_PATH"

cpus="$(nproc 2>/dev/null || true)"
[[ "$cpus" =~ ^[1-9][0-9]*$ ]] || fail 1 "could not read the CPU count"

reported_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

body="$(jq -cn \
  --argjson mem "$mem_mb" \
  --argjson docker "$docker_free" \
  --argjson load "$load1" \
  --argjson nproc "$cpus" \
  --arg at "$reported_at" \
  '{memAvailableMb: $mem, dockerFreeGb: $docker, load1: $load, nproc: $nproc, reportedAt: $at}')"

# --- the push ----------------------------------------------------------------------
# The bearer reaches curl as a config line on stdin, never as an argument.
if ! http="$(printf 'header = "Authorization: Bearer %s"\n' "$SECRET" | curl --fail -sS -o /dev/null -w '%{http_code}' \
    -X POST -H 'Content-Type: application/json' --data-binary "$body" --config - "$URL")"; then
  fail 1 "report not sent (the site refused it or could not be reached)"
fi

echo "capacity-push: sent memAvailableMb=$mem_mb dockerFreeGb=$docker_free load1=$load1 nproc=$cpus (HTTP $http)"
