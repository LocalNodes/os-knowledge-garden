#!/usr/bin/env bash
# Lane-only gas-sponsor relayer for a DDEV project (Phase 25, plan 25-09, D-01).
#
# Usage: ddev-relayer.sh enable | disable | status
#
#   enable   symlink .ddev/relayer/docker-compose.relayer.yaml to .ddev/docker-compose.relayer.yaml,
#            write .ddev/.env.relayer when absent, and put SPONSOR_RELAYER_URL and
#            SPONSOR_RELAYER_TOKEN in .ddev/.env (run `ddev restart` after).
#   disable  remove the symlink and the two Drupal-side names; .ddev/.env.relayer stays.
#   status   say what is enabled. Never prints a key or a token.
#
# Rules this script keeps (TM-89, D-01):
#   - The lane relayer signs with anvil's well-known dev account 9 only. Any other key, whether
#     passed in SPONSOR_RELAYER_PRIVATE_KEY or already sitting in .ddev/.env.relayer, is refused.
#   - Every file it writes is gitignored (.ddev/.env, .env.relayer, the symlink) and
#     mode 0600. The tracked compose file lives in .ddev/relayer/, which DDEV does not auto-load,
#     so other lanes are unaffected until they run `enable` themselves.
#   - Chain URLs point at the anvil containers that tests/e2e/fork/fork.sh attaches to the DDEV
#     network as anvil-ethereum-sepolia and anvil-base-sepolia. Nothing here reaches a real chain.
#
# Exit codes: 0 done, 1 failed, 2 usage or refused.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DDEV_DIR="$ROOT/.ddev"
TRACKED="$DDEV_DIR/relayer/docker-compose.relayer.yaml"
LINK="$DDEV_DIR/docker-compose.relayer.yaml"
ENV_RELAYER="$DDEV_DIR/.env.relayer"
ENV_DRUPAL="$DDEV_DIR/.env"
SOURCE_DIR="$ROOT/repos/gas-sponsor-relayer"

# anvil's account 9, published in the anvil docs. A public test key: it holds no real value.
ANVIL_KEY_9=0x2a871d0798f97d79848a013d4936a73bf4cc922c825d33c1cf7073dff6d409c6

die() { echo "ddev-relayer: $*" >&2; exit 1; }
refuse() { echo "ddev-relayer: $*" >&2; exit 2; }
usage() { echo "usage: ddev-relayer.sh enable | disable | status" >&2; exit 2; }

# The DDEV site name: `name:` in .ddev/config.yaml when set, else the checkout directory name.
site_name() {
  local n
  n=$(awk '/^name:/ {print $2; exit}' "$DDEV_DIR/config.yaml" 2>/dev/null || true)
  printf '%s' "${n:-$(basename "$ROOT")}"
}

# The value of NAME in a KEY=VALUE file, or empty. Never echoed by this script's callers.
value_of() {
  local file=$1 name=$2
  [ -f "$file" ] || return 0
  awk -F= -v k="$name" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' "$file"
}

# Drop the named keys from a KEY=VALUE file, keep every other line, then append the new lines.
# Mode 0600 whatever the old mode was.
rewrite_env() {
  local file=$1; shift
  local tmp keys=() pair
  tmp=$(mktemp "$DDEV_DIR/.relayer-env.XXXXXX")
  chmod 600 "$tmp"
  for pair in "$@"; do keys+=("${pair%%=*}"); done
  if [ -f "$file" ]; then
    local line k skip
    while IFS= read -r line || [ -n "$line" ]; do
      skip=false
      for k in "${keys[@]}"; do
        case "$line" in "$k="*) skip=true ;; esac
      done
      $skip || printf '%s\n' "$line" >> "$tmp"
    done < "$file"
  fi
  for pair in "$@"; do printf '%s\n' "$pair" >> "$tmp"; done
  mv "$tmp" "$file"
  chmod 600 "$file"
}

drop_env_keys() {
  local file=$1; shift
  [ -f "$file" ] || return 0
  local tmp line k skip
  tmp=$(mktemp "$DDEV_DIR/.relayer-env.XXXXXX")
  chmod 600 "$tmp"
  while IFS= read -r line || [ -n "$line" ]; do
    skip=false
    for k in "$@"; do
      case "$line" in "$k="*) skip=true ;; esac
    done
    $skip || printf '%s\n' "$line" >> "$tmp"
  done < "$file"
  mv "$tmp" "$file"
  chmod 600 "$file"
}

cmd_enable() {
  [ -f "$TRACKED" ] || die "missing $TRACKED"
  [ -d "$SOURCE_DIR" ] || die "missing $SOURCE_DIR (the relayer source clone the service builds from)"

  # A key given in the environment, or already on disk, must be the anvil key. Checked before
  # anything is written, so a refusal leaves the tree as it was.
  if [ -n "${SPONSOR_RELAYER_PRIVATE_KEY:-}" ] && [ "$SPONSOR_RELAYER_PRIVATE_KEY" != "$ANVIL_KEY_9" ]; then
    refuse "SPONSOR_RELAYER_PRIVATE_KEY is not anvil's dev account 9. The lane relayer never holds another key."
  fi
  local existing_key
  existing_key=$(value_of "$ENV_RELAYER" SPONSOR_RELAYER_PRIVATE_KEY)
  if [ -n "$existing_key" ] && [ "$existing_key" != "$ANVIL_KEY_9" ]; then
    refuse "$ENV_RELAYER holds a key that is not anvil's dev account 9. Remove that file and run enable again."
  fi

  ln -sfn relayer/docker-compose.relayer.yaml "$LINK"

  local token
  token=$(value_of "$ENV_RELAYER" SPONSOR_RELAYER_TOKEN)
  if [ ! -f "$ENV_RELAYER" ] || [ -z "$token" ]; then
    token=$(openssl rand -hex 16)
    local site
    site=$(site_name)
    rewrite_env "$ENV_RELAYER" \
      "WEB3_ENABLED=true" \
      "SPONSOR_RELAYER_PRIVATE_KEY=$ANVIL_KEY_9" \
      "SPONSOR_RELAYER_TOKEN=$token" \
      "SPONSOR_RELAYER_RPC_URL_11155111=http://anvil-ethereum-sepolia:8545" \
      "SPONSOR_RELAYER_RPC_URL_84532=http://anvil-base-sepolia:8545" \
      "SPONSOR_RELAYER_COMMUNITY=$site"
    echo "ddev-relayer: wrote .ddev/.env.relayer (anvil dev key 9, a fresh token)"
  else
    echo "ddev-relayer: kept the existing .ddev/.env.relayer"
  fi

  # The Drupal side. DDEV 1.25 passes .ddev/.env into the web container (checked with ddev exec).
  rewrite_env "$ENV_DRUPAL" "SPONSOR_RELAYER_URL=http://relayer:8080" "SPONSOR_RELAYER_TOKEN=$token"
  echo "ddev-relayer: enabled. Run 'ddev restart', then fork.sh up and connect (LN_SITE_PROJECT=$(site_name))."
}

cmd_disable() {
  if [ -L "$LINK" ]; then rm -f "$LINK"; echo "ddev-relayer: removed the compose symlink"; else echo "ddev-relayer: no compose symlink"; fi
  drop_env_keys "$ENV_DRUPAL" SPONSOR_RELAYER_URL SPONSOR_RELAYER_TOKEN
  echo "ddev-relayer: disabled. .ddev/.env.relayer is kept. Run 'ddev restart' to drop the service."
}

cmd_status() {
  local on=no files=no key=none
  [ -L "$LINK" ] && [ -e "$LINK" ] && on=yes
  [ -f "$ENV_RELAYER" ] && files=yes
  if [ -f "$ENV_RELAYER" ]; then
    if [ "$(value_of "$ENV_RELAYER" SPONSOR_RELAYER_PRIVATE_KEY)" = "$ANVIL_KEY_9" ]; then key=anvil-dev-9; else key=NOT-ANVIL; fi
  fi
  echo "compose service linked: $on"
  echo ".ddev/.env.relayer present: $files (key: $key)"
  echo "drupal side set: $([ -n "$(value_of "$ENV_DRUPAL" SPONSOR_RELAYER_URL)" ] && [ -n "$(value_of "$ENV_DRUPAL" SPONSOR_RELAYER_TOKEN)" ] && echo yes || echo no)"
  local c="ddev-$(site_name)-relayer" st
  st=$(docker inspect -f '{{.State.Status}}' "$c" 2>/dev/null || true)
  echo "container: ${st:-none}"
}

[ $# -eq 1 ] || usage
case "$1" in
  enable) cmd_enable ;;
  disable) cmd_disable ;;
  status) cmd_status ;;
  *) usage ;;
esac
