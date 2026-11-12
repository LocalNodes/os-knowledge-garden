#!/bin/bash
# Shell test for scripts/backup/restore-site.sh.
#
# Makes real sets with nightly-backup.sh (fake docker, fake aws, throwaway
# OpenPGP key), then restores them into a fake container (a folder; see
# scripts/tests/fixtures/backup/docker). Checks what the restore refuses
# before it touches the site, that it writes only to the container it
# checked, and that a failure part-way leaves the site closed. Output is TAP;
# the exit status is non-zero when any check fails.
#
# Usage: bash scripts/tests/restore-site.test.sh

set -u
# The bash -c checks take their values as arguments, so single quotes are meant.
# shellcheck disable=SC2016

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
JOB="$REPO_ROOT/scripts/backup/nightly-backup.sh"
RESTORE="${RESTORE:-$REPO_ROOT/scripts/backup/restore-site.sh}"
FIX="$REPO_ROOT/scripts/tests/fixtures/backup"
A=5f0c2a8e-1b7d-4c3e-9a61-0d4e8b2f7c19
B=7a1d3c55-2e4f-4b6a-8c9d-0e1f2a3b4c5d
C=11111111-2222-4333-8444-555555555555
SET_A="localnodes-backup/$A/sets/20261111T093000Z.tar.gpg"
SET_B="localnodes-backup/$B/sets/20261111T093000Z.tar.gpg"

TMP="$(mktemp -d)"
trap 'gpgconf --homedir "$TMP/gpg" --kill all >/dev/null 2>&1; gpgconf --homedir "$TMP/gpg2" --kill all >/dev/null 2>&1; rm -rf "$TMP"' EXIT

N=0
FAILED=0
echo "TAP version 13"
check() {
  local name="$1"; shift
  N=$((N + 1))
  if "$@" >/dev/null 2>&1; then echo "ok $N - $name"; else FAILED=$((FAILED + 1)); echo "not ok $N - $name"; fi
}
no() { ! "$@"; }

# --- keys: the operator's, and a stranger's ----------------------------------------
for k in gpg gpg2; do
  mkdir -m 700 "$TMP/$k"
  gpg --homedir "$TMP/$k" --batch --quiet --passphrase '' --quick-gen-key "Test $k <$k@test.invalid>" default default never 2>/dev/null
done
gpg --homedir "$TMP/gpg" --batch --armor --export > "$TMP/operator.pub"
gpg --homedir "$TMP/gpg" --batch --armor --export-secret-keys > "$TMP/operator.key" 2>/dev/null
gpg --homedir "$TMP/gpg2" --batch --armor --export-secret-keys > "$TMP/stranger.key" 2>/dev/null

# --- storage shared by the sets and the restores ------------------------------------
S3="$TMP/s3"; mkdir -p "$S3" "$S3.meta" "$TMP/log"
printf '[default]\naws_access_key_id = k\naws_secret_access_key = s\n' > "$TMP/creds"; chmod 600 "$TMP/creds"
jq -n '{Rules: [{Status: "Enabled", Filter: {Prefix: ""}, Expiration: {Days: 14}, AbortIncompleteMultipartUpload: {DaysAfterInitiation: 1}}]}' > "$TMP/rule"
cat > "$TMP/storage.conf" <<EOF
S3_ENDPOINT=https://s3.test.invalid
S3_BUCKET=test-bucket
S3_CREDENTIALS=$TMP/creds
EOF
chmod 600 "$TMP/storage.conf"

make_set() {  # make_set UUID: one nightly run of site UUID into the store
  local w="$TMP/make-$1"
  mkdir -p "$w/root/tmp" "$w/lock" "$w/stage"
  cat "$TMP/storage.conf" > "$w/conf"
  printf 'CONTAINER=app-source\nSITE_UUID=%s\nRECIPIENT_KEY=%s\nSTATUS_FILE=%s\n' "$1" "$TMP/operator.pub" "$w/status" >> "$w/conf"
  chmod 600 "$w/conf"
  env DOCKER_BIN="$FIX/docker" AWS_BIN="$FIX/aws" LOCK_DIR="$w/lock" STAGING_PARENT="$w/stage" \
    FAKE_ROOT="$w/root" FAKE_S3="$S3" FAKE_LOG_DIR="$TMP/log" FAKE_LIFECYCLE="$TMP/rule" FAKE_UUID="$1" \
    bash "$JOB" "$w/conf" >/dev/null 2>&1
}
make_set "$A"
make_set "$B"
[ -f "$S3/test-bucket/$SET_A" ] && [ -f "$S3/test-bucket/$SET_B" ] || { echo "Bail out! could not make the sets"; exit 1; }

# --- a fresh target per case -------------------------------------------------------
fresh() {  # fresh UUID: a running site holding UUID, with old content
  W="$TMP/t$N-$RANDOM"
  R="$W/root"
  mkdir -p "$R/var/www/html/html/sites/default/files/old" "$R/var/www/private/.localnodes" "$R/var/www/private/olddocs" \
    "$W/log" "$W/lock" "$W/tmp"
  echo "$1" > "$R/site-uuid"
  echo "-- site_uuid: $1" > "$R/db.sql"; echo "OLD ROW" >> "$R/db.sql"
  echo old > "$R/var/www/html/html/sites/default/files/old/pic.png"
  echo old > "$R/var/www/private/olddocs/a.txt"
  echo "site salt" > "$R/var/www/private/.localnodes/hash-salt"
  : > "$W/log/docker.log"; : > "$W/log/aws.log"
  cat > "$W/registry" <<EOF
house-a app-test $A $A
house-b app-test $B $B
house-stale app-test $C $A
house-new app-new $C $A
EOF
  unset FAKE_FAIL_AT FAKE_BACKUP_RUNNING FAKE_UUID_AFTER_PAUSE FAKE_DOCKER_DOWN FAKE_DOCKER_PS
}

restore() {  # restore ARGS...: sets RC, OUT, ERR
  OUT="$(env DOCKER_BIN="$FIX/docker" AWS_BIN="$FIX/aws" FAKE_ROOT="$R" FAKE_S3="$S3" FAKE_LOG_DIR="$W/log" \
    LOCK_DIR="$W/lock" TMPDIR="$W/tmp" \
    bash "$RESTORE" --registry "$W/registry" --storage "$TMP/storage.conf" --private-key "$TMP/operator.key" \
      --drain-seconds 0 "$@" 2>"$W/err")"
  RC=$?
  ERR="$(cat "$W/err")"
}

untouched() {  # the site's database and files are as fresh() left them
  grep -q 'OLD ROW' "$R/db.sql" && [ -f "$R/var/www/html/html/sites/default/files/old/pic.png" ] \
    && [ -f "$R/var/www/private/olddocs/a.txt" ]
}
no_docker_write() { ! grep -qE 'create-restore|sql:drop|sql:cli| find | tar ' "$W/log/docker.log"; }
paused() { [ -f "$R/var/www/private/.localnodes/backup-pause" ]; }
tmp_empty() { [ -z "$(ls -A "$W/tmp")" ]; }

# --- 1. a good restore in place ------------------------------------------------------
fresh "$A"
restore --target house-a --set "$SET_A"
check "a good restore exits 0" [ "$RC" = 0 ]
check "the database is the set's" bash -c 'grep -q SENTINEL-ARCHIVE "$1" && ! grep -q "OLD ROW" "$1"' _ "$R/db.sql"
check "public files are the set's, old ones gone" \
  bash -c '[ -f "$1/avatar.png" ] && [ ! -e "$1/old" ]' _ "$R/var/www/html/html/sites/default/files"
check "private files are the set's, old ones gone" \
  bash -c '[ -f "$1/docs/minutes.txt" ] && [ ! -e "$1/olddocs" ]' _ "$R/var/www/private"
check "the site's own .localnodes is kept" grep -q 'site salt' "$R/var/www/private/.localnodes/hash-salt"
check "the site is open again" no paused
check "the report names the capture and the account count" \
  bash -c 'jq -e ".status == \"restored\" and .capture_start == \"2026-11-11T09:30:00Z\" and .accounts == 1" <<<"$1"' _ "$OUT"
check "web requests already running are waited for, after the pause and before the drop" \
  bash -c 'p=$(grep -n "create-restore" "$1" | cut -d: -f1); w=$(grep -n "localnodes-pause wait-web" "$1" | cut -d: -f1); d=$(grep -n "sql:drop" "$1" | cut -d: -f1); [ -n "$w" ] && [ "$p" -lt "$w" ] && [ "$w" -lt "$d" ]' _ "$W/log/docker.log"
check "the worker's pass is waited for before the database is dropped" \
  bash -c '[ "$(grep -n "wait-worker" "$1" | head -1 | cut -d: -f1)" -lt "$(grep -n "sql:drop" "$1" | cut -d: -f1)" ]' _ "$W/log/docker.log"
check "the live UUID is read again after the pause, before the drop" \
  bash -c 'awk "/create-restore/{p=1} p&&/config:get/{c=1} /sql:drop/{exit !c}" "$1"' _ "$W/log/docker.log"
check "every container call goes to the checked container" \
  bash -c '! grep "^exec" "$1" | grep -qv " app-test "' _ "$W/log/docker.log"
check "updatedb and a cache rebuild run" bash -c 'grep -q " updatedb -y" "$1" && grep -q " cr$" "$1"' _ "$W/log/docker.log"
check "the search indexes are emptied before the site opens again" \
  bash -c 'c=$(grep -n " search-api:clear$" "$1" | cut -d: -f1); r=$(grep -n "localnodes-pause release" "$1" | cut -d: -f1); [ -n "$c" ] && [ -n "$r" ] && [ "$c" -lt "$r" ]' _ "$W/log/docker.log"
check "the indexes are checked empty after the clear, before the site opens" \
  bash -c 'c=$(grep -n " search-api:clear$" "$1" | cut -d: -f1); e=$(grep -n " php:eval " "$1" | cut -d: -f1); r=$(grep -n "localnodes-pause release" "$1" | cut -d: -f1); [ -n "$e" ] && [ "$c" -lt "$e" ] && [ "$e" -lt "$r" ]' _ "$W/log/docker.log"
check "no plaintext left on the operator's machine" tmp_empty

# --- 1b. run through the link the installer makes ------------------------------------
mkdir -p "$TMP/sbin"
ln -sfn "$RESTORE" "$TMP/sbin/localnodes-restore"
fresh "$A"
RESTORE_REAL="$RESTORE"; RESTORE="$TMP/sbin/localnodes-restore"
restore --target house-a --set "$SET_A"
RESTORE="$RESTORE_REAL"
check "run through a link in another folder, the restore still works" [ "$RC" = 0 ]

# --- 2. refusals before anything changes ---------------------------------------------
fresh "$B"
restore --target house-b --set "$SET_A"
check "set A offered to target B is refused" bash -c '[ "$1" = 1 ] && grep -q "accepts sets from" <<<"$2"' _ "$RC" "$ERR"
check "...with nothing deleted and no write call" bash -c "$(declare -f untouched no_docker_write); R='$R' W='$W'; untouched && no_docker_write"
check "...and no plaintext left" tmp_empty

fresh "$A"
restore --target house-stale --set "$SET_A"
check "a container whose live UUID differs from the registry is refused" bash -c '[ "$1" = 1 ] && grep -q "not '"$C"'" <<<"$2"' _ "$RC" "$ERR"
check "...before the site is closed or anything deleted" bash -c "$(declare -f untouched no_docker_write); R='$R' W='$W'; untouched && no_docker_write"

fresh "$A"
restore --target nowhere --set "$SET_A"
check "an unknown target is refused" bash -c '[ "$1" = 1 ] && [ ! -s "$2" ]' _ "$RC" "$W/log/docker.log"

fresh "$A"
cp "$S3/test-bucket/$SET_A" "$W/keep"
printf 'X' | dd of="$S3/test-bucket/$SET_A" bs=1 seek=200 conv=notrunc 2>/dev/null
restore --target house-a --set "$SET_A"
cp "$W/keep" "$S3/test-bucket/$SET_A"
check "a set changed in storage is refused" bash -c '[ "$1" = 1 ] && grep -q "checksum stored" <<<"$2"' _ "$RC" "$ERR"
check "...before any container call" bash -c '[ ! -s "$1" ]' _ "$W/log/docker.log"

# A set whose archive does not match its identity.json: rebuilt and
# re-encrypted, stored with a correct object checksum.
fresh "$A"
mkdir "$W/forge"
gpg --homedir "$TMP/gpg" --batch --quiet --output "$W/forge/set.tar" --decrypt "$S3/test-bucket/$SET_A" 2>/dev/null
tar -xf "$W/forge/set.tar" -C "$W/forge"
arch="$(jq -r .archive "$W/forge/identity.json")"
echo extra >> "$W/forge/$arch"
tar -cf "$W/forge/new.tar" -C "$W/forge" "$arch" identity.json
gpg --homedir "$TMP/gpg" --batch --quiet --trust-model always --recipient-file "$TMP/operator.pub" --output "$W/forge/new.tar.gpg" --encrypt "$W/forge/new.tar"
FORGED="localnodes-backup/$A/sets/20261112T093000Z.tar.gpg"
cp "$W/forge/new.tar.gpg" "$S3/test-bucket/$FORGED"
sha256sum "$W/forge/new.tar.gpg" | cut -d' ' -f1 | tr -d '\n' > "$S3.meta/test-bucket/$FORGED"
restore --target house-a --set "$FORGED"
check "an archive that does not match identity.json is refused" bash -c '[ "$1" = 1 ] && grep -q "checksum in identity.json" <<<"$2"' _ "$RC" "$ERR"
check "...before any container call, no plaintext left" bash -c '[ ! -s "$1" ] && [ -z "$(ls -A "$2")" ]' _ "$W/log/docker.log" "$W/tmp"

fresh "$A"
OUT="$(env DOCKER_BIN="$FIX/docker" AWS_BIN="$FIX/aws" FAKE_ROOT="$R" FAKE_S3="$S3" FAKE_LOG_DIR="$W/log" LOCK_DIR="$W/lock" TMPDIR="$W/tmp" \
  bash "$RESTORE" --registry "$W/registry" --storage "$TMP/storage.conf" --private-key "$TMP/stranger.key" --drain-seconds 0 --target house-a --set "$SET_A" 2>&1)"
RC=$?
check "another private key cannot open the set" bash -c '[ "$1" = 1 ] && grep -q "could not decrypt" <<<"$2"' _ "$RC" "$OUT"
check "...and nothing changes" untouched

fresh "$A"
exec 9>"$W/lock/$A.lock"; flock -n 9
restore --target house-a --set "$SET_A"
exec 9>&-
check "a restore of the same site already running here is refused" bash -c '[ "$1" = 1 ] && grep -q "another restore" <<<"$2"' _ "$RC" "$ERR"
check "...and nothing changes" untouched

fresh "$A"; export FAKE_BACKUP_RUNNING=1
restore --target house-a --set "$SET_A"
check "a backup running in the container refuses the restore" bash -c '[ "$1" = 1 ] && grep -q "could not close the site" <<<"$2"' _ "$RC" "$ERR"
check "...with nothing deleted" bash -c "$(declare -f untouched paused); R='$R'; untouched && ! paused"

fresh "$A"
echo '{"token":"aaaa","until":null,"reason":"restore"}' > "$R/var/www/private/.localnodes/backup-pause"
restore --target house-a --set "$SET_A"
check "a site already paused refuses the restore" bash -c '[ "$1" = 1 ] && grep -q "could not close the site" <<<"$2"' _ "$RC" "$ERR"
check "...and the other pause is left as it was" bash -c "$(declare -f untouched); R='$R'; untouched && grep -q aaaa '$R/var/www/private/.localnodes/backup-pause'"

fresh "$A"; export FAKE_UUID_AFTER_PAUSE="$C"
restore --target house-a --set "$SET_A"
check "a container that changes site after the pause is refused" bash -c '[ "$1" = 1 ] && grep -q "now holds site" <<<"$2"' _ "$RC" "$ERR"
check "...nothing deleted and our pause lifted" bash -c "$(declare -f untouched paused); R='$R'; untouched && ! paused"

# --- 3. a failure part-way leaves the site closed ------------------------------------
for step in wait-worker wait-web sql:cli find tar updatedb cr search-api:clear search-check; do
  fresh "$A"; export FAKE_FAIL_AT="$step"
  restore --target house-a --set "$SET_A"
  check "a failure at $step exits 1 and leaves the restore pause" bash -c "$(declare -f paused); R='$R'; [ '$RC' = 1 ] && paused"
  check "...and prints the --clear-pause command" bash -c 'grep -q -- "--clear-pause --target house-a" <<<"$1"' _ "$ERR"
  check "...and prints no file name, row or tool message" no grep -q SENTINEL <<<"$ERR$OUT"
  check "...and leaves no plaintext" tmp_empty
done
# After the database is dropped a new restore refuses (the pause is in force
# and the live UUID is unreadable): the advice must keep the pause.
for step in sql:cli updatedb; do
  fresh "$A"; export FAKE_FAIL_AT="$step"
  restore --target house-a --set "$SET_A"
  check "after a failure at $step the advice keeps the pause" \
    bash -c 'grep -q "Keep the pause" <<<"$1" && ! grep -qi "run the restore again" <<<"$1"' _ "$ERR"
  unset FAKE_FAIL_AT
  restore --target house-a --set "$SET_A"
  check "...because a new restore over that pause is refused" bash -c "$(declare -f paused); R='$R'; [ '$RC' = 1 ] && paused"
done
# Before anything is deleted, the advice is to lift the pause and run again;
# following it works.
fresh "$A"; export FAKE_FAIL_AT=wait-web
restore --target house-a --set "$SET_A"
check "a failure before any delete says nothing was deleted" bash -c 'grep -q "nothing on the site was deleted yet" <<<"$1"' _ "$ERR"
check "...and nothing was" bash -c "$(declare -f untouched); R='$R'; untouched && ! grep -q sql:drop '$W/log/docker.log'"
unset FAKE_FAIL_AT
restore --target house-a --clear-pause
restore --target house-a --set "$SET_A"
check "...and lifting the pause and running again restores the site" bash -c "$(declare -f paused); R='$R'; [ '$RC' = 0 ] && ! paused && grep -q SENTINEL-ARCHIVE '$R/db.sql'"
fresh "$A"; export FAKE_FAIL_AT=sql:cli
restore --target house-a --set "$SET_A"
check "a failed database load prints none of the rows" no grep -q SENTINEL-ARCHIVE <<<"$ERR$OUT"
fresh "$A"; export FAKE_FAIL_AT=wait-worker
restore --target house-a --set "$SET_A"
check "a busy worker stops the restore before the database is dropped" bash -c "$(declare -f untouched); R='$R'; untouched && ! grep -q sql:drop '$W/log/docker.log'"
unset FAKE_FAIL_AT
restore --target house-a --clear-pause
check "--clear-pause lifts the pause" bash -c "$(declare -f paused); R='$R'; [ '$RC' = 0 ] && ! paused"

# --- 4. set-up -----------------------------------------------------------------------
fresh "$A"; chmod 644 "$TMP/storage.conf"
restore --target house-a --set "$SET_A"
chmod 600 "$TMP/storage.conf"
check "a storage config readable by others is refused" [ "$RC" = 2 ]
fresh "$A"
restore --target house-a --set 'localnodes-backup/../x.tar.gpg;rm'
check "a set key with odd characters is refused" [ "$RC" = 2 ]
fresh "$A"
restore --target house-a --set "${SET_A%.tar.gpg}-gone.tar.gpg"
check "a set that is not in storage is refused" bash -c '[ "$1" = 1 ] && grep -q "could not download" <<<"$2"' _ "$RC" "$ERR"
check "...without the store's own error text" bash -c '! grep -q "NoSuchKey" <<<"$1" && grep -q "storage call .get-object. failed (exit 254)" <<<"$1"' _ "$ERR"

echo "1..$N"
echo "# fail $FAILED"
[ "$FAILED" = 0 ]
