#!/usr/bin/env bash
# The whole wallet e2e suite on the local chains, recorded and indexed (21-43).
#
# Usage: bash tests/e2e/run.sh [ethereum_sepolia|base_sepolia|all]   (default all)
#        npm run test:e2e
# Exit codes: 0 every network passed, 1 a spec failed (or a step broke), 2 usage or refused.
#
# Steps:
#   - refuse under 10 GB free, or while the canned-read fixture module
#     (safe_smart_accounts_rpc_fixture) is enabled on fresh3-e2e;
#   - take the fresh3-e2e run lock (tests/e2e/.state/fresh3-e2e.lock, the same flock as
#     tests/e2e/site/build.sh and 21-45's recording; waits up to 30 minutes);
#   - one run id for every network: LN_E2E_RUN_ID, or the UTC time;
#   - start the chains (any running suite chain is stopped first: fork.sh up refuses a
#     running one), and stop them all on exit, whatever happens;
#   - add a line to e2e-evidence/INDEX.md naming the run and the site identity;
#   - per network, in order: reset the chain to its post-setup snapshot, run every *.e2e.ts
#     with trace and video into e2e-evidence/runs/<runId>/<network>/, copy the results file
#     there, and index the report with tests/e2e/evidence-index.mjs;
#   - print one line per network and exit 1 if any network failed.
#
# Lane rules (R-D): only anvil's well-known dev accounts sign, only on the local anvil
# chains, and only for the .test host (tests/e2e/wallet/test-wallet.ts enforces it).
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$ROOT" || exit 2

STATE_DIR="$ROOT/tests/e2e/.state"
LOCK="$STATE_DIR/fresh3-e2e.lock"
IDENTITY="$STATE_DIR/site-identity-fresh3-e2e.json"
PHASE_DIR="$ROOT/.planning/phases/21-real-safe-reads-balances"
INDEX="$PHASE_DIR/e2e-evidence/INDEX.md"
FORK="$ROOT/tests/e2e/fork/fork.sh"
SITE="$ROOT/tests/e2e/site/site.sh"
MIN_FREE_KB=$((10 * 1024 * 1024))
FIXTURE=safe_smart_accounts_rpc_fixture

say() { echo "run.sh: $*"; }
refuse() { echo "run.sh: refused: $*" >&2; exit 2; }

TARGET="${1:-all}"
[ $# -le 1 ] || { echo "usage: run.sh [ethereum_sepolia|base_sepolia|all]" >&2; exit 2; }
case "$TARGET" in
  all) NETWORKS=(ethereum_sepolia base_sepolia) ;;
  ethereum_sepolia|base_sepolia) NETWORKS=("$TARGET") ;;
  *) echo "usage: run.sh [ethereum_sepolia|base_sepolia|all]" >&2; exit 2 ;;
esac

free_kb=$(df -Pk "$ROOT" | awk 'NR==2 {print $4}')
[ "$free_kb" -ge "$MIN_FREE_KB" ] || refuse "$((free_kb / 1024 / 1024)) GB free, the floor is 10 GB"

# core.extension, not pml: the fixture module is `hidden: true`, and pml leaves hidden modules
# out, so a pml check could never refuse (corrected 2026-11-07, 21-28 open decision 2).
enabled=$(bash "$SITE" exec ddev drush config:get core.extension module 2>/dev/null) \
  || refuse "can't read fresh3-e2e's module list (is the site built and running?)"
if grep -qE "^[[:space:]]+$FIXTURE:" <<<"$enabled"; then
  refuse "$FIXTURE is enabled on fresh3-e2e; the suite never runs against canned reads"
fi

# One builder, suite run or recording per lane site at a time. The lock file holds the
# holder's description (opened for append, so a waiting process never wipes it).
mkdir -p "$STATE_DIR"
exec 9>>"$LOCK"
if ! flock -w 1800 9; then
  holder=$(head -1 "$LOCK" 2>/dev/null || true)
  echo "run.sh: $LOCK is still held after 30 minutes${holder:+ (holder: $holder)}" >&2
  exit 1
fi
: > "$LOCK"
echo "run.sh pid $$ since $(date -u +%Y-%m-%dT%H:%M:%SZ)" >&9

RID="${LN_E2E_RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"
[[ "$RID" =~ ^[A-Za-z0-9_.-]{1,80}$ ]] || refuse "LN_E2E_RUN_ID has unexpected characters"
export LN_E2E_RUN_ID="$RID"

trap 'bash "$FORK" down all >/dev/null 2>&1; say "chains stopped"' EXIT

say "run $RID on ${NETWORKS[*]}"
bash "$FORK" down all >/dev/null 2>&1 || true
if [ "$TARGET" = all ]; then up_target=all; else up_target="$TARGET"; fi
bash "$FORK" up "$up_target" || { echo "run.sh: the chains didn't start" >&2; exit 1; }

if [ -f "$IDENTITY" ]; then
  ident=$(jq -r '"fresh3 \(.fresh3[0:7]), safe_smart_accounts \(.safe_smart_accounts[0:7]), group_treasury \(.group_treasury[0:7]), siwe_login \(.siwe_login[0:7]); installed \(.installedAt // "unknown")"' "$IDENTITY")
else
  ident="unknown (no $IDENTITY)"
fi
printf '\nRun %s (tests/e2e/run.sh %s): site fresh3-e2e at %s.\n' "$RID" "$TARGET" "$ident" >> "$INDEX"

failed=0
summary=()
for n in "${NETWORKS[@]}"; do
  ev="$PHASE_DIR/e2e-evidence/runs/$RID/$n"
  mkdir -p "$ev"
  say "$n: reset the chain"
  if ! bash "$FORK" reset "$n"; then
    failed=1
    summary+=("$n: FAILED (chain reset), evidence $ev")
    continue
  fi
  mode=$(bash "$FORK" mode "$n")
  say "$n: the suite ($mode mode)"
  LN_E2E_NETWORK="$n" LN_E2E_EVIDENCE_DIR="$ev" \
    npx --no-install playwright test -c tests/e2e/playwright.config.ts
  rc=$?
  results="$STATE_DIR/results-$n.json"
  [ -f "$results" ] && cp "$results" "$ev/"
  if ! node tests/e2e/evidence-index.mjs "$STATE_DIR/report-$n.json" "$n" "$RID" "$mode"; then
    rc=1
  fi
  if [ "$rc" -eq 0 ]; then
    summary+=("$n: passed, results $results, evidence $ev")
  else
    failed=1
    summary+=("$n: FAILED (exit $rc), results $results, evidence $ev")
  fi
done

for line in "${summary[@]}"; do say "$line"; done
exit "$failed"
