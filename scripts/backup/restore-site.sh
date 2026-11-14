#!/usr/bin/env bash
#
# Puts a nightly backup set back into one LocalNodes site.
#
# Run it on the operator's own machine, never on the app host: it needs the
# operator's private key, which the server must not hold. It reaches the
# host's Docker over SSH:
#
#   DOCKER_HOST=ssh://<user>@<host> restore-site.sh \
#     --target <name> --registry <file> --storage <conf> \
#     --set <prefix>/<site uuid>/sets/<T>.tar.gpg \
#     --private-key <file> [--passphrase-file <file>] [--drain-seconds 20]
#
#   DOCKER_HOST=ssh://<user>@<host> restore-site.sh \
#     --clear-pause --target <name> --registry <file>
#
# The registry is a plain file the operator keeps off the app host, one line
# per target:
#
#   <target-name> <app-container> <live-site-uuid> <accepts-site-uuid>
#
# <app-container> is a name or label:<key>=<value>. For a restore in place
# both UUIDs are the site's own. For a fresh replacement install, the live
# UUID is the new install's (drush config:get system.site uuid), read when
# the line is added, and accepts is the UUID of the site whose sets it takes.
#
# --storage names a config file with the S3_* lines of nightly-backup.sh
# (mode 600), with credentials that can read the bucket.
#
# What it refuses, before anything on the site changes: an unknown target, a
# set whose stored checksum, archive checksum or site does not match, a set
# for another site than the line accepts, a container whose live site UUID is
# not the one on the line, a running backup or restore. Then it closes the
# site with a restore pause that has no end time, waits for the worker's pass
# and for web requests already running to finish, reads the live UUID again, and loads the database and files
# through that same container, and empties the search indexes before it
# opens the site again. A failure after that point leaves the site
# closed (a half-loaded site is never served) and prints the --clear-pause
# command.
#
# Sets are encrypted but not signed: whoever holds the bucket's write key
# could plant one. Keep that key on the backup host only.
#
# Exit status: 0 restored (or pause cleared), 1 refused or failed, 2 usage.

set -euo pipefail

HERE="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
# shellcheck source=backup-lib.sh
. "$HERE/backup-lib.sh"

DRUSH_IN_CONTAINER='/var/www/html/vendor/bin/drush -r /var/www/html/html'
PUBLIC_DIR=/var/www/html/html/sites/default/files
PRIVATE_DIR=/var/www/private
UUID_RE='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'

usage() { echo "restore-site: $1" >&2; exit 2; }
refuse() { echo "restore-site: refused: $1. Nothing on the site was changed." >&2; exit 1; }

TARGET="" REGISTRY="" STORAGE="" SET="" PRIVATE_KEY="" PASSFILE="" DRAIN=20 CLEAR=0
while [ $# -gt 0 ]; do
  case "$1" in
    --target) TARGET="${2:-}"; shift 2 ;;
    --registry) REGISTRY="${2:-}"; shift 2 ;;
    --storage) STORAGE="${2:-}"; shift 2 ;;
    --set) SET="${2:-}"; shift 2 ;;
    --private-key) PRIVATE_KEY="${2:-}"; shift 2 ;;
    --passphrase-file) PASSFILE="${2:-}"; shift 2 ;;
    --drain-seconds) DRAIN="${2:-}"; shift 2 ;;
    --clear-pause) CLEAR=1; shift ;;
    *) usage "unknown argument $1" ;;
  esac
done
[ -n "$TARGET" ] && [ -n "$REGISTRY" ] || usage "--target and --registry are required"
[ -f "$REGISTRY" ] || usage "registry file not found"
[[ "$DRAIN" =~ ^[0-9]+$ ]] || usage "--drain-seconds must be a whole number"

# --- the registry line ---------------------------------------------------------------
line="$(awk -v t="$TARGET" '$1 == t' "$REGISTRY")"
[ -n "$line" ] || refuse "no target named '$TARGET' in the registry"
[ "$(wc -l <<<"$line")" = 1 ] || refuse "the registry names '$TARGET' more than once"
read -r _ CONTAINER LIVE_UUID ACCEPTS_UUID extra <<<"$line"
[ -z "${extra:-}" ] || refuse "the registry line for '$TARGET' has more than four fields"
[[ "$LIVE_UUID" =~ $UUID_RE ]] && [[ "$ACCEPTS_UUID" =~ $UUID_RE ]] || refuse "the registry line for '$TARGET' has no valid site UUIDs"

app_exec() {  # app_exec USER ARGS...: runs in the checked container
  local user="$1"; shift
  "$DOCKER_BIN" exec -i -u "$user" -e HOME=/tmp "$APP" "$@"
}
drush() {
  # shellcheck disable=SC2086
  app_exec www-data $DRUSH_IN_CONTAINER "$@"
}
live_uuid() { drush config:get system.site uuid --format=string 2>/dev/null | tr -d '[:space:]'; }

if [ "$CLEAR" = 1 ]; then
  APP="$(resolve_container "$CONTAINER")" || refuse "$APP"
  app_exec www-data localnodes-pause clear
  app_exec www-data localnodes-pause status
  exit 0
fi

[ -n "$STORAGE" ] && [ -n "$SET" ] && [ -n "$PRIVATE_KEY" ] || usage "--storage, --set and --private-key are required"
[[ "$SET" =~ ^[A-Za-z0-9._/-]+\.tar\.gpg$ ]] || usage "--set must be the key of a .tar.gpg set"
why="$(conf_check "$STORAGE")" || usage "$why"
s3_setup "$STORAGE" || usage "$S3_ERR"

# --- one restore at a time from this machine ---------------------------------------
LOCK_DIR="${LOCK_DIR:-${XDG_RUNTIME_DIR:-/tmp}/localnodes-restore}"
mkdir -p "$LOCK_DIR" && chmod 700 "$LOCK_DIR"
exec 8>"$LOCK_DIR/$ACCEPTS_UUID.lock"
flock -n 8 || refuse "another restore of this site is running from this machine"

# --- the set, checked before the site is touched ----------------------------------
WORK="" DECRYPT_PID=""
cleanup() {
  trap - EXIT
  trap '' HUP INT TERM
  if [ -n "$DECRYPT_PID" ]; then
    kill -TERM "$DECRYPT_PID" 2>/dev/null || true
    wait "$DECRYPT_PID" 2>/dev/null || true
  fi
  if [ -n "$WORK" ]; then rm -rf -- "$WORK"; fi
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM
WORK="$(mktemp -d)"
chmod 700 "$WORK"

s3_get "$SET" "$WORK/set.tar.gpg" || refuse "could not download $SET"
head="$(s3api head-object --bucket "$S3_BUCKET" --key "$SET")" || refuse "could not read the set's stored checksum"
[ "$(jq -r '.Metadata.sha256 // empty' <<<"$head")" = "$(sha256sum "$WORK/set.tar.gpg" | cut -d' ' -f1)" ] \
  || refuse "the download does not match the checksum stored with the set"
_decrypt_with_worker "$PRIVATE_KEY" "$WORK/set.tar.gpg" "$WORK/set.tar" "$PASSFILE" &
DECRYPT_PID=$!
decrypt_rc=0
wait "$DECRYPT_PID" || decrypt_rc=$?
DECRYPT_PID=""
[ "$decrypt_rc" = 0 ] || refuse "could not decrypt the set with this key"
rm -f -- "$WORK/set.tar.gpg"
mkdir "$WORK/set"
tar -xf "$WORK/set.tar" -C "$WORK/set" --no-same-owner identity.json 2>/dev/null || refuse "the set holds no identity.json"
ARCHIVE="$(jq -r '.archive // empty' "$WORK/set/identity.json")"
[[ "$ARCHIVE" =~ ^[A-Za-z0-9._-]+\.tar$ ]] || refuse "identity.json names no archive"
tar -xf "$WORK/set.tar" -C "$WORK/set" --no-same-owner "$ARCHIVE" 2>/dev/null || refuse "the set does not hold the archive it names"
rm -f -- "$WORK/set.tar"
[ "$(sha256sum "$WORK/set/$ARCHIVE" | cut -d' ' -f1)" = "$(jq -r '.archive_sha256' "$WORK/set/identity.json")" ] \
  || refuse "the archive does not match the checksum in identity.json"
SET_UUID="$(jq -r '.site_uuid // empty' "$WORK/set/identity.json")"
CAPTURE="$(jq -r '.capture_start // empty' "$WORK/set/identity.json")"
[ "$SET_UUID" = "$ACCEPTS_UUID" ] \
  || refuse "the set is from site $SET_UUID; target '$TARGET' accepts sets from $ACCEPTS_UUID only"

FOLDER="${ARCHIVE%.tar}"
mkdir "$WORK/x"
tar -xf "$WORK/set/$ARCHIVE" -C "$WORK/x" --no-same-owner 2>/dev/null || refuse "the archive could not be unpacked"
rm -f -- "$WORK/set/$ARCHIVE"
D="$WORK/x/$FOLDER"
[ -f "$D/SHA256SUMS" ] && [ -f "$D/manifest.json" ] || refuse "the archive is not a site export"
(cd "$D" && sha256sum --quiet -c SHA256SUMS) >/dev/null 2>&1 || refuse "a part of the archive does not match SHA256SUMS"
[ "$(jq -r '.site_uuid // empty' "$D/manifest.json")" = "$SET_UUID" ] || refuse "the archive's manifest names another site"
for part in database.sql.gz public-files.tar.gz private-files.tar.gz; do
  [ -f "$D/$part" ] || refuse "the archive has no $part"
done

# --- the target: the container on the line, holding the site on the line ----------
APP="$(resolve_container "$CONTAINER")" || refuse "$APP"
got="$(live_uuid)" || true
[ "$got" = "$LIVE_UUID" ] || refuse "container $APP holds site '${got:-unreadable}', not $LIVE_UUID from the registry"

# --- close the site; from here a failure leaves it closed --------------------------
TOKEN="$(app_exec www-data localnodes-pause create-restore 2>&1)" \
  || refuse "could not close the site: $TOKEN"
[[ "$TOKEN" =~ ^[0-9a-f]{32}$ ]] || refuse "the pause tool printed no token"

# Run in the container after search-api:clear. It fails (an exception, so
# drush exits non-zero; drush counts any exit() as abnormal) unless no delete
# is still queued and every enabled Solr index is empty. A delete may take a
# few seconds to show, so it looks for up to 30.
SEARCH_EMPTY_CHECK='
$tasks = \Drupal::service("search_api.server_task_manager");
$tasks->execute();
if ($tasks->getCount()) { throw new \RuntimeException("a search delete is still queued"); }
foreach (range(1, 30) as $try) {
  $left = [];
  foreach (\Drupal\search_api\Entity\Index::loadMultiple() as $index) {
    if (!$index->status() || !$index->isServerEnabled() || $index->getServerInstance()->getBackendId() !== "search_api_solr") { continue; }
    $query = $index->query()->range(0, 0);
    $query->setOption("search_api_bypass_access", TRUE);
    if ($query->execute()->getResultCount()) { $left[] = $index->id(); }
  }
  if (!$left) { return; }
  sleep(1);
}
throw new \RuntimeException("search index not empty: " . implode(", ", $left));
'

STEP=""
DELETED=0
stuck() {
  local clear="  DOCKER_HOST=${DOCKER_HOST:-} $0 --clear-pause --target $TARGET --registry $REGISTRY"
  echo "restore-site: failed at '$STEP'. The site stays closed (no end time)." >&2
  if [ "$DELETED" = 0 ]; then
    echo "restore-site: nothing on the site was deleted yet. Lift the pause, fix the cause, then run the restore again:" >&2
    echo "$clear" >&2
  else
    # A new restore refuses while this pause is in force, and the database
    # may be empty; lifting the pause would serve a half-loaded site.
    echo "restore-site: the site's database or files may be half loaded. Keep the pause: a new restore refuses while it is in force, and lifting it would serve the half-loaded site." >&2
    echo "restore-site: fix the cause, then finish the restore by hand with the site still closed (load the set's database and files, run updatedb, cr and search-api:clear). Lift the pause only once the database is known good:" >&2
    echo "$clear" >&2
  fi
  exit 1
}

# Nothing is written by these waits; their messages are fixed text.
STEP=wait-worker
app_exec www-data localnodes-pause wait-worker 900 >/dev/null 2>&1 || stuck
# Web requests that began before the pause may still be writing.
STEP=wait-web
app_exec www-data localnodes-pause wait-web 300 >/dev/null 2>&1 || stuck
sleep "$DRAIN"

# Read again right before the first delete: the container on the line must
# still hold the site on the line.
STEP=live-uuid
got="$(live_uuid)" || true
if [ "$got" != "$LIVE_UUID" ]; then
  app_exec www-data localnodes-pause release "$TOKEN" >/dev/null 2>&1 || true
  refuse "container $APP now holds site '${got:-unreadable}', not $LIVE_UUID"
fi

STEP=database
DELETED=1
drush sql:drop -y >/dev/null 2>&1 || stuck
# The client prints a failed statement in full on stderr; that would put
# members' rows into the terminal and its logs, so the error stays quiet.
gunzip -c "$D/database.sql.gz" | drush sql:cli >/dev/null 2>&1 || stuck

STEP=files
# Emptied as root (Drupal may have left root-owned files), unpacked as the web
# user. .localnodes keeps the site's own salt, locks and this pause. rm names
# a file it cannot delete, and file names can hold members' words, so its
# messages stay out of the terminal and its logs.
for dir in "$PUBLIC_DIR" "$PRIVATE_DIR"; do
  app_exec 0 find "$dir" -mindepth 1 -maxdepth 1 ! -name .localnodes -exec rm -rf -- {} + >/dev/null 2>&1 || stuck
done
# tar names the file it failed on; an uploaded file's name can hold a
# member's words, so its messages stay out of the terminal and its logs.
app_exec www-data tar -xzf - -C "$PUBLIC_DIR" < "$D/public-files.tar.gz" 2>/dev/null || stuck
app_exec www-data tar -xzf - -C "$PRIVATE_DIR" --exclude=./.localnodes --exclude=.localnodes < "$D/private-files.tar.gz" 2>/dev/null || stuck

# Drush's own errors can quote rows, file names or a search server's reply;
# a failure prints the fixed step message only.
STEP=update
drush updatedb -y >/dev/null 2>&1 || stuck
drush cr >/dev/null 2>&1 || stuck
# The search servers still hold what was indexed before the restore, with the
# access rules it had then: a page made public after the copy would still show
# to visitors while the restored database says it is private. Empty every
# index before the site opens; the worker indexes the restored content again.
STEP=search
drush search-api:clear >/dev/null 2>&1 || stuck
# A search server that refuses the delete leaves it queued as a task and the
# clear still succeeds; the site must not open with the old documents in
# place. Retry the queued tasks, then check every Solr index holds nothing.
STEP=search-check
drush php:eval "$SEARCH_EMPTY_CHECK" >/dev/null 2>&1 || stuck
ACCOUNTS="$(drush sql:query 'SELECT COUNT(*) FROM users WHERE uid > 0' 2>/dev/null | tr -d '[:space:]')" || stuck

STEP=reopen
app_exec www-data localnodes-pause release "$TOKEN" >/dev/null || stuck
jq -nc --arg target "$TARGET" --arg site "$SET_UUID" --arg capture "$CAPTURE" --arg accounts "$ACCOUNTS" \
  '{status: "restored", target: $target, site_uuid: $site, capture_start: $capture, accounts: ($accounts | tonumber? // $accounts)}'
echo "restore-site: the site is open again. Its search indexes were emptied; the worker fills them again (see the host steps)." >&2
