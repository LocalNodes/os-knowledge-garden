#!/usr/bin/env bash
# Lockfile age check for tests/e2e-live (21-45, T-21-SC).
#
# Usage: lock-age-check.sh [lockfile] [cutoff ISO time]
#   lockfile defaults to tests/e2e-live/package-lock.json;
#   cutoff defaults to 14 days ago.
# Exit 0: every package in the lockfile was published before the cutoff.
# Exit 1: a package is newer than the cutoff, or its publish time can't be read.
#
# npm aliases ("string-width-cjs": "npm:string-width@4.2.3") sit under the alias key, but the
# registry knows them by their real name, which the lockfile entry carries in .name. The
# lookup uses .name when present and the key's last segment otherwise. (The plan's first
# version used the key, so every alias read as "missing".) The self-test is
# lock-age-check.test.sh.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCK="${1:-$HERE/../package-lock.json}"
CUTOFF="${2:-$(node -e 'console.log(new Date(Date.now()-14*864e5).toISOString())')}"
[ -s "$LOCK" ] || { echo "lock-age-check: no lockfile at $LOCK" >&2; exit 1; }

LIST="$(mktemp)"
trap 'rm -f "$LIST"' EXIT
jq -r '.packages | to_entries[]
  | select(.key | startswith("node_modules/"))
  | select(.value.link != true)
  | "\(.value.name // (.key | sub("^.*node_modules/"; "")))@\(.value.version)"' "$LOCK" | sort -u > "$LIST"
[ -s "$LIST" ] || { echo "lock-age-check: no packages in $LOCK" >&2; exit 1; }

xargs -P 8 -I{} sh -c '
  name="${1%@*}"; ver="${1##*@}"
  t=$(npm view "$name" time --json 2>/dev/null | jq -r --arg v "$ver" ".[\$v] // \"missing\"" 2>/dev/null || echo missing)
  echo "${t:-missing} $1"' _ {} < "$LIST" \
  | awk -v c="$CUTOFF" '
      $1 == "missing" || $1 >= c {bad=1; print "too new or unknown: " $2 " " $1}
      END {if (!bad) print "lock-age-check: OK, " NR " packages published before " c; exit bad}'
