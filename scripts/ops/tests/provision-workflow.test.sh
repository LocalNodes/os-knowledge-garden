#!/usr/bin/env bash
# Tests for .github/workflows/provision-instance.yml (PR #26 review fixes).
#
# Each case extracts the real `run:` block of a workflow step (via Ruby's YAML parser) and
# runs it as GitHub does (bash --noprofile --norc -eo pipefail) with a fake `curl` and a
# no-op `sleep` on PATH. No network, no Coolify, no secrets.
#
# The workflow's pinned sign-up origin (top-level env SIGNUP_ORIGIN, https://localnodes.xyz) is
# replaced in every run by the fake's https://cb.test, the same way COOLIFY_API becomes
# coolify.test; teardown-workflow.test.sh does the same. The contract checks near the end read
# the real literal and compare it with teardown-instance.yml and with the callback_url that
# localnodes-onboarding's dispatcher sends (${ONBOARDING_DIR:-../localnodes-onboarding}).
#
# Usage: bash scripts/ops/tests/provision-workflow.test.sh
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
WF="${WF:-$REPO/.github/workflows/provision-instance.yml}"  # override to test another copy
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
ok()   { PASS=$((PASS + 1)); echo "ok   - $1"; }
nok()  { FAIL=$((FAIL + 1)); echo "FAIL - $1"; }
check() { if eval "$2"; then ok "$1"; else nok "$1"; fi; }

# Provision contract v1 fixtures (23-03 interfaces block)
PID="k3Jx9_Qm-2LpWv7RtYd4Nz"
SECRET="shared-callback-secret"
MANIFEST_SITE="Fern Hollow Commons"
MANIFEST_EMAIL="organizer@fernhollow.example"
# The four community texts (23-33) as base64url JSON, as the manifest carries them (23-34).
SEED_B64="eyJhYm91dCI6IkZlcm4gSG9sbG93IENvbW1vbnMgaXMgYSBuZWlnaGJvcmhvb2QgZ3JvdXAgdGhhdCBzaGFyZXMgdG9vbHMgYW5kIG1lYWxzLiIsImdyb3VwX2Rlc2NyaXB0aW9uIjoiTmVpZ2hib3JzIG9mIEZlcm4gSG9sbG93IHNoYXJpbmcgdG9vbHMsIG1lYWxzIGFuZCByZXBhaXIgZGF5cy4iLCJhc3Npc3RhbnRfY29udGV4dCI6IkZlcm4gSG9sbG93IENvbW1vbnMgaXMgYSBuZWlnaGJvcmhvb2QgZ3JvdXAgdGhhdCBzaGFyZXMgdG9vbHMgYW5kIHBsYW5zIHJlcGFpciBkYXlzLiIsIndlbGNvbWVfcG9zdCI6IldlbGNvbWUgdG8gRmVybiBIb2xsb3cgQ29tbW9ucy4gU2F5IGhlbGxvIGFuZCB0ZWxsIHVzIHdoYXQgeW91IGNhbiBsZW5kLiJ9"
TOKEN="$(printf '%s' "$PID" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)"

# Fake binaries
mkdir -p "$WORK/bin"
cp "$HERE/fake-curl.py" "$WORK/bin/curl"
chmod +x "$WORK/bin/curl"
printf '#!/bin/sh\nexit 0\n' > "$WORK/bin/sleep"
chmod +x "$WORK/bin/sleep"

# The workflow is converted to JSON once (Ruby or, where Ruby is missing, Python with PyYAML),
# and every query below is plain jq. YAML reads a bare `on:` key as boolean true, so it
# arrives as "true" in the JSON.
if command -v ruby >/dev/null 2>&1; then
  ruby -ryaml -rjson -e 'puts JSON.generate(YAML.load_file(ARGV[0]))' "$WF" > "$WORK/wf.json"
else
  python3 -c 'import json, sys, yaml; print(json.dumps(yaml.safe_load(open(sys.argv[1]))))' "$WF" > "$WORK/wf.json"
fi || { echo "FAIL - could not parse $WF as YAML (needs ruby, or python3 with PyYAML)"; exit 1; }

step_script() {  # step_script NAME -> the step's run: text on stdout, nonzero when there is no such step
  jq -j --arg n "$1" '[.jobs.provision.steps[] | select(.name == $n)] | if length == 0 then (("no step " + $n + "\n") | halt_error(1)) else .[0].run end' "$WORK/wf.json"
}

# A fresh Coolify app as the compose parse leaves it: compose defaults, one duplicate,
# and a preview copy of the admin password.
reset_state() {
  rm -rf "$WORK/state"; mkdir -p "$WORK/state"
  cat > "$WORK/state/envs.json" <<'JSON'
[
 {"uuid":"u1","key":"DEMO_MODULE","value":"localnodes_demo","is_preview":false},
 {"uuid":"u2","key":"DRUPAL_SITE_NAME","value":"LocalNodes","is_preview":false},
 {"uuid":"u3","key":"DRUPAL_ADMIN_PASS","value":"compose-default","is_preview":false},
 {"uuid":"u4","key":"IMAGE_TAG","value":"latest","is_preview":false},
 {"uuid":"u5","key":"DB_PASSWORD","value":"changeme","is_preview":false},
 {"uuid":"u6","key":"DB_ROOT_PASSWORD","value":"rootpassword","is_preview":false},
 {"uuid":"u7","key":"DRUPAL_HASH_SALT","value":"generate-random","is_preview":false},
 {"uuid":"u8","key":"GEMINI_API_KEY","value":"","is_preview":false},
 {"uuid":"u9","key":"DB_PASSWORD","value":"changeme","is_preview":false},
 {"uuid":"p3","key":"DRUPAL_ADMIN_PASS","value":"compose-default","is_preview":true}
]
JSON
  : > "$WORK/state/requests.log"
  : > "$WORK/state/bodies.log"
  : > "$WORK/state/auth.log"
  cat > "$WORK/state/manifest.json" <<JSON
{"provision_id":"$PID","subdomain":"demo","site_name":"$MANIFEST_SITE","organizer_email":"$MANIFEST_EMAIL",
 "topic_types_b64":"WyJHYXJkZW5zIl0","community_seed":"$SEED_B64","ai_provider":"gemini","ai_key_source":"platform"}
JSON
  : > "$WORK/github_env"
  : > "$WORK/summary.md"
}

# run_step STEP SCENARIO [NAME=VALUE ...] -> sets RC, OUT (stdout+stderr)
run_step() {
  local name="$1" scenario="$2"; shift 2
  step_script "$name" > "$WORK/step.sh" || { RC=99; OUT="extract failed"; return; }
  OUT="$(cd "$WORK" && env -i PATH="$WORK/bin:/usr/bin:/bin:/opt/homebrew/bin:/usr/local/bin" HOME="$WORK" \
    FAKE_STATE="$WORK/state" FAKE_SCENARIO="$scenario" \
    COOLIFY_API="http://coolify.test/api/v1" COOLIFY_TOKEN="test-token" SIGNUP_ORIGIN="https://cb.test" \
    COOLIFY_PROJECT="proj" COOLIFY_SERVER="srv" COOLIFY_ENV="production" \
    GIT_REPO="https://github.com/LocalNodes/os-knowledge-garden" GIT_BRANCH="main" \
    GITHUB_ENV="$WORK/github_env" GITHUB_STEP_SUMMARY="$WORK/summary.md" \
    APP_UUID="app123" SUBDOMAIN="demo" FQDN="demo.localnodes.xyz" DEPLOY_UUID="dep123" \
    "$@" bash --noprofile --norc -eo pipefail "$WORK/step.sh" 2>&1)"
  RC=$?
}

genv() {  # genv KEY -> the value the step wrote to GITHUB_ENV (KEY=value or KEY<<DELIM ... DELIM)
  awk -v k="$1" '
    d != "" { if ($0 == d) { d = ""; exit } ; v = v (v == "" ? "" : "\n") $0; next }
    index($0, k "<<") == 1 { d = substr($0, length(k) + 3); next }
    index($0, k "=") == 1 { print substr($0, length(k) + 2); exit }
    END { if (v != "") print v }' "$WORK/github_env"
}

stored() {  # stored KEY -> production value in the fake store
  K="$1" jq -r '[.[] | select(.key == env.K and (.is_preview | not))][0].value // ""' "$WORK/state/envs.json"
}

CONFIG_DEFAULTS=(DEMO_MODULE_INPUT=localnodes_demo SITE_NAME="Demo Commons" ORGANIZER_EMAIL=
  CALLBACK_URL_INPUT= IMAGE_TAG_INPUT=9b8e2a1
  GEMINI_API_KEY_SECRET=g RESEND_API_KEY_SECRET=r)

# The OpenRouter backup chat model (23-40, ONB-07). Both values are read, never typed, so this
# file names no model id. YML_BACKUP is the shipped localnodes_openrouter.settings
# backup_chat_model (empty when the key is null, empty or missing: no gate-passing backup);
# WF_BACKUP is the Configure step's env.OPENROUTER_BACKUP_MODEL (empty when absent).
# BACKUP_FIXTURE is a non-empty value for cases that need one whatever the gate ruled; the
# placeholder is not a real model id.
OR_SETTINGS="${OR_SETTINGS:-$REPO/html/modules/custom/localnodes_platform/modules/localnodes_openrouter/config/install/localnodes_openrouter.settings.yml}"
YML_READ=ok
if command -v ruby >/dev/null 2>&1; then
  YML_BACKUP="$(ruby -ryaml -e 'v = (YAML.load_file(ARGV[0]) || {})["backup_chat_model"]; print(v.nil? ? "" : v.to_s)' "$OR_SETTINGS")" || YML_READ=failed
else
  YML_BACKUP="$(python3 -c 'import sys, yaml; v = (yaml.safe_load(open(sys.argv[1])) or {}).get("backup_chat_model"); sys.stdout.write("" if v is None else str(v))' "$OR_SETTINGS")" || YML_READ=failed
fi
WF_BACKUP="$(jq -r '[.jobs.provision.steps[] | select(.name == "Configure environment variables")][0].env.OPENROUTER_BACKUP_MODEL // ""' "$WORK/wf.json")"
BACKUP_FIXTURE="${WF_BACKUP:-fixture/backup-model}"

INJECT='x$(touch '"$WORK"'/PWNED1)`touch '"$WORK"'/PWNED2`;touch '"$WORK"'/PWNED3'

echo "# static checks"
EXPR_RUNS="$(jq -r '.jobs[].steps[] | select((.run // "") | contains("${{")) | .name' "$WORK/wf.json")"
check "no \${{ }} expression inside any run: block" '[ -z "$EXPR_RUNS" ]'
ORDER="$(jq -r '
  [.jobs.provision.steps[] | .run // ""] as $r
  | (first(range(0; $r | length) | select($r[.] | contains("IMAGE_TAG_INPUT") and contains("{7,40}"))) // null) as $v
  | (first(range(0; $r | length) | select($r[.] | contains("/applications/public"))) // null) as $c
  | ([range(0; $r | length) | select($r[.] | test("-X (POST|PATCH|DELETE)|env_request"))] | min) as $m
  | if $v != null and $c != null and $m != null and $v < $c and $v < $m then "ok" else "bad v=\($v) c=\($c) m=\($m)" end' "$WORK/wf.json")"
check "image_tag is validated before the first Coolify mutation" '[ "$ORDER" = ok ]'
INPUT_KEYS="$(jq -r '(.["true"] // .on).workflow_dispatch.inputs | keys | join(" ")' "$WORK/wf.json")"
check "dispatch inputs are exactly the contract set (provision contract v1)" \
  '[ "$INPUT_KEYS" = "attempt callback_url demo_module gemini_key_tier image_tag provision_id subdomain" ]'
check "no organizer email, site name or Stripe session id input (D-05, Pitfall 8)" \
  '! grep -vE "^\s*#" "$WF" | grep -qE "^\s+(stripe_session_id|email|site_name):|inputs\.(stripe_session_id|email|site_name)"'
check "the manifest is fetched from the sign-up app" 'grep -q "api/provision-manifest" "$WF"'
check "at least 4 add-mask lines (admin password, token, and the manifest values)" '[ "$(grep -c "add-mask" "$WF")" -ge 4 ]'
check "the G8 gemini_key_tier step still exists" 'step_script "Check Gemini key tier (G8)" | grep -q "gemini_key_tier=paid"'
check "status callbacks are conditioned on provision_id, not the Stripe session" \
  '[ "$(jq -r "[.jobs.provision.steps[] | select(.name | startswith(\"Notify status\")) | .if] | map(contains(\"inputs.provision_id\")) | all" "$WORK/wf.json")" = true ]'

echo "# Validate inputs (P2)"
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=9B8E2A1
check "uppercase image_tag fails validation" '[ $RC -ne 0 ] && grep -q "Invalid image_tag" <<<"$OUT"'
check "  ...and nothing was sent to Coolify" '[ ! -s "$WORK/state/requests.log" ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=9b8e2a1
check "valid sha7 image_tag passes" '[ $RC -eq 0 ] && grep -qx "FQDN=demo.localnodes.xyz" "$WORK/github_env"'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=
check "empty image_tag passes (latest)" '[ $RC -eq 0 ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT="$(printf 'demo\nBASH_ENV=/tmp/x')" IMAGE_TAG_INPUT=
check "multi-line subdomain is rejected (no GITHUB_ENV injection)" '[ $RC -ne 0 ] && ! grep -q BASH_ENV "$WORK/github_env" && [ "$(cat "$WORK/github_env")" = "FAIL_REASON=invalid_request" ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT="$INJECT" IMAGE_TAG_INPUT=
check "injected subdomain is rejected and runs nothing" '[ $RC -ne 0 ] && ! ls "$WORK"/PWNED* >/dev/null 2>&1'

echo "# Validate inputs: provision_id and attempt"
VALID=(SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT= PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=1 CALLBACK_URL_INPUT=https://cb.test)
reset_state; run_step "Validate inputs" ok "${VALID[@]}"
check "a 22-character url-safe provision_id with attempt 1 passes" '[ $RC -eq 0 ]'
reset_state; run_step "Validate inputs" ok "${VALID[@]}" ATTEMPT_INPUT=2
check "attempt 2 passes" '[ $RC -eq 0 ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=
check "a manual run without provision_id (demo instances) still passes" '[ $RC -eq 0 ]'
for BAD in "short" "${PID}x" "k3Jx9_Qm-2LpWv7RtYd4N!" "$(printf 'k3Jx9_Qm-2LpWv7RtYd4N\nz')" "$INJECT"; do
  reset_state; run_step "Validate inputs" ok "${VALID[@]}" PROVISION_ID_INPUT="$BAD"
  check "provision_id '$(printf '%.12s' "$BAD" | tr '\n' ' ')...' is refused as invalid_request" '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=invalid_request" "$WORK/github_env" && ! ls "$WORK"/PWNED* >/dev/null 2>&1'
done
for BAD in 0 3 "" 12 "1 "; do
  reset_state; run_step "Validate inputs" ok "${VALID[@]}" ATTEMPT_INPUT="$BAD"
  check "attempt '$BAD' is refused as invalid_request" '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=invalid_request" "$WORK/github_env"'
done
reset_state; run_step "Validate inputs" ok "${VALID[@]}" CALLBACK_URL_INPUT=
check "a provision_id without a callback_url is refused" '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=invalid_request" "$WORK/github_env"'
reset_state; run_step "Validate inputs" ok "${VALID[@]}" CALLBACK_URL_INPUT=http://cb.test
check "a plain-http callback_url is refused (the bearer would travel in clear)" '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=invalid_request" "$WORK/github_env"'

echo "# Validate inputs: the pinned sign-up origin (23-43 Task 4; the teardown fix of 2026-11-09)"
# A well-formed https base URL that is not the pinned origin gets nothing: no request, no token
# (no mask line), no REPORT_OK, and so no failure notice either (a foreign host is not a safe
# place to report to, and the pinned origin did not dispatch this run). The runs use the pinned
# value as substituted above (https://cb.test).
for BAD in "https://evil.test" "https://cb.test.evil.test" "https://evilcb.test" "https://site.cb.test" \
           "https://cb.test:8443" "https://cb.test:443" "https://cb.test@evil.test" "https://cb.test." \
           "https://CB.TEST" "http://cb.test"; do
  reset_state; run_step "Validate inputs" ok "${VALID[@]}" CALLBACK_URL_INPUT="$BAD"
  check "callback_url $BAD is refused as invalid_request before any request, with no REPORT_OK and no mask line" \
    '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = invalid_request ] && [ -z "$(genv REPORT_OK)" ] && [ ! -s "$WORK/state/requests.log" ] && ! grep -q "add-mask" <<<"$OUT"'
  # The chain: the refused run's GITHUB_ENV goes to the failure notice, with the secret
  # available and no manifest token, exactly as GitHub would run it after this failure.
  run_step "Notify status - failed" ok CALLBACK_URL_INPUT="$BAD" PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=1 \
    CALLBACK_SECRET="$SECRET" FAIL_REASON="$(genv FAIL_REASON)" REPORT_OK="$(genv REPORT_OK)"
  check "  ...a refused callback_url gets no failure notice: nothing sent anywhere, no token derived (no mask line)" \
    '[ $RC -eq 0 ] && [ ! -s "$WORK/state/requests.log" ] && ! grep -q "add-mask" <<<"$OUT" && ! grep -qF -- "$TOKEN" <<<"$OUT"'
done
reset_state; run_step "Validate inputs" ok "${VALID[@]}"
check "the pinned origin itself passes and only then sets REPORT_OK=true" '[ $RC -eq 0 ] && [ "$(genv REPORT_OK)" = true ]'
reset_state; run_step "Validate inputs" ok "${VALID[@]}" SUBDOMAIN_INPUT=Bad_Name
check "a sign-up run refused for its subdomain is still reported (REPORT_OK set before the subdomain check)" \
  '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = invalid_request ] && [ "$(genv REPORT_OK)" = true ]'
reset_state; run_step "Validate inputs" ok "${VALID[@]}" PROVISION_ID_INPUT=short
check "a refused provision_id is never reported (no REPORT_OK)" '[ $RC -ne 0 ] && [ -z "$(genv REPORT_OK)" ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT= CALLBACK_URL_INPUT=https://evil.test
check "a manual run naming a foreign callback_url is refused too" '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = invalid_request ] && [ -z "$(genv REPORT_OK)" ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=
check "a manual run with neither provision_id nor callback_url passes as before and sets no REPORT_OK" '[ $RC -eq 0 ] && [ -z "$(genv REPORT_OK)" ]'

echo "# Fetch provision manifest (D-17, D-21)"
MF=(CALLBACK_URL_INPUT=https://cb.test PROVISION_ID_INPUT="$PID" CALLBACK_SECRET="$SECRET" SUBDOMAIN="demo")
reset_state; run_step "Fetch provision manifest" ok "${MF[@]}"
check "the manifest is fetched and the step passes" '[ $RC -eq 0 ] && grep -q "^GET https://cb.test/api/provision-manifest?provision_id=$PID " "$WORK/state/requests.log"'
check "  ...with the per-provision HMAC token, not the shared secret" \
  'grep -qx "GET https://cb.test/api/provision-manifest?provision_id=$PID Bearer $TOKEN" "$WORK/state/auth.log" && ! grep -qF -- "$SECRET" "$WORK/state/auth.log" && ! grep -qF -- "$SECRET" "$WORK/state/requests.log"'
check "  ...the token is masked, and appears in the log only on that line" \
  'grep -qx "::add-mask::$TOKEN" <<<"$OUT" && [ "$(grep -F -- "$TOKEN" <<<"$OUT" | grep -vc "^::add-mask::")" -eq 0 ]'
check "  ...the shared secret never reaches the log" '! grep -qF -- "$SECRET" <<<"$OUT"'
check "  ...the organizer email is masked and never printed otherwise" \
  'grep -qx "::add-mask::$MANIFEST_EMAIL" <<<"$OUT" && [ "$(grep -F -- "$MANIFEST_EMAIL" <<<"$OUT" | grep -vc "^::add-mask::")" -eq 0 ]'
check "  ...the site name is masked and never printed otherwise" \
  'grep -qx "::add-mask::$MANIFEST_SITE" <<<"$OUT" && [ "$(grep -F -- "$MANIFEST_SITE" <<<"$OUT" | grep -vc "^::add-mask::")" -eq 0 ]'
check "  ...SITE_NAME, ORGANIZER_EMAIL, TOPIC_TYPES_B64, AI_PROVIDER, AI_KEY_SOURCE and PROVISION_TOKEN reach GITHUB_ENV" \
  '[ "$(genv SITE_NAME)" = "$MANIFEST_SITE" ] && [ "$(genv ORGANIZER_EMAIL)" = "$MANIFEST_EMAIL" ] && [ "$(genv TOPIC_TYPES_B64)" = WyJHYXJkZW5zIl0 ] && [ "$(genv AI_PROVIDER)" = gemini ] && [ "$(genv AI_KEY_SOURCE)" = platform ] && [ "$(genv PROVISION_TOKEN)" = "$TOKEN" ]'
check "  ...a platform key leaves AI_KEY empty" '[ -z "$(genv AI_KEY)" ]'
check "  ...the community seed reaches GITHUB_ENV as COMMUNITY_SEED_B64, exactly as received (23-34)" \
  '[ "$(genv COMMUNITY_SEED_B64)" = "$SEED_B64" ]'
check "  ...the community seed is masked and never printed otherwise (23-34, T-23-96)" \
  'grep -qx "::add-mask::$SEED_B64" <<<"$OUT" && [ "$(grep -F -- "$SEED_B64" <<<"$OUT" | grep -vc "^::add-mask::")" -eq 0 ]'
MSCRIPT="$(step_script "Fetch provision manifest")"
mask_line() { grep -n -- "$1" <<<"$MSCRIPT" | head -1 | cut -d: -f1; }
check "  ...in the script every ::add-mask:: line comes before the first value is written to GITHUB_ENV" \
  '[ "$(grep -n "add-mask" <<<"$MSCRIPT" | tail -1 | cut -d: -f1)" -lt "$(mask_line "put_env SITE_NAME")" ]'
check "  ...the community seed's mask comes before it is written to GITHUB_ENV (23-34)" \
  '[ "$(mask_line "add-mask::\$COMMUNITY_SEED")" -lt "$(mask_line "put_env COMMUNITY_SEED_B64")" ]'
check "  ...and the token mask comes before the manifest request" '[ "$(mask_line "add-mask::\${TOKEN}")" -lt "$(mask_line "curl ")" ]'

reset_state; jq '. + {ai_provider:"openrouter", ai_key_source:"organizer", ai_key:"sk-or-v1-TESTKEY-0123456789"}' "$WORK/state/manifest.json" > "$WORK/m.json" && mv "$WORK/m.json" "$WORK/state/manifest.json"
run_step "Fetch provision manifest" ok "${MF[@]}"
check "an organizer's own key is masked, held in AI_KEY and never printed" \
  '[ $RC -eq 0 ] && grep -qx "::add-mask::sk-or-v1-TESTKEY-0123456789" <<<"$OUT" && [ "$(grep -F -- "sk-or-v1-TESTKEY" <<<"$OUT" | grep -vc "^::add-mask::")" -eq 0 ] && [ "$(genv AI_KEY)" = sk-or-v1-TESTKEY-0123456789 ] && [ "$(genv AI_PROVIDER)" = openrouter ] && [ "$(genv AI_KEY_SOURCE)" = organizer ]'

reset_state; run_step "Fetch provision manifest" manifest_401 "${MF[@]}"
check "a 401 from the sign-up app fails with manifest_failed" '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=manifest_failed" "$WORK/github_env"'
check "  ...and the response body never reaches the log" '! grep -q "SHOULD-NOT-LEAK" <<<"$OUT"'
reset_state; run_step "Fetch provision manifest" manifest_down "${MF[@]}"
check "an unreachable sign-up app fails with manifest_failed" '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=manifest_failed" "$WORK/github_env"'
reset_state; jq '.subdomain = "someone-else"' "$WORK/state/manifest.json" > "$WORK/m.json" && mv "$WORK/m.json" "$WORK/state/manifest.json"
run_step "Fetch provision manifest" ok "${MF[@]}"
check "a manifest for another subdomain is refused (manifest_failed), nothing masked or written" \
  '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=manifest_failed" "$WORK/github_env" && [ -z "$(genv SITE_NAME)" ]'
reset_state; jq '.provision_id = "AAAAAAAAAAAAAAAAAAAAAA"' "$WORK/state/manifest.json" > "$WORK/m.json" && mv "$WORK/m.json" "$WORK/state/manifest.json"
run_step "Fetch provision manifest" ok "${MF[@]}"
check "a manifest for another provision_id is refused (manifest_failed)" '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=manifest_failed" "$WORK/github_env"'
reset_state; jq --arg v "$(printf 'Fern\nBASH_ENV=/tmp/x')" '.site_name = $v' "$WORK/state/manifest.json" > "$WORK/m.json" && mv "$WORK/m.json" "$WORK/state/manifest.json"
run_step "Fetch provision manifest" ok "${MF[@]}"
check "a multi-line site name is refused (it would escape ::add-mask:: and GITHUB_ENV)" \
  '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=manifest_failed" "$WORK/github_env" && ! grep -q "BASH_ENV" "$WORK/github_env" && ! grep -q "BASH_ENV" <<<"$OUT"'
reset_state; jq '.site_name = "x$(touch '"$WORK"'/PWNED1)`touch '"$WORK"'/PWNED2`"' "$WORK/state/manifest.json" > "$WORK/m.json" && mv "$WORK/m.json" "$WORK/state/manifest.json"
run_step "Fetch provision manifest" ok "${MF[@]}"
check "a site name carrying shell syntax is data: runs nothing, stored verbatim" \
  '[ $RC -eq 0 ] && ! ls "$WORK"/PWNED* >/dev/null 2>&1 && grep -qF "touch" "$WORK/github_env"'
reset_state; run_step "Fetch provision manifest" ok "${MF[@]}" CALLBACK_URL_INPUT=https://evil.test
check "the manifest is read from the pinned origin even when the environment holds another callback_url" \
  '[ $RC -eq 0 ] && grep -q "^GET https://cb.test/api/provision-manifest?provision_id=$PID " "$WORK/state/requests.log" && ! grep -q "evil.test" "$WORK/state/requests.log" "$WORK/state/auth.log"'
reset_state; run_step "Fetch provision manifest" ok "${MF[@]}" CALLBACK_SECRET=
check "an empty callback secret fails with manifest_failed before any request" '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=manifest_failed" "$WORK/github_env" && [ ! -s "$WORK/state/requests.log" ]'

echo "# Fetch provision manifest: the community seed (23-34)"
for BADSEED in 'abc+def/ghi=' 'has space' 'ev"il' "$(printf 'a\nb')" "$(head -c 16385 /dev/zero | tr '\0' 'A')"; do
  reset_state; jq --arg v "$BADSEED" '.community_seed = $v' "$WORK/state/manifest.json" > "$WORK/m.json" && mv "$WORK/m.json" "$WORK/state/manifest.json"
  run_step "Fetch provision manifest" ok "${MF[@]}"
  check "a community_seed outside base64url or over 16384 characters ('$(printf '%.10s' "$BADSEED" | tr '\n' ' ')...') fails with manifest_failed and writes nothing" \
    '[ $RC -ne 0 ] && grep -qx "FAIL_REASON=manifest_failed" "$WORK/github_env" && [ -z "$(genv SITE_NAME)" ] && [ -z "$(genv COMMUNITY_SEED_B64)" ]'
done
reset_state; jq --arg v "$(head -c 16384 /dev/zero | tr '\0' 'A')" '.community_seed = $v' "$WORK/state/manifest.json" > "$WORK/m.json" && mv "$WORK/m.json" "$WORK/state/manifest.json"
run_step "Fetch provision manifest" ok "${MF[@]}"
check "a community_seed of exactly 16384 characters passes" '[ $RC -eq 0 ] && [ "$(genv COMMUNITY_SEED_B64 | wc -c)" -eq 16385 ]'
reset_state; jq 'del(.community_seed)' "$WORK/state/manifest.json" > "$WORK/m.json" && mv "$WORK/m.json" "$WORK/state/manifest.json"
run_step "Fetch provision manifest" ok "${MF[@]}"
check "a manifest without community_seed still passes, with an empty COMMUNITY_SEED_B64" '[ $RC -eq 0 ] && [ -z "$(genv COMMUNITY_SEED_B64)" ] && [ -n "$(genv SITE_NAME)" ]'

echo "# Create Coolify application"
reset_state; run_step "Create Coolify application" ok
check "create passes Coolify 4.1 path validation (leading-slash docker_compose_location)" '[ $RC -eq 0 ] && grep -qx "APP_UUID=app123" "$WORK/github_env"'
check "  ...and sends the compose path and domain as JSON" '[ "$(head -1 "$WORK/state/bodies.log" | jq -r ".docker_compose_location + \" \" + .docker_compose_domains[0].domain")" = "/docker-compose.yaml https://demo.localnodes.xyz" ]'

echo "# Configure environment variables: demo sender (MAIL_FROM)"
for DM in localnodes_demo boulder_demo portland_demo; do
  reset_state; run_step "Configure environment variables" ok "${CONFIG_DEFAULTS[@]}" DEMO_MODULE_INPUT=$DM
  check "$DM instance sends from noreply@demo.localnodes.xyz" '[ $RC -eq 0 ] && [ "$(stored MAIL_FROM)" = noreply@demo.localnodes.xyz ]'
done
reset_state; run_step "Configure environment variables" ok "${CONFIG_DEFAULTS[@]}" DEMO_MODULE_INPUT=none
check "blank instance (none) leaves MAIL_FROM unset (default localnodes.xyz sender)" '[ $RC -eq 0 ] && [ -z "$(stored MAIL_FROM)" ] && ! grep -q " MAIL_FROM$" "$WORK/state/requests.log"'
# The welcome mail is sent from Drupal since 23-12 (the entrypoint no longer mails with curl), so its
# sender is the site mail below; the entrypoint has no sender of its own to check.
INSTALL_PHP="$(dirname "$WF")/../../html/modules/custom/localnodes_platform/localnodes_platform.install"
check "site mail (system.site) reads MAIL_FROM" "grep -qF \"getenv('MAIL_FROM') ?: 'noreply@localnodes.xyz'\" \"\$INSTALL_PHP\""

echo "# Configure environment variables: update_env fails hard (P1 #2)"
reset_state; run_step "Configure environment variables" all503 "${CONFIG_DEFAULTS[@]}"
check "HTTP 503 on PATCH fails the step (Codex reproduction)" '[ $RC -ne 0 ] && grep -q "PATCH failed (503)" <<<"$OUT"'
check "  ...without falling back to POST" '! grep -q "^POST .*/envs " "$WORK/state/requests.log"'
check "  ...and stops at the first variable" '[ "$(grep -c "^PATCH" "$WORK/state/requests.log")" -eq 1 ]'
check "  ...and IMAGE_TAG is never written" '! grep -q " IMAGE_TAG$" "$WORK/state/requests.log"'

reset_state; run_step "Configure environment variables" post503 "${CONFIG_DEFAULTS[@]}" ORGANIZER_EMAIL=o@example.org
check "missing variable + POST 503 fails the step" '[ $RC -ne 0 ] && grep -q "POST failed (503)" <<<"$OUT"'

reset_state; run_step "Configure environment variables" app404 "${CONFIG_DEFAULTS[@]}"
check "404 that is not a missing variable fails without POST" '[ $RC -ne 0 ] && ! grep -q "^POST" "$WORK/state/requests.log"'

reset_state; run_step "Configure environment variables" post409_once "${CONFIG_DEFAULTS[@]}" ORGANIZER_EMAIL=o@example.org
check "409 from a concurrent create is retried and succeeds" '[ $RC -eq 0 ] && [ "$(stored ORGANIZER_EMAIL)" = o@example.org ]'

# DEMO_MODULE_INPUT=none keeps MAIL_FROM out, and the fixture store holds every key up to the
# organizer email (and no site name is set), so ORGANIZER_EMAIL is the first missing (POSTed)
# key
reset_state; run_step "Configure environment variables" post409_always "${CONFIG_DEFAULTS[@]}" ORGANIZER_EMAIL=o@example.org DEMO_MODULE_INPUT=none SITE_NAME=
check "persistent 409 fails after 3 bounded attempts" '[ $RC -ne 0 ] && grep -q "still conflicting after 3 attempts" <<<"$OUT" && [ "$(grep -c "^POST .* ORGANIZER_EMAIL$" "$WORK/state/requests.log")" -eq 3 ]'

echo "# Codex re-check: curl exit status and duplicate deletion"
reset_state; run_step "Configure environment variables" curl23 "${CONFIG_DEFAULTS[@]}"
check "curl exit 23 with HTTP 200 fails update_env" '[ $RC -ne 0 ] && grep -q "PATCH failed (curl-exit-23)" <<<"$OUT"'
check "  ...and nothing after the first variable is written" '[ "$(grep -c "^PATCH" "$WORK/state/requests.log")" -eq 1 ]'

reset_state; run_step "Configure environment variables" delete503 "${CONFIG_DEFAULTS[@]}"
check "DELETE 503 on a duplicate fails the step (Codex reproduction)" '[ $RC -ne 0 ] && grep -q "Deleting duplicate DB_PASSWORD (u9) failed (curl exit 0, HTTP 503)" <<<"$OUT"'
check "  ...without claiming the delete happened" '! grep -q "Deleted duplicate DB_PASSWORD" <<<"$OUT"'
check "  ...and before any value is written or deployed" '! grep -qE "^(PATCH|POST) " "$WORK/state/requests.log"'

reset_state; run_step "Configure environment variables" delete_curl23 "${CONFIG_DEFAULTS[@]}"
check "DELETE with HTTP 200 but curl exit 23 fails the step" '[ $RC -ne 0 ] && grep -q "failed (curl exit 23, HTTP 200)" <<<"$OUT"'

reset_state; run_step "Configure environment variables" delete_noop "${CONFIG_DEFAULTS[@]}"
check "DELETE that reports 200 but removes nothing is caught by read-back" '[ $RC -ne 0 ] && grep -q "Duplicate env vars remain after 3 cleanup rounds: DB_PASSWORD" <<<"$OUT"'
check "  ...after exactly 3 bounded rounds" '[ "$(grep -c "^DELETE .*/u9 " "$WORK/state/requests.log")" -eq 3 ]'

reset_state; run_step "Configure environment variables" delete_late "${CONFIG_DEFAULTS[@]}"
check "a third-round DELETE that succeeds passes (final read-back, Codex re-check 2)" '[ $RC -eq 0 ] && grep -q "Verified: no duplicates remain" <<<"$OUT" && [ "$(grep -c "^DELETE .*/u9 " "$WORK/state/requests.log")" -eq 3 ]'

echo "# Configure environment variables: read-back before deploy"
reset_state; run_step "Configure environment variables" ok "${CONFIG_DEFAULTS[@]}" SITE_NAME="$INJECT" ORGANIZER_EMAIL=o@example.org
PASSWD="$(stored DRUPAL_ADMIN_PASS)"
check "happy path succeeds and verifies both values" '[ $RC -eq 0 ] && grep -q "Verified DRUPAL_ADMIN_PASS" <<<"$OUT" && grep -q "Verified IMAGE_TAG" <<<"$OUT"'
check "  ...IMAGE_TAG pinned to 9b8e2a1" '[ "$(stored IMAGE_TAG)" = 9b8e2a1 ]'
check "  ...admin password is 32 random hex, not the compose default" '[[ "$PASSWD" =~ ^[0-9a-f]{32}$ ]]'
check "  ...password appears in output only on the ::add-mask:: line" '[ "$(grep -F -- "$PASSWD" <<<"$OUT" | grep -vc "^::add-mask::")" -eq 0 ]'
check "  ...password is never on a curl command line (requests.log)" '! grep -qF -- "$PASSWD" "$WORK/state/requests.log"'
check "injected site_name runs nothing" '! ls "$WORK"/PWNED* >/dev/null 2>&1'
check "  ...and is stored verbatim in DRUPAL_SITE_NAME and ORGANIZER_NAME" '[ "$(stored DRUPAL_SITE_NAME)" = "$INJECT" ] && [ "$(stored ORGANIZER_NAME)" = "$INJECT" ]'
check "  ...duplicate rows were removed and the removal verified" '[ "$(jq "[.[] | select(.key == \"DB_PASSWORD\")] | length" "$WORK/state/envs.json")" -eq 1 ] && grep -q "Verified: no duplicates remain" <<<"$OUT"'

reset_state; run_step "Configure environment variables" readback_wrong "${CONFIG_DEFAULTS[@]}"
check "IMAGE_TAG read back different from the pin fails" '[ $RC -ne 0 ] && grep -q "IMAGE_TAG: saved value does not match" <<<"$OUT"'

reset_state; run_step "Configure environment variables" dupes_after "${CONFIG_DEFAULTS[@]}"
PASSWD="$(jq -r '.[] | select(.uuid == "u3") | .value' "$WORK/state/envs.json" 2>/dev/null)"
check "password lost to dedup (stale row kept) fails the read-back" '[ $RC -ne 0 ] && grep -q "DRUPAL_ADMIN_PASS: saved value does not match" <<<"$OUT"'
check "  ...without printing any password" '! grep -qE "[0-9a-f]{32}" <<<"$(grep -v "^::add-mask::" <<<"$OUT")"'

reset_state; run_step "Configure environment variables" hidden "${CONFIG_DEFAULTS[@]}"
check "token without read:sensitive fails closed" '[ $RC -ne 0 ] && grep -q "needs read:sensitive" <<<"$OUT"'

echo "# Configure environment variables: sign-up values reach the container (D-12, D-17, D-20, D-21)"
KEYV="sk-or-v1-TESTKEY-0123456789"
GKEY="AIzaSyORGANIZER-GEMINI-KEY-0123456789"
TOPICS_B64="WyJHYXJkZW5zIl0"
# A sign-up run as "Fetch provision manifest" leaves it: the values arrive through GITHUB_ENV
SIGNUP=(DEMO_MODULE_INPUT=none CALLBACK_URL_INPUT=https://cb.test IMAGE_TAG_INPUT=9b8e2a1
  GEMINI_API_KEY_SECRET=platform-gemini-secret RESEND_API_KEY_SECRET=r GEMINI_KEY_TIER_INPUT=paid
  PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=1 PROVISION_TOKEN="$TOKEN"
  SITE_NAME="$MANIFEST_SITE" ORGANIZER_EMAIL="$MANIFEST_EMAIL" TOPIC_TYPES_B64="$TOPICS_B64" COMMUNITY_SEED_B64="$SEED_B64"
  AI_PROVIDER=gemini AI_KEY_SOURCE=platform PROVISION_CALLBACK_SECRET_VALUE="$SECRET")
flagged() {  # flagged KEY -> true when every body sent for KEY carried is_shown_once and is_literal true
  jq -s -e --arg k "$1" '[.[] | select(.key == $k)] | length > 0 and all(.is_shown_once == true and .is_literal == true)' "$WORK/state/bodies.log" >/dev/null
}
unflagged() {  # unflagged KEY -> true when KEY was sent and no body for it carried is_shown_once
  jq -s -e --arg k "$1" '[.[] | select(.key == $k)] | length > 0 and all((.is_shown_once // false) == false)' "$WORK/state/bodies.log" >/dev/null
}
written() { grep -qE "^(PATCH|POST) .*/envs $1$" "$WORK/state/requests.log"; }

reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}"
check "a sign-up run passes and sets PROVISION_ID, PROVISION_ATTEMPT and the per-provision PROVISION_CALLBACK_TOKEN" \
  '[ $RC -eq 0 ] && [ "$(stored PROVISION_ID)" = "$PID" ] && [ "$(stored PROVISION_ATTEMPT)" = 1 ] && [ "$(stored PROVISION_CALLBACK_TOKEN)" = "$TOKEN" ]'
check "  ...SITE_NAME, ORGANIZER_EMAIL, LOCALNODES_TOPIC_TYPES (the manifest value as is), AI_PROVIDER and GEMINI_KEY_TIER" \
  '[ "$(stored SITE_NAME)" = "$MANIFEST_SITE" ] && [ "$(stored ORGANIZER_EMAIL)" = "$MANIFEST_EMAIL" ] && [ "$(stored LOCALNODES_TOPIC_TYPES)" = "$TOPICS_B64" ] && [ "$(stored AI_PROVIDER)" = gemini ] && [ "$(stored GEMINI_KEY_TIER)" = paid ]'
check "  ...LOCALNODES_COMMUNITY_SEED is stored exactly as received, unflagged like the page types (23-34)" \
  '[ "$(stored LOCALNODES_COMMUNITY_SEED)" = "$SEED_B64" ] && ! grep -qF -- "$SEED_B64" <<<"$OUT" && ! grep -qF -- "$SEED_B64" "$WORK/state/requests.log"'
check "  ...a platform AI key keeps today's repository-secret GEMINI_API_KEY and sets no OpenRouter key" \
  '[ "$(stored GEMINI_API_KEY)" = platform-gemini-secret ] && ! written OPENROUTER_API_KEY'
check "  ...the shared callback secret is never written to the container" \
  '! written PROVISION_CALLBACK_SECRET && ! grep -qF -- "$SECRET" "$WORK/state/envs.json" "$WORK/state/bodies.log" "$WORK/state/requests.log" && [ "$(stored PROVISION_CALLBACK_SECRET)" = "" ]'
check "  ...the per-provision token is flagged is_shown_once and is_literal, and never printed" \
  'flagged PROVISION_CALLBACK_TOKEN && ! grep -qF -- "$TOKEN" <<<"$OUT" && ! grep -qF -- "$TOKEN" "$WORK/state/requests.log"'
check "  ...the token is verified by presence, not by value (the API hides write-once values)" \
  'grep -q "Verified PROVISION_CALLBACK_TOKEN" <<<"$OUT"'
check "  ...PROVISION_CALLBACK_URL is the pinned origin" '[ "$(stored PROVISION_CALLBACK_URL)" = https://cb.test ]'
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" CALLBACK_URL_INPUT=https://evil.test
check "a sign-up run writes PROVISION_CALLBACK_URL from the pin, never from the raw callback_url input" \
  '[ $RC -eq 0 ] && [ "$(stored PROVISION_CALLBACK_URL)" = https://cb.test ] && ! grep -qF "evil.test" "$WORK/state/envs.json" "$WORK/state/bodies.log"'
check "  ...and the manifest ack goes to the pinned origin, nothing to the input" \
  'grep -qx "POST https://cb.test/api/provision-manifest/ack -" "$WORK/state/requests.log" && ! grep -q "evil.test" "$WORK/state/requests.log" "$WORK/state/auth.log"'
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" CALLBACK_URL_INPUT=
check "a sign-up run gets PROVISION_CALLBACK_URL and the ack from the pin even with no callback_url in its environment" \
  '[ $RC -eq 0 ] && [ "$(stored PROVISION_CALLBACK_URL)" = https://cb.test ] && grep -qx "POST https://cb.test/api/provision-manifest/ack -" "$WORK/state/requests.log"'
reset_state; run_step "Configure environment variables" ok "${CONFIG_DEFAULTS[@]}" CALLBACK_URL_INPUT=https://cb.test
check "a manual run never gets PROVISION_CALLBACK_URL, even with a callback_url" '[ $RC -eq 0 ] && ! written PROVISION_CALLBACK_URL'

# The real Configure step always carries OPENROUTER_BACKUP_MODEL in its env map, so it is
# passed here too: a manual run must write no backup even when the workflow holds one (23-40).
reset_state; run_step "Configure environment variables" ok "${CONFIG_DEFAULTS[@]}" OPENROUTER_BACKUP_MODEL="$BACKUP_FIXTURE"
check "a manual demo run sets no sign-up values and makes no ack call" \
  '[ $RC -eq 0 ] && ! written PROVISION_ID && ! written PROVISION_ATTEMPT && ! written PROVISION_CALLBACK_TOKEN && ! written LOCALNODES_TOPIC_TYPES && ! written LOCALNODES_COMMUNITY_SEED && ! written AI_PROVIDER && ! grep -q "provision-manifest/ack" "$WORK/state/requests.log"'
check "  ...and writes neither AI_BACKUP_PROVIDER nor AI_BACKUP_MODEL (23-40)" \
  '! written AI_BACKUP_PROVIDER && ! written AI_BACKUP_MODEL'
check "  ...and keeps today's platform GEMINI_API_KEY" '[ "$(stored GEMINI_API_KEY)" = g ]'

echo "# Configure environment variables: an organizer's own key is write-once (D-21, Pitfall 13)"
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" AI_PROVIDER=openrouter AI_KEY_SOURCE=organizer AI_KEY="$KEYV"
check "an own OpenRouter key is stored in OPENROUTER_API_KEY with is_shown_once and is_literal" \
  '[ $RC -eq 0 ] && [ "$(stored OPENROUTER_API_KEY)" = "$KEYV" ] && flagged OPENROUTER_API_KEY && [ "$(stored AI_PROVIDER)" = openrouter ]'
check "  ...the key never reaches the log or a curl command line" '! grep -qF -- "$KEYV" <<<"$OUT" && ! grep -qF -- "$KEYV" "$WORK/state/requests.log"'
# harness self-check: the fake hides a write-once value on read, as Coolify does, so a value
# comparison on such a key could never pass and only a presence check can
HIDDEN_READ="$(PATH="$WORK/bin:$PATH" FAKE_STATE="$WORK/state" FAKE_SCENARIO=ok curl -s http://coolify.test/api/v1/applications/app123/envs | jq -c '[.[] | select(.key == "OPENROUTER_API_KEY")][0] | has("value")')"
check "  ...it is verified by presence only (the fake read hides write-once values, as Coolify does)" \
  '[ "$HIDDEN_READ" = false ] && grep -q "Verified OPENROUTER_API_KEY" <<<"$OUT"'
check "  ...the platform Gemini key is not sent for an OpenRouter site" '! grep -qF -- "platform-gemini-secret" "$WORK/state/bodies.log" && [ -z "$(stored GEMINI_API_KEY)" ]'
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" AI_PROVIDER=gemini AI_KEY_SOURCE=organizer AI_KEY="$GKEY"
check "an own Gemini key goes to GEMINI_API_KEY with is_shown_once and is_literal; the platform key is not used" \
  '[ $RC -eq 0 ] && [ "$(stored GEMINI_API_KEY)" = "$GKEY" ] && flagged GEMINI_API_KEY && ! grep -qF -- "platform-gemini-secret" "$WORK/state/bodies.log" && ! grep -qF -- "$GKEY" <<<"$OUT" && ! grep -qF -- "$GKEY" "$WORK/state/requests.log" && ! written OPENROUTER_API_KEY'
check "  ...and it is verified by presence only" 'grep -q "Verified GEMINI_API_KEY" <<<"$OUT"'
# Retry after the ack: the manifest still says organizer but no longer carries the key, and the
# reused app already holds it, so nothing is written for it and it must still be there.
reset_state
jq '. + [{"uuid":"k1","key":"OPENROUTER_API_KEY","is_preview":false,"is_shown_once":true,"is_literal":true,"value":"kept"}]' "$WORK/state/envs.json" > "$WORK/e.json" && mv "$WORK/e.json" "$WORK/state/envs.json"
run_step "Configure environment variables" ok "${SIGNUP[@]}" ATTEMPT_INPUT=2 AI_PROVIDER=openrouter AI_KEY_SOURCE=organizer AI_KEY=
check "a retry with no key in the manifest keeps the key the reused app holds and does not write it again" \
  '[ $RC -eq 0 ] && ! written OPENROUTER_API_KEY && [ "$(stored OPENROUTER_API_KEY)" = kept ] && grep -q "Verified OPENROUTER_API_KEY" <<<"$OUT"'
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" AI_PROVIDER=openrouter AI_KEY_SOURCE=organizer AI_KEY=
check "an organizer site with no key in the manifest and none on the app fails with env_failed, and no ack is sent" \
  '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = env_failed ] && ! grep -q "provision-manifest/ack" "$WORK/state/requests.log"'
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" AI_PROVIDER=openrouter AI_KEY_SOURCE=platform
check "OpenRouter with a platform key source is refused before anything is written" \
  '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = env_failed ] && ! grep -qE "^(PATCH|POST) " "$WORK/state/requests.log"'

echo "# Configure environment variables: the sealed key is told to go away after the read-back (D-21)"
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" AI_PROVIDER=openrouter AI_KEY_SOURCE=organizer AI_KEY="$KEYV"
check "after the read-back the workflow POSTs the manifest ack with the per-provision token and only the provision id" \
  '[ $RC -eq 0 ] && grep -qx "POST https://cb.test/api/provision-manifest/ack -" "$WORK/state/requests.log" && grep -qx "POST https://cb.test/api/provision-manifest/ack Bearer $TOKEN" "$WORK/state/auth.log" && [ "$(tail -1 "$WORK/state/bodies.log" | jq -cS .)" = "{\"provision_id\":\"$PID\"}" ]'
ACK_LINE="$(grep -n "provision-manifest/ack" "$WORK/state/requests.log" | head -1 | cut -d: -f1)"
LAST_READ="$(grep -n "^GET .*/envs " "$WORK/state/requests.log" | tail -1 | cut -d: -f1)"
check "  ...the ack comes after the last env read-back, and the log says verified before acked" \
  '[ -n "$ACK_LINE" ] && [ -n "$LAST_READ" ] && [ "$ACK_LINE" -gt "$LAST_READ" ] && [ "$(grep -n "Verified OPENROUTER_API_KEY" <<<"$OUT" | cut -d: -f1)" -lt "$(grep -n "Manifest ack" <<<"$OUT" | cut -d: -f1)" ]'
check "  ...the ack is the last request of the step" '[ "$(tail -1 "$WORK/state/requests.log" | cut -d" " -f1,2)" = "POST https://cb.test/api/provision-manifest/ack" ]'
reset_state; run_step "Configure environment variables" readback_wrong "${SIGNUP[@]}"
check "a failed read-back sends no ack (the sealed key stays until the env is known good)" \
  '[ $RC -ne 0 ] && ! grep -q "provision-manifest/ack" "$WORK/state/requests.log"'
reset_state; run_step "Configure environment variables" ack_down "${SIGNUP[@]}"
check "an unreachable sign-up app at ack time is a warning, not a failure" '[ $RC -eq 0 ] && grep -q "ack failed (non-fatal)" <<<"$OUT"'
reset_state; run_step "Configure environment variables" ack_401 "${SIGNUP[@]}"
check "a 401 on the ack is logged as a status only, and the body never reaches the log" \
  '[ $RC -eq 0 ] && grep -q "Manifest ack: HTTP 401" <<<"$OUT" && ! grep -q "SHOULD-NOT-LEAK" <<<"$OUT"'

echo "# Configure environment variables: the backup chat model (23-40, ONB-07)"
# Orchestrator answer 91ab2f6: only an OpenRouter site gets a backup, always on its own provider
# (23-10 refuses a backup on another one), and its model is the shipped backup_chat_model or
# nothing. A Gemini site and a manual demo run get none.
check "the shipped localnodes_openrouter.settings.yml was read" '[ "$YML_READ" = ok ]'
check "the Configure step carries OPENROUTER_BACKUP_MODEL in its env map" \
  '[ "$(jq -r "[.jobs.provision.steps[] | select(.name == \"Configure environment variables\")][0].env | has(\"OPENROUTER_BACKUP_MODEL\")" "$WORK/wf.json")" = true ]'
check "the workflow's OPENROUTER_BACKUP_MODEL equals the shipped backup_chat_model (change both together)" \
  '[ "$WF_BACKUP" = "$YML_BACKUP" ]'
OR_SIGNUP=("${SIGNUP[@]}" AI_PROVIDER=openrouter AI_KEY_SOURCE=organizer AI_KEY="$KEYV")
reset_state; run_step "Configure environment variables" ok "${OR_SIGNUP[@]}" OPENROUTER_BACKUP_MODEL="$WF_BACKUP"
if [ -n "$YML_BACKUP" ]; then
  check "an OpenRouter sign-up stores AI_BACKUP_PROVIDER=openrouter and AI_BACKUP_MODEL equal to the shipped backup" \
    '[ $RC -eq 0 ] && [ "$(stored AI_BACKUP_PROVIDER)" = openrouter ] && [ -n "$WF_BACKUP" ] && [ "$(stored AI_BACKUP_MODEL)" = "$YML_BACKUP" ]'
  check "  ...neither is sent write-once (no is_shown_once flag): both are read back by value" \
    'unflagged AI_BACKUP_PROVIDER && unflagged AI_BACKUP_MODEL'
  check "  ...both are verified by value before the deploy" \
    'grep -q "Verified AI_BACKUP_PROVIDER$" <<<"$OUT" && grep -q "Verified AI_BACKUP_MODEL$" <<<"$OUT"'
  check "  ...and before the manifest ack" \
    '[ -n "$(grep -n "Manifest ack" <<<"$OUT")" ] && [ "$(grep -n "Verified AI_BACKUP_MODEL" <<<"$OUT" | cut -d: -f1)" -lt "$(grep -n "Manifest ack" <<<"$OUT" | cut -d: -f1)" ]'
else
  check "the distribution ships no OpenRouter backup, so an OpenRouter sign-up writes neither AI_BACKUP variable" \
    '[ $RC -eq 0 ] && ! written AI_BACKUP_PROVIDER && ! written AI_BACKUP_MODEL'
fi
reset_state; run_step "Configure environment variables" ok "${OR_SIGNUP[@]}" OPENROUTER_BACKUP_MODEL=
check "an OpenRouter sign-up with an empty OPENROUTER_BACKUP_MODEL passes and writes neither AI_BACKUP variable" \
  '[ $RC -eq 0 ] && ! written AI_BACKUP_PROVIDER && ! written AI_BACKUP_MODEL'
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" OPENROUTER_BACKUP_MODEL="$BACKUP_FIXTURE"
check "a Gemini sign-up on the platform key passes and writes neither AI_BACKUP variable" \
  '[ $RC -eq 0 ] && [ "$(stored AI_PROVIDER)" = gemini ] && ! written AI_BACKUP_PROVIDER && ! written AI_BACKUP_MODEL'
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" AI_KEY_SOURCE=organizer AI_KEY="$GKEY" OPENROUTER_BACKUP_MODEL="$BACKUP_FIXTURE"
check "a Gemini sign-up on the organizer's own key passes and writes neither AI_BACKUP variable" \
  '[ $RC -eq 0 ] && [ "$(stored GEMINI_API_KEY)" = "$GKEY" ] && ! written AI_BACKUP_PROVIDER && ! written AI_BACKUP_MODEL'
reset_state; run_step "Configure environment variables" readback_wrong_backup "${OR_SIGNUP[@]}" OPENROUTER_BACKUP_MODEL="$BACKUP_FIXTURE"
check "an AI_BACKUP_MODEL read back different from what was sent fails with env_failed, and no ack is sent" \
  '[ $RC -ne 0 ] && grep -q "AI_BACKUP_MODEL: saved value does not match" <<<"$OUT" && [ "$(genv FAIL_REASON)" = env_failed ] && ! grep -q "provision-manifest/ack" "$WORK/state/requests.log"'

echo "# Static checks: secret keys and the shared secret"
UPDATE_NAMES="$(grep -oE 'update_env "[A-Z0-9_]+"' "$WF" | cut -d'"' -f2 | sort -u)"
check "update_env sends the secret flag as is_shown_once and is_literal" 'grep -q "is_shown_once" "$WF" && grep -q "is_literal" "$WF"'
check "the workflow never calls update_env for the shared callback secret" '! grep -qx PROVISION_CALLBACK_SECRET <<<"$UPDATE_NAMES"'
check "the Configure step no longer reads the shared secret" '! step_script "Configure environment variables" | grep -q PROVISION_CALLBACK_SECRET'
check "every Phase 23 container name is set by update_env" \
  '[ -z "$(for n in PROVISION_ID PROVISION_ATTEMPT PROVISION_CALLBACK_TOKEN SITE_NAME ORGANIZER_EMAIL LOCALNODES_TOPIC_TYPES LOCALNODES_COMMUNITY_SEED AI_PROVIDER GEMINI_KEY_TIER GEMINI_API_KEY OPENROUTER_API_KEY AI_BACKUP_PROVIDER AI_BACKUP_MODEL; do grep -qx "$n" <<<"$UPDATE_NAMES" || echo "$n"; done)" ]'

echo "# Retry reuses its own half-made app (D-15, D-18, Pitfall 5)"
RETRY=(PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=2)
reset_state; run_step "Check subdomain availability" reuse "${RETRY[@]}"
check "attempt 2 with an app tagged ln-provision:{provision_id} on the domain reuses it (APP_UUID, REUSE=true)" \
  '[ $RC -eq 0 ] && [ "$(genv APP_UUID)" = half123 ] && [ "$(genv REUSE)" = true ] && ! grep -q "FAIL_REASON" "$WORK/github_env"'
reset_state; run_step "Check subdomain availability" foreign "${RETRY[@]}"
check "attempt 2 with another provision's app on the domain fails with subdomain_taken and reuses nothing" \
  '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = subdomain_taken ] && [ -z "$(genv REUSE)" ] && [ -z "$(genv APP_UUID)" ]'
reset_state; run_step "Check subdomain availability" taken "${RETRY[@]}"
check "attempt 2 with an untagged app on the domain fails with subdomain_taken" '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = subdomain_taken ] && [ -z "$(genv REUSE)" ]'
reset_state; run_step "Check subdomain availability" reuse PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=1
check "attempt 1 with a tagged app on the domain still fails with subdomain_taken" '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = subdomain_taken ] && [ -z "$(genv REUSE)" ]'
reset_state; run_step "Check subdomain availability" reuse PROVISION_ID_INPUT= ATTEMPT_INPUT=2
check "a manual run (no provision_id) never reuses an app" '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = subdomain_taken ] && [ -z "$(genv REUSE)" ]'
reset_state; run_step "Check subdomain availability" reuse_dup "${RETRY[@]}"
check "two apps on the domain are a collision even when one is tagged for this provision" \
  '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = subdomain_taken ] && [ -z "$(genv REUSE)" ]'
reset_state; run_step "Check subdomain availability" ok "${RETRY[@]}"
check "attempt 2 with nothing on the domain passes and does not set REUSE" '[ $RC -eq 0 ] && [ -z "$(genv REUSE)" ] && grep -q "is available" <<<"$OUT"'
reset_state; run_step "Check subdomain availability" list_garbage "${RETRY[@]}"
check "an unreadable application list fails with coolify_unreachable (it must not read as free)" '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = coolify_unreachable ]'
check "  ...no application or app uuid text from Coolify reaches the log on a reuse" \
  'reset_state; run_step "Check subdomain availability" reuse "${RETRY[@]}"; ! grep -q "ln-provision" <<<"$OUT"'

reset_state; run_step "Create Coolify application" ok PROVISION_ID_INPUT="$PID"
check "create tags the app with the description ln-provision:{provision_id}" \
  '[ $RC -eq 0 ] && [ "$(head -1 "$WORK/state/bodies.log" | jq -r .description)" = "ln-provision:$PID" ]'
reset_state; run_step "Create Coolify application" ok
check "a manual run's app gets no description" '[ $RC -eq 0 ] && ! head -1 "$WORK/state/bodies.log" | jq -e "has(\"description\")" >/dev/null'
check "the create step is skipped when REUSE is true" \
  '[ "$(jq -r "[.jobs.provision.steps[] | select(.name == \"Create Coolify application\") | .if] | .[0]" "$WORK/wf.json")" = "env.REUSE != '"'"'true'"'"'" ]'
reset_state; run_step "Deploy instance" ok REUSE=true APP_UUID=half123
check "a reused app is redeployed with force=true" \
  '[ $RC -eq 0 ] && grep -qx "POST http://coolify.test/api/v1/deploy?uuid=half123&force=true -" "$WORK/state/requests.log"'
DELETES="$(grep -vE '^\s*#' "$WF" | grep -E -- '(-X *|--request +)DELETE')"
check "the workflow never deletes an application (its only DELETE calls are env-var rows under \$ENVS_URL)" \
  '[ -n "$DELETES" ] && [ -z "$(grep -v "ENVS_URL" <<<"$DELETES")" ]'

echo "# A reused app keeps its generated secrets (its database already holds them)"
# seed_old_secrets: the app as a first attempt left it, with real values the database was created with
seed_old_secrets() {
  reset_state
  jq 'map(if .key == "DB_PASSWORD" then .value = "olddbpass" elif .key == "DB_ROOT_PASSWORD" then .value = "oldrootpass"
            elif .key == "DRUPAL_HASH_SALT" then .value = "oldsalt" elif .key == "DRUPAL_ADMIN_PASS" and (.is_preview | not) then .value = "oldadminpass" else . end)' \
    "$WORK/state/envs.json" > "$WORK/e.json" && mv "$WORK/e.json" "$WORK/state/envs.json"
}
seed_old_secrets; run_step "Configure environment variables" ok "${SIGNUP[@]}" REUSE=true ATTEMPT_INPUT=2
check "on a reused app the database passwords, hash salt and admin password it already holds are kept" \
  '[ $RC -eq 0 ] && [ "$(stored DB_PASSWORD)" = olddbpass ] && [ "$(stored DB_ROOT_PASSWORD)" = oldrootpass ] && [ "$(stored DRUPAL_HASH_SALT)" = oldsalt ] && [ "$(stored DRUPAL_ADMIN_PASS)" = oldadminpass ]'
sent_only() {  # sent_only KEY VALUE -> every body sent for KEY (and there is one) carries exactly VALUE
  jq -s -e --arg k "$1" --arg v "$2" '[.[] | select(.key == $k)] | length > 0 and all(.value == $v)' "$WORK/state/bodies.log" >/dev/null
}
check "  ...and the only values sent for them are the held ones (no new secret is ever offered to the app)" \
  'sent_only DB_PASSWORD olddbpass && sent_only DB_ROOT_PASSWORD oldrootpass && sent_only DRUPAL_HASH_SALT oldsalt && sent_only DRUPAL_ADMIN_PASS oldadminpass'
check "  ...a kept secret is masked and never printed" \
  '[ "$(grep -F -e olddbpass -e oldrootpass -e oldadminpass -e oldsalt <<<"$OUT" | grep -vc "^::add-mask::")" -eq 0 ]'
check "  ...the admin password read-back still passes (it checks the kept value)" 'grep -q "Verified DRUPAL_ADMIN_PASS" <<<"$OUT"'
reset_state; run_step "Configure environment variables" ok "${SIGNUP[@]}" REUSE=true ATTEMPT_INPUT=2
check "a reused app whose secrets are still the compose defaults gets fresh ones" \
  '[ $RC -eq 0 ] && [[ "$(stored DB_PASSWORD)" =~ ^[0-9a-f]{32}$ ]] && [[ "$(stored DRUPAL_HASH_SALT)" =~ ^[0-9a-f]{64}$ ]]'
seed_old_secrets; run_step "Configure environment variables" ok "${SIGNUP[@]}"
check "a fresh app (no REUSE) always gets newly generated secrets" \
  '[ $RC -eq 0 ] && [[ "$(stored DB_PASSWORD)" =~ ^[0-9a-f]{32}$ ]] && [[ "$(stored DRUPAL_ADMIN_PASS)" =~ ^[0-9a-f]{32}$ ]]'

echo "# Deadlines fail the run (Codex re-check 2)"
reset_state; run_step "Wait for deployment" ok
check "deployment that finishes passes" '[ $RC -eq 0 ] && grep -q "Deployment complete" <<<"$OUT"'
reset_state; run_step "Wait for deployment" deploy_stuck
check "deployment still in progress at the deadline fails" '[ $RC -ne 0 ] && grep -q "did not finish within 5 min (last status: in_progress)" <<<"$OUT" && [ "$(grep -c "/deployments/" "$WORK/state/requests.log")" -eq 30 ]'
reset_state; run_step "Wait for deployment" deploy_garbage
check "unreadable deployment status at the deadline fails" '[ $RC -ne 0 ] && grep -q "last status: unknown" <<<"$OUT"'
reset_state; run_step "Wait for site to become healthy" ok
check "site answering 200 passes" '[ $RC -eq 0 ] && grep -qx "HTTP_CODE=200" "$WORK/github_env"'
reset_state; run_step "Wait for site to become healthy" site503
check "site still 503 at the deadline fails" '[ $RC -ne 0 ] && grep -q "Site not healthy after 10 min (last HTTP 503" <<<"$OUT" && [ "$(grep -c "/user/login" "$WORK/state/requests.log")" -eq 60 ]'

echo "# Summary and callbacks (P1 #1, D-05, D-17)"
reset_state; run_step "Summary" ok DEMO_MODULE_INPUT=localnodes_demo GEMINI_KEY_TIER_INPUT=paid SITE_NAME="$MANIFEST_SITE" ORGANIZER_EMAIL="$MANIFEST_EMAIL"
check "Summary step passes" '[ $RC -eq 0 ] && grep -q "Coolify UUID" "$WORK/summary.md"'
check "  ...the run Summary holds no organizer email and no community name (D-05)" \
  '! grep -qiE "fernhollow|Fern Hollow|@" "$WORK/summary.md"'
check "  ...and the workflow has no Organizer Email row at all" '! grep -q "Organizer Email" "$WF"'

# cb_run STEP [NAME=VALUE ...]: runs a Notify step with a valid provision and the per-provision token
cb_run() { local S="$1"; shift; reset_state; run_step "$S" ok CALLBACK_URL_INPUT=https://cb.test PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=2 \
  PROVISION_TOKEN="$TOKEN" CALLBACK_SECRET="$SECRET" "$@"; }
for S in "Notify status - provisioning" "Notify status - installing"; do
  ST="${S##* - }"
  cb_run "$S"
  check "$S: body is {provision_id, attempt 2 (a number), status $ST, site_url, source workflow}" \
    '[ $RC -eq 0 ] && [ "$(head -1 "$WORK/state/bodies.log" | jq -cS .)" = "{\"attempt\":2,\"provision_id\":\"$PID\",\"site_url\":\"https://demo.localnodes.xyz\",\"source\":\"workflow\",\"status\":\"$ST\"}" ]'
  check "  ...no email, session id or free-text error in the body" '! head -1 "$WORK/state/bodies.log" | jq -e "has(\"email\") or has(\"session_id\") or has(\"error\")" >/dev/null'
  check "  ...authenticated with the per-provision token, never the shared secret" \
    'grep -qx "POST https://cb.test/api/provision-callback Bearer $TOKEN" "$WORK/state/auth.log" && ! grep -qF -- "$SECRET" "$WORK/state/auth.log"'
  cb_run "$S" PROVISION_ID_INPUT="$INJECT" ATTEMPT_INPUT=1
  check "  ...an injected provision_id runs nothing and is sent as JSON data" \
    '[ $RC -eq 0 ] && ! ls "$WORK"/PWNED* >/dev/null 2>&1 && [ "$(head -1 "$WORK/state/bodies.log" | jq -r .provision_id)" = "$INJECT" ]'
  cb_run "$S" CALLBACK_URL_INPUT=https://evil.test
  check "  ...it posts to the pinned origin even when the environment holds another callback_url" \
    '[ $RC -eq 0 ] && grep -qx "POST https://cb.test/api/provision-callback -" "$WORK/state/requests.log" && ! grep -q "evil.test" "$WORK/state/requests.log" "$WORK/state/auth.log"'
  reset_state; run_step "$S" callback_down CALLBACK_URL_INPUT=https://cb.test PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=1 PROVISION_TOKEN="$TOKEN"
  check "  ...an unreachable sign-up app is a warning, not a failure" '[ $RC -eq 0 ] && grep -q "callback failed (non-fatal)" <<<"$OUT"'
done

echo "# Failure reasons (D-17): each failing step writes one code to FAIL_REASON"
check "the workflow writes at least 8 FAIL_REASON= lines" '[ "$(grep -c "FAIL_REASON=" "$WF")" -ge 8 ]'
# reason_case STEP SCENARIO EXPECTED [NAME=VALUE ...]: a forced failure leaves FAIL_REASON=EXPECTED
# (EXPECTED "none" = the step passes and writes no reason)
reason_case() {
  local step="$1" scenario="$2" want="$3"; shift 3
  reset_state; run_step "$step" "$scenario" "$@"
  if [ "$want" = none ]; then
    check "$step ($scenario) passes and sets no FAIL_REASON" '[ $RC -eq 0 ] && ! grep -q "FAIL_REASON" "$WORK/github_env"'
  else
    check "$step ($scenario) fails with FAIL_REASON=$want" '[ $RC -ne 0 ] && [ "$(genv FAIL_REASON)" = "$want" ]'
  fi
}
reason_case "Validate inputs" ok invalid_request SUBDOMAIN_INPUT=Bad_Name IMAGE_TAG_INPUT=
reason_case "Validate inputs" ok invalid_request SUBDOMAIN_INPUT=www IMAGE_TAG_INPUT=
reason_case "Validate inputs" ok invalid_request SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=ZZZ
reason_case "Fetch provision manifest" manifest_401 manifest_failed "${MF[@]}"
reason_case "Check subdomain availability" ok none
reason_case "Check subdomain availability" list503 coolify_unreachable
reason_case "Check subdomain availability" taken subdomain_taken
reason_case "Create Coolify application" ok none
reason_case "Create Coolify application" create_fail create_failed
reason_case "Configure environment variables" ok none "${CONFIG_DEFAULTS[@]}"
reason_case "Configure environment variables" all503 env_failed "${CONFIG_DEFAULTS[@]}"
reason_case "Configure environment variables" delete503 env_failed "${CONFIG_DEFAULTS[@]}"
reason_case "Configure environment variables" readback_wrong env_failed "${CONFIG_DEFAULTS[@]}"
reason_case "Deploy instance" ok none
reason_case "Deploy instance" deploy_fail deploy_failed
reason_case "Wait for deployment" ok none

echo "# Coolify error bodies stay out of the log (23-43 code review fix round 1, LOW)"
# A Coolify reply can carry anything (an echoed request, a token in a message), so a failing
# step logs the HTTP status and a fixed reason only, never the body.
check "no echo in any run: block prints a raw Coolify reply (\$RESPONSE)" \
  '[ "$(jq -r ".jobs.provision.steps[] | .run // empty" "$WORK/wf.json" | grep -cE "echo.*[$][{]?RESPONSE")" -eq 0 ]'
reset_state; run_step "Create Coolify application" create_fail
check "a failed create logs the HTTP status and a fixed reason, not the body" \
  '[ $RC -ne 0 ] && ! grep -q "SHOULD-NOT-LEAK" <<<"$OUT" && grep -qx "::error::Failed to create app (HTTP 200, no app uuid in the reply)" <<<"$OUT"'
reset_state; run_step "Deploy instance" deploy_fail
check "a failed deploy trigger logs the HTTP status and a fixed reason, not the body" \
  '[ $RC -ne 0 ] && ! grep -q "SHOULD-NOT-LEAK" <<<"$OUT" && grep -qx "::error::Failed to trigger deployment (HTTP 200, no deployment uuid in the reply)" <<<"$OUT"'
reason_case "Wait for deployment" deploy_cancelled deploy_failed
reason_case "Wait for deployment" deploy_stuck deploy_timeout
reason_case "Wait for deployment" deploy_garbage deploy_timeout
reason_case "Wait for site to become healthy" ok none
reason_case "Wait for site to become healthy" site503 install_timeout

echo "# Failure callback (D-17)"
# fail_cb [NAME=VALUE ...]: the failed-status step for sign-up 2, attempt 1, after a deploy timeout
# (REPORT_OK=true: "Validate inputs" accepted the provision_id and the callback_url)
fail_cb() { reset_state; run_step "Notify status - failed" ok CALLBACK_URL_INPUT=https://cb.test PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=1 \
  PROVISION_TOKEN="$TOKEN" CALLBACK_SECRET="$SECRET" FAIL_REASON=deploy_timeout APP_UUID=app123 REPORT_OK=true "$@"; }
fail_cb
check "the failure body is exactly {provision_id, attempt, status failed, reason, app_uuid, source workflow}" \
  '[ $RC -eq 0 ] && [ "$(head -1 "$WORK/state/bodies.log" | jq -cS .)" = "{\"app_uuid\":\"app123\",\"attempt\":1,\"provision_id\":\"$PID\",\"reason\":\"deploy_timeout\",\"source\":\"workflow\",\"status\":\"failed\"}" ]'
check "  ...it has no error field and no free text" '! head -1 "$WORK/state/bodies.log" | jq -e "has(\"error\") or has(\"message\") or has(\"session_id\")" >/dev/null && head -1 "$WORK/state/bodies.log" | jq -e ".reason | test(\"^[a-z_]+$\")" >/dev/null'
check "  ...it is authenticated with the per-provision token, not the shared secret" \
  'grep -qx "POST https://cb.test/api/provision-callback Bearer $TOKEN" "$WORK/state/auth.log" && ! grep -qF -- "$SECRET" "$WORK/state/auth.log"'
fail_cb APP_UUID=
check "an unknown app uuid is omitted from the body" '[ $RC -eq 0 ] && ! head -1 "$WORK/state/bodies.log" | jq -e "has(\"app_uuid\")" >/dev/null && [ "$(head -1 "$WORK/state/bodies.log" | jq -r .reason)" = deploy_timeout ]'
fail_cb FAIL_REASON=
check "a failure that set no reason is reported as unknown" '[ "$(head -1 "$WORK/state/bodies.log" | jq -r .reason)" = unknown ]'
fail_cb FAIL_REASON="Coolify said: boom <html>"
check "free text in FAIL_REASON is never sent (reported as unknown)" '[ "$(head -1 "$WORK/state/bodies.log" | jq -r .reason)" = unknown ] && ! grep -q "boom" "$WORK/state/bodies.log"'
fail_cb PROVISION_TOKEN=
check "without the manifest token it derives the same per-provision token from the secret" \
  '[ $RC -eq 0 ] && grep -qx "POST https://cb.test/api/provision-callback Bearer $TOKEN" "$WORK/state/auth.log" && grep -qx "::add-mask::$TOKEN" <<<"$OUT"'
fail_cb PROVISION_TOKEN= CALLBACK_SECRET=
check "without token or secret it sends nothing and does not fail the step" '[ $RC -eq 0 ] && [ ! -s "$WORK/state/requests.log" ]'
reset_state; run_step "Notify status - failed" callback_down CALLBACK_URL_INPUT=https://cb.test PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=1 PROVISION_TOKEN="$TOKEN" REPORT_OK=true
check "an unreachable sign-up app is a warning, not a failure" '[ $RC -eq 0 ] && grep -q "callback failed (non-fatal)" <<<"$OUT"'
fail_cb PROVISION_ID_INPUT="$INJECT"
check "an injected provision_id runs nothing and is sent as JSON data" '[ $RC -eq 0 ] && ! ls "$WORK"/PWNED* >/dev/null 2>&1 && [ "$(head -1 "$WORK/state/bodies.log" | jq -r .provision_id)" = "$INJECT" ]'
# the chain: a taken subdomain leaves a reason that the failure step then reports
reset_state; run_step "Check subdomain availability" taken
CHAIN_REASON="$(genv FAIL_REASON)"
run_step "Notify status - failed" ok CALLBACK_URL_INPUT=https://cb.test PROVISION_ID_INPUT="$PID" ATTEMPT_INPUT=1 PROVISION_TOKEN="$TOKEN" FAIL_REASON="$CHAIN_REASON" APP_UUID= REPORT_OK=true
check "a taken subdomain is reported end to end as subdomain_taken" '[ "$(head -1 "$WORK/state/bodies.log" | jq -r .reason)" = subdomain_taken ]'
fail_cb CALLBACK_URL_INPUT=https://evil.test PROVISION_TOKEN=
check "the failure notice posts to the pinned origin even when the environment holds another callback_url" \
  '[ $RC -eq 0 ] && grep -qx "POST https://cb.test/api/provision-callback Bearer $TOKEN" "$WORK/state/auth.log" && ! grep -q "evil.test" "$WORK/state/requests.log" "$WORK/state/auth.log"'
fail_cb REPORT_OK= PROVISION_TOKEN=
check "without REPORT_OK the failure notice derives no token (no mask line) and sends nothing, though the secret is there" \
  '[ $RC -eq 0 ] && [ ! -s "$WORK/state/requests.log" ] && ! grep -q "add-mask" <<<"$OUT" && ! grep -qF -- "$TOKEN" <<<"$OUT"'
FSCRIPT="$(step_script "Notify status - failed")"
check "  ...the REPORT_OK check is the first line of its script, before any token is derived or masked" \
  'head -1 <<<"$FSCRIPT" | grep -q "REPORT_OK" && [ "$(grep -n "REPORT_OK" <<<"$FSCRIPT" | head -1 | cut -d: -f1)" -lt "$(grep -n -e openssl -e add-mask <<<"$FSCRIPT" | head -1 | cut -d: -f1)" ]'

echo "# The pinned sign-up origin: static checks and the contract (23-43 Task 4)"
PINNED="$(jq -r '.env.SIGNUP_ORIGIN // empty' "$WORK/wf.json")"
check "the workflow pins the sign-up origin as a literal top-level env value (not an input, not a variable)" \
  '[[ "$PINNED" =~ ^https://[a-z0-9.-]+$ ]] && [ "$(jq -r "(.jobs.provision.env.SIGNUP_ORIGIN // \"\")" "$WORK/wf.json")" = "" ]'
TEARDOWN_WF="${TEARDOWN_WF:-$REPO/.github/workflows/teardown-instance.yml}"
if command -v ruby >/dev/null 2>&1; then
  TEARDOWN_PIN="$(ruby -ryaml -e 'print((YAML.load_file(ARGV[0])["env"] || {})["SIGNUP_ORIGIN"].to_s)' "$TEARDOWN_WF" 2>/dev/null)"
else
  TEARDOWN_PIN="$(python3 -I -c 'import sys, yaml; print((yaml.safe_load(open(sys.argv[1])).get("env") or {}).get("SIGNUP_ORIGIN") or "", end="")' "$TEARDOWN_WF" 2>/dev/null)"
fi
check "the pinned origin equals teardown-instance.yml's SIGNUP_ORIGIN" '[ -n "$TEARDOWN_PIN" ] && [ "$PINNED" = "$TEARDOWN_PIN" ]'
ONBOARDING_DIR="${ONBOARDING_DIR:-$REPO/../localnodes-onboarding}"
DISPATCHED="$(python3 -I -c '
import re, sys
src = open(sys.argv[1]).read()
start = src.index("export async function dispatchWorkflow(")
end = src.find("\nexport ", start + 1)
body = src[start:end if end != -1 else len(src)]
hits = re.findall(r"callback_url:\s*([\x27\x22])([^\x27\x22]*)\1", body)
print(hits[0][1] if len(hits) == 1 else "")
' "$ONBOARDING_DIR/server/utils/github.ts" 2>/dev/null)"
check "contract: the pinned origin equals the callback_url the onboarding dispatcher sends ($ONBOARDING_DIR/server/utils/github.ts, dispatchWorkflow; set ONBOARDING_DIR if the checkout is elsewhere)" \
  '[ -n "$DISPATCHED" ] && [ "$PINNED" = "$DISPATCHED" ]'
check "no curl line names the callback_url input" \
  '[ "$(grep -vE "^\s*#" "$WF" | grep -c "curl.*\$CALLBACK_URL_INPUT")" -eq 0 ]'
# Every curl to the sign-up app, continuation lines joined, goes through $SIGNUP_ORIGIN.
SIGNUP_BREACHES="$(python3 -I -c '
import json, re, sys
wf = json.load(open(sys.argv[1]))
for step in wf["jobs"]["provision"]["steps"]:
    run = re.sub(r"\\\n\s*", " ", step.get("run", ""))
    for line in run.split("\n"):
        if not re.search(r"\bcurl\b", line) or line.lstrip().startswith("#"):
            continue
        if "CALLBACK_URL_INPUT" in line:
            print(step["name"] + ": curl names the callback_url input")
        elif "/api/" in line and "$SIGNUP_ORIGIN/api/" not in line:
            print(step["name"] + ": a sign-up app call that does not go to $SIGNUP_ORIGIN")
' "$WORK/wf.json")"
check "every request to the sign-up app goes to \$SIGNUP_ORIGIN (manifest, ack, status notifications, failure notice)" '[ -z "$SIGNUP_BREACHES" ]'
[ -n "$SIGNUP_BREACHES" ] && sed 's/^/#   /' <<<"$SIGNUP_BREACHES"
INPUT_ENV_STEPS="$(jq -r '.jobs.provision.steps[] | select(.name != "Validate inputs") | select((.env // {}) | tostring | contains("inputs.callback_url")) | .name' "$WORK/wf.json")"
check "the callback_url input is in no step's env but \"Validate inputs\"" '[ -z "$INPUT_ENV_STEPS" ]'
check "PROVISION_CALLBACK_URL is never written from the input" \
  '[ "$(grep -vE "^\s*#" "$WF" | grep -c "update_env \"PROVISION_CALLBACK_URL\" \"\$CALLBACK_URL_INPUT\"")" -eq 0 ] && grep -vE "^\s*#" "$WF" | grep -q "update_env \"PROVISION_CALLBACK_URL\" \"\$SIGNUP_ORIGIN\""'

echo
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
