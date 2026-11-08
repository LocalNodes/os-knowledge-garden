#!/usr/bin/env bash
# Local checks for the CI-only live-Sepolia suite (21-45 Task B, T-21-119, T-21-120).
#
# Never runs the suite with a key. It checks only what can be checked on a machine that has
# no seed:
#   1. `npm run list` lists live-sepolia.e2e.ts (the whole import graph loads with no secret
#      in the environment).
#   2. Without GITHUB_ACTIONS, `npm run e2e` is refused with "live suite refused: CI only",
#      before any browser starts.
#   3. With GITHUB_ACTIONS=true but a base URL that isn't localhost or .test, it is refused too.
#   4. The seed variable is named only in wallet-setup/live/live.setup.ts, never in a spec or a
#      config, and the local (anvil) setup doesn't name it.
#   5. The live config turns trace, video and screenshots off.
# Usage: bash tests/e2e-live/scripts/live-suite-check.sh (from anywhere).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIVE="$(cd "$HERE/.." && pwd)"
fail() { echo "live-suite-check: FAIL: $*" >&2; exit 1; }

[ -f "$LIVE/playwright.config.ts" ] || fail "no live config at tests/e2e-live/playwright.config.ts"
[ -f "$LIVE/live-sepolia.e2e.ts" ] || fail "no live spec at tests/e2e-live/live-sepolia.e2e.ts"
[ -f "$LIVE/wallet-setup/live/live.setup.ts" ] || fail "no live wallet setup"

# No secret may be in this shell's environment for the checks below.
for v in SYNPRESS_SEED_PHRASE SYNPRESS_WALLET_PASSWORD; do
  [ -z "${!v:-}" ] || fail "$v is set in this shell; this check runs only without secrets"
done

# 1. The list.
set +e
list=$(env -u GITHUB_ACTIONS npm --prefix "$LIVE" run list 2>&1); rc=$?
set -e
[ "$rc" -eq 0 ] || { printf '%s\n' "$list" >&2; fail "npm run list exited $rc"; }
printf '%s\n' "$list" | grep -q "live-sepolia.e2e.ts" || { printf '%s\n' "$list" >&2; fail "the list has no live-sepolia.e2e.ts"; }
echo "live-suite-check: list OK: $(printf '%s\n' "$list" | grep -c 'live-sepolia.e2e.ts') test(s) in live-sepolia.e2e.ts"

# 2. Refused outside CI.
set +e
out=$(env -u GITHUB_ACTIONS npm --prefix "$LIVE" run e2e 2>&1); rc=$?
set -e
[ "$rc" -ne 0 ] || fail "a run without GITHUB_ACTIONS was not refused"
printf '%s\n' "$out" | grep -q "live suite refused: CI only" || { printf '%s\n' "$out" >&2; fail "no 'live suite refused: CI only' message"; }
if printf '%s\n' "$out" | grep -qE "Running [0-9]+ test|MetaMask cache"; then
  printf '%s\n' "$out" >&2; fail "the refused run got past global setup"
fi
echo "live-suite-check: refusal outside CI OK"

# 3. Refused on a host that isn't localhost or .test, even in CI.
set +e
out=$(GITHUB_ACTIONS=true LN_LIVE_BASE_URL=https://cascadia.localnodes.xyz npm --prefix "$LIVE" run e2e 2>&1); rc=$?
set -e
[ "$rc" -ne 0 ] || fail "a run against a public host was not refused"
printf '%s\n' "$out" | grep -q "live suite refused: cascadia.localnodes.xyz is not localhost or a .test host" \
  || { printf '%s\n' "$out" >&2; fail "no host refusal message"; }
echo "live-suite-check: refusal of a public host OK"

# 4. Where the seed variable is named.
for f in live-sepolia.e2e.ts playwright.config.ts local.config.ts local-anvil.e2e.ts live-global-setup.ts wallet-setup/local/anvil-local.setup.ts; do
  [ -f "$LIVE/$f" ] || continue
  n=$(grep -c "SYNPRESS_SEED_PHRASE" "$LIVE/$f" || true)
  [ "$n" = "0" ] || fail "$f names SYNPRESS_SEED_PHRASE ($n times)"
done
grep -q "SYNPRESS_SEED_PHRASE" "$LIVE/wallet-setup/live/live.setup.ts" || fail "the live setup doesn't read SYNPRESS_SEED_PHRASE"
echo "live-suite-check: the seed variable is named only in wallet-setup/live/live.setup.ts"

# 5. No recording in the live config.
for k in "trace: 'off'" "video: 'off'" "screenshot: 'off'"; do
  grep -qF "$k" "$LIVE/playwright.config.ts" || fail "the live config lacks $k"
done
grep -q "GITHUB_ACTIONS" "$LIVE/playwright.config.ts" || fail "the live config doesn't check GITHUB_ACTIONS"

# 6. The live wallet cache build is refused outside CI too, before it downloads or builds anything.
had_cache=no; [ -e "$LIVE/.cache-synpress/live-sepolia" ] && had_cache=yes
set +e
out=$(env -u GITHUB_ACTIONS npm --prefix "$LIVE" run cache 2>&1); rc=$?
set -e
[ "$rc" -ne 0 ] || fail "the live cache build ran outside CI"
printf '%s\n' "$out" | grep -q "live suite refused: CI only" || { printf '%s\n' "$out" >&2; fail "no refusal from the live cache build"; }
[ "$had_cache" = yes ] || [ ! -e "$LIVE/.cache-synpress/live-sepolia" ] || fail "the refused cache build left a live profile"
echo "live-suite-check: refusal of the live cache build outside CI OK"
echo "live-suite-check: OK"
