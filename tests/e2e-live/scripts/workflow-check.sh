#!/usr/bin/env bash
# Local checks for the nightly live-Sepolia workflow (21-46, T-21-122, T-21-123).
#
# Usage: bash tests/e2e-live/scripts/workflow-check.sh [--base <compose file>]... [--no-network]
#   --base     a base compose file to check the CI override against (repeatable). The default is
#              the checkout's docker-compose.yaml. Pass GitHub main's copy too: the PR is built
#              against it, and it has no gas-sponsor-relayer service.
#   --no-network  skip the action-age check (it reads commit dates with `gh api`, read-only).
#
# Checks:
#   1. Workflow structure: triggers are schedule and workflow_dispatch only; permissions are
#      contents: read; a timeout of at most 45 minutes; main only; no secret at job level or
#      inline in a run script; every action pinned to a 40-character SHA; no artifact upload
#      (no upload-artifact, and the image build's record upload is off); the seed secret named
#      only in the wallet cache-build step's env (no other step mentions it, under any name);
#      the password secret only in the cache-build and test steps; only the secrets and the
#      variable the ruling names. Both this workflow and governance-smoke.yml, which pays from
#      the same wallet, use the workflow-level concurrency group sepolia-test-wallet with
#      cancel-in-progress false, so the two never send from the wallet at the same time.
#   2. The stack is started by naming its services, so the compose file's other services (hub
#      main's gas-sponsor-relayer, whose image isn't published) are never pulled or started.
#   1b. Mutation cases: each planted break, on a scratch copy of a workflow, must fail the
#      structure check with its own message (an anchor that doesn't match exactly once is an
#      error, never a catch). The script prints "caught N of M".
#   3. For each base compose file: the CI override merges, the merged opensocial service has
#      the CI values, and a dry run with the workflow's service list creates only opensocial,
#      mariadb, solr and qdrant.
#   4. seed-live.php has no PHP syntax error (php8.3 -l in a local image).
#   5. Every pinned action commit is at least 14 days old and listed in the README table.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../../.." && pwd)"
W="$ROOT/.github/workflows/nightly-wallet-e2e.yml"
G="$ROOT/.github/workflows/governance-smoke.yml"
CI="$ROOT/tests/e2e-live/compose.ci.yaml"
SEED="$ROOT/tests/e2e-live/seed-live.php"
README="$ROOT/tests/e2e-live/README.md"
fail() { echo "workflow-check: FAIL: $*" >&2; exit 1; }

BASES=()
NETWORK=yes
while [ $# -gt 0 ]; do
  case "$1" in
    --base) BASES+=("$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"); shift 2 ;;
    --no-network) NETWORK=no; shift ;;
    *) fail "unknown argument $1" ;;
  esac
done
[ ${#BASES[@]} -gt 0 ] || BASES=("$ROOT/docker-compose.yaml")

for f in "$W" "$G" "$CI" "$SEED" "$README"; do [ -f "$f" ] || fail "missing ${f#"$ROOT"/}"; done

# 1 and 2. Structure (python3 with PyYAML). Arguments: the nightly workflow, governance-smoke.yml.
STRUCT=$(cat <<'PY'
import os, re, sys, yaml
w, g = sys.argv[1], sys.argv[2]
text = open(w).read()
y = yaml.safe_load(text)
def need(cond, msg):
    if not cond:
        print("structure:", msg, file=sys.stderr)
        sys.exit(1)
on = y.get("on", y.get(True))
need(isinstance(on, dict) and sorted(on) == ["schedule", "workflow_dispatch"], "triggers must be schedule and workflow_dispatch only")
need("pull_request" not in text, "the word pull_request is in the workflow")
need(y.get("permissions") == {"contents": "read"}, "permissions must be contents: read")
need("secrets." not in str(y.get("env", "")), "secrets at workflow level")
need(len(y["jobs"]) == 1, "one job")
j = list(y["jobs"].values())[0]
need(int(j.get("timeout-minutes", 0)) in range(1, 46), "timeout-minutes must be 1 to 45")
need("refs/heads/main" in str(j.get("if", "")), "the job must run on main only")
need("secrets." not in str(j.get("env", "")), "secrets at job level")
SHARED = {"group": "sepolia-test-wallet", "cancel-in-progress": False}
for path, doc in ((w, y), (g, yaml.safe_load(open(g)))):
    name = os.path.basename(path)
    need(doc.get("concurrency") == SHARED, "%s: concurrency must be exactly group sepolia-test-wallet with cancel-in-progress false, at workflow level" % name)
    need(not any("concurrency" in jj for jj in doc["jobs"].values()), "%s: no job-level concurrency (the shared group is at workflow level)" % name)
st = j["steps"]
uses = [s["uses"] for s in st if "uses" in s]
need(uses and all(re.search(r"@[0-9a-f]{40}$", u) for u in uses), "every action pinned to a full SHA")
need(not any("upload-artifact" in u for u in uses), "no upload-artifact")
need(not any("secrets." in str(s.get("run", "")) or "vars." in str(s.get("run", "")) for s in st), "a secret or variable inline in a run script")
cache = [i for i, s in enumerate(st) if "npm --prefix tests/e2e-live run cache" in str(s.get("run", ""))]
e2e = [i for i, s in enumerate(st) if "npm --prefix tests/e2e-live run e2e" in str(s.get("run", ""))]
need(len(cache) == 1 and len(e2e) == 1, "one wallet cache-build step and one test step")
seed = [i for i, s in enumerate(st) if "SYNPRESS_SEED_PHRASE" in str(s)]
others = [st[i].get("name") for i in seed if i not in cache]
need(not others, "the seed secret is named in a step other than the cache-build step: %s" % others)
need(st[cache[0]].get("env", {}).get("SYNPRESS_SEED_PHRASE") == "${{ secrets.SYNPRESS_SEED_PHRASE }}", "the cache-build step's env must read SYNPRESS_SEED_PHRASE from secrets.SYNPRESS_SEED_PHRASE")
need(text.count("secrets.SYNPRESS_SEED_PHRASE") == 1, "secrets.SYNPRESS_SEED_PHRASE must appear exactly once in the workflow")
pw = [i for i, s in enumerate(st) if "SYNPRESS_WALLET_PASSWORD" in str(s)]
need(pw == sorted(cache + e2e), "the password secret must be named only in the cache-build and test steps")
need(sum("secrets.SEPOLIA_RPC_URL" in str(s.get("env", "")) for s in st) in (0, 1, 2), "the RPC secret in at most two step envs")
refs = set(re.findall(r"\b(secrets|vars)\.([A-Za-z0-9_]+)", text))
allowed = {("secrets", "SYNPRESS_SEED_PHRASE"), ("secrets", "SYNPRESS_WALLET_PASSWORD"), ("secrets", "SEPOLIA_RPC_URL"), ("vars", "SYNPRESS_WALLET_ADDRESS")}
need(refs <= allowed, "secrets or variables beyond the ruling: %s" % sorted(refs - allowed))
for s in st:
    if "build-push-action" in s.get("uses", ""):
        e = s.get("env", {})
        need(str(e.get("DOCKER_BUILD_RECORD_UPLOAD", "")).lower() == "false", "the image build must not upload its build record artifact")
        need(s.get("with", {}).get("push") in (False, "false"), "the image build must not push")
        need(s.get("with", {}).get("load") in (True, "true"), "the image build must load the image")
    if "actions/checkout" in s.get("uses", ""):
        need(s.get("with", {}).get("persist-credentials") in (False, "false"), "checkout without persisted credentials")
ups = [s for s in st if re.search(r"docker compose .*\bup\b", str(s.get("run", "")))]
need(len(ups) == 1, "one compose up step")
up = re.search(r"\bup\b[^\n]*", ups[0]["run"]).group(0)
need(re.search(r"\bopensocial\b", up) and "relayer" not in up, "compose up must name its services (opensocial), never a relayer")
print("workflow-check: structure OK")
PY
)
python3 -c "$STRUCT" "$W" "$G" || fail "workflow structure"

# 1b. Mutation cases on scratch copies.
MUT=$(mktemp -d)
trap 'rm -rf "$MUT"' EXIT
STRUCT="$STRUCT" python3 - "$W" "$G" "$MUT" <<'PY' || fail "mutation cases"
import os, shutil, subprocess, sys
w, g, tmp = sys.argv[1:4]
SEED_LINE = "          SYNPRESS_SEED_PHRASE: ${{ secrets.SYNPRESS_SEED_PHRASE }}\n"
# (name, file, old text, new text, the structure message it must fail with)
M = [
    ("seed in the test step", "w", "      - name: Live Sepolia wallet run\n        env:\n",
     "      - name: Live Sepolia wallet run\n        env:\n" + SEED_LINE,
     "the seed secret is named in a step other than the cache-build step"),
    ("seed under another name in the compose-up step", "w", "          SEPOLIA_RPC_URL: ${{ secrets.SEPOLIA_RPC_URL }}\n",
     "          SEPOLIA_RPC_URL: ${{ secrets.SEPOLIA_RPC_URL }}\n          MNEMONIC: ${{ secrets.SYNPRESS_SEED_PHRASE }}\n",
     "the seed secret is named in a step other than the cache-build step"),
    ("seed removed from the cache-build step", "w", SEED_LINE, "",
     "the cache-build step's env must read SYNPRESS_SEED_PHRASE"),
    ("seed at job level", "w", "    timeout-minutes: 45\n",
     "    timeout-minutes: 45\n    env:\n      S: ${{ secrets.SYNPRESS_SEED_PHRASE }}\n",
     "secrets at job level"),
    ("password removed from the test step", "w",
     "          SYNPRESS_WALLET_PASSWORD: ${{ secrets.SYNPRESS_WALLET_PASSWORD }}\n          LN_LIVE_BASE_URL",
     "          LN_LIVE_BASE_URL",
     "the password secret must be named only in the cache-build and test steps"),
    ("nightly group not shared", "w", "  group: sepolia-test-wallet\n", "  group: nightly-wallet-e2e\n",
     "nightly-wallet-e2e.yml: concurrency must be exactly group sepolia-test-wallet"),
    ("nightly cancel-in-progress true", "w", "  cancel-in-progress: false\n", "  cancel-in-progress: true\n",
     "nightly-wallet-e2e.yml: concurrency must be exactly group sepolia-test-wallet"),
    ("nightly job-level concurrency", "w", "    timeout-minutes: 45\n",
     "    timeout-minutes: 45\n    concurrency: other\n",
     "nightly-wallet-e2e.yml: no job-level concurrency"),
    ("governance group not shared", "g", "  group: sepolia-test-wallet\n", "  group: governance-smoke\n",
     "governance-smoke.yml: concurrency must be exactly group sepolia-test-wallet"),
    ("governance cancel-in-progress true", "g", "  cancel-in-progress: false\n", "  cancel-in-progress: true\n",
     "governance-smoke.yml: concurrency must be exactly group sepolia-test-wallet"),
    ("bare up -d", "w", "up -d opensocial mariadb solr qdrant", "up -d",
     "compose up must name its services"),
    ("relayer named", "w", "up -d opensocial mariadb solr qdrant", "up -d opensocial mariadb solr qdrant gas-sponsor-relayer",
     "compose up must name its services"),
    ("build record upload on", "w", "DOCKER_BUILD_RECORD_UPLOAD: 'false'", "DOCKER_BUILD_RECORD_UPLOAD: 'true'",
     "must not upload its build record"),
    ("push trigger", "w", "  workflow_dispatch:\n\npermissions", "  workflow_dispatch:\n  push:\n\npermissions",
     "triggers must be schedule and workflow_dispatch only"),
    ("extra secret", "w", "          WALLET_ADDRESS: ${{ vars.SYNPRESS_WALLET_ADDRESS }}\n",
     "          WALLET_ADDRESS: ${{ vars.SYNPRESS_WALLET_ADDRESS }}\n          EXTRA: ${{ secrets.OTHER_TOKEN }}\n",
     "secrets or variables beyond the ruling"),
    ("unpinned action", "w", "actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0", "actions/setup-node@v7",
     "every action pinned to a full SHA"),
]
src = {"w": open(w).read(), "g": open(g).read()}
caught, errors = 0, 0
for name, which, old, new, msg in M:
    n = src[which].count(old)
    if n != 1:
        errors += 1
        print("mutation: ANCHOR ERROR (%d matches): %s" % (n, name), file=sys.stderr)
        continue
    d = os.path.join(tmp, str(M.index((name, which, old, new, msg))))
    os.makedirs(d)
    pw, pg = os.path.join(d, os.path.basename(w)), os.path.join(d, os.path.basename(g))
    shutil.copy(w, pw); shutil.copy(g, pg)
    open(pw if which == "w" else pg, "w").write(src[which].replace(old, new))
    r = subprocess.run([sys.executable, "-c", os.environ["STRUCT"], pw, pg], capture_output=True, text=True)
    if r.returncode != 0 and msg in r.stderr:
        caught += 1
        print("mutation: caught: %s" % name)
    else:
        print("mutation: NOT CAUGHT: %s (exit %d, %s)" % (name, r.returncode, r.stderr.strip()), file=sys.stderr)
print("workflow-check: mutation cases caught %d of %d (anchor errors: %d)" % (caught, len(M), errors))
sys.exit(0 if caught == len(M) and errors == 0 else 1)
PY

# 3. The override against each base compose file.
UP_SERVICES=$(python3 -c '
import re, sys, yaml
y = yaml.safe_load(open(sys.argv[1]))
st = list(y["jobs"].values())[0]["steps"]
run = [s["run"] for s in st if re.search(r"docker compose .*\bup\b", str(s.get("run", "")))][0]
line = re.search(r"\bup\b([^\n]*)", run).group(1)
print(" ".join(t for t in line.split() if not t.startswith("-")))' "$W")
[ -n "$UP_SERVICES" ] || fail "no services named on the compose up line"
for base in "${BASES[@]}"; do
  [ -f "$base" ] || fail "no compose file at $base"
  SEPOLIA_RPC_URL= docker compose --project-directory "$ROOT" -f "$base" -f "$CI" config --quiet \
    || fail "the CI override doesn't merge with $base"
  merged=$(SEPOLIA_RPC_URL= docker compose --project-directory "$ROOT" -f "$base" -f "$CI" config --format json)
  jq -e '.services.opensocial
    | .image == "os-knowledge-garden:nightly"
      and .pull_policy == "never"
      and .environment.WEB3_ENABLED == "true"
      and .environment.DEMO_MODULE == "none"
      and .environment.SERVICE_FQDN_OPENSOCIAL == "localhost:8080"
      and (.environment | has("SAFE_RPC_URL_ETHEREUM_SEPOLIA"))
      and ([.ports[] | select(.host_ip == "127.0.0.1" and .published == "8080" and .target == 80)] | length == 1)
      and ([.volumes[] | select(.target == "/opt/e2e-live/seed-live.php" and .read_only == true)] | length == 1)' <<<"$merged" >/dev/null \
    || fail "the merged opensocial service lacks the CI values ($base)"
  # The workflow's own service list, as a dry run of `create` (what `up` pulls and creates):
  # only the named services and their dependencies. Not `up --dry-run`: it also simulates the
  # health-check waits, and that simulation sometimes reports "opensocial is missing dependency
  # mariadb" at random. Without the service names, this same dry run tries to pull hub main's
  # unpublished relayer image and fails with "denied".
  # shellcheck disable=SC2086
  dry=$(SEPOLIA_RPC_URL= docker compose --project-directory "$ROOT" -p ln-workflow-check -f "$base" -f "$CI" create --dry-run --no-build $UP_SERVICES 2>&1) \
    || { printf '%s\n' "$dry" >&2; fail "the dry run of compose create failed ($base)"; }
  if printf '%s\n' "$dry" | grep -qi "relayer"; then
    printf '%s\n' "$dry" >&2; fail "the dry run would touch a relayer ($base)"
  fi
  created=$(printf '%s\n' "$dry" | grep -oE "Container ln-workflow-check-[a-z-]+-1 +Creat" | sed -E 's/Container ln-workflow-check-([a-z-]+)-1.*/\1/' | sort -u | tr '\n' ' ')
  [ "$created" = "mariadb opensocial qdrant solr " ] || { printf '%s\n' "$dry" >&2; fail "the dry run would create '$created', not mariadb opensocial qdrant solr ($base)"; }
  echo "workflow-check: compose OK with ${base#"$ROOT"/} (dry run creates: $created)"
done

# 4. PHP syntax of the seed.
I="${DDEV_WEBSERVER_IMAGE:-ddev/ddev-webserver:v1.25.4-fresh3-p21-built}"
O=$(docker run --rm -v "$ROOT:/w:ro" -w /w --entrypoint "" "$I" php8.3 -l tests/e2e-live/seed-live.php 2>&1) || true
[ "${O#*No syntax errors}" != "$O" ] || fail "seed-live.php: $O"
echo "workflow-check: seed-live.php lint OK"

# 5. Action ages and the README table.
if [ "$NETWORK" = yes ]; then
  C=$(node -e 'console.log(new Date(Date.now()-14*864e5).toISOString())')
  U=$(grep -oE '[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(/[^@ ]*)?@[0-9a-f]{40}' "$W" | sort -u)
  [ -n "$U" ] || fail "no pinned actions found"
  while read -r u; do
    a=${u%@*}; s=${u##*@}; r=$(printf '%s' "$a" | cut -d/ -f1-2)
    d=$(gh api "repos/$r/commits/$s" --jq .commit.committer.date) || fail "can't read the commit date of $u"
    awk -v d="$d" -v c="$C" 'BEGIN { exit !(d < c) }' || fail "too new: $u $d (cutoff $C)"
    grep -q "$s" "$README" || fail "not in the README table: $u"
    echo "workflow-check: $r@$s committed $d (before $C)"
  done <<<"$U"
fi
echo "workflow-check: OK"
