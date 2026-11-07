#!/bin/bash
# Shell test for scripts/ci/phase22-evals.sh's gate sets (Phase 22, 22-27/22-29).
#
# The gate sets are what block the image build. Orchestrator rulings
# (2026-11-07): a broken leak fix must block an image build, so the Kernel
# classes proving the RQ-13 stale-access fix, the one no-answer copy
# (option A) and the runtime support check are gated, not only nightly; and
# the Kernel set is split into two parallel jobs (gate-a, gate-b) that the
# build needs both of. This checks:
# - each job's list (KERNEL_SAI_A, KERNEL_SAI_B) holds its pinned classes;
# - the two lists don't overlap and their union is exactly the full gated
#   set, so a test can't drop out of both;
# - every listed class exists as a test file;
# - build-image.yml runs both sets as a matrix and the build needs that job.
# Output is TAP; exit status is non-zero when any check fails.
#
# Usage: bash scripts/tests/phase22-evals-gate.test.sh

set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$REPO_ROOT/scripts/ci/phase22-evals.sh"
WORKFLOW="$REPO_ROOT/.github/workflows/build-image.yml"
KERNEL_DIR="$REPO_ROOT/html/modules/custom/social_ai_indexing/tests/src/Kernel"

# gate-a: the release blockers (AI-SPEC §7).
PINNED_A=(AccessPolicyBaselineTest LeakMatrixPostsTest LeakMatrixCommentsTest LeakMatrixRagTest AgentLeakMatrixTest OverviewLeakTest FailClosedTest RagQueryOptionsTest OverviewRewriteTest LogHygieneTest ObservabilityDefaultsTest FileContentExtractorTest)
# gate-b: RQ-13 stale access, option A and the guard exits, the support check.
PINNED_B=(RQ13StaleAccessTest StaleIndexTest GuardRunnerTest SupportCheckAssistantTest SupportCheckOverviewTest GenerationConfigTest)

PASS=0
FAIL=0
N=0
ok() { N=$((N + 1)); PASS=$((PASS + 1)); echo "ok $N - $1"; }
not_ok() { N=$((N + 1)); FAIL=$((FAIL + 1)); echo "not ok $N - $1"; }
check() { if eval "$2"; then ok "$1"; else not_ok "$1"; fi; }

list_a="$(sed -n "s/^KERNEL_SAI_A='\(.*\)'$/\1/p" "$SCRIPT")"
list_b="$(sed -n "s/^KERNEL_SAI_B='\(.*\)'$/\1/p" "$SCRIPT")"
check "KERNEL_SAI_A is set" '[ -n "$list_a" ]'
check "KERNEL_SAI_B is set" '[ -n "$list_b" ]'

for class in "${PINNED_A[@]}"; do
  check "gate-a includes $class" 'printf "%s" "|$list_a|" | grep -q "|$class|"'
done
for class in "${PINNED_B[@]}"; do
  check "gate-b includes $class" 'printf "%s" "|$list_b|" | grep -q "|$class|"'
done

IFS='|' read -r -a listed_a <<< "$list_a"
IFS='|' read -r -a listed_b <<< "$list_b"
for class in "${listed_a[@]}"; do
  check "$class is in gate-a only" '! printf "%s" "|$list_b|" | grep -q "|$class|"'
done
union="$(printf '%s\n' "${listed_a[@]}" "${listed_b[@]}" | sort -u | tr '\n' ' ')"
expected="$(printf '%s\n' "${PINNED_A[@]}" "${PINNED_B[@]}" | sort -u | tr '\n' ' ')"
check "the union of both jobs is exactly the gated set" '[ "$union" = "$expected" ]'

for class in "${listed_a[@]}" "${listed_b[@]}"; do
  check "$class exists" '[ -f "$KERNEL_DIR/$class.php" ]'
done

check "the script runs gate-a" 'grep -q "gate-a)" "$SCRIPT"'
check "the script runs gate-b" 'grep -q "gate-b)" "$SCRIPT"'
check "the workflow matrix runs gate-a and gate-b" 'grep -Eq "set: \[ *gate-a *, *gate-b *\]" "$WORKFLOW"'
check "the workflow passes the set to the script" 'grep -q "PHASE22_EVALS_SET: \${{ matrix.set }}" "$WORKFLOW"'
check "the build needs the gate job" 'grep -Eq "^    needs: phase22-evals$" "$WORKFLOW"'

echo "1..$N"
echo "# pass $PASS, fail $FAIL"
[ "$FAIL" -eq 0 ]
