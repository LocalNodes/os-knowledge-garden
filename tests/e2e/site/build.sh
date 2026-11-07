#!/usr/bin/env bash
# Build, refresh or reinstall a Phase 21 lane site from committed HEADs (21-40).
#
# Usage: build.sh [--reinstall]
# The project comes from LN_SITE_PROJECT (default fresh3-e2e). Only the lane projects
# fresh3-e2e and fresh3-p21-a11y are allowed; any other name is refused with exit 2.
# Exit codes: 0 done, 1 failed, 2 usage or refused.
#
# Steps: take the flock tests/e2e/.state/<project>.lock (shared with the lane's suite runners;
# waits up to 30 minutes), refuse under 15 GB free, site.sh init (first time) and code (which
# also writes the lane-only settings into the gitignored settings.local.php), write
# the gitignored .ddev/config.local.yaml (fresh3-e2e: additional FQDN fresh3.test), ddev start,
# ddev composer install, link html/modules/contrib/<module> to the site's repos/<module>,
# install (first time or --reinstall: site:install social, localnodes_platform,
# localnodes_web3) or update (updatedb, cr; never config:import), set the SIWE domain list
# (fresh3-e2e only; test-site setup, no siwe_login code change), then, for fresh3-e2e only,
# attach any running local chain (tests/e2e/fork/fork.sh connect all). The suite's chains
# belong to the e2e lane: fresh3-p21-a11y reads canned fixtures and never joins them (corrected
# 2026-11-07, 21-28 open decision 4). The stored RPC lists stay as shipped.
#
# No sudo and no /etc/hosts edit: DDEV runs with DDEV_NONINTERACTIVE=true. Clients map
# fresh3.test to 127.0.0.1 themselves (curl --resolve, Chromium --host-resolver-rules).
set -euo pipefail

EXPECTED_ROOT=/home/dev/Code/localnodes/fresh3-work
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(git -C "$HERE" rev-parse --show-toplevel 2>/dev/null || true)"
if [ "$ROOT" != "$EXPECTED_ROOT" ]; then
  echo "site refused: run from fresh3-work" >&2
  exit 2
fi
PROJECT="${LN_SITE_PROJECT:-fresh3-e2e}"
case "$PROJECT" in
  fresh3-e2e|fresh3-p21-a11y) ;;
  *) echo "site refused: $PROJECT isn't a lane project" >&2; exit 2 ;;
esac
export LN_SITE_PROJECT="$PROJECT"

REINSTALL=0
case "${1:-}" in
  '') ;;
  --reinstall) REINSTALL=1 ;;
  *) echo "usage: build.sh [--reinstall]   (LN_SITE_PROJECT=fresh3-e2e|fresh3-p21-a11y)" >&2; exit 2 ;;
esac
[ $# -le 1 ] || { echo "usage: build.sh [--reinstall]" >&2; exit 2; }

SITE="$HERE/site.sh"
DIR="$(bash "$SITE" dir)"
STATE_DIR="$ROOT/tests/e2e/.state"
IDENTITY="$STATE_DIR/site-identity-$PROJECT.json"
LOCK="$STATE_DIR/$PROJECT.lock"
MODULES=(safe_smart_accounts group_treasury siwe_login)
MIN_FREE_KB=$((15 * 1024 * 1024))
SIWE_DOMAINS='fresh3.test,fresh3-e2e.ddev.site,fresh3-e2e.ddev.site:33000'

die() { echo "build.sh: $*" >&2; exit 1; }
step() { echo "build.sh: [$(date -u +%H:%M:%S)] $*"; }

# One builder or suite run per project at a time. The lock file holds the current holder's
# description (opened for append, so a waiting process never wipes it).
mkdir -p "$STATE_DIR"
exec 9>>"$LOCK"
if ! flock -w 1800 9; then
  holder=$(head -1 "$LOCK" 2>/dev/null || true)
  die "$LOCK is still held after 30 minutes${holder:+ (holder: $holder)}"
fi
: > "$LOCK"
echo "build.sh pid $$ since $(date -u +%Y-%m-%dT%H:%M:%SZ)" >&9

free_kb=$(df -Pk "$(dirname "$ROOT")" | awk 'NR==2 {print $4}')
[ "$free_kb" -ge "$MIN_FREE_KB" ] || die "refused: $((free_kb / 1024 / 1024)) GB free, the floor is 15 GB"

started=$(date +%s)
step "$PROJECT: code"
bash "$SITE" init
bash "$SITE" code

step "$PROJECT: .ddev/config.local.yaml"
{
  echo "# Written by fresh3-work tests/e2e/site/build.sh (21-40). Gitignored by DDEV."
  if [ "$PROJECT" = fresh3-e2e ]; then echo 'additional_fqdns: ["fresh3.test"]'; fi
} > "$DIR/.ddev/config.local.yaml"

export DDEV_NONINTERACTIVE=true
ddev_site() { (cd "$DIR" && ddev "$@"); }

step "$PROJECT: ddev start"
ddev_site start
step "$PROJECT: composer install"
ddev_site composer install --no-interaction

step "$PROJECT: link the module clones"
for m in "${MODULES[@]}"; do
  link="$DIR/html/modules/contrib/$m"
  case "$link" in "$DIR"/html/modules/contrib/*) ;; *) die "bad link path $link" ;; esac
  if [ "$(readlink "$link" 2>/dev/null || true)" != "../../../repos/$m" ]; then
    rm -rf "$link"
    ln -s "../../../repos/$m" "$link"
  fi
  [ "$(readlink "$link")" = "../../../repos/$m" ] || die "$link isn't linked to repos/$m"
  [ -f "$link/$m.info.yml" ] || die "$link/$m.info.yml is missing"
done

installed=0
if [ "$(ddev_site drush status --field=bootstrap 2>/dev/null || true)" = "Successful" ]; then installed=1; fi
if [ "$installed" = 0 ] || [ "$REINSTALL" = 1 ]; then
  step "$PROJECT: site:install social"
  ddev_site drush site:install social -y
  # site:install appends $databases and a hash_salt to the tracked settings.php. DDEV's
  # settings.ddev.php (included at its end) already sets both, so restore the committed file:
  # the site runs committed code only. Lane-only settings live in the gitignored
  # settings.local.php (site.sh code), which this restore never touches.
  if ! git -C "$DIR" diff --quiet HEAD -- html/sites/default/settings.php; then
    git -C "$DIR" checkout -q HEAD -- html/sites/default/settings.php
    step "$PROJECT: restored the committed settings.php after site:install"
  fi
  step "$PROJECT: enable localnodes_platform"
  ddev_site drush en localnodes_platform -y
  step "$PROJECT: enable localnodes_web3"
  ddev_site drush en localnodes_web3 -y
  ddev_site drush cr
  tmp=$(mktemp "$STATE_DIR/.identity.XXXXXX")
  jq --arg t "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '.installedAt = $t' "$IDENTITY" > "$tmp" && mv "$tmp" "$IDENTITY"
else
  step "$PROJECT: updatedb and cache rebuild (already installed)"
  ddev_site drush updatedb -y
  ddev_site drush cr
fi

if [ "$PROJECT" = fresh3-e2e ]; then
  step "$PROJECT: SIWE domain list"
  ddev_site drush config:set siwe_login.settings expected_domain "$SIWE_DOMAINS" -y
  # No RPC list trim any more (21-43): settings.php sends .test requests to the local chains
  # through $settings['safe_rpc_urls'], which fully replaces the stored list (SSA 1f055c7,
  # fresh3 8f21fb0). A stopped chain then gives an honest read error on the .test host,
  # with no fallback to a shipped public RPC. The ddev.site host reads the shipped list.
  ddev_site drush cr
fi

if [ "$PROJECT" = fresh3-e2e ]; then
  step "$PROJECT: attach running local chains"
  bash "$ROOT/tests/e2e/fork/fork.sh" connect all
else
  step "$PROJECT: local chains not attached (they belong to fresh3-e2e)"
fi

echo "build.sh: done in $(( $(date +%s) - started )) s"
echo "build.sh: https://$PROJECT.ddev.site"
if [ "$PROJECT" = fresh3-e2e ]; then
  echo "build.sh: https://fresh3.test (map it: curl --resolve fresh3.test:443:127.0.0.1, Chromium --host-resolver-rules='MAP fresh3.test 127.0.0.1')"
fi
jq . "$IDENTITY"
