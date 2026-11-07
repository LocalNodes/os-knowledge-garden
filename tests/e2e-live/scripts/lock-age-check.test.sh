#!/usr/bin/env bash
# Self-test for lock-age-check.sh: proves the check fires on an aliased package.
#
# The fixture lockfile has one npm alias, string-width-cjs -> string-width 4.2.3, published
# 2021-09-23T17:17:21Z (a registry fact).
#   1. Cutoff 2021-01-01: the real package is younger than the floor, so the check must FAIL,
#      naming string-width with its real date (not "missing", which the alias-key bug gave).
#   2. Cutoff 2022-01-01: the real package is older, so the check must PASS (the old bug
#      failed here too, with "missing").
# Needs the npm registry (npm view, read-only).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CHECK="$HERE/lock-age-check.sh"
FIXTURE="$HERE/fixtures/alias-lock.json"
fail() { echo "lock-age-check.test: FAIL: $*" >&2; exit 1; }

set +e
out=$(bash "$CHECK" "$FIXTURE" 2021-01-01T00:00:00Z 2>&1); rc=$?
set -e
echo "case 1 (alias younger than the floor): exit $rc: $out"
[ "$rc" -ne 0 ] || fail "an aliased package younger than the floor passed"
grep -q "too new or unknown: string-width@4.2.3 2021-09-23" <<<"$out" || fail "case 1 didn't name the real package and its date"

set +e
out=$(bash "$CHECK" "$FIXTURE" 2022-01-01T00:00:00Z 2>&1); rc=$?
set -e
echo "case 2 (alias older than the floor): exit $rc: $out"
[ "$rc" -eq 0 ] || fail "an aliased package older than the floor failed"

echo "lock-age-check.test: OK"
