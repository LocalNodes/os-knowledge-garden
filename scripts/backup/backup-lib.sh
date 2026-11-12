# shellcheck shell=bash
# shellcheck disable=SC2034  # S3_* and S3_ERR are read by the scripts that source this
#
# Shared by nightly-backup.sh and restore-site.sh: reading a config file, the
# S3-compatible storage calls, and encryption. Sourced, never run.
#
# Storage goes through the AWS command line tool (aws, version 2). It is the
# one client both candidate storage companies document, and it reads a
# bucket's expiry rule as exact JSON, which the nightly job must check.
# Credentials stay in an aws credentials file named by the config, so they
# never appear on a command line or in this script's output.

# Overridable for the shell tests.
AWS_BIN="${AWS_BIN:-aws}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
GPG_BIN="${GPG_BIN:-gpg}"

# Some S3-compatible stores reject the newer default checksum headers; only
# send them when an operation needs them. Integrity is checked another way:
# Content-MD5 on upload, then the stored size and SHA-256 read back.
export AWS_REQUEST_CHECKSUM_CALCULATION=when_required
export AWS_RESPONSE_CHECKSUM_VALIDATION=when_required

# conf_check FILE: the file holds no secret value itself, but it names where
# the secrets are, so it must be private to the user running the job.
conf_check() {
  local file="$1" mode owner
  [ -f "$file" ] || { echo "config file not found: $file"; return 1; }
  mode="$(stat -c '%a' "$file")"
  owner="$(stat -c '%u' "$file")"
  case "$mode" in 600|400) ;; *) echo "config file must be mode 600 or 400 (it is $mode)"; return 1 ;; esac
  [ "$owner" = "$(id -u)" ] || { echo "config file must belong to the user running this script"; return 1; }
}

# conf_get FILE NAME: the value of NAME= in FILE, without surrounding quotes.
# The file is read, never sourced.
conf_get() {
  local line
  line="$(grep -m1 -E "^$2=" "$1" || true)"
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

# s3_setup CONF: reads the storage settings into S3_* variables. Call it
# directly, not in $(...), or the variables are lost; on failure it returns 1
# with the reason in S3_ERR.
s3_setup() {
  S3_ENDPOINT="$(conf_get "$1" S3_ENDPOINT)"
  S3_BUCKET="$(conf_get "$1" S3_BUCKET)"
  S3_REGION="$(conf_get "$1" S3_REGION)"
  S3_CREDENTIALS="$(conf_get "$1" S3_CREDENTIALS)"
  S3_PREFIX="$(conf_get "$1" S3_PREFIX)"
  S3_PREFIX="${S3_PREFIX:-localnodes-backup}"
  [[ "$S3_ENDPOINT" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]+)?/?$ ]] || { S3_ERR="S3_ENDPOINT must be an http(s) address with no path"; return 1; }
  [[ "$S3_BUCKET" =~ ^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$ ]] || { S3_ERR="S3_BUCKET is not a valid bucket name"; return 1; }
  [[ "$S3_PREFIX" =~ ^[A-Za-z0-9._-]+$ ]] || { S3_ERR="S3_PREFIX may hold letters, digits, dot, dash and underscore only"; return 1; }
  S3_REGION="${S3_REGION:-auto}"
  [ -f "$S3_CREDENTIALS" ] || { S3_ERR="S3_CREDENTIALS must name an aws credentials file"; return 1; }
  case "$(stat -c '%a' "$S3_CREDENTIALS")" in 600|400) ;; *) S3_ERR="the aws credentials file must be mode 600 or 400"; return 1 ;; esac
  export AWS_SHARED_CREDENTIALS_FILE="$S3_CREDENTIALS"
  export AWS_CONFIG_FILE=/dev/null
  export AWS_PROFILE=default
  export AWS_DEFAULT_REGION="$S3_REGION"
  unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN
}

# The store's own error text is not passed on: these scripts run on the backup
# host and, for a restore, on the operator's own machine, and that text would
# sit in a terminal or a journal. A failed call reports its operation and the
# tool's exit status, nothing else.
s3api() {
  local rc=0
  "$AWS_BIN" --endpoint-url "$S3_ENDPOINT" --output json s3api "$@" 2>/dev/null || rc=$?
  [ "$rc" -eq 0 ] || echo "storage call '$1' failed (exit $rc)" >&2
  return "$rc"
}

# s3_put FILE KEY: uploads, then reads the object's size and metadata back.
# The store refuses a body that does not match Content-MD5; the read-back
# catches a short or missing object and checks the SHA-256 we sent is kept
# with it, for the restore to compare against the download.
s3_put() {
  local file="$1" key="$2" md5 sha size head
  md5="$(openssl dgst -md5 -binary "$file" | base64)"
  sha="$(sha256sum "$file" | cut -d' ' -f1)"
  size="$(stat -c '%s' "$file")"
  s3api put-object --bucket "$S3_BUCKET" --key "$key" --body "$file" \
    --content-md5 "$md5" --metadata "sha256=$sha" >/dev/null || return 1
  head="$(s3api head-object --bucket "$S3_BUCKET" --key "$key")" || return 1
  [ "$(jq -r '.ContentLength' <<<"$head")" = "$size" ] || return 1
  [ "$(jq -r '.Metadata.sha256 // empty' <<<"$head")" = "$sha" ] || return 1
}

# s3_get KEY FILE: downloads one object.
s3_get() {
  s3api get-object --bucket "$S3_BUCKET" --key "$1" "$2" >/dev/null
}

# s3_list PREFIX: prints each key under PREFIX, one per line.
s3_list() {
  s3api list-objects-v2 --bucket "$S3_BUCKET" --prefix "$1" | jq -r '.Contents[]?.Key'
}

# s3_lifecycle_ok KEY_PREFIX DAYS: succeeds only when an enabled bucket rule
# that applies to every key under KEY_PREFIX deletes objects within DAYS and
# aborts unfinished uploads within DAYS; on a versioned bucket it must also
# delete old versions within DAYS, with no "keep the newest N" condition
# (each copy's key has one version, so such a rule would never delete it).
# Prints why not, otherwise nothing.
s3_lifecycle_ok() {
  local prefix="$1" days="$2" rules versioning
  rules="$(s3api get-bucket-lifecycle-configuration --bucket "$S3_BUCKET" 2>/dev/null)" || rules='{"Rules":[]}'
  # A versioned bucket needs the old-version rule too, so an unreadable
  # answer must not count as "not versioned". A bucket that was never
  # versioned answers with no Status (or an empty body).
  versioning="$(s3api get-bucket-versioning --bucket "$S3_BUCKET" 2>/dev/null)" \
    || { echo "could not read whether the bucket keeps old versions"; return 0; }
  versioning="$(jq -r 'if . == null then "Off" elif (.Status // "Off") | IN("Off", "Enabled", "Suspended") then .Status // "Off" else error end' <<<"${versioning:-null}" 2>/dev/null)" \
    || { echo "could not read whether the bucket keeps old versions"; return 0; }
  jq -r --arg p "$prefix" --argjson d "$days" --arg v "${versioning:-Off}" '
    [ .Rules[]?
      | select(.Status == "Enabled")
      # A rule that applies to every key under our prefix: no tag or size
      # filter, and its prefix (empty for the whole bucket) starts ours.
      | select((.Filter // {}) | (has("Tag") or has("And") or has("ObjectSizeGreaterThan") or has("ObjectSizeLessThan")) | not)
      | select(((.Filter.Prefix // .Prefix // "") as $rp | $p | startswith($rp)))
    ] as $r
    | if ($r | map(select((.Expiration.Days // 1e9) <= $d)) | length) == 0 then "no enabled rule deletes copies within \($d) days"
      elif ($r | map(select((.AbortIncompleteMultipartUpload.DaysAfterInitiation // 1e9) <= $d)) | length) == 0 then "no enabled rule removes unfinished uploads within \($d) days"
      elif ($v != "Off") and (($r | map(select((.NoncurrentVersionExpiration.NoncurrentDays // 1e9) <= $d and (.NoncurrentVersionExpiration | has("NewerNoncurrentVersions") | not))) | length) == 0) then "the bucket keeps old versions and no enabled rule deletes them within \($d) days"
      else empty end
  ' <<<"$rules"
}

# resolve_container SPEC: prints the running container SPEC names, a name or
# label:<key>=<value> matching exactly one running container. On failure it
# prints why and returns 1.
resolve_container() {
  local spec="$1" found=()
  if [[ "$spec" == label:* ]]; then
    mapfile -t found < <("$DOCKER_BIN" ps --filter "label=${spec#label:}" --filter status=running --format '{{.Names}}')
    [ "${#found[@]}" -eq 1 ] || { echo "the label matches ${#found[@]} running containers, not one"; return 1; }
    spec="${found[0]}"
  fi
  "$DOCKER_BIN" inspect --format '{{.State.Running}}' "$spec" 2>/dev/null | grep -qx true \
    || { echo "the app container is not running"; return 1; }
  printf '%s' "$spec"
}

# encrypt_to PUBLIC_KEY IN OUT: encrypts to the operator's public key with a
# throwaway keyring, so nothing persists on the host.
encrypt_to() {
  local home rc=0
  home="$(mktemp -d)"
  "$GPG_BIN" --homedir "$home" --batch --quiet --no-tty --trust-model always \
    --recipient-file "$1" --output "$3" --encrypt "$2" 2>/dev/null || rc=1
  gpgconf --homedir "$home" --kill all >/dev/null 2>&1 || true
  rm -rf "$home"
  return "$rc"
}

# decrypt_with SECRET_KEY IN OUT [PASSPHRASE_FILE]: decrypts with the
# operator's private key, imported into a throwaway keyring that is removed
# afterwards. A key with a passphrase needs the passphrase file.
decrypt_with() {
  local home rc=0 pass=()
  [ -n "${4:-}" ] && pass=(--pinentry-mode loopback --passphrase-file "$4")
  home="$(mktemp -d)"
  if "$GPG_BIN" --homedir "$home" --batch --quiet --no-tty "${pass[@]}" --import "$1" 2>/dev/null; then
    "$GPG_BIN" --homedir "$home" --batch --quiet --no-tty "${pass[@]}" --yes --output "$3" --decrypt "$2" 2>/dev/null || rc=1
  else
    rc=1
  fi
  gpgconf --homedir "$home" --kill all >/dev/null 2>&1 || true
  rm -rf "$home"
  return "$rc"
}
