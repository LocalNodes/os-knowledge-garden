#!/usr/bin/env bash
# Lane sites for the Phase 21 e2e lane (21-40): init / code / dir / exec.
#
# Usage: site.sh init | code | dir | exec <cmd...>
# The project comes from LN_SITE_PROJECT (default fresh3-e2e). Only the lane projects
# fresh3-e2e and fresh3-p21-a11y are allowed; any other name is refused with exit 2.
# Exit codes: 0 done, 1 failed, 2 usage or refused.
#
# The site dir is /home/dev/Code/localnodes/<project>, a sibling of fresh3-work. It runs
# committed code only:
#   - the site dir is a detached `git worktree` of fresh3-work;
#   - repos/safe_smart_accounts and repos/group_treasury in it are detached worktrees of the
#     matching fresh3-work clones;
#   - repos/siwe_login in it is a `git clone --shared` copy of fresh3-work's repos/siwe_login
#     (orchestrator ruling, 2026-11-06: siwe_login is read-only for Phase 21, so nothing is
#     written into its .git, not even worktree metadata). --shared borrows the clone's objects;
#     it is safe because siwe_login gets no gc or history rewrite in Phase 21.
# `code` discards any tracked-file change in those four checkouts (the site is a disposable
# mirror; nothing there is ever kept), moves them to the current HEADs, writes the lane-only
# settings into the site's gitignored html/sites/default/settings.local.php (never into the
# tracked settings.php), and writes tests/e2e/.state/site-identity-<project>.json.
#
# Teardown (no plan runs it; deleting a lane site is the orchestrator's call), in this order:
#   (cd <dir> && ddev delete -Oy <project>)
#   git -C <fresh3-work>/repos/safe_smart_accounts worktree remove --force <dir>/repos/safe_smart_accounts
#   git -C <fresh3-work>/repos/group_treasury worktree remove --force <dir>/repos/group_treasury
#   rm -rf <dir>/repos/siwe_login
#   git -C <fresh3-work> worktree remove --force <dir>
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

DIR="$(dirname "$ROOT")/$PROJECT"
STATE_DIR="$ROOT/tests/e2e/.state"
IDENTITY="$STATE_DIR/site-identity-$PROJECT.json"
WT_MODULES=(safe_smart_accounts group_treasury)
CLONE_MODULE=siwe_login

die() { echo "site.sh: $*" >&2; exit 1; }
usage() { echo "usage: site.sh init | code | dir | exec <cmd...>   (LN_SITE_PROJECT=fresh3-e2e|fresh3-p21-a11y)" >&2; exit 2; }

# True when $2 is a registered worktree of the repo at $1.
is_worktree_of() { git -C "$1" worktree list --porcelain | grep -qxF "worktree $2"; }
# True when $1 is a --shared clone of fresh3-work's siwe_login.
is_shared_clone() {
  [ -d "$1/.git" ] \
    && [ "$(git -C "$1" config --get remote.origin.url 2>/dev/null)" = "$ROOT/repos/$CLONE_MODULE" ] \
    && grep -qxF "$ROOT/repos/$CLONE_MODULE/.git/objects" "$1/.git/objects/info/alternates" 2>/dev/null
}
is_site() { [ -d "$DIR" ] && is_worktree_of "$ROOT" "$DIR"; }

# The four checkouts of the site, as "label path" lines.
checkouts() {
  echo "fresh3 $DIR"
  for m in "${WT_MODULES[@]}" "$CLONE_MODULE"; do echo "$m $DIR/repos/$m"; done
}

cmd_init() {
  git -C "$ROOT" worktree prune
  for m in "${WT_MODULES[@]}"; do git -C "$ROOT/repos/$m" worktree prune; done
  if [ -e "$DIR" ]; then
    is_site || die "$DIR exists and isn't a worktree of fresh3-work; refused"
  else
    git -C "$ROOT" worktree add -q --detach "$DIR" HEAD
    echo "site.sh: $DIR added as a detached worktree of fresh3-work"
  fi
  mkdir -p "$DIR/repos"
  for m in "${WT_MODULES[@]}"; do
    if [ -e "$DIR/repos/$m" ]; then
      is_worktree_of "$ROOT/repos/$m" "$DIR/repos/$m" || die "$DIR/repos/$m exists and isn't a worktree of repos/$m; refused"
    else
      git -C "$ROOT/repos/$m" worktree add -q --detach "$DIR/repos/$m" HEAD
      echo "site.sh: repos/$m added as a detached worktree"
    fi
  done
  local c="$DIR/repos/$CLONE_MODULE"
  if [ -e "$c" ]; then
    is_shared_clone "$c" || die "$c exists and isn't a --shared clone of repos/$CLONE_MODULE; refused"
  else
    git clone -q --shared --no-checkout "$ROOT/repos/$CLONE_MODULE" "$c"
    git -C "$c" checkout -q --detach "$(git -C "$ROOT/repos/$CLONE_MODULE" rev-parse HEAD)"
    echo "site.sh: repos/$CLONE_MODULE cloned (--shared) and detached"
  fi
}

# Lane-only settings go in the site's gitignored settings.local.php, which the tracked
# settings.php includes last. They never go in the tracked settings.php, so `code` (which
# discards tracked changes) can't wipe them and they can't be committed. Rewritten on every
# `code`, so the file always matches this script.
#   - extension_discovery_scan_tests and localnodes_a11y_ai_deny: Phase 22's a11y
#     global-setup (tests/a11y/global-setup.ts) refuses a site without both. The deny does
#     nothing until that setup enables the test-only guard module.
write_local_settings() {
  local f="$DIR/html/sites/default/settings.local.php"
  case "$f" in "$DIR"/html/sites/default/settings.local.php) ;; *) die "bad settings path $f" ;; esac
  git -C "$DIR" check-ignore -q "html/sites/default/settings.local.php" \
    || die "html/sites/default/settings.local.php isn't gitignored at the site's HEAD; refused"
  cat > "$f" <<'PHP'
<?php

// Written by fresh3-work tests/e2e/site/site.sh on every `site.sh code`.
// A lane test site only. Gitignored: never commit it, and never copy it to a real site.

// Phase 22's a11y suite (tests/a11y/global-setup.ts) needs both.
$settings['extension_discovery_scan_tests'] = TRUE;
$settings['localnodes_a11y_ai_deny'] = TRUE;
PHP
  echo "site.sh: wrote the lane-only settings.local.php"
}

cmd_code() {
  is_site || die "no site at $DIR yet; run site.sh init first"
  local label path src sha changed discarded='[]'
  while read -r label path <&3; do
    # Never act on fresh3-work or its clones, only on the site's own checkouts.
    case "$path" in "$DIR"|"$DIR"/repos/*) ;; *) die "refusing to touch $path" ;; esac
    [ "$(git -C "$path" rev-parse --show-toplevel)" = "$path" ] || die "$path isn't a checkout root"
    changed=$(git -C "$path" diff --name-only HEAD)
    if [ -n "$changed" ]; then
      git -C "$path" checkout -q HEAD -- .
      discarded=$(jq -c --arg l "$label" --arg c "$changed" '. + ($c | split("\n") | map(select(length > 0)) | map($l + ":" + .))' <<<"$discarded")
      echo "site.sh: discarded tracked changes in $label: $(tr '\n' ' ' <<<"$changed")"
    fi
    if [ "$label" = fresh3 ]; then src="$ROOT"; else src="$ROOT/repos/$label"; fi
    sha=$(git -C "$src" rev-parse HEAD)
    if [ "$label" = "$CLONE_MODULE" ] && ! git -C "$path" cat-file -e "$sha^{commit}" 2>/dev/null; then
      git -C "$path" fetch -q origin
    fi
    git -C "$path" checkout -q --detach "$sha"
  done 3< <(checkouts)

  write_local_settings

  mkdir -p "$STATE_DIR"
  local installed=null tmp
  if [ -f "$IDENTITY" ]; then installed=$(jq -c '.installedAt // null' "$IDENTITY"); fi
  tmp=$(mktemp "$STATE_DIR/.identity.XXXXXX")
  jq -n --arg project "$PROJECT" --arg dir "$DIR" \
    --arg fresh3 "$(git -C "$DIR" rev-parse HEAD)" \
    --arg ssa "$(git -C "$DIR/repos/safe_smart_accounts" rev-parse HEAD)" \
    --arg gt "$(git -C "$DIR/repos/group_treasury" rev-parse HEAD)" \
    --arg siwe "$(git -C "$DIR/repos/siwe_login" rev-parse HEAD)" \
    --arg synced "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --argjson installed "$installed" --argjson discarded "$discarded" \
    '{project: $project, dir: $dir, fresh3: $fresh3, safe_smart_accounts: $ssa, group_treasury: $gt,
      siwe_login: $siwe, siwe_login_source: "git clone --shared", discarded: $discarded,
      syncedAt: $synced, installedAt: $installed}' > "$tmp"
  mv "$tmp" "$IDENTITY"
  echo "site.sh: $PROJECT at fresh3 $(jq -r .fresh3 "$IDENTITY" | cut -c1-7)," \
    "safe_smart_accounts $(jq -r .safe_smart_accounts "$IDENTITY" | cut -c1-7)," \
    "group_treasury $(jq -r .group_treasury "$IDENTITY" | cut -c1-7)," \
    "siwe_login $(jq -r .siwe_login "$IDENTITY" | cut -c1-7)"
}

[ $# -ge 1 ] || usage
cmd=$1; shift
case "$cmd" in
  init) [ $# -eq 0 ] || usage; cmd_init ;;
  code) [ $# -eq 0 ] || usage; cmd_code ;;
  dir) [ $# -eq 0 ] || usage; echo "$DIR" ;;
  exec)
    [ $# -ge 1 ] || usage
    [ -d "$DIR" ] || die "no site at $DIR yet"
    cd "$DIR"
    exec "$@"
    ;;
  *) usage ;;
esac
