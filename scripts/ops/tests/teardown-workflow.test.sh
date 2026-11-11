#!/usr/bin/env bash
# Tests for .github/workflows/teardown-instance.yml (23-36; D-18 note, Tom's answers 2 and 4).
#
# The workflow is converted to JSON (Ruby's YAML parser or, where Ruby is missing, Python with
# PyYAML). Every step's real `run:` text is then run as GitHub runs it
# (bash --noprofile --norc -eo pipefail), in order, with:
#   - the step's own env: block resolved from the test's inputs and secrets (so the env:
#     wiring itself is under test, not a hand-made copy of it),
#   - $GITHUB_ENV carried from step to step, last write winning, as GitHub does,
#   - the step's if: evaluated (only the forms this workflow uses; any other is a failure),
#   - a fake `curl` (fake-coolify-teardown.py) and a no-op `sleep` first on PATH.
# No network, no Coolify, no sign-up app, no GitHub. A request to any other host is recorded
# by the fake and fails the run.
#
# Usage: bash scripts/ops/tests/teardown-workflow.test.sh
#        WF=/path/to/copy.yml bash scripts/ops/tests/teardown-workflow.test.sh   (mutation runs)
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
WF="${WF:-$REPO/.github/workflows/teardown-instance.yml}"  # override to test another copy
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); echo "ok   - $1"; }
nok()  { FAIL=$((FAIL + 1)); echo "FAIL - $1"; }
check() { if eval "$2"; then ok "$1"; else nok "$1"; fi; }
finish() { echo "# $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]; exit $?; }

# Fixtures (the provision contract v1 id shape; 23-03, 23-11)
PID="k3Jx9_Qm-2LpWv7RtYd4Nz"
OTHER_PID="AAAAAAAAAAAAAAAAAAAAAA"
SECRET="shared-callback-secret"
COOLIFY_SECRET="test-token"
TOKEN="$(printf '%s' "$PID" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"
SUBDOMAIN="demo"
FQDN="demo.localnodes.xyz"
CB="https://cb.test"
CAPI="http://coolify.test/api/v1"
TAG="ln-provision:$PID"
DELETE_QUERY="delete_configurations=true&delete_volumes=true&docker_cleanup=true&delete_connected_networks=true"

# Fake binaries
mkdir -p "$WORK/bin" "$WORK/tmp"
cp "$HERE/fake-coolify-teardown.py" "$WORK/bin/curl"
chmod +x "$WORK/bin/curl"
printf '#!/bin/sh\nexit 0\n' > "$WORK/bin/sleep"
chmod +x "$WORK/bin/sleep"

echo "# the workflow file"
if [ ! -f "$WF" ]; then
  nok "the workflow file exists ($WF)"
  finish
fi
ok "the workflow file exists"

# YAML reads a bare `on:` key as boolean true, so it arrives as "true" in the JSON.
if command -v ruby >/dev/null 2>&1; then
  ruby -ryaml -rjson -e 'puts JSON.generate(YAML.load_file(ARGV[0]))' "$WF" > "$WORK/wf.json"
else
  python3 -c 'import json, sys, yaml; print(json.dumps(yaml.safe_load(open(sys.argv[1]))))' "$WF" > "$WORK/wf.json"
fi 2>/dev/null || { nok "the workflow parses as YAML (needs ruby, or python3 with PyYAML)"; finish; }
ok "the workflow parses as YAML"

NSTEPS="$(jq '.jobs.teardown.steps | length' "$WORK/wf.json" 2>/dev/null || echo 0)"

step_script() {  # step_script NAME -> the step's run: text on stdout, nonzero when there is no such step
  jq -j --arg n "$1" '[.jobs.teardown.steps[] | select(.name == $n)] | if length == 0 then (("no step " + $n + "\n") | halt_error(1)) else .[0].run end' "$WORK/wf.json"
}

# ---------------------------------------------------------------------------------------------
# The runner: every step in order, as GitHub would run them.

declare -A INPUTS SECRETS GENV STEP_RC
set_inputs() { INPUTS=([subdomain]="$1" [provision_id]="$2" [callback_url]="$3"); }
set_secrets() { SECRETS=([COOLIFY_API_TOKEN]="$1" [PROVISION_CALLBACK_SECRET]="$2"); }

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
    # The pinned sign-up origin becomes the fake's host, so the runs below exercise the
    # workflow's comparison; the static checks under "# the pinned origin" hold the real value
    # against the onboarding dispatcher.
    if [ "$k" = SIGNUP_ORIGIN ]; then RESOLVED="$CB"; fi
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

reset_state() {
  rm -rf "$WORK/state" "$WORK"/PWNED*; mkdir -p "$WORK/state"
  echo '[]' > "$WORK/state/apps.json"
  : > "$WORK/state/requests.log"; : > "$WORK/state/auth.log"; : > "$WORK/state/bodies.log"
  : > "$WORK/state/leak.log"; : > "$WORK/state/github_env"; : > "$WORK/state/summary.md"
  : > "$WORK/state/flags.log"
}

app() {  # app UUID DESCRIPTION|null HOST|null -> one Coolify application object (compact JSON)
  jq -cn --arg u "$1" --arg d "$2" --arg h "$3" '{uuid: $u, name: $u,
    description: (if $d == "null" then null else $d end),
    docker_compose_domains: (if $h == "null" then null else ([{name: "opensocial", domain: ("https://" + $h)}] | tojson) end)}'
}
set_apps() { printf '%s\n' "$@" | jq -s . > "$WORK/state/apps.json"; }

# Background apps that must never be touched: an untagged app and another sign-up's app,
# both on other domains.
NOISE1="$(app noise1 null elsewhere.localnodes.xyz)"
NOISE2="$(app noise2 "ln-provision:$OTHER_PID" other.localnodes.xyz)"
TAGGED="$(app tag123 "$TAG" "$FQDN")"

RUN_RC=0
OUT=""
CUR=""
SCENARIOS_RUN=0
run_all() {  # run_all LABEL SCENARIO -> RUN_RC (the run's exit), OUT (all output), STEP_RC[name]
  CUR="$1"
  local scenario="$2" i name cond run_it failed=0 rc out
  OUT=""; RUN_RC=0; STEP_RC=()
  for ((i = 0; i < NSTEPS; i++)); do
    name="$(jq -r ".jobs.teardown.steps[$i].name" "$WORK/wf.json")"
    cond="$(jq -r ".jobs.teardown.steps[$i].if // \"\"" "$WORK/wf.json")"
    load_genv
    case "$cond" in
      "") run_it=$((failed == 0)) ;;
      "always()") run_it=1 ;;
      "env.SKIP_DELETE != 'true'") run_it=0; [ "$failed" -eq 0 ] && [ "${GENV[SKIP_DELETE]-}" != true ] && run_it=1 ;;
      *) echo "unmodelled if: on step $name: $cond" >> "$WORK/harness-errors"; run_it=$((failed == 0)) ;;
    esac
    if [ "$run_it" -ne 1 ]; then STEP_RC[$name]=skip; continue; fi
    jq -j ".jobs.teardown.steps[$i].run // \"\"" "$WORK/wf.json" > "$WORK/step.sh"
    ENVV=(PATH="$WORK/bin:/usr/bin:/bin" HOME="$WORK" TMPDIR="$WORK/tmp"
      FAKE_STATE="$WORK/state" FAKE_SCENARIO="$scenario"
      GITHUB_ENV="$WORK/state/github_env" GITHUB_STEP_SUMMARY="$WORK/state/summary.md")
    add_env_block '.env'
    add_env_block '.jobs.teardown.env'
    for k in "${!GENV[@]}"; do ENVV+=("$k=${GENV[$k]}"); done
    add_env_block ".jobs.teardown.steps[$i].env"
    out="$(cd "$WORK" && env -i "${ENVV[@]}" bash --noprofile --norc -eo pipefail "$WORK/step.sh" 2>&1)"
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

# After every run: properties that must hold in every scenario. A breach is recorded with the
# scenario's label and reported by the named checks at the end of this file.
sweep() {
  local f="$WORK/state" shown s
  [ -s "$f/leak.log" ] && echo "$CUR: $(head -1 "$f/leak.log")" >> "$WORK/v-leak"
  shown="$(grep -v '^::add-mask::' <<<"$OUT")"
  for s in "$TOKEN" "$SECRET" "$COOLIFY_SECRET"; do
    grep -qF -- "$s" "$f/requests.log" && echo "$CUR: secret in requests.log" >> "$WORK/v-secret"
    grep -qF -- "$s" <<<"$shown" && echo "$CUR: secret in the run output" >> "$WORK/v-secret"
    grep -qF -- "$s" "$f/summary.md" && echo "$CUR: secret in the step summary" >> "$WORK/v-secret"
  done
  grep -q 'SHOULD-NOT-LEAK' <<<"$OUT" && echo "$CUR: a response body was echoed" >> "$WORK/v-body"
  grep ' http://coolify\.test/' "$f/requests.log" | grep -vE '^(GET|DELETE) ' | sed "s|^|$CUR: |" >> "$WORK/v-method"
  grep -E '^[A-Z]+ http://coolify\.test/' "$f/requests.log" | grep -E 'deploy|/start|/stop|/restart' | sed "s|^|$CUR: |" >> "$WORK/v-method"
  awk -v t="Bearer $TOKEN" -v c="Bearer $COOLIFY_SECRET" -v s="$CUR" '
    $2 ~ /^https:\/\/cb\.test\// { if (substr($0, index($0, $3)) != t) print s ": wrong bearer to cb.test" }
    $2 ~ /^http:\/\/coolify\.test\// { if (substr($0, index($0, $3)) != c) print s ": wrong bearer to coolify.test" }' \
    "$f/auth.log" >> "$WORK/v-auth"
  local posts; posts="$(grep -c '^POST https://cb\.test/api/teardown-result$' "$f/requests.log")"
  [ "$posts" -le 1 ] || echo "$CUR: $posts result callbacks" >> "$WORK/v-result"
  while IFS= read -r b; do
    jq -e --arg p "${INPUTS[provision_id]}" '(keys == ["provision_id", "result"]) and .provision_id == $p
      and (.result | IN("deleted", "gone", "refused", "failed"))' >/dev/null 2>&1 <<<"$b" \
      || echo "$CUR: result body outside the contract" >> "$WORK/v-result"
  done < "$f/bodies.log"
  ls "$WORK"/PWNED* >/dev/null 2>&1 && echo "$CUR: an input ran as shell" >> "$WORK/v-inject"
  # 23-43 Task 5: every request is bounded. --connect-timeout 10 on all; --max-time 10 on the
  # delete polls (a GET of the app after its DELETE), --max-time 30 on everything else.
  awk -v s="$CUR" '
    $1 == "DELETE" { del = 1 }
    {
      poll = del && $1 == "GET" && $2 ~ /^http:\/\/coolify\.test\/api\/v1\/applications\/[A-Za-z0-9]+$/
      want = poll ? "max=10" : "max=30"
      if ($3 != "connect=10" || $4 != want) print s ": " $1 " " $2 " " $3 " " $4 " (want connect=10 " want ")"
    }' "$f/flags.log" >> "$WORK/v-timeout"
  [ -s "$WORK/harness-errors" ] && { sed "s|^|$CUR: |" "$WORK/harness-errors" >> "$WORK/v-harness"; : > "$WORK/harness-errors"; }
  return 0
}

reqs() { cat "$WORK/state/requests.log"; }
no_delete() { ! grep -q '^DELETE ' "$WORK/state/requests.log"; }
no_coolify() { ! grep -q ' http://coolify\.test/' "$WORK/state/requests.log"; }
result_word() {  # the result word posted to the sign-up app, or "none"
  if grep -q '^POST https://cb\.test/api/teardown-result$' "$WORK/state/requests.log"; then
    tail -1 "$WORK/state/bodies.log" | jq -r '.result'
  else echo none; fi
}

# Default dispatch: this sign-up, the real secrets' stand-ins
reset_inputs() { set_inputs "$SUBDOMAIN" "$PID" "$CB"; set_secrets "$COOLIFY_SECRET" "$SECRET"; }

# ---------------------------------------------------------------------------------------------
echo "# workflow shape"
check "on.workflow_dispatch has exactly the inputs subdomain, provision_id and callback_url" \
  '[ "$(jq -r "(.[\"true\"] // .on).workflow_dispatch.inputs | keys | join(\" \")" "$WORK/wf.json")" = "callback_url provision_id subdomain" ]'
check "  ...each required, of type string" \
  '[ "$(jq "[(.[\"true\"] // .on).workflow_dispatch.inputs[] | (.required == true and .type == \"string\")] | all" "$WORK/wf.json")" = true ]'
check "concurrency group is built from inputs.provision_id, cancel-in-progress false" \
  '[ "$(jq -r ".concurrency.group" "$WORK/wf.json")" = "teardown-\${{ inputs.provision_id }}" ] && [ "$(jq ".concurrency[\"cancel-in-progress\"]" "$WORK/wf.json")" = false ]'
check "permissions is an empty map at the top level, and no job widens it" \
  '[ "$(jq -c ".permissions" "$WORK/wf.json")" = "{}" ] && [ "$(jq "[.jobs[] | has(\"permissions\")] | any" "$WORK/wf.json")" = false ]'
check "one job, named teardown" '[ "$(jq -r ".jobs | keys | join(\" \")" "$WORK/wf.json")" = teardown ]'
EXPR_RUNS="$(jq -r '.jobs[].steps[] | select((.run // "") | contains("${{")) | .name' "$WORK/wf.json")"
check "no \${{ }} expression inside any run: block" '[ -z "$EXPR_RUNS" ]'

echo "# scenario ok: one app on $FQDN carrying this sign-up's tag"
reset_state; reset_inputs; set_apps "$NOISE1" "$TAGGED" "$NOISE2"
run_all ok ok
EXPECT_OK="GET $CB/api/teardown-check?provision_id=$PID
GET $CAPI/applications
GET $CAPI/applications/tag123
GET $CB/api/teardown-check?provision_id=$PID
DELETE $CAPI/applications/tag123?$DELETE_QUERY
GET $CAPI/applications/tag123
POST $CB/api/teardown-result"
check "ok: the run exits 0" '[ "$RUN_RC" -eq 0 ]'
check "ok: confirm, list, re-read, re-check, DELETE with all four flags, wait for 404, report, in that order" \
  '[ "$(reqs)" = "$EXPECT_OK" ]'
check "ok: both teardown-checks carry the per-provision bearer" \
  '[ "$(grep -cx "GET $CB/api/teardown-check?provision_id=$PID Bearer $TOKEN" "$WORK/state/auth.log")" -eq 2 ]'
check "ok: the teardown-check carries the per-provision bearer" \
  'grep -qx "GET $CB/api/teardown-check?provision_id=$PID Bearer $TOKEN" "$WORK/state/auth.log"'
check "ok: the result callback carries the same bearer" \
  'grep -qx "POST $CB/api/teardown-result Bearer $TOKEN" "$WORK/state/auth.log"'
check "ok: the result body is {provision_id, result: deleted}" \
  '[ "$(tail -1 "$WORK/state/bodies.log" | jq -c .)" = "{\"provision_id\":\"$PID\",\"result\":\"deleted\"}" ]'
check "ok: only the tagged app is gone; the other sign-up's app and the untagged app stay" \
  '[ "$(jq -r "[.[].uuid] | join(\" \")" "$WORK/state/apps.json")" = "noise1 noise2" ]'
check "ok: the token is masked in the confirmation step, before any request" \
  'grep -qx "::add-mask::$TOKEN" <<<"$OUT"'
check "ok: the step summary names the subdomain, the provision id, the app uuid and the result" \
  'grep -qF "$SUBDOMAIN" "$WORK/state/summary.md" && grep -qF "$PID" "$WORK/state/summary.md" && grep -qF tag123 "$WORK/state/summary.md" && grep -qF deleted "$WORK/state/summary.md"'

echo "# a second run for the same sign-up (concurrency queues it behind the first)"
# Same Coolify state (the app is already deleted); fresh logs and a fresh GITHUB_ENV.
for f in requests.log auth.log bodies.log leak.log github_env summary.md flags.log; do : > "$WORK/state/$f"; done
run_all ok-again ok
check "a repeat run after the delete finds nothing, deletes nothing and reports gone, exit 0" \
  '[ "$RUN_RC" -eq 0 ] && no_delete && [ "$(result_word)" = gone ]'

# ---------------------------------------------------------------------------------------------
# Task 2: every refusal and failure deletes nothing (D-18 note, T-23-160, T-23-161)

apps_snapshot() { jq -cS . "$WORK/state/apps.json"; }
# refused_run LABEL SCENARIO APP... -> runs with the default inputs on that app list
refused_run() {
  local label="$1" scenario="$2"; shift 2
  reset_state; reset_inputs; set_apps "$@"
  BEFORE="$(apps_snapshot)"
  run_all "$label" "$scenario"
}
deleted_nothing() { no_delete && [ "$(apps_snapshot)" = "$BEFORE" ]; }

echo "# the tag guard"
refused_run untagged ok "$NOISE1" "$(app untag1 null "$FQDN")" "$NOISE2"
check "untagged: an app on $FQDN with no description is refused, nothing deleted, result refused" \
  '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = refused ]'
refused_run untagged-empty ok "$(app untag2 "" "$FQDN")"
check "untagged (empty description): refused, nothing deleted" \
  '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = refused ]'
refused_run foreign ok "$NOISE1" "$(app foreign1 "ln-provision:$OTHER_PID" "$FQDN")"
check "foreign: an app on $FQDN tagged for another sign-up is refused, nothing deleted" \
  '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = refused ]'
refused_run tag-prefix ok "$(app prefix1 "$TAG-extra" "$FQDN")"
check "a description that only starts with the tag is not the tag: refused, nothing deleted" \
  '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = refused ]'
refused_run tagged_plus_foreign ok "$TAGGED" "$(app untag1 null "$FQDN")"
check "tagged_plus_foreign: the tagged app plus an untagged app on $FQDN is refused, nothing deleted" \
  '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = refused ]'
refused_run two_tagged ok "$TAGGED" "$(app tag456 "$TAG" null)"
check "two_tagged: two apps carrying this tag are refused, nothing deleted" \
  '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = refused ]'
refused_run reread_mismatch reread_mismatch "$TAGGED"
check "reread_mismatch: the list shows the tag but the re-read does not: refused, no DELETE" \
  '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = refused ] && grep -qx "GET $CAPI/applications/tag123" "$WORK/state/requests.log"'

reset_state; reset_inputs; set_apps "$NOISE1" "$(app half1 "$TAG" null)" "$NOISE2"
run_all tag_elsewhere ok
check "tag_elsewhere: a half-made tagged app with no domain is deleted (with volumes), result deleted" \
  '[ "$RUN_RC" -eq 0 ] && grep -qx "DELETE $CAPI/applications/half1?$DELETE_QUERY" "$WORK/state/requests.log" && [ "$(grep -c "^DELETE " "$WORK/state/requests.log")" -eq 1 ] && [ "$(result_word)" = deleted ] && [ "$(jq -r "[.[].uuid] | join(\" \")" "$WORK/state/apps.json")" = "noise1 noise2" ]'

reset_state; reset_inputs; set_apps "$NOISE1" "$NOISE2"
run_all none ok
check "none: no app on $FQDN and none tagged: no DELETE, result gone, exit 0" \
  '[ "$RUN_RC" -eq 0 ] && no_delete && [ "$(result_word)" = gone ] && [ "${STEP_RC[Delete the app and its volumes]}" = skip ]'
check "  ...and the summary says gone, with no app uuid" \
  'grep -qF "| **Result** | gone |" "$WORK/state/summary.md" && grep -qF "\`none\`" "$WORK/state/summary.md"'

echo "# the sign-up app's confirmation"
for s in check_refused check_true_not_200 check_garbage check_false check_string check_down; do
  refused_run "$s" "$s" "$TAGGED"
  check "$s: refused, and no Coolify request at all" \
    '[ "$RUN_RC" -ne 0 ] && no_coolify && deleted_nothing && [ "$(result_word)" = refused ]'
done
check "  ...the refusal names the HTTP code only (check_down shows 000)" \
  'grep -qF "did not confirm the teardown (HTTP 000)" <<<"$OUT"'
echo "# teardown-check asked again right before the DELETE (23-43 Task 5, T-23-195)"
refused_run check_flips check_flips "$NOISE1" "$TAGGED" "$NOISE2"
EXPECT_FLIPS="GET $CB/api/teardown-check?provision_id=$PID
GET $CAPI/applications
GET $CAPI/applications/tag123
GET $CB/api/teardown-check?provision_id=$PID
POST $CB/api/teardown-result"
check "check_flips: yes at the first check, 409 at the second: refused, no DELETE, nothing deleted, result refused" \
  '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = refused ]'
check "  ...requests: confirm, list, re-read, re-check, report (nothing after the re-check but the result)" \
  '[ "$(reqs)" = "$EXPECT_FLIPS" ]'
check "  ...the refusal says nothing was deleted and names the HTTP code only" \
  'grep -qF "did not confirm the teardown again (HTTP 409). Nothing was deleted." <<<"$OUT"'
reset_state; reset_inputs; set_secrets "$COOLIFY_SECRET" ""; set_apps "$TAGGED"
BEFORE="$(apps_snapshot)"
run_all no_secret ok
check "no PROVISION_CALLBACK_SECRET: refused, no request at all, nothing deleted" \
  '[ "$RUN_RC" -ne 0 ] && [ ! -s "$WORK/state/requests.log" ] && deleted_nothing && grep -q "no token for the result callback" <<<"$OUT"'

echo "# Coolify errors"
for s in list503 list_garbage list_object; do
  refused_run "$s" "$s" "$TAGGED"
  check "$s: result failed, no DELETE, exit non-zero" \
    '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(result_word)" = failed ]'
done
refused_run delete500 delete500 "$TAGGED"
check "delete500: result failed, exit non-zero, no wait loop after the refused DELETE" \
  '[ "$RUN_RC" -ne 0 ] && [ "$(result_word)" = failed ] && [ "$(awk "/^DELETE /{d=1; next} d && /^GET /" "$WORK/state/requests.log" | wc -l)" -eq 0 ]'
reset_state; reset_inputs; set_apps "$TAGGED"
run_all delete_slow delete_slow
check "delete_slow: still there after 18 polls: result failed, exit non-zero, exactly 18 GETs after the DELETE" \
  '[ "$RUN_RC" -ne 0 ] && [ "$(result_word)" = failed ] && [ "$(awk "/^DELETE /{d=1; next} d && /^GET http:\/\/coolify\.test\/api\/v1\/applications\/tag123$/" "$WORK/state/requests.log" | wc -l)" -eq 18 ]'
reset_state; reset_inputs; set_apps "$TAGGED"
run_all delete_late delete_late
check "delete_late: gone on the third poll: result deleted, exit 0" \
  '[ "$RUN_RC" -eq 0 ] && [ "$(result_word)" = deleted ] && [ "$(awk "/^DELETE /{d=1; next} d && /^GET /" "$WORK/state/requests.log" | wc -l)" -eq 3 ]'

echo "# the result callback never fails the run"
reset_state; reset_inputs; set_apps "$TAGGED"
run_all result_down result_down
check "result_down: the run exits as the delete did (0), with a warning line" \
  '[ "$RUN_RC" -eq 0 ] && [ "${STEP_RC[Report the result]}" = 0 ] && grep -q "Warning: result callback failed (non-fatal)" <<<"$OUT" && [ "$(jq length "$WORK/state/apps.json")" -eq 0 ]'
reset_state; reset_inputs; set_apps "$TAGGED"
run_all result_down-refused check_refused
check "  ...and a refused run still reports refused, and the report step itself exits 0" \
  '[ "$RUN_RC" -ne 0 ] && [ "${STEP_RC[Report the result]}" = 0 ] && [ "${STEP_RC[Summary]}" = 0 ] && [ "$(result_word)" = refused ]'

echo "# bad inputs: refused before any request"
INJECT='x$(touch '"$WORK"'/PWNED1)`touch '"$WORK"'/PWNED2`;touch '"$WORK"'/PWNED3'
# bad_input LABEL SUBDOMAIN PROVISION_ID CALLBACK_URL REPORT(yes|no)
bad_input() {
  local label="$1" report="$5"
  reset_state; set_inputs "$2" "$3" "$4"; set_secrets "$COOLIFY_SECRET" "$SECRET"; set_apps "$TAGGED"
  BEFORE="$(apps_snapshot)"
  run_all "$label" ok
  if [ "$report" = yes ]; then
    check "$label: refused before any request; only the result callback (refused) goes out" \
      '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ "$(reqs)" = "POST $CB/api/teardown-result" ] && [ "$(result_word)" = refused ]'
    check "  ...its bearer is derived in the report step and masked first" \
      'grep -qx "::add-mask::$TOKEN" <<<"$OUT" && grep -qx "POST $CB/api/teardown-result Bearer $TOKEN" "$WORK/state/auth.log"'
  else
    check "$label: refused before any request, and no result callback to an unvalidated address" \
      '[ "$RUN_RC" -ne 0 ] && deleted_nothing && [ ! -s "$WORK/state/requests.log" ] && grep -q "Result not reported" <<<"$OUT"'
    check "  ...and no token was derived at all (no mask line, no TOKEN in GITHUB_ENV)" \
      '! grep -q "::add-mask::" <<<"$OUT" && ! grep -q "^TOKEN=" "$WORK/state/github_env"'
  fi
  if [ "$report" = yes ]; then EXPECT_GENV=$'REPORT_OK=true\nRESULT=refused'; else EXPECT_GENV="RESULT=refused"; fi
  check "  ...GITHUB_ENV holds only $(tr '\n' ' ' <<<"$EXPECT_GENV")(no injected line)" \
    '[ "$(cat "$WORK/state/github_env")" = "$EXPECT_GENV" ]'
}
bad_input upper-subdomain Demo "$PID" "$CB" yes
bad_input multiline-subdomain "$(printf 'demo\nBASH_ENV=/tmp/x')" "$PID" "$CB" yes
bad_input injected-subdomain "$INJECT" "$PID" "$CB" yes
bad_input reserved-www www "$PID" "$CB" yes
bad_input id-21 "$SUBDOMAIN" "${PID:0:21}" "$CB" no
bad_input id-injected "$SUBDOMAIN" "$INJECT" "$CB" no
bad_input id-multiline "$SUBDOMAIN" "$(printf '%s\nBASH_ENV=/tmp/x' "$PID")" "$CB" no
bad_input http-callback "$SUBDOMAIN" "$PID" "http://cb.test" no
bad_input callback-with-path "$SUBDOMAIN" "$PID" "https://cb.test/x" no
bad_input callback-injected "$SUBDOMAIN" "$PID" "https://cb.test$INJECT" no

echo "# the pinned origin: only the sign-up app may approve a delete or hear its result (review fix, 2026-11-09)"
# A well-formed https base URL that is not the pinned origin gets nothing: no token, no
# teardown-check, no Coolify call, no delete and no result post (a foreign host is not a safe
# place to report to). The runs use the pinned value as substituted above (https://cb.test).
bad_input foreign-host "$SUBDOMAIN" "$PID" "https://evil.test" no
bad_input lookalike-suffix "$SUBDOMAIN" "$PID" "https://cb.test.evil.test" no
bad_input lookalike-ends-with "$SUBDOMAIN" "$PID" "https://evilcb.test" no
bad_input lookalike-subdomain "$SUBDOMAIN" "$PID" "https://site.cb.test" no
bad_input lookalike-port "$SUBDOMAIN" "$PID" "https://cb.test:8443" no
bad_input lookalike-port-443 "$SUBDOMAIN" "$PID" "https://cb.test:443" no
bad_input lookalike-userinfo "$SUBDOMAIN" "$PID" "https://cb.test@evil.test" no
bad_input lookalike-trailing-dot "$SUBDOMAIN" "$PID" "https://cb.test." no
bad_input lookalike-case "$SUBDOMAIN" "$PID" "https://CB.TEST" no
bad_input lookalike-http "$SUBDOMAIN" "$PID" "http://cb.test" no

PINNED="$(jq -r '.env.SIGNUP_ORIGIN // empty' "$WORK/wf.json")"
check "the workflow pins the sign-up origin as a literal top-level env value (not an input, not a variable)" \
  '[[ "$PINNED" =~ ^https://[a-z0-9.-]+$ ]]'
ONBOARDING_DIR="${ONBOARDING_DIR:-$REPO/../localnodes-onboarding}"
DISPATCHED="$(python3 -I -c '
import re, sys
src = open(sys.argv[1]).read()
start = src.index("export async function dispatchTeardownWorkflow")
end = src.find("\nexport ", start + 1)
body = src[start:end if end != -1 else len(src)]
hits = re.findall(r"callback_url:\s*([\x27\x22])([^\x27\x22]*)\1", body)
print(hits[0][1] if len(hits) == 1 else "")
' "$ONBOARDING_DIR/server/utils/github.ts" 2>/dev/null)"
check "contract: the pinned origin equals the callback_url the onboarding dispatcher sends ($ONBOARDING_DIR/server/utils/github.ts, dispatchTeardownWorkflow; set ONBOARDING_DIR if the checkout is elsewhere)" \
  '[ -n "$DISPATCHED" ] && [ "$PINNED" = "$DISPATCHED" ]'
reset_state; set_inputs "$SUBDOMAIN" "$PID" "$CB"; set_secrets "$COOLIFY_SECRET" "$SECRET"; set_apps "$TAGGED"
run_all pinned-ok ok
check "the pinned origin itself still works: confirmed, deleted, result deleted posted to it" \
  '[ "$RUN_RC" -eq 0 ] && [ "$(result_word)" = deleted ] && grep -qx "GET $CB/api/teardown-check?provision_id=$PID Bearer $TOKEN" "$WORK/state/auth.log"'

# ---------------------------------------------------------------------------------------------
# Task 3: the workflow's surface, read from the file (the way provision-workflow.test.sh does)
echo "# surface"
check "at least one ::add-mask:: line" '[ "$(grep -c "::add-mask::" "$WF")" -ge 1 ]'
mask_before_curl() {  # mask_before_curl STEP -> the first add-mask line comes before the first curl
  local script m c
  script="$(step_script "$1")" || return 1
  m="$(grep -n "add-mask" <<<"$script" | head -1 | cut -d: -f1)"
  c="$(grep -n "curl " <<<"$script" | head -1 | cut -d: -f1)"
  [ -n "$m" ] && [ -n "$c" ] && [ "$m" -lt "$c" ]
}
check "  ...in the confirmation step the token mask comes before the first curl" 'mask_before_curl "Confirm with the sign-up app"'
check "  ...and in the report step the derived token's mask comes before its curl" 'mask_before_curl "Report the result"'
# Every curl in every run: block, continuation lines joined: Coolify calls use no -X or -X
# DELETE and never name deploy, start, stop or restart; a POST, PATCH or PUT goes only to the
# sign-up app's teardown-result; no curl names any other host.
CURL_BREACHES="$(python3 -I -c '
import json, re, sys
wf = json.load(open(sys.argv[1]))
for step in wf["jobs"]["teardown"]["steps"]:
    run = re.sub(r"\\\n\s*", " ", step.get("run", ""))
    for line in run.split("\n"):
        if not re.search(r"\bcurl\b", line) or line.lstrip().startswith("#"):
            continue
        name = step["name"]
        x = re.search(r"-X\s+(\S+)", line)
        method = x.group(1) if x else "GET"
        if "$COOLIFY_API" in line:
            if method not in ("GET", "DELETE"):
                print(name + ": Coolify " + method)
            if re.search(r"deploy|/start|/stop|/restart", line):
                print(name + ": Coolify lifecycle URL")
        elif "$CALLBACK_URL_INPUT" in line:
            print(name + ": curl to the dispatch input callback_url (requests go to $SIGNUP_ORIGIN only)")
        elif "$SIGNUP_ORIGIN/api/teardown-" in line:
            if method != "GET" and "$SIGNUP_ORIGIN/api/teardown-result" not in line:
                print(name + ": " + method + " to the sign-up app outside teardown-result")
        else:
            print(name + ": curl to an unknown host")
' "$WORK/wf.json")"
check "Coolify is only read and deleted from in the file (no POST, PATCH or PUT; no deploy, /start, /stop); sign-up app calls go to \$SIGNUP_ORIGIN, never to the callback_url input" '[ -z "$CURL_BREACHES" ]'
[ -n "$CURL_BREACHES" ] && sed 's/^/#   /' <<<"$CURL_BREACHES"
CURL_LINES="$(python3 -I -c '
import json, re, sys
wf = json.load(open(sys.argv[1]))
n = t = c = 0
for step in wf["jobs"]["teardown"]["steps"]:
    run = re.sub(r"\\\n\s*", " ", step.get("run", ""))
    for line in run.split("\n"):
        if re.search(r"\bcurl\b", line) and not line.lstrip().startswith("#"):
            n += 1
            t += len(re.findall(r"--max-time\s+[0-9]+", line)) == 1
            c += len(re.findall(r"--connect-timeout\s+10\b", line)) == 1
print(n, t, c)
' "$WORK/wf.json")"
check "every curl in the file (7: confirm, list, re-read, re-check, DELETE, poll, report) carries one --max-time and --connect-timeout 10" \
  '[ "$CURL_LINES" = "7 7 7" ]'
check "  ...grep -c -- --max-time equals the non-comment curl lines" \
  '[ "$(grep -c -- "--max-time" "$WF")" = "$(grep -vE "^\s*#" "$WF" | grep -cE "\bcurl\b")" ]'
check "the second teardown-check sits in the delete step, after the tag re-read and before the DELETE" \
  'python3 -I -c "
import json, sys
wf = json.load(open(sys.argv[1]))
run = [s for s in wf[\"jobs\"][\"teardown\"][\"steps\"] if s[\"name\"] == \"Delete the app and its volumes\"][0][\"run\"]
r, c, d = run.find(\".description == \$tag\"), run.find(\"api/teardown-check\"), run.find(\"-X DELETE\")
sys.exit(0 if -1 < r < c < d else 1)
" "$WORK/wf.json"'
check "the DELETE asks for configurations, volumes, docker cleanup and networks (delete_volumes=true once)" \
  '[ "$(grep -c "delete_volumes=true" "$WF")" -eq 1 ] && grep -q "delete_configurations=true&delete_volumes=true&docker_cleanup=true&delete_connected_networks=true" "$WF"'
check "the workflow asks api/teardown-check and reports to api/teardown-result" \
  'grep -q "api/teardown-check" "$WF" && grep -q "api/teardown-result" "$WF"'
check "the tag is ln-provision:{provision_id}, the scheme provision-instance.yml sets at creation" \
  'grep -qF "TAG=ln-provision:\$PROVISION_ID_INPUT" "$WF" && grep -qF "\"ln-provision:\" + \$pid" "$REPO/.github/workflows/provision-instance.yml"'
check "no reason input and no personal-data input (the run page is public)" \
  '! grep -vE "^\s*#" "$WF" | grep -qE "^\s+(reason|email|site_name|stripe_session_id):"'
check "the job has a timeout and the Coolify token comes only from secrets.COOLIFY_API_TOKEN" \
  '[ "$(jq ".jobs.teardown[\"timeout-minutes\"]" "$WORK/wf.json")" = 15 ] && [ "$(jq -r ".jobs.teardown.env.COOLIFY_TOKEN" "$WORK/wf.json")" = "\${{ secrets.COOLIFY_API_TOKEN }}" ]'
check "no third-party action (plain curl, jq and openssl only)" \
  '[ "$(jq "[.jobs[].steps[] | has(\"uses\")] | any" "$WORK/wf.json")" = false ]'

# ---------------------------------------------------------------------------------------------
echo "# every scenario ($SCENARIOS_RUN run)"
check "no request left the fake (only coolify.test and cb.test were called)" '[ ! -s "$WORK/v-leak" ]'
check "the token, the shared secret and the Coolify token never reach requests.log, the output or the summary" '[ ! -s "$WORK/v-secret" ]'
check "no response body is echoed" '[ ! -s "$WORK/v-body" ]'
check "the bearer to cb.test is the per-provision token, the bearer to coolify.test the Coolify token" '[ ! -s "$WORK/v-auth" ]'
check "Coolify is only read (GET) and deleted from (DELETE); no deploy, start, stop or restart" '[ ! -s "$WORK/v-method" ]'
check "at most one result callback per run, and its body is {provision_id, result in deleted|gone|refused|failed}" '[ ! -s "$WORK/v-result" ]'
check "no input ran as shell" '[ ! -s "$WORK/v-inject" ]'
check "every request had --connect-timeout 10 and --max-time 30 (the delete polls --max-time 10)" '[ ! -s "$WORK/v-timeout" ]'
check "the runner modelled every if: and every \${{ }} in env: (no unknown form)" '[ ! -s "$WORK/v-harness" ]'
for v in leak secret body auth method result inject timeout harness; do
  [ -s "$WORK/v-$v" ] && sed 's/^/#   /' "$WORK/v-$v"
done

finish
