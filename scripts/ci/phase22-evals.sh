#!/usr/bin/env bash
# The Phase 22 tests the phase22-evals CI job runs (plan 22-29).
#
# Same bytes in CI and locally. Every test here answers model calls from
# recorded fixtures and mocks (ScriptedLlmSubscriber, the ai_test echoai
# provider, Guzzle MockHandler), uses SQLite, and needs no network, Solr,
# Qdrant or provider key. Functional tests (@group phase22_functional) need
# HTTP and stay local (scripts/run-phpunit.sh).
#
# Sets (PHASE22_EVALS_SET):
# - gate (default): all Phase 22 Unit tests, plus the smallest Kernel set
#   that proves the release blockers (AI-SPEC §7). This gates the image.
# - full: every non-Functional Phase 22 test. A scheduled nightly job runs
#   it and doesn't block the build. Some of its Kernel tests chunk text
#   with tiktoken, which loads a vocab file; set TIKTOKEN_CACHE_DIR to a
#   pre-fetched copy (scripts/ci/tiktoken-vocab.sh) so the run needs no
#   network.
#
# One phpunit run per directory: PHPUnit 9 takes one path, and
# html/modules/custom as a whole doesn't load, because localnodes_web3's
# Kernel tests extend SafeKernelTestBase, which the composer-installed
# safe_smart_accounts doesn't ship.
#
# The config path is absolute: Kernel tests run in child processes, which
# can't read a relative -c path.
#
# Env (defaults are the CI values): SIMPLETEST_DB, SIMPLETEST_BASE_URL,
# SYMFONY_DEPRECATIONS_HELPER (weak: deprecation notices are printed but
# don't fail the run; the gate is for behaviour), PHP (php binary).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
# SQLite on tmpfs: a Kernel test spends most of its time installing
# modules, and every write syncs to disk. /dev/shm made LeakMatrixRagTest
# 4.6x faster (46 s to 10 s) on desktop-dev; GitHub's Ubuntu runners
# mount /dev/shm too.
if [ -d /dev/shm ] && [ -w /dev/shm ]; then DB_DIR=/dev/shm; else DB_DIR=/tmp; fi
export SIMPLETEST_DB="${SIMPLETEST_DB:-sqlite://localhost/$DB_DIR/phase22.sqlite}"
export SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-http://localhost}"
export SYMFONY_DEPRECATIONS_HELPER="${SYMFONY_DEPRECATIONS_HELPER:-weak}"
PHPUNIT=("${PHP:-php}" vendor/bin/phpunit -c "$ROOT/html/core/phpunit.xml.dist")

M=html/modules/custom

# Kernel classes that prove the release blockers (AI-SPEC §7: E1, E2, E3,
# E7, E8, E10). Unit tests cover the rest of E3 and E8.
# E1 who can see it: AccessPolicyBaseline, LeakMatrix{Posts,Comments,Rag},
#   AgentLeakMatrix, OverviewLeak. E2 fails closed: FailClosed,
#   RagQueryOptions. E3/E8 overview rewrite and sanitising: OverviewRewrite.
#   E10 logs and defaults: LogHygiene, ObservabilityDefaults. E7 (F-4)
#   attached file text reaches the index: FileContentExtractor.
# gate-a's Kernel list is the release blockers above.
KERNEL_SAI_A='AccessPolicyBaselineTest|LeakMatrixPostsTest|LeakMatrixCommentsTest|LeakMatrixRagTest|AgentLeakMatrixTest|OverviewLeakTest|FailClosedTest|RagQueryOptionsTest|OverviewRewriteTest|LogHygieneTest|ObservabilityDefaultsTest|FileContentExtractorTest'
# gate-b (orchestrator rulings, 2026-11-07: a broken leak fix must block the
#   image; split into a second parallel job so the gate stays near 5 min).
#   RQ-13 stale access in a long-lived process: RQ13StaleAccess, StaleIndex.
#   One no-answer copy (option A) and the guard exits: GuardRunner. The
#   runtime support check in both doors and its output cap:
#   SupportCheckAssistant, SupportCheckOverview, GenerationConfig.
KERNEL_SAI_B='RQ13StaleAccessTest|StaleIndexTest|GuardRunnerTest|SupportCheckAssistantTest|SupportCheckOverviewTest|GenerationConfigTest'
# scripts/tests/phase22-evals-gate.test.sh pins both lists and their union.
# E7 no silent gaps: ImportManifest. E8 imported text stays data:
#   RestrictedFormat, MediaWikiImport.
# Merge review R1 blockers 1 and 2 (and item 4): the importer follows a
#   Topic's current access and group: CurrentTopicAccess.
KERNEL_LNI='ImportManifestTest|RestrictedFormatTest|MediaWikiImportTest|CurrentTopicAccessTest'

run() {
  echo "::group::phpunit $*"
  local start=$SECONDS rc=0
  "${PHPUNIT[@]}" "$@" || rc=$?
  echo "phase22-evals: $* -> exit $rc in $((SECONDS - start)) s"
  echo "::endgroup::"
  return $rc
}

status=0
gate_a() {
  run --group phase22_unit "$M/social_ai_indexing" || status=1
  run --group phase22_unit "$M/localnodes_import" || status=1
  run --group phase22 "$M/localnodes_platform/tests" || status=1
  run --group phase22 --exclude-group phase22_functional --filter "/($KERNEL_SAI_A)::/" "$M/social_ai_indexing" || status=1
  run --group phase22 --exclude-group phase22_functional --filter "/($KERNEL_LNI)::/" "$M/localnodes_import" || status=1
}
gate_b() {
  run --group phase22 --exclude-group phase22_functional --filter "/($KERNEL_SAI_B)::/" "$M/social_ai_indexing" || status=1
}
case "${PHASE22_EVALS_SET:-gate}" in
  full)
    # Nightly, non-blocking: every Phase 22 test except Functional.
    run --group phase22 --exclude-group phase22_functional "$M/social_ai_indexing" || status=1
    run --group phase22 --exclude-group phase22_functional "$M/localnodes_import" || status=1
    run --group phase22 "$M/localnodes_platform/tests" || status=1
    ;;
  gate-a)
    # CI job 1 of 2: Unit tests and the release-blocker Kernel set.
    gate_a
    ;;
  gate-b)
    # CI job 2 of 2: stale access, option A, the support check.
    gate_b
    ;;
  gate)
    # Both halves in one run (local use).
    gate_a
    gate_b
    ;;
  *)
    echo "phase22-evals: unknown PHASE22_EVALS_SET '${PHASE22_EVALS_SET}' (gate, gate-a, gate-b, full)" >&2
    exit 2
    ;;
esac
exit $status
