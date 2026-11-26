#!/usr/bin/env bash
# Tests for .github/workflows/funds-on.yml.
#
# The workflow is converted to JSON (Ruby's YAML parser or, where Ruby is missing, Python with
# PyYAML). Every step's real `run:` text is then run as GitHub runs it (bash --noprofile --norc -e:
# the default shell of a step with no `shell:`, so no pipefail), in order, with:
#   - the step's own env: block resolved from the test's inputs and secrets (so the env: wiring
#     itself is under test, not a hand-made copy of it),
#   - $GITHUB_ENV carried from step to step, last write winning, as GitHub does,
#   - the step's if: evaluated (only the forms this workflow uses; any other is a failure),
#   - the fake `curl` (fake-curl.py) and a no-op `sleep` first on PATH.
# No network, no Coolify, no GitHub. This workflow has not yet been dispatched against a real host:
# everything proven here is proven against the fake.
#
# After every run a sweep looks for every form of every key and token the run held, sent or
# generated, saved or not (the key with and without 0x, the token, the Coolify token), in the run's
# output outside ::add-mask:: lines, in requests.log and in the step summary. The values to look
# for are read from the fixture, from the fake's body capture (bodies.log), from the ::add-mask::
# lines and from the stored envs, so a key whose write failed and a token that was never sent are
# covered. bodies.log is only the source of the values; every "nothing named the key" check reads
# requests.log.
#
# The shared bash functions must stay byte-identical to provision-instance.yml's.
#
# The key rule: a secret name is written only by the run that creates its row; an
# existing row is never replaced or deleted by a workflow, whatever it reads as. funds-on never creates a site, so
# it never writes or deletes the fee wallet key or token: it keeps a pair the site holds, refuses a site created
# without funds with one fixed line, and refuses a funds-on site whose key or token is not shown as held.
#
# Usage: bash scripts/ops/tests/funds-on-workflow.test.sh
#        WF=/path/to/funds-on-copy.yml PROVISION_WF=/path/to/provision-copy.yml bash scripts/ops/tests/funds-on-workflow.test.sh   (to test changed copies)
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
WF="${WF:-$REPO/.github/workflows/funds-on.yml}"  # override to test another copy
PROVISION_WF="${PROVISION_WF:-$REPO/.github/workflows/provision-instance.yml}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); echo "ok   - $1"; }
nok()  { FAIL=$((FAIL + 1)); echo "FAIL - $1"; }
check() { if eval "$2"; then ok "$1"; else nok "$1"; fi; }
finish() { echo "# $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]; exit $?; }

# Fixtures. Every secret-like value is a throwaway made here or by the run; no real key is read.
COOLIFY_LIT="coolify-throwaway-token-0001"
SUBDOMAIN="demo"
FQDN="demo.localnodes.xyz"
CAPI="http://coolify.test/api/v1"
D16="Done means the settings were saved and read back, the redeploy finished and the site answers. It does not prove the network fee account works on a site that was already handed over: open the site's Network fee account page, which shows the account's address once it runs. The sign-up app is not told this address."
HELD_KEY="0x$(printf 'ab%.0s' {1..32})"
HELD_TOKEN="$(printf 'cd%.0s' {1..32})"

# Fake binaries
mkdir -p "$WORK/bin" "$WORK/tmp"
cp "$HERE/fake-curl.py" "$WORK/bin/curl"
chmod +x "$WORK/bin/curl"
printf '#!/bin/sh\nexit 0\n' > "$WORK/bin/sleep"
chmod +x "$WORK/bin/sleep"

yaml_json() {  # yaml_json FILE -> the YAML file as compact JSON (Ruby, else Python + PyYAML); a bare `on:` key arrives as "true"
  if command -v ruby >/dev/null 2>&1; then
    ruby -ryaml -rjson -e 'puts JSON.generate(YAML.load_file(ARGV[0]))' "$1"
  else
    python3 -c 'import json, sys, yaml; print(json.dumps(yaml.safe_load(open(sys.argv[1]))))' "$1"
  fi
}

echo "# the workflow file"
if [ ! -f "$WF" ]; then
  nok "the workflow file exists ($WF)"
  finish
fi
ok "the workflow file exists"
yaml_json "$WF" > "$WORK/wf.json" 2>/dev/null || { nok "the workflow parses as YAML (needs ruby, or python3 with PyYAML)"; finish; }
ok "the workflow parses as YAML"
yaml_json "$PROVISION_WF" > "$WORK/prov.json" 2>/dev/null || { nok "provision-instance.yml parses as YAML ($PROVISION_WF)"; finish; }
PROV_RPC="$(jq -r '[.jobs.provision.steps[] | select(.name == "Configure environment variables")][0].env.PRACTICE_RPC_URL // ""' "$WORK/prov.json")"

NSTEPS="$(jq '.jobs.funds_on.steps | length' "$WORK/wf.json" 2>/dev/null || echo 0)"

step_script() {  # step_script NAME -> the step's run: text on stdout, nonzero when there is no such step
  jq -j --arg n "$1" '[.jobs.funds_on.steps[] | select(.name == $n)] | if length == 0 then (("no step " + $n + "\n") | halt_error(1)) else .[0].run end' "$WORK/wf.json"
}

# ---------------------------------------------------------------------------------------------
# The runner: every step in order, as GitHub would run them.

declare -A INPUTS SECRETS GENV STEP_RC
set_inputs() { INPUTS=([subdomain]="$1"); }
set_secrets() { SECRETS=([COOLIFY_API_TOKEN]="$1"); }
reset_inputs() { set_inputs "$SUBDOMAIN"; set_secrets "$COOLIFY_LIT"; }

RESOLVED=""
resolve() {  # resolve RAW -> RESOLVED; nonzero for an expression the test does not model
  local raw="$1"
  if [[ "$raw" =~ ^\$\{\{\ *inputs\.([a-z_]+)\ *\}\}$ ]]; then
    RESOLVED="${INPUTS[${BASH_REMATCH[1]}]-}"
  elif [[ "$raw" =~ ^\$\{\{\ *secrets\.([A-Z_]+)\ *\}\}$ ]]; then
    RESOLVED="${SECRETS[${BASH_REMATCH[1]}]-}"
  elif [[ "$raw" == *'${{'* ]]; then
    return 1
  else
    RESOLVED="$raw"
  fi
}

ENVV=()
add_env_block() {  # add_env_block JQ_PATH -> appends NAME=VALUE for every key of that env: map
  local k v
  while IFS= read -r -d '' k && IFS= read -r -d '' v; do
    if ! resolve "$v"; then
      echo "unmodelled expression in $1.$k: $v" >> "$WORK/harness-errors"
      continue
    fi
    if [ "$k" = COOLIFY_API ]; then RESOLVED="$CAPI"; fi  # the real API is never reached
    ENVV+=("$k=$RESOLVED")
  done < <(jq -j "($1 // {}) | to_entries[] | .key, \"\u0000\", (.value | tostring), \"\u0000\"" "$WORK/wf.json")
}

load_genv() {  # GITHUB_ENV as GitHub reads it: KEY=value or KEY<<DELIM ... DELIM, the last write wins
  local k v
  GENV=()
  while IFS= read -r -d '' k && IFS= read -r -d '' v; do GENV[$k]="$v"; done < <(python3 -I -c '
import sys
d, lines, i = {}, open(sys.argv[1]).read().split("\n"), 0
while i < len(lines):
    l = lines[i]
    if "<<" in l and ("=" not in l or l.index("<<") < l.index("=")):
        k, dl = l.split("<<", 1); v = []; i += 1
        while i < len(lines) and lines[i] != dl:
            v.append(lines[i]); i += 1
        d[k] = "\n".join(v)
    elif "=" in l:
        k, v = l.split("=", 1); d[k] = v
    i += 1
for k, v in d.items():
    sys.stdout.write(k + "\0" + v + "\0")
' "$WORK/state/github_env")
}

genv() { load_genv; printf '%s' "${GENV[$1]-}"; }

# Apps. app UUID HOST|null: an application whose domains are the stringified [{name, domain}] array
# the fake and the teardown fixtures use. app_raw UUID JSON: docker_compose_domains is that JSON value.
app() {
  if [ "$2" = null ]; then app_raw "$1" null; else app_url "$1" "https://$2"; fi
}
app_url() {  # app_url UUID URL: one service whose domain is exactly that URL (the stringified array the fake and the teardown fixtures use)
  jq -cn --arg u "$1" --arg d "$2" '{uuid: $u, name: $u, description: null, docker_compose_domains: ([{name: "opensocial", domain: $d}] | tojson)}'
}
app_raw() { jq -cn --arg u "$1" --argjson d "$2" '{uuid: $u, name: $u, description: null, docker_compose_domains: $d}'; }  # domains is that JSON value
app_str() { jq -cn --arg u "$1" --arg d "$2" '{uuid: $u, name: $u, description: null, docker_compose_domains: $d}'; }  # domains is that string as is
set_apps() { printf '%s\n' "$@" | jq -s . > "$WORK/state/apps.json"; }
TARGET="$(app site123 "$FQDN")"
NOISE1="$(app noise1 elsewhere.localnodes.xyz)"
NOISE2="$(app noise2 null)"

HELD_VALUES=()
reset_state() {  # an app provisioned with funds on (practice): mode true, the held (throwaway) key and token, FEE_WALLET_SCALE 1
  rm -rf "$WORK/state" "$WORK"/PWNED*; mkdir -p "$WORK/state"
  cat > "$WORK/state/envs.json" <<'JSON'
[
 {"uuid":"e1","key":"DEMO_MODULE","value":"localnodes_demo","is_preview":false},
 {"uuid":"e2","key":"DRUPAL_SITE_NAME","value":"LocalNodes","is_preview":false},
 {"uuid":"e3","key":"DRUPAL_ADMIN_PASS","value":"fixture-admin-pass","is_preview":false},
 {"uuid":"e4","key":"IMAGE_TAG","value":"9b8e2a1","is_preview":false},
 {"uuid":"e5","key":"DB_PASSWORD","value":"fixture-db-pass","is_preview":false},
 {"uuid":"e6","key":"WEB3_ENABLED","value":"true","is_preview":false},
 {"uuid":"e7","key":"SPONSOR_RELAYER_PRIVATE_KEY","value":"HELD-KEY","is_preview":false},
 {"uuid":"e8","key":"SPONSOR_RELAYER_TOKEN","value":"HELD-TOKEN","is_preview":false},
 {"uuid":"e9","key":"FEE_WALLET_SCALE","value":"1","is_preview":false}
]
JSON
  envs_edit --arg k "$HELD_KEY" --arg t "$HELD_TOKEN" 'map(if .value == "HELD-KEY" then .value = $k elif .value == "HELD-TOKEN" then .value = $t else . end)'
  : > "$WORK/state/requests.log"; : > "$WORK/state/auth.log"; : > "$WORK/state/bodies.log"
  : > "$WORK/state/github_env"; : > "$WORK/state/summary.md"
  set_apps "$TARGET" "$NOISE1" "$NOISE2"
  HELD_VALUES=("$HELD_KEY" "$HELD_TOKEN")
}
seed_held() { :; }  # the default app already holds the key and token (kept for the setups that name it)
funds_off() {  # funds_off -> the app as a funds-off provisioning left it: mode false, empty key and token rows, FEE_WALLET_SCALE 0
  envs_edit 'map(if .key == "WEB3_ENABLED" then .value = "false" elif .key == "SPONSOR_RELAYER_PRIVATE_KEY" or .key == "SPONSOR_RELAYER_TOKEN" then .value = "" elif .key == "FEE_WALLET_SCALE" then .value = "0" else . end)'
  HELD_VALUES=()
}
envs_edit() { jq "$@" "$WORK/state/envs.json" > "$WORK/state/envs.new" && mv "$WORK/state/envs.new" "$WORK/state/envs.json"; }  # envs_edit [jq options] FILTER
stored() {  # stored KEY -> the production value in the fake store
  K="$1" jq -r '[.[] | select(.key == env.K and (.is_preview | not))][0].value // ""' "$WORK/state/envs.json"
}
reqs() { cat "$WORK/state/requests.log"; }
clear_logs() { for f in requests.log auth.log bodies.log github_env summary.md; do : > "$WORK/state/$f"; done; }
# app_deleted: requests.log has an application DELETE. Its lines are "METHOD URL KEY", so the app id ends at a space,
# or at a query string; a DELETE of an env var (/applications/<id>/envs/<uuid>) is not one.
app_deleted() { grep -qE '^DELETE [^ ]*/applications/[^/ ?]+([?][^ ]*)?( |$)' "$WORK/state/requests.log"; }

RUN_RC=0
OUT=""
CUR=""
SCENARIOS_RUN=0
run_all() {  # run_all LABEL SCENARIO -> RUN_RC (the run's exit), OUT (all output), STEP_RC[name]
  CUR="$1"
  local scenario="$2" i name cond run_it failed=0 rc out k
  OUT=""; RUN_RC=0; STEP_RC=()
  for ((i = 0; i < NSTEPS; i++)); do
    name="$(jq -r ".jobs.funds_on.steps[$i].name" "$WORK/wf.json")"
    cond="$(jq -r ".jobs.funds_on.steps[$i].if // \"\"" "$WORK/wf.json")"
    load_genv
    case "$cond" in
      "") run_it=$((failed == 0)) ;;
      "always()") run_it=1 ;;
      *) echo "unmodelled if: on step $name: $cond" >> "$WORK/harness-errors"; run_it=$((failed == 0)) ;;
    esac
    if [ "$run_it" -ne 1 ]; then STEP_RC[$name]=skip; continue; fi
    jq -j ".jobs.funds_on.steps[$i].run // \"\"" "$WORK/wf.json" > "$WORK/step.sh"
    ENVV=(PATH="$WORK/bin:/usr/bin:/bin" HOME="$WORK" TMPDIR="$WORK/tmp"
      FAKE_STATE="$WORK/state" FAKE_SCENARIO="$scenario"
      GITHUB_ENV="$WORK/state/github_env" GITHUB_STEP_SUMMARY="$WORK/state/summary.md")
    add_env_block '.env'
    add_env_block '.jobs.funds_on.env'
    for k in "${!GENV[@]}"; do ENVV+=("$k=${GENV[$k]}"); done
    add_env_block ".jobs.funds_on.steps[$i].env"
    out="$(cd "$WORK" && env -i "${ENVV[@]}" bash --noprofile --norc -e "$WORK/step.sh" 2>&1)"
    rc=$?
    STEP_RC[$name]=$rc
    OUT+="## $name (rc=$rc)"$'\n'"$out"$'\n'
    if [ "$rc" -ne 0 ]; then
      failed=1
      [ "$RUN_RC" -eq 0 ] && RUN_RC=$rc
    fi
  done
  sweep
  SCENARIOS_RUN=$((SCENARIOS_RUN + 1))
}

# ---------------------------------------------------------------------------------------------
# The leak sweep. After every run: every form of every value the run held, in three
# places. A breach is recorded with the run's label and reported by three separate named checks at
# the end of this file, one per place, so each kind of leak turns its own line red.
#
# What to look for ($WORK/patterns, one fixed string per line):
#   - the held values the run's fixture seeded;
#   - every `value` the fake received for the relayer key or token (bodies.log, each line read as
#     JSON, other lines skipped): a key whose write failed and was never saved is in here;
#   - every value on a line of the run's output that starts with ::add-mask:: : a token that was
#     generated but never sent is in here;
#   - the key and token stored at the end of the run, and the Coolify token literal.
# For a value starting with 0x the same value without 0x is looked for too (the 64 hex characters
# are the whole usable key). Empty values are dropped (an empty pattern matches every line).
collect_patterns() {
  local f="$WORK/state" raw="$WORK/pat.raw"
  : > "$raw"
  printf '%s\n' ${HELD_VALUES[@]+"${HELD_VALUES[@]}"} >> "$raw"
  jq -Rr 'fromjson? | select(type == "object") | select(.key == "SPONSOR_RELAYER_PRIVATE_KEY" or .key == "SPONSOR_RELAYER_TOKEN") | (.value // empty) | strings' \
    "$f/bodies.log" >> "$raw" 2>/dev/null
  printf '%s\n' "$OUT" | sed -n 's/^::add-mask:://p' >> "$raw"
  jq -r '.[] | select(.key == "SPONSOR_RELAYER_PRIVATE_KEY" or .key == "SPONSOR_RELAYER_TOKEN") | (.value // empty) | strings' \
    "$f/envs.json" >> "$raw" 2>/dev/null
  printf '%s\n' "$COOLIFY_LIT" >> "$raw"
  awk 'NF { print; if (substr($0, 1, 2) == "0x" && length($0) > 2) print substr($0, 3) }' "$raw" | sort -u > "$WORK/patterns"
}

sweep() {
  local f="$WORK/state" h
  collect_patterns
  printf '%s\n' "$OUT" | grep -v '^::add-mask::' > "$WORK/shown.txt"
  grep -qF -f "$WORK/patterns" -- "$WORK/shown.txt" && echo "$CUR" >> "$WORK/v-out"
  grep -qF -f "$WORK/patterns" -- "$f/requests.log" && echo "$CUR" >> "$WORK/v-reqs"
  grep -qF -f "$WORK/patterns" -- "$f/summary.md" && echo "$CUR" >> "$WORK/v-sum"
  grep -q 'SHOULD-NOT-LEAK' <<<"$OUT" && echo "$CUR" >> "$WORK/v-body"
  for h in $(awk 'NF >= 2 { print $2 }' "$f/requests.log" | sed -E 's|^[a-z]+://([^/:?]+).*|\1|' | sort -u); do
    case "$h" in coolify.test|"$FQDN") ;; *) echo "$CUR: $h" >> "$WORK/v-host" ;; esac
  done
  grep '^DELETE ' "$f/requests.log" | grep -v '/envs/' | sed "s|^|$CUR: |" >> "$WORK/v-delete"
  ls "$WORK"/PWNED* >/dev/null 2>&1 && echo "$CUR" >> "$WORK/v-inject"
  [ -s "$WORK/harness-errors" ] && { sed "s|^|$CUR: |" "$WORK/harness-errors" >> "$WORK/v-harness"; : > "$WORK/harness-errors"; }
  return 0
}

# ---------------------------------------------------------------------------------------------
echo "# workflow shape"
wf_q() { jq -r "$@" "$WORK/wf.json"; }
check "on.workflow_dispatch has exactly the input subdomain, required, of type string" \
  '[ "$(wf_q "(.[\"true\"] // .on).workflow_dispatch.inputs | [keys, ([.[] | (.required == true and .type == \"string\")] | all)] | tojson")" = "[[\"subdomain\"],true]" ]'
check "permissions is an empty map at the top level, and no job widens it" \
  '[ "$(wf_q ".permissions | tojson")" = "{}" ] && [ "$(wf_q "[.jobs[] | has(\"permissions\")] | any")" = false ]'
check "one job, named funds_on, with a timeout" \
  '[ "$(wf_q ".jobs | keys | join(\" \")")" = funds_on ] && [ "$(wf_q ".jobs.funds_on[\"timeout-minutes\"] | type")" = number ]'
check "the Coolify token comes only from secrets.COOLIFY_API_TOKEN (job env)" \
  '[ "$(wf_q ".jobs.funds_on.env.COOLIFY_TOKEN")" = "\${{ secrets.COOLIFY_API_TOKEN }}" ]'
check "concurrency group is built from inputs.subdomain, cancel-in-progress false" \
  '[ "$(wf_q ".concurrency.group")" = "funds-on-\${{ inputs.subdomain }}" ] && [ "$(wf_q ".concurrency[\"cancel-in-progress\"]")" = false ]'
EXPR_RUNS="$(jq -r '.jobs[].steps[] | select((.run // "") | contains("${{")) | .name' "$WORK/wf.json")"
check "no \${{ }} expression inside any run: block" '[ -z "$EXPR_RUNS" ]'
check "no step uses a third-party action (plain curl, jq and openssl only)" \
  '[ "$(wf_q "[.jobs[].steps[] | has(\"uses\")] | any")" = false ]'

# ---------------------------------------------------------------------------------------------
envs_snapshot() { jq -cS . "$WORK/state/envs.json"; }
row_set() { envs_edit --arg k "$1" --argjson v "$2" 'map(if .key == $k and (.is_preview | not) then .value = $v else . end)'; }  # row_set KEY JSONVALUE (production rows)
deleted_row() { grep -qE "^DELETE [^ ]*/envs/$1 " "$WORK/state/requests.log"; }  # deleted_row UUID
nothing_written() {  # no PATCH, POST or DELETE, no redeploy, and the stored envs are as they were
  ! grep -qE '^(PATCH|POST|DELETE) ' "$WORK/state/requests.log" && ! grep -q '/deploy?' "$WORK/state/requests.log" && [ "$(envs_snapshot)" = "$BEFORE" ]
}
echo "# scenario ok: a site provisioned with funds on; one dispatch writes the practice settings again and keeps the key and token"
reset_state; reset_inputs
SECRETS_BEFORE="$(jq -cS '[.[] | select(.key == "SPONSOR_RELAYER_PRIVATE_KEY" or .key == "SPONSOR_RELAYER_TOKEN")]' "$WORK/state/envs.json")"
secret_rows() { jq -cS '[.[] | select(.key == "SPONSOR_RELAYER_PRIVATE_KEY" or .key == "SPONSOR_RELAYER_TOKEN")]' "$WORK/state/envs.json"; }
no_secret_request() { ! grep -qE "^(PATCH|POST) [^ ]*/envs (DRUPAL_ADMIN_PASS|DB_PASSWORD|DB_ROOT_PASSWORD|DRUPAL_HASH_SALT|SPONSOR_RELAYER_PRIVATE_KEY|SPONSOR_RELAYER_TOKEN)\$" "$WORK/state/requests.log"; }
no_secret_sent() { ! jq -Rr 'fromjson? | .key? // empty' "$WORK/state/bodies.log" | grep -qxE "DRUPAL_ADMIN_PASS|DB_PASSWORD|DB_ROOT_PASSWORD|DRUPAL_HASH_SALT|SPONSOR_RELAYER_PRIVATE_KEY|SPONSOR_RELAYER_TOKEN"; }
run_all ok ok
check "ok: the run exits 0" '[ "$RUN_RC" -eq 0 ]'
check "ok: WEB3_ENABLED, SIWE_CHAIN_ID, WAAP_ENVIRONMENT and FEE_WALLET_SCALE are stored as practice needs them" \
  '[ "$(stored WEB3_ENABLED)" = true ] && [ "$(stored SIWE_CHAIN_ID)" = 11155111 ] && [ "$(stored WAAP_ENVIRONMENT)" = production ] && [ "$(stored FEE_WALLET_SCALE)" = 1 ]'
check "ok: SIWE_CHAIN_RPC_URL is provision-instance.yml's practice RPC (and not empty)" \
  '[ -n "$PROV_RPC" ] && [ "$(stored SIWE_CHAIN_RPC_URL)" = "$PROV_RPC" ]'
check "ok: the key and token rows are exactly as the site held them (uuid and value)" '[ "$(secret_rows)" = "$SECRETS_BEFORE" ]'
check "ok: no PATCH, POST or body names any of the six secret names, and no row of the key or token is deleted" \
  'no_secret_request && no_secret_sent && ! deleted_row e7 && ! deleted_row e8'
check "ok: nothing is masked (no key or token is made or read into the run)" '! grep -q "^::add-mask::" <<<"$OUT"'
check "ok: every env request names applications/site123/envs, and there are some" \
  '[ "$(grep -c "/envs" "$WORK/state/requests.log")" -gt 0 ] && ! grep "/envs" "$WORK/state/requests.log" | grep -qv "^[A-Z]* $CAPI/applications/site123/envs"'
check "ok: exactly one redeploy request, with force=true" \
  '[ "$(grep -cxF "POST $CAPI/deploy?uuid=site123&force=true -" "$WORK/state/requests.log")" -eq 1 ] && [ "$(grep -c "/deploy?" "$WORK/state/requests.log")" -eq 1 ]'
line_of() { grep -nxF -- "$1" "$WORK/state/requests.log" | head -1 | cut -d: -f1; }
after_redeploy() {  # the deployment is read after the redeploy, the login page after the deployment
  local d p l
  d="$(grep -nF "/deploy?" "$WORK/state/requests.log" | head -1 | cut -d: -f1)"
  p="$(line_of "GET $CAPI/deployments/dep123 -")"; l="$(line_of "GET https://$FQDN/user/login -")"
  [ -n "$d" ] && [ -n "$p" ] && [ -n "$l" ] && [ "$d" -lt "$p" ] && [ "$p" -lt "$l" ]
}
check "ok: the deployment is read, then the site's login page, both after the redeploy" 'after_redeploy'
check "ok: the sweep is not vacuous: it looks for the held key, its bare form and the held token" \
  'grep -qxF -- "$HELD_KEY" "$WORK/patterns" && grep -qxF -- "${HELD_KEY#0x}" "$WORK/patterns" && grep -qxF -- "$HELD_TOKEN" "$WORK/patterns"'
check "ok: the step summary has the app, the group funds line and the done sentence verbatim" \
  'grep -qF "$FQDN" "$WORK/state/summary.md" && grep -qF site123 "$WORK/state/summary.md" && grep -qF "Group funds: on, practice (test-network money only)" "$WORK/state/summary.md" && grep -qF "$D16" "$WORK/state/summary.md"'
check "ok: no application is deleted" '! app_deleted'

echo "# a second run on the same state keeps the key and token"
clear_logs
run_all ok-again ok
check "ok-again: the run exits 0, the key and token rows are unchanged, and nothing names them" \
  '[ "$RUN_RC" -eq 0 ] && [ "$(secret_rows)" = "$SECRETS_BEFORE" ] && no_secret_request && no_secret_sent'
bodies_for() {  # bodies_for KEY -> every value the fake received for that env var, one per line
  jq -Rr --arg k "$1" 'fromjson? | select(type == "object" and .key == $k) | .value' "$WORK/state/bodies.log"
}

echo "# a site created without funds: refused with the one fixed line, before any write"
NOFUNDS_LINE="::error::Funds cannot be turned on for a site that was created without them. Create the site again with funds on."
for SETUP in 'funds_off' \
             'funds_off; envs_edit "map(select(.key != \"WEB3_ENABLED\"))"' \
             'funds_off; row_set WEB3_ENABLED "\"\""' \
             'row_set WEB3_ENABLED "\"false\""'; do
  reset_state; reset_inputs; eval "$SETUP"; BEFORE="$(envs_snapshot)"
  run_all "nofunds" ok
  check "nofunds ($SETUP): refused with exactly the fixed line, nothing written, no redeploy, the store as it was" \
    '[ "$RUN_RC" -ne 0 ] && grep -qxF -- "$NOFUNDS_LINE" <<<"$OUT" && [ "$(grep -c "^::error::" <<<"$OUT")" -eq 1 ] && nothing_written'
done

echo "# a funds-on site whose key or token is not shown as held: refused, only the owner places keys"
line_missing() { printf '%s' "::error::This app's settings do not show $1 as held. Only the run that creates a site writes its keys and passwords: create the site again, or the site's owner must place the key. Nothing was stored or sent."; }
line_unclear() { printf '%s' "::error::This app's settings did not clearly show $1. Nothing was stored or sent."; }
# owner_refusal LABEL SCENARIO KEY KIND SETUP: refused before any write with that key's line
owner_refusal() {
  local label="$1" scen="$2" key="$3" kind="$4" setup="$5"
  reset_state; reset_inputs; eval "$setup"; BEFORE="$(envs_snapshot)"
  run_all "$label" "$scen"
  WANT_LINE="$(line_"$kind" "$key")"
  check "$label: refused before any write with the $kind line for $key, the store as it was" \
    '[ "$RUN_RC" -ne 0 ] && grep -qxF -- "$WANT_LINE" <<<"$OUT" && nothing_written'
}
owner_refusal key-empty ok SPONSOR_RELAYER_PRIVATE_KEY missing 'row_set SPONSOR_RELAYER_PRIVATE_KEY "\"\""; HELD_VALUES=("$HELD_TOKEN")'
owner_refusal key-not-listed ok SPONSOR_RELAYER_PRIVATE_KEY missing 'envs_edit "map(select(.key != \"SPONSOR_RELAYER_PRIVATE_KEY\"))"; HELD_VALUES=("$HELD_TOKEN")'
owner_refusal token-empty ok SPONSOR_RELAYER_TOKEN missing 'row_set SPONSOR_RELAYER_TOKEN "\"\""; HELD_VALUES=("$HELD_KEY")'
owner_refusal interrupted-provisioning ok SPONSOR_RELAYER_PRIVATE_KEY missing 'row_set SPONSOR_RELAYER_PRIVATE_KEY "\"\""; row_set SPONSOR_RELAYER_TOKEN "\"\""; HELD_VALUES=()'
owner_refusal key-null ok SPONSOR_RELAYER_PRIVATE_KEY missing 'row_set SPONSOR_RELAYER_PRIVATE_KEY null'
check "the owner line says the site's owner must place the key" 'grep -qF "the site'"'"'s owner must place the key" <<<"$(line_missing SPONSOR_RELAYER_PRIVATE_KEY)"'

# ---------------------------------------------------------------------------------------------
# Target selection: the app is the one whose domain URL is https and whose host is
# exactly the site. Every other list is refused before any write.
nothing_written() {  # no PATCH, POST or DELETE, no redeploy, and the stored envs are as they were
  ! grep -qE '^(PATCH|POST|DELETE) ' "$WORK/state/requests.log" && ! grep -q '/deploy?' "$WORK/state/requests.log" && [ "$(envs_snapshot)" = "$BEFORE" ]
}
# refusal LABEL SCENARIO MESSAGE APP... : a fresh funds-off app, that list of applications, the default input
refusal() {
  local label="$1" scenario="$2" msg="$3"; shift 3
  reset_state; reset_inputs; set_apps "$@"
  BEFORE="$(envs_snapshot)"
  run_all "$label" "$scenario"
  check "$label: refused before any write, and the error says: $msg" '[ "$RUN_RC" -ne 0 ] && nothing_written && grep -qF -- "::error::$msg" <<<"$OUT"'
}
# refusal_body LABEL MESSAGE JSON: the same, with the application list's body as given (not necessarily a list)
refusal_body() {
  local label="$1" msg="$2" body="$3"
  reset_state; reset_inputs; printf '%s\n' "$body" > "$WORK/state/apps.json"
  BEFORE="$(envs_snapshot)"
  run_all "$label" ok
  check "$label: refused before any write, and the error says: $msg" '[ "$RUN_RC" -ne 0 ] && nothing_written && grep -qF -- "::error::$msg" <<<"$OUT"'
}
ZERO="Expected exactly one app on $FQDN, found 0. Nothing was changed."
TWO="Expected exactly one app on $FQDN, found 2. Nothing was changed."
UNREADABLE="The Coolify application list could not be read. Nothing was changed."

echo "# target selection: look-alikes and zero apps are found 0, refused before any write"
refusal path-lookalike ok "$ZERO" "$(app lookalike1 elsewhere.localnodes.xyz//demo.localnodes.xyz)"
refusal userinfo-lookalike ok "$ZERO" "$(app lookalike2 demo.localnodes.xyz@elsewhere.localnodes.xyz)"
refusal http-only ok "$ZERO" "$(app_url lookalike3 http://demo.localnodes.xyz)"
refusal other-port ok "$ZERO" "$(app_url lookalike4 https://demo.localnodes.xyz:8443)"
refusal mydemo ok "$ZERO" "$(app lookalike5 mydemo.localnodes.xyz)"
refusal longer-domain ok "$ZERO" "$(app lookalike6 demo.localnodes.xyz.evil.test)"
refusal empty-list ok "$ZERO"
refusal only-unrelated-apps ok "$ZERO" "$NOISE1" "$NOISE2"
echo "# target selection: two apps on the site"
refusal two-apps ok "$TWO" "$TARGET" "$(app site456 demo.localnodes.xyz)"
echo "# target selection: a list that cannot be read is not found 0"
refusal_body not-a-list "$UNREADABLE" '{}'
refusal_body wrapped-list "$UNREADABLE" '{"data": []}'
refusal_body empty-body "$UNREADABLE" ''
refusal list_garbage list_garbage "$UNREADABLE" "$TARGET"
refusal domains-not-json ok "$UNREADABLE" "$(app_str bad1 'not json')"
refusal domains-not-objects ok "$UNREADABLE" "$(app_str bad2 '["https://demo.localnodes.xyz"]')"
refusal list503 list503 "Could not list the Coolify applications. Nothing was changed." "$TARGET"

echo "# target selection: the site's one app is found in every supported shape, and only it"
# positive LABEL EXPECTED_UUID APP... : the run succeeds and every env request and the redeploy name that app
positive() {
  local label="$1" want="$2"; shift 2
  reset_state; reset_inputs; set_apps "$@"
  run_all "$label" ok
  WANT_APP="$want"
  check "$label: the run succeeds and every env request and the redeploy name $want, and only $want" \
    '[ "$RUN_RC" -eq 0 ] && [ "$(stored FEE_WALLET_SCALE)" = 1 ] && [ "$(grep -c "/envs" "$WORK/state/requests.log")" -gt 0 ] && ! grep "/envs" "$WORK/state/requests.log" | grep -qv "^[A-Z]* $CAPI/applications/$WANT_APP/envs" && grep -qxF "POST $CAPI/deploy?uuid=$WANT_APP&force=true -" "$WORK/state/requests.log"'
}
positive native-array site123 "$(app_raw site123 '[{"name":"opensocial","domain":"https://demo.localnodes.xyz:443/"}]')"
positive stored-object-string site123 "$(app_str site123 '{"opensocial":{"domain":"https://demo.localnodes.xyz"}}')"
positive comma-separated site123 "$(app_url site123 https://www.demo.localnodes.xyz,https://demo.localnodes.xyz)"
positive target-with-noise site123 "$(app noise3 elsewhere.localnodes.xyz//demo.localnodes.xyz)" "$TARGET" \
  "$(app noise4 demo.localnodes.xyz@elsewhere.localnodes.xyz)" "$(app noise5 mydemo.localnodes.xyz)" "$NOISE2"

echo "# input: refused before any request"
INJECT='x$(touch '"$WORK"'/PWNED1)`touch '"$WORK"'/PWNED2`;touch '"$WORK"'/PWNED3'
# bad_input LABEL SUBDOMAIN MESSAGE
bad_input() {
  reset_state; set_inputs "$2"; set_secrets "$COOLIFY_LIT"
  BEFORE="$(envs_snapshot)"
  run_all "$1" ok
  BAD_MSG="$3"
  check "$1: refused before any request, nothing written, and the error says: $3" \
    '[ "$RUN_RC" -ne 0 ] && [ ! -s "$WORK/state/requests.log" ] && nothing_written && [ ! -s "$WORK/state/github_env" ] && grep -qF -- "::error::$BAD_MSG" <<<"$OUT"'
}
bad_input upper-subdomain Demo "Invalid subdomain."
bad_input reserved-www www "Subdomain 'www' is reserved."
bad_input multiline-subdomain "$(printf 'demo\nBASH_ENV=/tmp/x')" "Invalid subdomain."
bad_input injected-subdomain "$INJECT" "Invalid subdomain."
bad_input empty-subdomain "" "Invalid subdomain."

# ---------------------------------------------------------------------------------------------
# The pre-write check. Each case starts from reset_state and edits the stored
# rows with the helpers below; BEFORE is taken after that edit, so nothing_written compares with the
# app as the run found it. HELD_VALUES is set inside the setup (reset_state clears it), so the leak
# sweep looks for every throwaway value a case seeds.
OTHER_TOKEN="$(printf 'ef%.0s' {1..32})"
LATE_KEY="0x$(printf 'ef%.0s' {1..32})"
UNREAD_MSG="Could not read this app's settings; the API token needs read:sensitive. Nothing was changed."
CURLFAIL_MSG="Could not read this app's settings. Nothing was changed."
SPLIT_MSG="This app holds more than one fee wallet key or token. Nothing was changed."
row_set() { envs_edit --arg k "$1" --argjson v "$2" 'map(if .key == $k and (.is_preview | not) then .value = $v else . end)'; }  # row_set KEY JSONVALUE (production rows)
row_nov() {  # row_nov KEY: the production row of that key has no value field (a row is added when the app has none)
  envs_edit --arg k "$1" 'if any(.[]; .key == $k and (.is_preview | not)) then map(if .key == $k and (.is_preview | not) then del(.value) else . end) else . + [{uuid: ("nv-" + $k), key: $k, is_preview: false}] end'
}
row_add() {  # row_add UUID KEY JSONVALUE PREVIEW(true|false) [front]: one more row, at the end or the front of the list
  envs_edit --arg u "$1" --arg k "$2" --argjson v "$3" --argjson p "$4" --arg f "${5:-}" \
    '{uuid: $u, key: $k, value: $v, is_preview: $p} as $r | if $f == "front" then [$r] + . else . + [$r] end'
}
prod_rows() { K="$1" jq -r '[.[] | select(.key == env.K and (.is_preview | not))] | length' "$WORK/state/envs.json"; }  # prod_rows KEY
deleted_row() { grep -qE "^DELETE [^ ]*/envs/$1 " "$WORK/state/requests.log"; }  # deleted_row UUID
env_case() { reset_state; reset_inputs; eval "$3"; BEFORE="$(envs_snapshot)"; run_all "$1" "$2"; }  # env_case LABEL SCENARIO SETUP
env_refusal() {  # env_refusal LABEL SCENARIO MESSAGE SETUP: refused before any write, with that error text
  local msg="$3"
  env_case "$1" "$2" "$4"
  check "$1: refused before any write, and the error says: $msg" '[ "$RUN_RC" -ne 0 ] && nothing_written && grep -qF -- "::error::$msg" <<<"$OUT"'
}
env_ok() {  # env_ok LABEL SETUP CHECKTEXT DESCRIPTION: the run goes ahead (RC 0) and CHECKTEXT holds afterwards
  CASE_CHECK="$3"
  env_case "$1" ok "$2"
  check "$1: $4" '[ "$RUN_RC" -eq 0 ] && eval "$CASE_CHECK"'
}

echo "# pre-write check: a held web3 or fee wallet row without a readable value is refused before any write"
env_refusal hidden-values hidden "$UNREAD_MSG" ''
for K in WEB3_ENABLED SPONSOR_RELAYER_PRIVATE_KEY SPONSOR_RELAYER_TOKEN FEE_WALLET_SCALE SIWE_CHAIN_ID; do
  env_refusal "no-value-$K" ok "$UNREAD_MSG" "row_nov $K"
done
env_refusal envs-get503 envs_get503 "$CURLFAIL_MSG" ''
env_refusal envs-empty envs_empty "$UNREAD_MSG" ''
env_refusal envs-garbage envs_garbage "$UNREAD_MSG" ''
# The pre-write check passes, then a later read answers 200 with an empty or whitespace-only body. Reads: 1 the pre-write
# check, 2 the key's check, 3 the token's check, 4 the cleanup (pass 1). None may read as "nothing held".
EMPTY_LIST_MSG="::error::The env var list could not be read (empty, not a list, or no value this token can read); duplicate cleanup stopped."
for SC in envs_empty_at_4 envs_blank_at_4; do
  env_case "cleanup-$SC" "$SC" ''
  check "cleanup-$SC: the run fails, the cleanup's error says the list could not be read, nothing written" \
    '[ "$RUN_RC" -ne 0 ] && grep -qxF -- "$EMPTY_LIST_MSG" <<<"$OUT" && nothing_written && [ "$(grep -c "^GET [^ ]*/envs " "$WORK/state/requests.log")" -eq 4 ]'
done
for SC in envs_empty_at_2 envs_blank_at_2 envs_empty_at_3; do
  env_case "key-$SC" "$SC" ''
  KEYNAME=SPONSOR_RELAYER_PRIVATE_KEY; [ "$SC" = envs_empty_at_3 ] && KEYNAME=SPONSOR_RELAYER_TOKEN
  check "key-$SC: the key check's read is that answer: refused naming $KEYNAME with the could-not-read-a-list line and what it saw, nothing written, no redeploy" \
    '[ "$RUN_RC" -ne 0 ] && grep -qxF -- "::error::This app'"'"'s settings did not come back as one readable list, so $KEYNAME was not checked. Nothing was stored or sent." <<<"$OUT" && grep -qxF -- "What this run saw (names and kinds only, no values): not one JSON document (0)" <<<"$OUT" && nothing_written'
done
# Every value comes back as an empty string (blanked): the first read stops the run before any write of any kind, also with
# an empty key row listed ahead of the held one.
for SETUP in 'seed_held' 'seed_held; row_add e0 SPONSOR_RELAYER_PRIVATE_KEY "\"\"" false front'; do
  env_case "blanked" blanked "$SETUP"
  check "blanked ($SETUP): refused at the first read with the fixed line, before any write of any kind" \
    '[ "$RUN_RC" -ne 0 ] && nothing_written && grep -qxF -- "::error::$UNREAD_MSG" <<<"$OUT" && [ "$(grep -cE "^GET [^ ]*/envs " "$WORK/state/requests.log")" -eq 1 ]'
done
# Selective blanking by NAME: the host sends "" for the six secret names while every
# other setting reads as stored. The run cannot see the held key: it is refused before any write, nothing replaced or deleted.
for SETUP in 'seed_held' 'seed_held; row_add e0 SPONSOR_RELAYER_PRIVATE_KEY "\"\"" false front'; do
  env_case "selective" selective "$SETUP"
  check "selective ($SETUP): refused with the owner line for the key, before any write, the store as it was" \
    '[ "$RUN_RC" -ne 0 ] && grep -qxF -- "$(line_missing SPONSOR_RELAYER_PRIVATE_KEY)" <<<"$OUT" && nothing_written'
done
env_case "secrets-absent" secrets_absent ''
check "secrets-absent: a host that leaves the key and token out of the list: refused with the owner line, the store as it was" \
  '[ "$RUN_RC" -ne 0 ] && grep -qxF -- "$(line_missing SPONSOR_RELAYER_PRIVATE_KEY)" <<<"$OUT" && nothing_written'
SB="$(secret_rows)"
env_case "masked" masked ''
check "masked: a host that shows the key and token as ********: kept, the run goes ahead, nothing written under them" \
  '[ "$RUN_RC" -eq 0 ] && [ "$(secret_rows)" = "$SB" ] && no_secret_request && no_secret_sent'
env_ok no-value-unrelated-key 'row_nov DB_PASSWORD' '[ "$(stored FEE_WALLET_SCALE)" = 1 ]' \
  "an unrelated row without a value does not stop the run"
env_ok no-value-preview-row 'row_add pv1 SPONSOR_RELAYER_PRIVATE_KEY "\"\"" true; envs_edit "map(if .uuid == \"pv1\" then del(.value) else . end)"' \
  '[ "$(stored FEE_WALLET_SCALE)" = 1 ] && ! deleted_row pv1' \
  "a preview row without a value does not stop the run"

echo "# pre-write check: the key or token split over production rows with different values is refused before any write"
env_refusal split-key-empty-first ok "$SPLIT_MSG" 'seed_held; row_set SPONSOR_RELAYER_PRIVATE_KEY "\"\""; row_add k2 SPONSOR_RELAYER_PRIVATE_KEY "\"$HELD_KEY\"" false; HELD_VALUES=("$HELD_KEY")'
env_refusal split-key-two-values ok "$SPLIT_MSG" 'seed_held; row_add k2 SPONSOR_RELAYER_PRIVATE_KEY "\"$LATE_KEY\"" false; HELD_VALUES+=("$LATE_KEY")'
env_refusal split-token-two-values ok "$SPLIT_MSG" 'seed_held; row_add t2 SPONSOR_RELAYER_TOKEN "\"$OTHER_TOKEN\"" false; HELD_VALUES+=("$OTHER_TOKEN")'
env_ok same-token-twice 'row_add t2 SPONSOR_RELAYER_TOKEN "\"$HELD_TOKEN\"" false' \
  '[ "$(stored SPONSOR_RELAYER_TOKEN)" = "$HELD_TOKEN" ] && [ "$(prod_rows SPONSOR_RELAYER_TOKEN)" = 2 ] && ! deleted_row t2 && ! deleted_row e8 && no_secret_request' \
  "two production token rows holding the same value go ahead; both rows are kept (nothing under a secret name is deleted) and nothing names the token"
# A null row of the key or the token next to the held one: null is not shown to mean "empty".
# The pre-write check reads it as empty next to a value, so it refuses the split before the key check; nothing is deleted.
for SETUP in 'row_add k2 SPONSOR_RELAYER_PRIVATE_KEY null false' 'row_add k2 SPONSOR_RELAYER_PRIVATE_KEY null false front'; do
  env_case "null-dup" ok "$SETUP"
  check "null-dup ($SETUP): refused before any write (the split line), nothing deleted" \
    '[ "$RUN_RC" -ne 0 ] && nothing_written && grep -qxF -- "::error::$SPLIT_MSG" <<<"$OUT"'
done
env_ok preview-key-differs 'seed_held; row_add pk1 SPONSOR_RELAYER_PRIVATE_KEY "\"$LATE_KEY\"" true front; HELD_VALUES+=("$LATE_KEY")' \
  '[ "$(stored SPONSOR_RELAYER_PRIVATE_KEY)" = "$HELD_KEY" ] && ! deleted_row pk1 && ! deleted_row e7' \
  "a preview key row holding another value is not a split: the held production key is kept, nothing deleted"

echo "# pre-write check: another network is refused before any write"
OTHER_MSG="This app is set up for another network; this workflow only turns on practice funds. Nothing was changed."
env_refusal other-network ok "$OTHER_MSG" 'row_add sc1 SIWE_CHAIN_ID "\"84532\"" false'
env_ok chain-held-empty 'row_add sc1 SIWE_CHAIN_ID "\"\"" false' '[ "$(stored SIWE_CHAIN_ID)" = 11155111 ]' \
  "a held empty SIWE_CHAIN_ID goes ahead, and the network written is 11155111"
env_ok chain-held-sepolia 'row_add sc1 SIWE_CHAIN_ID "\"11155111\"" false' '[ "$(stored SIWE_CHAIN_ID)" = 11155111 ]' \
  "a held 11155111 goes ahead"
env_ok chain-preview-other 'row_add sc1 SIWE_CHAIN_ID "\"84532\"" true' '[ "$(stored SIWE_CHAIN_ID)" = 11155111 ] && ! deleted_row sc1' \
  "another network held only on a preview row does not stop the run"

echo "# the shared cleanup on the funds-on side: a held pair behind preview rows"
env_ok preview-rows-first 'seed_held; row_add pk1 SPONSOR_RELAYER_PRIVATE_KEY "\"\"" true front; row_add pt1 SPONSOR_RELAYER_TOKEN "\"\"" true front' \
  '[ "$(stored SPONSOR_RELAYER_PRIVATE_KEY)" = "$HELD_KEY" ] && [ "$(stored SPONSOR_RELAYER_TOKEN)" = "$HELD_TOKEN" ] && ! deleted_row e7 && ! deleted_row e8 && no_secret_request && no_secret_sent' \
  "empty preview rows listed before the held production pair: the pair is kept, never deleted, never written"
env_ok other-duplicate-cleaned 'row_add m1 MAIL_FROM "\"\"" false; row_add m2 MAIL_FROM "\"\"" false' \
  '{ deleted_row m1 || deleted_row m2; } && ! deleted_row e7 && ! deleted_row e8' \
  "an empty duplicate of another setting is still cleaned; nothing under the key or token is deleted"

echo "# failures after the writes never deploy and never delete an app"
# post_write_failure LABEL SCENARIO MESSAGE: the run fails with that error text and no application is deleted
post_write_failure() {
  local msg="$3"
  env_case "$1" "$2" ''
  check "$1: the run fails and says: $msg" '[ "$RUN_RC" -ne 0 ] && grep -qF -- "::error::$msg" <<<"$OUT"'
  check "$1: no application is deleted" '! app_deleted'
}
post_write_failure drop_scale drop_scale "FEE_WALLET_SCALE: expected exactly one production variable"
check "drop_scale: the read-back stops the run before any deploy request" '! grep -q "/deploy?" "$WORK/state/requests.log"'
post_write_failure deploy_fail deploy_fail "Failed to trigger deployment (HTTP 200, no deployment uuid in the reply)"
check "deploy_fail: stops before the waits (no GET of /deployments/ and none of the login page)" '! grep -q "/deployments/" "$WORK/state/requests.log" && ! grep -q "/user/login" "$WORK/state/requests.log"'
post_write_failure deploy_stuck deploy_stuck "Deployment did not finish within 5 min (last status: in_progress)"
post_write_failure site503 site503 "Site not healthy after 10 min (last HTTP 503 at https://$FQDN/user/login)"

# ---------------------------------------------------------------------------------------------
# The shared functions are copied byte for byte from provision-instance.yml.
echo "# contract: the shared bash is byte-identical to provision-instance.yml's"
run_text() {  # run_text JSONFILE JOB STEP -> the step's run: text
  jq -j --arg j "$2" --arg n "$3" '[.jobs[$j].steps[] | select(.name == $n)][0].run // ""' "$1"
}
extract_fn() {  # extract_fn RUNTEXTFILE NAME -> the definition, from `NAME() {` to the first `}` in column 0 (nothing when missing)
  awk -v n="$2" '$0 == n "() {" { p = 1 } p { print } p && $0 == "}" { exit }' "$1"
}
run_text "$WORK/prov.json" provision "Configure environment variables" > "$WORK/prov-run.sh"
run_text "$WORK/wf.json" funds_on "Turn group funds on (practice)" > "$WORK/funds-run.sh"
SHARED_FNS="env_request update_env list_dupes dedup_envs values_readable verify_env secret_names created_here check_secret settings_summary turn_on_practice_funds verify_practice_funds"
for fn in $SHARED_FNS; do
  extract_fn "$WORK/prov-run.sh" "$fn" > "$WORK/p.fn"
  extract_fn "$WORK/funds-run.sh" "$fn" > "$WORK/f.fn"
  check "contract: $fn is non-empty in both workflows and byte-identical (sha256)" \
    '[ -s "$WORK/p.fn" ] && [ -s "$WORK/f.fn" ] && [ "$(sha256sum < "$WORK/p.fn")" = "$(sha256sum < "$WORK/f.fn")" ]'
done
FUNDS_RPC="$(jq -r '[.jobs.funds_on.steps[] | select(.name == "Turn group funds on (practice)")][0].env.PRACTICE_RPC_URL // ""' "$WORK/wf.json")"
check "contract: PRACTICE_RPC_URL is the same non-empty string in both step envs" '[ -n "$PROV_RPC" ] && [ "$PROV_RPC" = "$FUNDS_RPC" ]'
check "contract: funds-on never creates a site: no CREATED_APP_UUID in any env, input or GITHUB_ENV write, no steps. expression" \
  '[ "$(jq "[.. | objects | to_entries[] | select(.key == \"CREATED_APP_UUID\")] | length" "$WORK/wf.json")" -eq 0 ] && ! grep -vE "^[[:space:]]*#" "$WF" | grep -qE "CREATED_APP_UUID.*GITHUB_ENV|steps\." && [ "$(grep -vE "^[[:space:]]*#" "$WF" | grep -c CREATED_APP_UUID)" -eq "$(extract_fn "$WORK/funds-run.sh" created_here | grep -c CREATED_APP_UUID)" ]'
check "contract: the funds-on step env sets REUSE to the string true (every run here is a later run)" \
  '[ "$(jq -r "[.jobs.funds_on.steps[] | select(.name == \"Turn group funds on (practice)\")][0].env.REUSE | if type == \"string\" then . else \"not-a-string\" end" "$WORK/wf.json")" = true ]'
# Outside the shared function bodies (comments left out) the step calls the shared writers nowhere.
awk -v names="$SHARED_FNS" 'BEGIN { n = split(names, a, " "); for (i = 1; i <= n; i++) fn[a[i]] = 1 }
  skip { if ($0 == "}") skip = 0; next }
  { if (match($0, /^[a-z_]+\(\) \{$/)) { nm = substr($0, 1, RLENGTH - 4); if (nm in fn) { skip = 1; next } } print }' \
  "$WORK/funds-run.sh" | grep -vE '^[[:space:]]*#' > "$WORK/funds-outside.sh"
check "contract: outside the shared functions the step calls update_env and verify_env nowhere (one writer)" \
  '! grep -qE "(^|[^A-Za-z_])(update_env|verify_env)([^A-Za-z_]|$)" "$WORK/funds-outside.sh"'
word_count() { grep -oE "(^|[^A-Za-z0-9_])$1([^A-Za-z0-9_]|\$)" "$WORK/funds-outside.sh" | wc -l; }  # word_count NAME -> NAME as a word outside the shared functions
check "contract: turn_on_practice_funds and verify_practice_funds are each named once outside the shared functions, as a line of their own, turn before verify" \
  '[ "$(word_count turn_on_practice_funds)" -eq 1 ] && [ "$(word_count verify_practice_funds)" -eq 1 ] && [ "$(grep -cx "[[:space:]]*turn_on_practice_funds" "$WORK/funds-outside.sh")" -eq 1 ] && [ "$(grep -cx "[[:space:]]*verify_practice_funds" "$WORK/funds-outside.sh")" -eq 1 ] && [ "$(grep -nx "[[:space:]]*turn_on_practice_funds" "$WORK/funds-outside.sh" | cut -d: -f1)" -lt "$(grep -nx "[[:space:]]*verify_practice_funds" "$WORK/funds-outside.sh" | cut -d: -f1)" ]'

# ---------------------------------------------------------------------------------------------
echo "# every scenario ($SCENARIOS_RUN runs)"
sweep_check() {  # sweep_check NAME FILE -> ok, or a FAIL line naming the runs that breached
  if [ ! -s "$WORK/$2" ]; then ok "$1"; else nok "$1 (breached in: $(sort -u "$WORK/$2" | tr '\n' ' '))"; fi
}
sweep_check "sweep: no fee wallet key (with or without 0x), token or Coolify token in any run's output outside ::add-mask:: lines" v-out
sweep_check "sweep: no fee wallet key (with or without 0x), token or Coolify token in any run's requests.log" v-reqs
sweep_check "sweep: no fee wallet key (with or without 0x), token or Coolify token in any run's step summary" v-sum
sweep_check "sweep: no response body is echoed (no SHOULD-NOT-LEAK text in any run's output)" v-body
sweep_check "sweep: every request went to coolify.test or $FQDN" v-host
sweep_check "sweep: no application is deleted (every DELETE names /envs/)" v-delete
sweep_check "sweep: no input ran as shell" v-inject
sweep_check "sweep: the runner modelled every if: and every \${{ }} in env: (no unknown form)" v-harness
for v in out reqs sum body host delete inject harness; do
  [ -s "$WORK/v-$v" ] && sed 's/^/#   /' "$WORK/v-$v"
done

finish
