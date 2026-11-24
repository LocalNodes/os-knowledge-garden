#!/usr/bin/env bash
# Tests for .github/workflows/fund-practice-relayer.yml, and the relayer input of
# governance-judge.yml, which signs with the same wallet key.
#
# Static checks on the parsed workflow (PyYAML): manual dispatch from main only, no permissions,
# the practice wallet's concurrency group, the one input, secrets only in the funding step's env, no ${{ }} inside
# a run: block, actions pinned by SHA, lockfile-only install. Then the funding step's real run:
# text is run with a stub `node` (no network, no key): its exit status is the script's, and the
# step summary holds FUNDING lines only, never another line the script printed. Last, the
# script's own tests (node --test, fake RPC and fake sign-up app).
#
# Usage: bash scripts/ops/tests/fund-relayer-workflow.test.sh
#        WF=/path/to/copy.yml bash scripts/ops/tests/fund-relayer-workflow.test.sh   (mutation runs)
#        GJ=/path/to/copy.yml bash scripts/ops/tests/fund-relayer-workflow.test.sh   (governance-judge.yml)
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
WF="${WF:-$REPO/.github/workflows/fund-practice-relayer.yml}"
GJ="${GJ:-$REPO/.github/workflows/governance-judge.yml}"
PKG="$REPO/scripts/ops/relayer-funding"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); echo "ok   - $1"; }
nok()  { FAIL=$((FAIL + 1)); echo "FAIL - $1"; }

# --- Static checks -------------------------------------------------------------------------
python3 - "$WF" > "$WORK/static.txt" <<'PY'
import re, sys, yaml
wf = yaml.safe_load(open(sys.argv[1]))
on = wf.get(True, wf.get('on'))
out = []
def check(name, cond): out.append(('ok' if cond else 'FAIL') + '\t' + name)
check('manual dispatch is the only trigger', isinstance(on, dict) and list(on.keys()) == ['workflow_dispatch'])
inputs = on.get('workflow_dispatch', {}).get('inputs', {}) if isinstance(on, dict) else {}
check('the one input is provision_id (no amount, no address, no origin)', list(inputs.keys()) == ['provision_id'])
check('permissions are empty', wf.get('permissions') == {})
cc = wf.get('concurrency', {})
check('the practice wallet\'s concurrency group (shared with governance-judge.yml), never cancelled', cc.get('group') == 'sepolia-test-wallet' and cc.get('cancel-in-progress') is False)
check('no workflow-level env', 'env' not in wf)
jobs = wf.get('jobs', {})
check('one job', len(jobs) == 1)
job = next(iter(jobs.values())) if jobs else {}
check('main only', job.get('if') == "github.ref == 'refs/heads/main'")
check('a time limit', isinstance(job.get('timeout-minutes'), int) and job['timeout-minutes'] <= 30)
check('no job-level env', 'env' not in job)
steps = job.get('steps', [])
raw = open(sys.argv[1]).read()
check('the practice wallet key secret is named once in the file (outside comments)', len(re.findall(r'secrets\.SPONSOR_RELAYER_PRIVATE_KEY_CI', '\n'.join(l for l in raw.splitlines() if not l.lstrip().startswith('#')))) == 1)
for s in steps:
    name = s.get('name', '?')
    if 'run' in s:
        check(f'no ${{{{ }}}} in the run: of "{name}"', '${{' not in s['run'])
    if 'uses' in s:
        check(f'"{name}" pinned by a full SHA', re.fullmatch(r'[\w.-]+/[\w.-]+@[0-9a-f]{40}', s['uses']) is not None)
    envs = ' '.join(str(v) for v in (s.get('env') or {}).values())
    if 'secrets.' in envs:
        check(f'secrets only in the funding step ("{name}")', name == 'Fund the relayer')
fund = [s for s in steps if s.get('name') == 'Fund the relayer']
check('a funding step', len(fund) == 1)
if fund:
    env = fund[0].get('env', {})
    check('the key reaches the funding step as FUNDER_KEY', env.get('FUNDER_KEY') == '${{ secrets.SPONSOR_RELAYER_PRIVATE_KEY_CI }}')
    check('the provision id comes from the input', env.get('PROVISION_ID') == '${{ inputs.provision_id }}')
    check('the funding step runs fund.mjs', 'node scripts/ops/relayer-funding/fund.mjs' in fund[0]['run'])
inst = [s for s in steps if 'npm ci' in str(s.get('run', ''))]
check('install from the lockfile with no install scripts', len(inst) == 1 and '--ignore-scripts' in inst[0]['run'] and '--prefix scripts/ops/relayer-funding' in inst[0]['run'])
chk = [s for s in steps if s.get('name') == 'Check the input']
check('the input is checked first, as a whole string', bool(steps) and steps[0].get('name') == 'Check the input' and '^[A-Za-z0-9_-]{22}$' in steps[0].get('run', ''))
for line in out: print(line)
PY
while IFS=$'\t' read -r verdict name; do
  if [ "$verdict" = ok ]; then ok "$name"; else nok "$name"; fi
done < "$WORK/static.txt"
[ -s "$WORK/static.txt" ] || nok "the workflow parsed"

# --- The funding step's run text, with a stub node -------------------------------------------
python3 - "$WF" > "$WORK/fund-step.sh" <<'PY'
import sys, yaml
wf = yaml.safe_load(open(sys.argv[1]))
for s in next(iter(wf['jobs'].values()))['steps']:
    if s.get('name') == 'Fund the relayer':
        print(s['run'])
PY
mkdir -p "$WORK/bin"
cat > "$WORK/bin/node" <<'EOF'
#!/usr/bin/env bash
echo "FUNDING signed 0x$(printf 'a%.0s' $(seq 1 64))"
echo "a stray line with SENTINEL-SECRET-TEXT"
echo "FUNDING_STOP ${STUB_CODE:-not_mined_yet}: words"
exit "${STUB_EXIT:-1}"
EOF
chmod +x "$WORK/bin/node"
run_step() {
  : > "$WORK/summary"
  ( cd "$WORK" && PATH="$WORK/bin:$PATH" RUNNER_TEMP="$WORK" GITHUB_STEP_SUMMARY="$WORK/summary" \
    PROVISION_ID=AAAAAAAAAAAAAAAAAAAAAA CALLBACK_SECRET=s FUNDER_KEY=k RPC_URL=https://x \
    STUB_EXIT="$1" bash --noprofile --norc -eo pipefail "$WORK/fund-step.sh" > "$WORK/out" 2>&1 )
}
run_step 1; st=$?
if [ "$st" -eq 1 ]; then ok "the step fails when the script stops"; else nok "the step fails when the script stops (got $st)"; fi
if grep -q 'FUNDING_STOP not_mined_yet' "$WORK/summary" && grep -q '^FUNDING signed 0x' "$WORK/summary"; then ok "the summary holds the FUNDING lines"; else nok "the summary holds the FUNDING lines"; fi
if grep -q SENTINEL "$WORK/summary"; then nok "the summary holds no other line"; else ok "the summary holds no other line"; fi
run_step 0; st=$?
if [ "$st" -eq 0 ]; then ok "the step passes when the script passes"; else nok "the step passes when the script passes (got $st)"; fi
( cd "$WORK" && PATH="$WORK/bin:$PATH" RUNNER_TEMP="$WORK" GITHUB_STEP_SUMMARY="$WORK/summary" PROVISION_ID=x CALLBACK_SECRET=s FUNDER_KEY= RPC_URL=https://x \
  bash --noprofile --norc -eo pipefail "$WORK/fund-step.sh" > "$WORK/out" 2>&1 ); st=$?
if [ "$st" -ne 0 ] && grep -q 'SPONSOR_RELAYER_PRIVATE_KEY_CI is not set' "$WORK/out"; then ok "an unset key secret stops before the script"; else nok "an unset key secret stops before the script"; fi

# --- governance-judge.yml: the relayer it builds while holding the wallet key ---------------
python3 - "$GJ" "$WORK/gj-main.sh" > "$WORK/gj.txt" 2> "$WORK/gj-check.sh" <<'PY'
import sys, yaml
wf = yaml.safe_load(open(sys.argv[1]))
on = wf.get(True, wf.get('on'))
inputs = on.get('workflow_dispatch', {}).get('inputs', {}) if isinstance(on, dict) else {}
out = []
def check(name, cond): out.append(('ok' if cond else 'FAIL') + '\t' + name)
check('governance-judge: the one input is relayer_sha (no repository input)', list(inputs.keys()) == ['relayer_sha'])
steps = next(iter(wf.get('jobs', {}).values()), {}).get('steps', [])
co = [i for i, s in enumerate(steps) if str(s.get('uses', '')).startswith('actions/checkout@') and 'repository' in (s.get('with') or {})]
check('governance-judge: one checkout of another repository, fixed to LocalNodes/gas-sponsor-relayer at relayer_sha',
      len(co) == 1 and steps[co[0]]['with']['repository'] == 'LocalNodes/gas-sponsor-relayer' and steps[co[0]]['with'].get('ref') == '${{ inputs.relayer_sha }}')
chk = [i for i, s in enumerate(steps) if s.get('name') == 'Check the relayer input']
check('governance-judge: the input is checked first, before any checkout', chk == [0] and steps[0].get('env', {}).get('RELAYER_SHA') == '${{ inputs.relayer_sha }}')
main = [i for i, s in enumerate(steps) if s.get('name') == 'Check the relayer commit is on the relayer\'s main branch']
builds = [i for i, s in enumerate(steps) if 'docker compose' in str(s.get('run', '')) or 'relayer-src' in str(s.get('run', '')) and i not in main]
mrun = steps[main[0]].get('run', '') if main else ''
check('governance-judge: the relayer checkout has full history', len(co) == 1 and steps[co[0]]['with'].get('fetch-depth') == 0)
check('governance-judge: the main-history check comes right after the relayer checkout, before anything builds or runs it',
      len(main) == 1 and len(co) == 1 and main[0] == co[0] + 1 and all(i > main[0] for i in builds))
check('governance-judge: the main-history check holds no secret (its only env is the SHA)',
      len(main) == 1 and steps[main[0]].get('env') == {'RELAYER_SHA': '${{ inputs.relayer_sha }}'} and 'secrets.' not in mrun and '${{' not in mrun)
check('governance-judge: the check fetches main from the relayer repository and requires the SHA be an ancestor of it',
      'git fetch --no-tags origin +refs/heads/main:refs/remotes/origin/main' in mrun and 'git merge-base --is-ancestor "$RELAYER_SHA" origin/main' in mrun)
for line in out: print(line)
if chk: print(steps[chk[0]]['run'], file=sys.stderr)
open(sys.argv[2], 'w').write(mrun)
PY
while IFS=$'\t' read -r verdict name; do
  if [ "$verdict" = ok ]; then ok "$name"; else nok "$name"; fi
done < "$WORK/gj.txt"
[ -s "$WORK/gj.txt" ] || nok "governance-judge.yml parsed"
# The check step's own run text, run with each value.
gj_check() { RELAYER_SHA="$1" bash --noprofile --norc -eo pipefail "$WORK/gj-check.sh" > /dev/null 2>&1; }
H40="0123456789abcdef0123456789abcdef01234567"
if [ -s "$WORK/gj-check.sh" ] && gj_check "$H40"; then ok "governance-judge: a 40-character lowercase SHA passes the check"; else nok "governance-judge: a 40-character lowercase SHA passes the check"; fi
refused=1
for bad in "${H40:0:39}" "${H40}0" "${H40^^}" main "" "${H40:0:39}g" "../${H40:0:37}" "${H40} " "$H40
x"; do
  if gj_check "$bad"; then refused=0; echo "  accepted: [$bad]"; fi
done
if [ -s "$WORK/gj-check.sh" ] && [ "$refused" = 1 ]; then ok "governance-judge: anything else is refused (39 or 41 characters, upper case, a branch, empty, a non-hex, a path, trailing text)"; else nok "governance-judge: anything else is refused"; fi

# The main-history check's own run text, against a local relayer repository: a commit on main
# passes; a commit only on another ref (a fork's, served under the same name) is refused, as is
# a checkout that is not at the named SHA.
gj_main() { (cd "$(dirname "$1")" && RELAYER_SHA="$2" bash --noprofile --norc -eo pipefail "$WORK/gj-main.sh" > /dev/null 2>&1); }
up="$WORK/relayer-up"; src="$WORK/ws/.relayer-src"
git init -q -b main "$up" && git -C "$up" -c user.name=t -c user.email=t@t commit -q --allow-empty -m one
on_main=$(git -C "$up" rev-parse HEAD)
git -C "$up" switch -q -c fork && git -C "$up" -c user.name=t -c user.email=t@t commit -q --allow-empty -m fork
off_main=$(git -C "$up" rev-parse HEAD)
git -C "$up" switch -q main && git -C "$up" -c user.name=t -c user.email=t@t commit -q --allow-empty -m two
git clone -q "$up" "$src" && git -C "$src" fetch -q origin "$off_main"
git -C "$src" checkout -q --detach "$on_main"
if [ -s "$WORK/gj-main.sh" ] && gj_main "$src" "$on_main"; then ok "governance-judge: a commit in main's history passes"; else nok "governance-judge: a commit in main's history passes"; fi
git -C "$src" checkout -q --detach "$off_main"
if [ -s "$WORK/gj-main.sh" ] && ! gj_main "$src" "$off_main"; then ok "governance-judge: a commit only outside main (a fork's) is refused"; else nok "governance-judge: a commit only outside main (a fork's) is refused"; fi
if [ -s "$WORK/gj-main.sh" ] && ! gj_main "$src" "$on_main"; then ok "governance-judge: a checkout not at the named SHA is refused"; else nok "governance-judge: a checkout not at the named SHA is refused"; fi

# --- The script itself --------------------------------------------------------------------
if grep -q "^export const SIGNUP_ORIGIN = 'https://localnodes.xyz'$" "$PKG/fund.mjs"; then ok "fund.mjs pins the sign-up origin"; else nok "fund.mjs pins the sign-up origin"; fi
if grep -nE 'console\.(log|error|warn)\([^)]*(err|error)\.(message|stack)' "$PKG/fund.mjs" >/dev/null; then nok "fund.mjs never prints an error's own text"; else ok "fund.mjs never prints an error's own text"; fi
if grep -q '"viem": "2.57.10"' "$PKG/package.json" && grep -q '"node_modules/viem"' "$PKG/package-lock.json"; then ok "viem pinned with a lockfile"; else nok "viem pinned with a lockfile"; fi
if [ "${SKIP_NODE_TESTS:-}" = 1 ]; then
  echo "# node --test skipped (SKIP_NODE_TESTS=1)"
elif [ -d "$PKG/node_modules/viem" ]; then
  if (cd "$PKG" && node --test > "$WORK/node-test.txt" 2>&1); then ok "fund.mjs tests ($(grep -E '^ℹ pass' "$WORK/node-test.txt"))"; else nok "fund.mjs tests"; tail -40 "$WORK/node-test.txt"; fi
else
  nok "fund.mjs tests: run npm ci --prefix scripts/ops/relayer-funding --ignore-scripts first"
fi

echo "# $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
