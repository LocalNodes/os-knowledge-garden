#!/usr/bin/env bash
# Tests for .github/workflows/provision-instance.yml (PR #26 review fixes).
#
# Each case extracts the real `run:` block of a workflow step (via Ruby's YAML parser) and
# runs it as GitHub does (bash --noprofile --norc -eo pipefail) with a fake `curl` and a
# no-op `sleep` on PATH. No network, no Coolify, no secrets.
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

# Fake binaries
mkdir -p "$WORK/bin"
cp "$HERE/fake-curl.py" "$WORK/bin/curl"
chmod +x "$WORK/bin/curl"
printf '#!/bin/sh\nexit 0\n' > "$WORK/bin/sleep"
chmod +x "$WORK/bin/sleep"

step_script() {
  ruby -ryaml -e '
    wf = YAML.load_file(ARGV[0])
    step = wf["jobs"]["provision"]["steps"].find { |s| s["name"] == ARGV[1] } or abort "no step #{ARGV[1]}"
    print step["run"]' "$WF" "$1"
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
  : > "$WORK/github_env"
  : > "$WORK/summary.md"
}

# run_step STEP SCENARIO [NAME=VALUE ...] -> sets RC, OUT (stdout+stderr)
run_step() {
  local name="$1" scenario="$2"; shift 2
  step_script "$name" > "$WORK/step.sh" || { RC=99; OUT="extract failed"; return; }
  OUT="$(cd "$WORK" && env -i PATH="$WORK/bin:/usr/bin:/bin:/opt/homebrew/bin:/usr/local/bin" HOME="$WORK" \
    FAKE_STATE="$WORK/state" FAKE_SCENARIO="$scenario" \
    COOLIFY_API="http://coolify.test/api/v1" COOLIFY_TOKEN="test-token" \
    COOLIFY_PROJECT="proj" COOLIFY_SERVER="srv" COOLIFY_ENV="production" \
    GIT_REPO="https://github.com/LocalNodes/os-knowledge-garden" GIT_BRANCH="main" \
    GITHUB_ENV="$WORK/github_env" GITHUB_STEP_SUMMARY="$WORK/summary.md" \
    APP_UUID="app123" SUBDOMAIN="demo" FQDN="demo.localnodes.xyz" DEPLOY_UUID="dep123" \
    "$@" bash --noprofile --norc -eo pipefail "$WORK/step.sh" 2>&1)"
  RC=$?
}

stored() {  # stored KEY -> production value in the fake store
  K="$1" jq -r '[.[] | select(.key == env.K and (.is_preview | not))][0].value // ""' "$WORK/state/envs.json"
}

CONFIG_DEFAULTS=(DEMO_MODULE_INPUT=localnodes_demo SITE_NAME_INPUT="Demo Commons" EMAIL_INPUT=
  CALLBACK_URL_INPUT= STRIPE_SESSION_ID_INPUT= IMAGE_TAG_INPUT=9b8e2a1
  GEMINI_API_KEY_SECRET=g PROVISION_CALLBACK_SECRET_VALUE=c RESEND_API_KEY_SECRET=r)

INJECT='x$(touch '"$WORK"'/PWNED1)`touch '"$WORK"'/PWNED2`;touch '"$WORK"'/PWNED3'

echo "# static checks"
EXPR_RUNS="$(ruby -ryaml -e '
  wf = YAML.load_file(ARGV[0])
  wf["jobs"].each_value { |j| j["steps"].each { |s| puts s["name"] if s["run"].to_s.include?("${{") } }' "$WF")"
check "no \${{ }} expression inside any run: block" '[ -z "$EXPR_RUNS" ]'
ORDER="$(ruby -ryaml -e '
  s = YAML.load_file(ARGV[0])["jobs"]["provision"]["steps"]
  v = s.index { |x| x["run"].to_s.include?("IMAGE_TAG_INPUT") && x["run"].to_s.include?("{7,40}") }
  c = s.index { |x| x["run"].to_s.include?("/applications/public") }
  m = s.each_index.select { |i| s[i]["run"].to_s =~ /-X (POST|PATCH|DELETE)|env_request/ }.min
  puts(v && c && v < c && v < m ? "ok" : "bad v=#{v} c=#{c} m=#{m}")' "$WF")"
check "image_tag is validated before the first Coolify mutation" '[ "$ORDER" = ok ]'

echo "# Validate inputs (P2)"
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=9B8E2A1
check "uppercase image_tag fails validation" '[ $RC -ne 0 ] && grep -q "Invalid image_tag" <<<"$OUT"'
check "  ...and nothing was sent to Coolify" '[ ! -s "$WORK/state/requests.log" ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=9b8e2a1
check "valid sha7 image_tag passes" '[ $RC -eq 0 ] && grep -qx "FQDN=demo.localnodes.xyz" "$WORK/github_env"'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT=demo IMAGE_TAG_INPUT=
check "empty image_tag passes (latest)" '[ $RC -eq 0 ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT="$(printf 'demo\nBASH_ENV=/tmp/x')" IMAGE_TAG_INPUT=
check "multi-line subdomain is rejected (no GITHUB_ENV injection)" '[ $RC -ne 0 ] && [ ! -s "$WORK/github_env" ]'
reset_state; run_step "Validate inputs" ok SUBDOMAIN_INPUT="$INJECT" IMAGE_TAG_INPUT=
check "injected subdomain is rejected and runs nothing" '[ $RC -ne 0 ] && ! ls "$WORK"/PWNED* >/dev/null 2>&1'

echo "# Configure environment variables: update_env fails hard (P1 #2)"
reset_state; run_step "Configure environment variables" all503 "${CONFIG_DEFAULTS[@]}"
check "HTTP 503 on PATCH fails the step (Codex reproduction)" '[ $RC -ne 0 ] && grep -q "PATCH failed (503)" <<<"$OUT"'
check "  ...without falling back to POST" '! grep -q "^POST .*/envs " "$WORK/state/requests.log"'
check "  ...and stops at the first variable" '[ "$(grep -c "^PATCH" "$WORK/state/requests.log")" -eq 1 ]'
check "  ...and IMAGE_TAG is never written" '! grep -q " IMAGE_TAG$" "$WORK/state/requests.log"'

reset_state; run_step "Configure environment variables" post503 "${CONFIG_DEFAULTS[@]}" EMAIL_INPUT=o@example.org
check "missing variable + POST 503 fails the step" '[ $RC -ne 0 ] && grep -q "POST failed (503)" <<<"$OUT"'

reset_state; run_step "Configure environment variables" app404 "${CONFIG_DEFAULTS[@]}"
check "404 that is not a missing variable fails without POST" '[ $RC -ne 0 ] && ! grep -q "^POST" "$WORK/state/requests.log"'

reset_state; run_step "Configure environment variables" post409_once "${CONFIG_DEFAULTS[@]}" EMAIL_INPUT=o@example.org
check "409 from a concurrent create is retried and succeeds" '[ $RC -eq 0 ] && [ "$(stored ORGANIZER_EMAIL)" = o@example.org ]'

reset_state; run_step "Configure environment variables" post409_always "${CONFIG_DEFAULTS[@]}" EMAIL_INPUT=o@example.org
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

echo "# Configure environment variables: read-back before deploy"
reset_state; run_step "Configure environment variables" ok "${CONFIG_DEFAULTS[@]}" SITE_NAME_INPUT="$INJECT" EMAIL_INPUT=o@example.org
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

echo "# Summary and callbacks (P1 #1)"
reset_state; run_step "Summary" ok SITE_NAME_INPUT="$INJECT|cell" DEMO_MODULE_INPUT=localnodes_demo EMAIL_INPUT="$INJECT"
check "Summary with injected site_name/email runs nothing" '[ $RC -eq 0 ] && ! ls "$WORK"/PWNED* >/dev/null 2>&1'
check "  ...and writes the literal text (pipe escaped)" 'grep -qF "x\$(touch" "$WORK/summary.md" && grep -qF "\\|cell" "$WORK/summary.md"'

for S in "Notify status - provisioning" "Notify status - installing" "Notify status - failed"; do
  reset_state; run_step "$S" ok CALLBACK_URL_INPUT="https://cb.test" STRIPE_SESSION_ID_INPUT="$INJECT" CALLBACK_SECRET=s
  check "$S: injected session id runs nothing and is sent as JSON data" \
    '[ $RC -eq 0 ] && ! ls "$WORK"/PWNED* >/dev/null 2>&1 && [ "$(head -1 "$WORK/state/bodies.log" | jq -r .session_id)" = "$INJECT" ]'
done

echo
echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
