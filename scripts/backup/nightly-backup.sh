#!/usr/bin/env bash
#
# The nightly backup of one LocalNodes site, run on the host by a systemd timer.
#
# Usage: nightly-backup.sh /etc/localnodes-backup/<name>.conf
#
# It runs drush localnodes:backup in the site's container (the site is closed
# with a 503 while the copy is taken), copies the archive out, encrypts it to
# the operator's public key, uploads it to an S3-compatible bucket, checks
# what was stored, and records the run. The host never holds the private key,
# so it cannot read the copies it makes.
#
# Copies are deleted by the bucket's own expiry rule, not by this script, so
# they still expire when the site or this server is gone. The job checks the
# rule before it closes the site and records no success without it.
#
# The config file (mode 600 or 400, owned by the user running this; read,
# never sourced):
#   CONTAINER       the app container's name, or label:<key>=<value> naming
#                   exactly one running container
#   SITE_UUID       the site's id (drush config:get system.site uuid)
#   RECIPIENT_KEY   path of the operator's public OpenPGP key (armoured)
#   S3_ENDPOINT     the storage company's S3 address, https://...
#   S3_BUCKET       the bucket
#   S3_REGION       the bucket's region (default auto)
#   S3_CREDENTIALS  path of an aws credentials file (mode 600) with a
#                   [default] access key that can write to the bucket
#   S3_PREFIX       folder for this server's copies (default localnodes-backup)
#   KEEP_DAYS       days the bucket rule keeps a copy, at most 14 (default 14)
#   STATUS_FILE     where the one-line run records go
#                   (default /var/lib/localnodes-backup/<SITE_UUID>.status)
#   DRAIN_SECONDS, MAX_PAUSE  passed to drush (defaults 20 and 5400)
#
# What is stored, per run (T is the capture time, 20261111T093000Z):
#   <prefix>/<site uuid>/sets/T.tar.gpg      the archive and identity.json
#   <prefix>/<site uuid>/secrets/T.json.gpg  hash salt and provision values
#   <prefix>/<site uuid>/runs/T.json         the run record (no secret, no title)
#
# Output: one status line (JSON) on stdout and appended to STATUS_FILE.
# Exit status: 0 stored, 1 failed (the line names the step), 2 bad set-up.
# Needs: bash, docker, aws (v2), gpg, jq, openssl, flock, sha256sum.
#
# Overrides, for the shell test (scripts/tests/nightly-backup.test.sh):
#   DOCKER_BIN, AWS_BIN, GPG_BIN, LOCK_DIR (default /run/localnodes-backup),
#   STAGING_PARENT (default /var/tmp).

set -euo pipefail

SCRIPT_VERSION=1
HERE="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
# shellcheck source=backup-lib.sh
. "$HERE/backup-lib.sh"

LOCK_DIR="${LOCK_DIR:-/run/localnodes-backup}"
STAGING_PARENT="${STAGING_PARENT:-/var/tmp}"
DRUSH_IN_CONTAINER='/var/www/html/vendor/bin/drush -r /var/www/html/html'

setup_fail() { echo "nightly-backup: $1" >&2; exit 2; }

CONF="${1:-}"
[ -n "$CONF" ] || setup_fail "usage: $0 /etc/localnodes-backup/<name>.conf"
why="$(conf_check "$CONF")" || setup_fail "$why"

CONTAINER="$(conf_get "$CONF" CONTAINER)"
SITE_UUID="$(conf_get "$CONF" SITE_UUID)"
RECIPIENT_KEY="$(conf_get "$CONF" RECIPIENT_KEY)"
KEEP_DAYS="$(conf_get "$CONF" KEEP_DAYS)"; KEEP_DAYS="${KEEP_DAYS:-14}"
DRAIN_SECONDS="$(conf_get "$CONF" DRAIN_SECONDS)"; DRAIN_SECONDS="${DRAIN_SECONDS:-20}"
MAX_PAUSE="$(conf_get "$CONF" MAX_PAUSE)"; MAX_PAUSE="${MAX_PAUSE:-5400}"
STATUS_FILE="$(conf_get "$CONF" STATUS_FILE)"

[[ "$SITE_UUID" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] || setup_fail "SITE_UUID is not a site id"
[[ "$CONTAINER" =~ ^(label:[A-Za-z0-9._/-]+=[A-Za-z0-9._/-]+|[A-Za-z0-9][A-Za-z0-9_.-]*)$ ]] || setup_fail "CONTAINER is not a container name or label:<key>=<value>"
[ -f "$RECIPIENT_KEY" ] || setup_fail "RECIPIENT_KEY must name the operator's public key file"
grep -q 'PRIVATE KEY' "$RECIPIENT_KEY" && setup_fail "RECIPIENT_KEY holds a private key; the server must have the public key only"
[[ "$KEEP_DAYS" =~ ^[0-9]+$ ]] && [ "$KEEP_DAYS" -ge 1 ] && [ "$KEEP_DAYS" -le 14 ] || setup_fail "KEEP_DAYS must be 1 to 14"
[[ "$DRAIN_SECONDS" =~ ^[0-9]+$ ]] || setup_fail "DRAIN_SECONDS must be a whole number"
[[ "$MAX_PAUSE" =~ ^[0-9]+$ ]] || setup_fail "MAX_PAUSE must be a whole number"
STATUS_FILE="${STATUS_FILE:-/var/lib/localnodes-backup/$SITE_UUID.status}"
s3_setup "$CONF" || setup_fail "$S3_ERR"
SITE_PREFIX="$S3_PREFIX/$SITE_UUID"

# --- the record of this run --------------------------------------------------
STEP=start
STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

record() {  # record JSON: one line on stdout and in the status file
  mkdir -p "$(dirname "$STATUS_FILE")"
  printf '%s\n' "$1" | tee -a "$STATUS_FILE"
}

failed() {  # failed MESSAGE: record the failure, try to store it, exit 1
  local line
  line="$(jq -nc --arg site "$SITE_UUID" --arg step "$STEP" --arg why "$1" --arg started "$STARTED" \
    --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson v "$SCRIPT_VERSION" \
    '{status: "failed", site_uuid: $site, step: $step, reason: $why, started: $started, finished: $at, script_version: $v}')"
  record "$line"
  # Best effort, so a failed night is visible in the bucket too.
  if [ -n "${STAGE:-}" ] && [ -d "$STAGE" ]; then
    printf '%s\n' "$line" > "$STAGE/failed.json"
    s3_put "$STAGE/failed.json" "$SITE_PREFIX/runs/$(date -u +%Y%m%dT%H%M%SZ)-failed.json" >/dev/null 2>&1 || true
  fi
  exit 1
}

# --- one run per site at a time ----------------------------------------------
mkdir -p "$LOCK_DIR"
exec 8>"$LOCK_DIR/$SITE_UUID.lock"
STEP=lock
flock -n 8 || failed "another backup, restore or removal of this site is running"

# --- plaintext only in places the trap removes -------------------------------
STAGE=""
C_STAGE=""
APP=""
cleanup() {
  if [ -n "$C_STAGE" ] && [ -n "$APP" ]; then
    "$DOCKER_BIN" exec -u 0 "$APP" rm -rf -- "$C_STAGE" >/dev/null 2>&1 || true
  fi
  if [ -n "$STAGE" ]; then
    rm -rf -- "$STAGE"
  fi
}
trap cleanup EXIT
trap 'exit 1' INT TERM
STAGE="$(mktemp -d "$STAGING_PARENT/localnodes-backup.XXXXXXXX")"
chmod 700 "$STAGE"

# --- before the site is closed: the expiry rule ------------------------------
STEP=expiry-rule
why="$(s3_lifecycle_ok "$SITE_PREFIX/" "$KEEP_DAYS")" || why="the bucket's expiry rule could not be read"
[ -z "$why" ] || failed "$why"

# --- the container -------------------------------------------------------------
STEP=container
APP="$(resolve_container "$CONTAINER")" || failed "$APP"

# --- the copy, taken while the site is closed --------------------------------
STEP=drush
C_STAGE="/tmp/localnodes-backup-$(openssl rand -hex 8)"
# Run as the web user, so the pause file and locks the command writes belong
# to the user that reads them.
# shellcheck disable=SC2086
line="$("$DOCKER_BIN" exec -u www-data -e HOME=/tmp "$APP" $DRUSH_IN_CONTAINER localnodes:backup \
  --destination="$C_STAGE" --expect-site-uuid="$SITE_UUID" --with-secret-record \
  --drain-seconds="$DRAIN_SECONDS" --max-pause="$MAX_PAUSE" 2>/dev/null | grep '^{' | tail -n 1)" \
  || failed "drush localnodes:backup failed (its own log in the container says why)"
jq -e --arg u "$SITE_UUID" '.site_uuid == $u and (.archive | test("^[A-Za-z0-9._-]+\\.tar$"))' >/dev/null 2>&1 <<<"$line" \
  || failed "drush printed no usable result line"

STEP=copy-out
mkdir "$STAGE/out"
"$DOCKER_BIN" cp "$APP:$C_STAGE/." "$STAGE/out" >/dev/null 2>&1 || failed "could not copy the archive out of the container"
"$DOCKER_BIN" exec -u 0 "$APP" rm -rf -- "$C_STAGE" >/dev/null 2>&1 || failed "could not delete the container's copy"
C_STAGE=""
ARCHIVE="$(jq -r '.archive' <<<"$line")"
[ -f "$STAGE/out/$ARCHIVE" ] && [ -f "$STAGE/out/secret-record.json" ] || failed "the archive or the secret record is missing"
[ "$(sha256sum "$STAGE/out/$ARCHIVE" | cut -d' ' -f1)" = "$(jq -r '.archive_sha256' <<<"$line")" ] \
  || failed "the archive does not match the checksum drush printed"

# --- the set: archive plus identity.json, encrypted --------------------------
STEP=encrypt
CAPTURE="$(jq -r '.capture_start' <<<"$line")"
[[ "$CAPTURE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || failed "drush printed no capture time"
T="$(tr -d ':-' <<<"$CAPTURE")"
IMAGE="$("$DOCKER_BIN" inspect --format '{{.Image}}' "$APP" 2>/dev/null || true)"
IMAGE_NAME="$("$DOCKER_BIN" inspect --format '{{.Config.Image}}' "$APP" 2>/dev/null || true)"
jq --arg image "$IMAGE" --arg image_name "$IMAGE_NAME" --arg host "$(hostname -s)" \
  --argjson v "$SCRIPT_VERSION" --argjson keep "$KEEP_DAYS" \
  '. + {image_digest: $image, image_name: $image_name, host: $host, script_version: $v, keep_days: $keep}' \
  <<<"$line" > "$STAGE/out/identity.json"
tar -cf "$STAGE/set.tar" -C "$STAGE/out" "$ARCHIVE" identity.json
encrypt_to "$RECIPIENT_KEY" "$STAGE/set.tar" "$STAGE/set.tar.gpg" || failed "could not encrypt the set"
encrypt_to "$RECIPIENT_KEY" "$STAGE/out/secret-record.json" "$STAGE/secret.json.gpg" || failed "could not encrypt the secret record"
# The plaintext goes now, not at exit.
rm -f -- "$STAGE/set.tar" "$STAGE/out/$ARCHIVE" "$STAGE/out/secret-record.json"

# --- upload, each object read back -------------------------------------------
STEP=upload
SET_KEY="$SITE_PREFIX/sets/$T.tar.gpg"
SECRET_KEY="$SITE_PREFIX/secrets/$T.json.gpg"
s3_put "$STAGE/set.tar.gpg" "$SET_KEY" || failed "the set was not stored intact"
s3_put "$STAGE/secret.json.gpg" "$SECRET_KEY" || failed "the secret record was not stored intact"

# --- the run record ----------------------------------------------------------
STEP=record
run="$(jq -c --arg set "$SET_KEY" --arg secret "$SECRET_KEY" \
  --arg sha "$(sha256sum "$STAGE/set.tar.gpg" | cut -d' ' -f1)" --argjson bytes "$(stat -c '%s' "$STAGE/set.tar.gpg")" \
  --arg started "$STARTED" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  '{status: "ok", site_uuid, capture_start, capture_end, set_key: $set, secret_key: $secret,
    set_sha256: $sha, set_bytes: $bytes, archive_sha256, archive_bytes, image_digest,
    build_commit, keep_days, script_version, started: $started, finished: $at}' "$STAGE/out/identity.json")"
printf '%s\n' "$run" > "$STAGE/run.json"
s3_put "$STAGE/run.json" "$SITE_PREFIX/runs/$T.json" || failed "the run record was not stored"
record "$run"
