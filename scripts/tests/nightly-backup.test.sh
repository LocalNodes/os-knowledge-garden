#!/bin/bash
# Shell test for scripts/backup/nightly-backup.sh.
#
# Runs the nightly job against a fake docker (the "container" is a folder and
# its drush prints a made-up archive) and a fake aws that keeps objects in a
# folder, with a throwaway OpenPGP key. No container, bucket or network is
# needed. The fakes are in scripts/tests/fixtures/backup/. Output is TAP; the
# exit status is non-zero when any check fails.
#
# The real storage calls are checked against MinIO in the local drill; this
# test checks what the job refuses, what it stores, and what it leaves behind.
#
# Usage: bash scripts/tests/nightly-backup.test.sh

set -u
# The bash -c checks take their values as arguments, so single quotes are meant.
# shellcheck disable=SC2016

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
JOB="${JOB:-$REPO_ROOT/scripts/backup/nightly-backup.sh}"
FIX="$REPO_ROOT/scripts/tests/fixtures/backup"
UUID=5f0c2a8e-1b7d-4c3e-9a61-0d4e8b2f7c19
T=20261111T093000Z

TMP="$(mktemp -d)"
cleanup_all() {
  gpgconf --homedir "$TMP/gpg" --kill all >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup_all EXIT

N=0
FAILED=0
echo "TAP version 13"
check() {  # check NAME COMMAND...: one TAP line for COMMAND's result
  local name="$1"; shift
  N=$((N + 1))
  if "$@" >/dev/null 2>&1; then
    echo "ok $N - $name"
  else
    FAILED=$((FAILED + 1))
    echo "not ok $N - $name"
  fi
}
no() { ! "$@"; }

# --- one throwaway key pair -----------------------------------------------------
mkdir -m 700 "$TMP/gpg"
gpg --homedir "$TMP/gpg" --batch --quiet --passphrase '' \
  --quick-gen-key 'Backup Test <backup@test.invalid>' default default never 2>/dev/null
gpg --homedir "$TMP/gpg" --batch --armor --export > "$TMP/operator.pub"
gpg --homedir "$TMP/gpg" --batch --armor --export-secret-keys > "$TMP/operator.key" 2>/dev/null
dec() { gpg --homedir "$TMP/gpg" --batch --quiet --yes --output "$2" --decrypt "$1" 2>/dev/null; }

# --- lifecycle rules ------------------------------------------------------------
rule() {  # rule FILE JSON-OF-ONE-RULE
  jq -n --argjson r "$2" '{Rules: [$r]}' > "$1"
}
GOOD='{"ID":"expire","Status":"Enabled","Filter":{"Prefix":""},"Expiration":{"Days":14},"AbortIncompleteMultipartUpload":{"DaysAfterInitiation":1},"NoncurrentVersionExpiration":{"NoncurrentDays":1}}'
mkdir "$TMP/rules"
rule "$TMP/rules/good" "$GOOD"
rule "$TMP/rules/prefix" "$(jq -c '.Filter.Prefix = "localnodes-backup/"' <<<"$GOOD")"
rule "$TMP/rules/long" "$(jq -c '.Expiration.Days = 30' <<<"$GOOD")"
rule "$TMP/rules/off" "$(jq -c '.Status = "Disabled"' <<<"$GOOD")"
rule "$TMP/rules/noabort" "$(jq -c 'del(.AbortIncompleteMultipartUpload)' <<<"$GOOD")"
rule "$TMP/rules/nononcurrent" "$(jq -c 'del(.NoncurrentVersionExpiration)' <<<"$GOOD")"
rule "$TMP/rules/tagged" "$(jq -c '.Filter = {"Tag":{"Key":"k","Value":"v"}}' <<<"$GOOD")"
rule "$TMP/rules/keepnewest" "$(jq -c '.NoncurrentVersionExpiration.NewerNoncurrentVersions = 1' <<<"$GOOD")"
rule "$TMP/rules/otherprefix" "$(jq -c '.Filter.Prefix = "elsewhere/"' <<<"$GOOD")"

# --- a fresh world per case -----------------------------------------------------
fresh() {
  W="$TMP/w$N-$RANDOM"
  mkdir -p "$W/root/tmp" "$W/s3" "$W/s3.meta" "$W/log" "$W/lock" "$W/stage" "$W/state"
  : > "$W/log/docker.log"; : > "$W/log/aws.log"
  printf '[default]\naws_access_key_id = SENTINEL-KEYID\naws_secret_access_key = SENTINEL-AWSSECRET\n' > "$W/creds"
  chmod 600 "$W/creds"
  cat > "$W/backup.conf" <<EOF
CONTAINER=app-test
SITE_UUID=$UUID
RECIPIENT_KEY=$TMP/operator.pub
S3_ENDPOINT=https://s3.test.invalid
S3_BUCKET=test-bucket
S3_CREDENTIALS=$W/creds
STATUS_FILE=$W/state/site.status
EOF
  chmod 600 "$W/backup.conf"
  LIFECYCLE="$TMP/rules/good"
  unset FAKE_VERSIONING FAKE_DRUSH FAKE_UUID FAKE_S3_CORRUPT FAKE_S3_FAIL_KEY FAKE_DOCKER_PS FAKE_DOCKER_DOWN
}

job() {  # runs the job on $W/backup.conf; sets RC and OUT
  OUT="$(env PATH="$PATH" DOCKER_BIN="$FIX/docker" AWS_BIN="$FIX/aws" \
    LOCK_DIR="$W/lock" STAGING_PARENT="$W/stage" \
    FAKE_ROOT="$W/root" FAKE_S3="$W/s3" FAKE_LOG_DIR="$W/log" FAKE_LIFECYCLE="$LIFECYCLE" \
    bash "$JOB" "$W/backup.conf" 2>"$W/log/stderr")"
  RC=$?
}

stored() { [ -f "$W/s3/test-bucket/localnodes-backup/$UUID/$1" ]; }
no_sets() { [ -z "$(ls -A "$W/s3/test-bucket/localnodes-backup/$UUID/sets" 2>/dev/null)" ]; }
no_ok_record() { ! stored "runs/$T.json"; }
step_is() { [ "$(jq -r '.step' <<<"$OUT")" = "$1" ] && [ "$(jq -r '.status' <<<"$OUT")" = failed ]; }
drush_not_called() { ! grep -q 'localnodes:backup' "$W/log/docker.log"; }
nothing_left() {
  [ -z "$(ls -A "$W/stage")" ] && [ -z "$(ls -A "$W/root/tmp")" ]
}

# --- 1. a good run ----------------------------------------------------------------
fresh
job
check "good run exits 0" [ "$RC" = 0 ]
check "good run stores the encrypted set" stored "sets/$T.tar.gpg"
check "good run stores the encrypted secret record" stored "secrets/$T.json.gpg"
check "good run stores the run record" stored "runs/$T.json"
check "the status line says ok and is in the status file" \
  bash -c '[ "$(jq -r .status <<<"$1")" = ok ] && grep -qxF "$1" "$2"' _ "$OUT" "$W/state/site.status"
mkdir "$W/open"
dec "$W/s3/test-bucket/localnodes-backup/$UUID/sets/$T.tar.gpg" "$W/open/set.tar"
check "the set decrypts with the operator's private key" [ -s "$W/open/set.tar" ]
tar -xf "$W/open/set.tar" -C "$W/open" 2>/dev/null
ARCHIVE="$(jq -r '.archive // empty' "$W/open/identity.json" 2>/dev/null)"
check "the set holds the archive and identity.json" [ -n "$ARCHIVE" -a -f "$W/open/$ARCHIVE" ]
check "the archive matches the checksum in identity.json" \
  bash -c '[ "$(sha256sum "$1" | cut -d" " -f1)" = "$(jq -r .archive_sha256 "$2")" ]' _ "$W/open/$ARCHIVE" "$W/open/identity.json"
check "identity.json records the image and keep days" \
  jq -e '.image_digest == "sha256:0123456789abcdef" and .keep_days == 14 and .site_uuid == "'"$UUID"'"' "$W/open/identity.json"
dec "$W/s3/test-bucket/localnodes-backup/$UUID/secrets/$T.json.gpg" "$W/open/secret.json"
check "the secret record decrypts and holds the salt" grep -q SENTINEL-SALT "$W/open/secret.json"
check "no stored object holds the salt or the records in plain text" \
  no grep -rqE 'SENTINEL-(SALT|TOKEN|ARCHIVE)' "$W/s3"
check "the run record names no site title" no grep -rq 'Juniper' "$W/s3/test-bucket/localnodes-backup/$UUID/runs" "$W/state"
check "the storage keys never reach a command line" no grep -q 'SENTINEL-' "$W/log/aws.log" "$W/log/docker.log"
check "every storage call uses the configured credentials file" \
  bash -c '! grep -v "creds=$1\$" "$2" | grep -q .' _ "$W/creds" "$W/log/aws.log"
check "no plaintext left on the host or in the container" nothing_left
check "drush ran as the web user with the site check" \
  grep -q -- "-u www-data .*--expect-site-uuid=$UUID --with-secret-record" "$W/log/docker.log"

# --- 1b. run through the link the installer makes ------------------------------------
# The timer runs the job as /usr/local/sbin/localnodes-backup, a link to the
# installed copy; the job must still find backup-lib.sh beside the real file.
mkdir -p "$TMP/sbin"
ln -sfn "$JOB" "$TMP/sbin/localnodes-backup"
fresh
JOB_REAL="$JOB"; JOB="$TMP/sbin/localnodes-backup"
job
JOB="$JOB_REAL"
check "run through a link in another folder, the job still works" [ "$RC" = 0 ]

# --- 2. the expiry rule is checked before the site closes --------------------------
for r in none long off noabort tagged otherprefix; do
  fresh
  LIFECYCLE="$TMP/rules/$r"
  job
  check "expiry rule '$r': refused before drush, only the failure stored" \
    bash -c '[ "$1" = 1 ] && [ "$(jq -r .step <<<"$2")" = expiry-rule ] && ! grep -q localnodes:backup "$3" && [ -z "$(find "$4" -type f ! -name "*-failed.json")" ]' \
    _ "$RC" "$OUT" "$W/log/docker.log" "$W/s3"
done
fresh; LIFECYCLE="$TMP/rules/nononcurrent"; export FAKE_VERSIONING=Enabled
job
check "versioned bucket without an old-version rule: refused" bash -c '[ "$1" = 1 ] && [ "$(jq -r .step <<<"$2")" = expiry-rule ]' _ "$RC" "$OUT"
fresh; LIFECYCLE="$TMP/rules/nononcurrent"; export FAKE_VERSIONING=Suspended
job
check "suspended versioning still needs the old-version rule" [ "$RC" = 1 ]
fresh; LIFECYCLE="$TMP/rules/nononcurrent"
job
check "never-versioned bucket needs no old-version rule" [ "$RC" = 0 ]
fresh; LIFECYCLE="$TMP/rules/prefix"; export FAKE_VERSIONING=Enabled
job
check "a rule on our prefix with old versions covered is accepted" [ "$RC" = 0 ]
fresh; LIFECYCLE="$TMP/rules/good"; export FAKE_VERSIONING=denied
job
check "versioning that cannot be read: refused, not taken as off" bash -c '[ "$1" = 1 ] && [ "$(jq -r .step <<<"$2")" = expiry-rule ]' _ "$RC" "$OUT"
fresh; LIFECYCLE="$TMP/rules/nononcurrent"; export FAKE_VERSIONING=empty
job
check "an empty versioning answer means never versioned" [ "$RC" = 0 ]
fresh; LIFECYCLE="$TMP/rules/keepnewest"; export FAKE_VERSIONING=Enabled
job
check "an old-version rule that keeps the newest versions: refused" bash -c '[ "$1" = 1 ] && [ "$(jq -r .step <<<"$2")" = expiry-rule ]' _ "$RC" "$OUT"

# --- 3. drush fails: nothing uploaded, an earlier set untouched -------------------
fresh
job
earlier="$(sha256sum "$W/s3/test-bucket/localnodes-backup/$UUID/sets/$T.tar.gpg" | cut -d' ' -f1)"
export FAKE_DRUSH=fail
job
check "drush failure exits 1 at the drush step" step_is drush
check "drush failure leaves the earlier set as it was" \
  bash -c '[ "$(sha256sum "$1" | cut -d" " -f1)" = "$2" ] && [ "$(ls "$3" | wc -l)" = 1 ]' \
  _ "$W/s3/test-bucket/localnodes-backup/$UUID/sets/$T.tar.gpg" "$earlier" "$W/s3/test-bucket/localnodes-backup/$UUID/sets"
check "drush failure leaves no partial copy in the container or on the host" nothing_left
check "drush failure is stored as a failed run record" \
  bash -c 'ls "$1" | grep -q -- "-failed.json$"' _ "$W/s3/test-bucket/localnodes-backup/$UUID/runs"

fresh; export FAKE_UUID=00000000-0000-4000-8000-000000000000
job
check "a result line for another site is refused" bash -c '[ "$1" = 1 ] && [ "$(jq -r .step <<<"$2")" = drush ]' _ "$RC" "$OUT"
check "...and nothing is stored" no_sets

# --- 4. checks on what came out and what was stored --------------------------------
fresh; export FAKE_DRUSH=badsum
job
check "an archive that does not match its checksum is refused" step_is copy-out
check "...with no set stored" no_sets
check "...and no plaintext left" nothing_left

fresh; export FAKE_S3_CORRUPT=1
job
check "a set stored short is caught by the read-back" step_is upload
check "...and no ok run record is written" no_ok_record
check "...and the status file has no ok line" no grep -q '"status":"ok"' "$W/state/site.status"

fresh; export FAKE_S3_FAIL_KEY=secrets/
job
check "a failed secret-record upload fails the run" step_is upload
check "...and the store's own error text is not passed on" no grep -q "InternalError" "$W/log/stderr"
check "...only the operation and the exit status" grep -q "storage call 'put-object' failed (exit 254)" "$W/log/stderr"
check "...with no ok run record" no_ok_record
check "...and no plaintext left" nothing_left

# --- 5. one run per site ---------------------------------------------------------
fresh
exec 9>"$W/lock/$UUID.lock"
flock -n 9
job
exec 9>&-
check "a held site lock refuses the run" step_is lock
check "...without calling drush" drush_not_called

# --- 6. set-up refusals (exit 2, nothing run) ------------------------------------
fresh; chmod 644 "$W/backup.conf"
job
check "a config file readable by others is refused" bash -c '[ "$1" = 2 ] && ! grep -q . "$2"' _ "$RC" "$W/log/docker.log"
fresh; chmod 644 "$W/creds"
job
check "a credentials file readable by others is refused" [ "$RC" = 2 ]
fresh; sed -i "s|^RECIPIENT_KEY=.*|RECIPIENT_KEY=$TMP/operator.key|" "$W/backup.conf"
job
check "a private key named as the recipient is refused" [ "$RC" = 2 ]
fresh; sed -i "s|^KEEP_DAYS=.*||; \$a KEEP_DAYS=30" "$W/backup.conf"
job
check "KEEP_DAYS over 14 is refused" [ "$RC" = 2 ]
fresh; sed -i "s|^SITE_UUID=.*|SITE_UUID=not-a-uuid|" "$W/backup.conf"
job
check "a bad SITE_UUID is refused" [ "$RC" = 2 ]
fresh; sed -i "s|^S3_ENDPOINT=.*|S3_ENDPOINT=https://s3.test.invalid/path?x=1|" "$W/backup.conf"
job
check "an endpoint with a path is refused" [ "$RC" = 2 ]

# --- 7. the container --------------------------------------------------------------
fresh; sed -i "s|^CONTAINER=.*|CONTAINER=label:localnodes.site=test|" "$W/backup.conf"
export FAKE_DOCKER_PS=$'app-one\napp-two\n'
job
check "a label matching two containers is refused" step_is container
fresh; sed -i "s|^CONTAINER=.*|CONTAINER=label:localnodes.site=test|" "$W/backup.conf"
export FAKE_DOCKER_PS=$'app-one\n'
job
check "a label matching one container is used" bash -c '[ "$1" = 0 ] && grep -q "exec -u www-data -e HOME=/tmp app-one " "$2"' _ "$RC" "$W/log/docker.log"
fresh; export FAKE_DOCKER_DOWN=1
job
check "a stopped container is refused before drush" bash -c '[ "$(jq -r .step <<<"$1")" = container ] && ! grep -q localnodes:backup "$2"' _ "$OUT" "$W/log/docker.log"

echo "1..$N"
echo "# fail $FAILED"
[ "$FAILED" = 0 ]
