#!/usr/bin/env bash
# Contract test: every variable the app reads must reach the opensocial container.
#
# Coolify renders docker-compose.yaml with the app's env as a .env file, which only
# interpolates ${...} references. A variable reaches the container only if the
# opensocial service's `environment:` block lists it. Outbound mail broke because
# RESEND_API_KEY was set in Coolify but never listed there.
#
# Checks:
#  1. Every key provision-instance.yml writes (update_env "KEY") is in opensocial's
#     environment (IMAGE_TAG is interpolation-only: it selects the image tag; the relayer key goes
#     to the relayer only; FEE_WALLET_SCALE is read by Compose itself and is in no service's
#     environment).
#  2. Every getenv('KEY') in settings.php, trusted-proxies.php and localnodes_platform.install is in it.
#  3. The entrypoint's mail, organizer and WEB3_ENABLED variables are in it.
#  4. Resend SMTP is smtps:// (implicit TLS) on 2465 in the install hook and settings.php.
#  5. Every Phase 23 variable (provision identity, page types, AI choice and keys, backup, host
#     report address) is listed, and the shared callback secret is NOT: a container holds only
#     its own per-provision token (23-08, T-23-28).
#  6. settings.php maps HOST_REPORT_EMAIL, AI_BACKUP_PROVIDER/AI_BACKUP_MODEL and OPENROUTER_BASE_URL
#     to their Drupal settings (run with php, skipped where php is missing).
#  7. The fee wallet service runs unless FEE_WALLET_SCALE=0: the relayer has `scale: ${FEE_WALLET_SCALE:-1}`,
#     no service has `profiles`, and an unset variable renders scale 1 and creates the relayer on a dry-run `up`
#     (the rule: a missing or ignored setting means the fee service runs). With FEE_WALLET_SCALE=0 it renders
#     scale 0 and a dry-run `up` creates no relayer container. The governance judge sets no switch and renders the
#     fee service at count 1, and the nightly never names the switch (7b: the funds-off
#     facts, fee-off-1 to fee-off-3, that make a site with no FEE_WALLET_SCALE row safe; the check-only-with-funds rule is held by install-resume.test.sh).
#  8. Docs: CLAUDE.md names FEE_WALLET_SCALE=0 and the funds-on workflow (funds-on.yml);
#     SELF-HOST.md, UPDATES.md and RESTORE.md each name the setting; the env template carries it commented out only;
#     no file names the retired COMPOSE_PROFILES.
#     Overrides: SELF_HOST_MD=, UPDATES_MD=, RESTORE_MD=, ENV_TEMPLATE= (the tracked example env file).
#  9. A funds-off site gets no fee page: the fee wallet module (gas_sponsor) is required only by
#     localnodes_web3 and nothing requires localnodes_web3 (checks no-fee-page-1 and no-fee-page-2, one ok/FAIL line each). That the
#     entrypoint enables localnodes_web3 only with WEB3_ENABLED=true and never names gas_sponsor is held by a run:
#     scripts/ops/tests/install-resume.test.sh section 11.
# IS_DDEV_PROJECT and DDEV_HOSTNAME are exempt (DDEV-only). LOCALNODES_DEFER_INDEXING
# is exempt too: the entrypoint sets it for one drush command, never for the container.
#
# Usage: bash scripts/ops/tests/compose-env.test.sh
#   (env COMPOSE=, WF=, FUNDS_WF=, GJ_WF=, NIGHTLY_WF=, CI_OVERRIDE=, GOV_OVERRIDE= to test another copy of the compose file,
#    provision-instance.yml, funds-on.yml, governance-judge.yml, nightly-wallet-e2e.yml, tests/e2e-live/compose.ci.yaml,
#    tests/e2e-live/compose.governance.yaml;
#    CLAUDE_MD= to test another copy of CLAUDE.md; ENTRYPOINT= and CUSTOM_MODULES= to test another copy of
#    docker/entrypoint.sh and of the custom module tree; DRY_TIMEOUT= seconds per `up --dry-run`, default 240, for
#    failure-injection runs through a PATH shim for docker)
#   Workflows are judged on the shell their run: steps execute (continuation lines joined, comment lines dropped).
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
COMPOSE="${COMPOSE:-$REPO/docker-compose.yaml}"
WF="${WF:-$REPO/.github/workflows/provision-instance.yml}"
FUNDS_WF="${FUNDS_WF:-$REPO/.github/workflows/funds-on.yml}"
GJ_WF="${GJ_WF:-$REPO/.github/workflows/governance-judge.yml}"
NIGHTLY_WF="${NIGHTLY_WF:-$REPO/.github/workflows/nightly-wallet-e2e.yml}"
CI_OVERRIDE="${CI_OVERRIDE:-$REPO/tests/e2e-live/compose.ci.yaml}"
GOV_OVERRIDE="${GOV_OVERRIDE:-$REPO/tests/e2e-live/compose.governance.yaml}"
SETTINGS="${SETTINGS:-$REPO/html/sites/default/settings.php}"
INSTALL="${INSTALL:-$REPO/html/modules/custom/localnodes_platform/localnodes_platform.install}"
PROXIES="${PROXIES:-$REPO/html/sites/default/trusted-proxies.php}"
CLAUDE_MD="${CLAUDE_MD:-$REPO/CLAUDE.md}"
SELF_HOST_MD="${SELF_HOST_MD:-$REPO/docs/SELF-HOST.md}"
UPDATES_MD="${UPDATES_MD:-$REPO/docs/hosting/UPDATES.md}"
RESTORE_MD="${RESTORE_MD:-$REPO/html/modules/custom/localnodes_platform/export/RESTORE.md}"
ENV_TEMPLATE="${ENV_TEMPLATE:-$REPO/.env.example}"
ENTRYPOINT="${ENTRYPOINT:-$REPO/docker/entrypoint.sh}"
CUSTOM_MODULES="${CUSTOM_MODULES:-$REPO/html/modules/custom}"

INTERPOLATION_ONLY="IMAGE_TAG"
LOCAL_ONLY="IS_DDEV_PROJECT DDEV_HOSTNAME LOCALNODES_DEFER_INDEXING"   # DDEV-only, or per-command (entrypoint demo load); never container env

# The compose file is read with Ruby, or with Python and PyYAML where Ruby is missing.
env_keys() {  # env_keys SERVICE -> the names in that service's environment block, one per line
  if command -v ruby >/dev/null 2>&1; then
    ruby -ryaml -e '
      env = YAML.load_file(ARGV[0])["services"][ARGV[1]]["environment"]
      keys = env.is_a?(Hash) ? env.keys : env.map { |e| e.split("=", 2)[0] }
      puts keys' "$COMPOSE" "$1"
  else
    python3 -c '
import sys, yaml
env = yaml.safe_load(open(sys.argv[1]))["services"][sys.argv[2]]["environment"]
print("\n".join(env.keys() if isinstance(env, dict) else [e.split("=", 1)[0] for e in env]))' "$COMPOSE" "$1"
  fi
}
ENV_KEYS="$(env_keys opensocial)" || { echo "FAIL - could not parse $COMPOSE"; exit 1; }
RELAYER_KEYS="$(env_keys gas-sponsor-relayer)" || { echo "FAIL - could not parse $COMPOSE"; exit 1; }
# Written by the workflow for the relayer container only (25-09): the app container never gets it.
RELAYER_ONLY="SPONSOR_RELAYER_PRIVATE_KEY"
# Read by Compose itself (it fills the relayer's scale), so it is in no service's environment block.
COMPOSE_ONLY="FEE_WALLET_SCALE"
yaml_json() {  # yaml_json FILE -> the whole YAML file as compact JSON (Ruby, else Python + PyYAML)
  if command -v ruby >/dev/null 2>&1; then
    ruby -ryaml -rjson -e 'puts JSON.generate(YAML.load_file(ARGV[0]))' "$1"
  else
    python3 -c 'import sys, yaml, json; print(json.dumps(yaml.safe_load(open(sys.argv[1]))))' "$1"
  fi
}
# Shell continuation lines (ending in a backslash) are joined first, so `update_env \` + `"KEY" "value"` is one command.
# A comment line never continues (bash ends a comment at the newline, backslash or not), so it is printed on its own.
join_continuations() { awk '{ if (buf == "" && $0 ~ /^[ \t]*#/) { print; next } if (sub(/\\$/, "")) { buf = buf $0 " "; next } print buf $0; buf = "" } END { if (buf != "") print buf }'; }
# The shell a workflow runs: every `run:` string from the parsed YAML (so a folded plain scalar is one string), continuation lines
# joined, comment lines dropped, blank runs squeezed to one space. A blank line ends each run, so no run continues into the next.
# Empty (and status 1) when the file cannot be read or parsed, or has no run: a guard that reads it must fail on empty.
wf_code() {  # wf_code WORKFLOW_FILE -> one shell command per line
  local runs
  runs="$(yaml_json "$1" 2>/dev/null | jq -r '[.. | objects | .run? | strings] | .[] | ., ""' 2>/dev/null)"
  [ -n "$runs" ] || return 1
  join_continuations <<<"$runs" | grep -vE '^[[:space:]]*#' | sed -E 's/[[:space:]]+/ /g; s/^ //'
}
COMPOSE_JSON="$(yaml_json "$COMPOSE" 2>/dev/null)"
# every name in any service's environment block, one per line
ALL_ENV_KEYS="$(jq -r '.services[] | (.environment // []) | (if type == "object" then keys[] else .[] | split("=")[0] end)' <<<"$COMPOSE_JSON" 2>/dev/null)"

PASS=0; FAIL=0
need() {  # need SOURCE KEY
  if grep -qx "$2" <<<"$ENV_KEYS"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - $2 (read by $1) is not in opensocial.environment"; fi
}

# Every writer workflow: provision-instance.yml and funds-on.yml. A missing funds-on.yml is a failure, not a skip.
WRITERS="$WF"
if [ -f "$FUNDS_WF" ]; then WRITERS="$WF $FUNDS_WF"
else FAIL=$((FAIL + 1)); echo "FAIL - the funds-on workflow does not exist ($FUNDS_WF)"; fi
# The writers are read as the shell their run: steps execute (wf_code): a call split over continuation lines counts, a comment does not.
declare -A CODE_OF
for W in $WRITERS; do CODE_OF[$W]="$(wf_code "$W")"; done
for W in $WRITERS; do
  KEYS="$(grep -oE 'update_env "[A-Z0-9_]+"' <<<"${CODE_OF[$W]}" | cut -d'"' -f2 | sort -u)"
  if [ -n "$KEYS" ]; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - $(basename "$W"): no update_env call could be read (missing, unreadable or not a workflow); its keys were not checked"; fi
  for K in $KEYS; do
    case " $INTERPOLATION_ONLY " in
      # Interpolated by Compose: it must be in a value of the parsed file (a comment naming it proves nothing).
      *" $K "*) jq -e --arg k "\${$K" '[.. | strings | select(contains($k))] | length > 0' <<<"$COMPOSE_JSON" >/dev/null 2>&1 && PASS=$((PASS + 1)) || { FAIL=$((FAIL + 1)); echo "FAIL - $K not referenced in a compose value"; } ;;
      *)
        case " $COMPOSE_ONLY " in
          *" $K "*)
            if [ -z "$ALL_ENV_KEYS" ]; then FAIL=$((FAIL + 1)); echo "FAIL - could not list the services' environment names"
            elif grep -qx "$K" <<<"$ALL_ENV_KEYS"; then FAIL=$((FAIL + 1)); echo "FAIL - $(basename "$W"): $K is compose-only (read by Compose, not a container) but a service lists it in its environment"
            else PASS=$((PASS + 1)); fi ;;
          *)
            case " $RELAYER_ONLY " in
              *" $K "*) if grep -qx "$K" <<<"$RELAYER_KEYS"; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL - $(basename "$W"): $K is not in gas-sponsor-relayer.environment"; fi ;;
              *) need "$(basename "$W")" "$K" ;;
            esac ;;
        esac ;;
    esac
  done
done
for F in "$SETTINGS" "$INSTALL" "$PROXIES"; do
  if [ -r "$F" ] && [ -s "$F" ]; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL - could not read $F: its getenv() keys were not checked"; fi
done
# The files are read as one line each, so getenv( + 'KEY') split over two lines is still one call.
for K in $(cat "$SETTINGS" "$INSTALL" "$PROXIES" 2>/dev/null | tr '\n' ' ' | grep -oE "getenv\([[:space:]]*'[A-Z0-9_]+'[[:space:]]*\)" | cut -d"'" -f2 | sort -u); do
  case " $LOCAL_ONLY " in *" $K "*) continue ;; esac
  need "settings.php/localnodes_platform.install" "$K"
done
for K in RESEND_API_KEY DEMO_PACK MAIL_FROM ORGANIZER_EMAIL ORGANIZER_NAME PROVISION_CALLBACK_URL PROVISION_CALLBACK_TOKEN STRIPE_SESSION_ID WEB3_ENABLED AI_PROVIDER_SWITCH; do
  need entrypoint.sh "$K"
done

# 5. Phase 23 variables reach the container (research Pitfall 4); the shared secret does not.
for K in PROVISION_ID PROVISION_ATTEMPT PROVISION_CALLBACK_TOKEN SITE_NAME ORGANIZER_EMAIL LOCALNODES_TOPIC_TYPES LOCALNODES_COMMUNITY_SEED \
         AI_PROVIDER GEMINI_KEY_TIER OPENROUTER_API_KEY OPENROUTER_BASE_URL AI_BACKUP_PROVIDER AI_BACKUP_MODEL HOST_REPORT_EMAIL; do
  need "Phase 23 (23-08)" "$K"
done
if grep -qx PROVISION_CALLBACK_SECRET <<<"$ENV_KEYS" || grep -q PROVISION_CALLBACK_SECRET "$COMPOSE"; then
  FAIL=$((FAIL + 1)); echo "FAIL - docker-compose.yaml still passes the shared PROVISION_CALLBACK_SECRET to a container"
else
  PASS=$((PASS + 1))
fi
if [ -z "${CODE_OF[$WF]}" ]; then
  FAIL=$((FAIL + 1)); echo "FAIL - provision-instance.yml could not be read: no proof it does not write PROVISION_CALLBACK_SECRET"
elif grep -qE 'update_env "PROVISION_CALLBACK_SECRET"' <<<"${CODE_OF[$WF]}"; then
  FAIL=$((FAIL + 1)); echo "FAIL - provision-instance.yml still writes the shared PROVISION_CALLBACK_SECRET into a container"
else
  PASS=$((PASS + 1))
fi

# 6. settings.php env mappings, run for real with php (no Drupal needed: it only fills $settings/$config).
settings_probe() {  # settings_probe NAME=VALUE ... -> JSON {"s": $settings, "c": $config}
  # settings.php reads DRUPAL_HASH_SALT and refuses to load without a private salt, so the probe gets a throwaway one.
  env -i PATH="$PATH" HOME="${TMPDIR:-/tmp}" DRUPAL_HASH_SALT=compose-env-probe-salt "$@" php -r '
    $app_root = "/x"; $site_path = "sites/default"; $settings = []; $config = []; $databases = [];
    include $argv[1];
    echo json_encode(["s" => $settings, "c" => $config]);' "$SETTINGS" 2>/dev/null
}
probe_check() {  # probe_check DESCRIPTION JQ_EXPR NAME=VALUE ...  (JQ_EXPR must print true)
  local d="$1" q="$2"; shift 2
  if [ "$(settings_probe "$@" | jq -r "$q" 2>/dev/null)" = true ]; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL - settings.php: $d"; fi
}
if command -v php >/dev/null 2>&1; then
  probe_check "HOST_REPORT_EMAIL becomes localnodes_host_report_email" '.s.localnodes_host_report_email == "host@fern.test"' HOST_REPORT_EMAIL=host@fern.test
  probe_check "an unset HOST_REPORT_EMAIL sets nothing" '.s | has("localnodes_host_report_email") | not'
  probe_check "an empty HOST_REPORT_EMAIL sets nothing" '.s | has("localnodes_host_report_email") | not' HOST_REPORT_EMAIL=
  probe_check "AI_BACKUP_PROVIDER and AI_BACKUP_MODEL become localnodes_ai_backup" \
    '.s.localnodes_ai_backup == {"provider": "gemini", "model": "gemini-2.5-flash"}' AI_BACKUP_PROVIDER=gemini AI_BACKUP_MODEL=gemini-2.5-flash
  probe_check "each backup field is set only when set" '.s.localnodes_ai_backup == {"provider": "gemini"}' AI_BACKUP_PROVIDER=gemini
  probe_check "no backup variables set nothing" '.s | has("localnodes_ai_backup") | not'
  probe_check "OPENROUTER_BASE_URL becomes the OpenRouter provider base_url" \
    '.c["ai_provider_openrouter.settings"].base_url == "https://gw.fern.test/v1"' OPENROUTER_BASE_URL=https://gw.fern.test/v1
  probe_check "an unset OPENROUTER_BASE_URL sets nothing" '.c | has("ai_provider_openrouter.settings") | not'
  probe_check "an OPENROUTER_BASE_URL that is not an http(s) URL is ignored" '.c | has("ai_provider_openrouter.settings") | not' OPENROUTER_BASE_URL=javascript:alert
else
  echo "skip - php is not installed: the settings.php mapping cases did not run"
fi

# 4. Resend SMTP must use implicit TLS on a port the VPS can reach. Every Resend
#    DSN in the install hook and settings.php must be smtps:// on 2465 (Resend's
#    implicit-TLS alternative). Rejected: smtp:// (STARTTLS is opportunistic in
#    Symfony Mailer 6.4, so a stripped STARTTLS sends the key in clear), port 587,
#    and 25/465 (blocked outbound by Hetzner). The `smtp` plugin with a 'port' is
#    rejected too, because it always builds smtp://.
ALLOWED_TLS_PORTS="2465"
smtp_check() {  # smtp_check FILE
  local f="$1" bad=0 dsns d port
  # [^;]* spans PHP string concatenation (settings.php builds the DSN around the key)
  # Whole-line comments are dropped and the rest is read as one line, so a DSN built over two lines is still one DSN; the text is
  # then broken before every scheme, so two DSNs in one statement are judged one by one, never as one match.
  dsns="$(grep -vE '^[[:space:]]*(//|#|\*|/\*)' "$f" 2>/dev/null | tr '\n' ' ' | sed -E 's#smtps?://#\n&#g' | grep -oE "smtps?://[^;]*smtp\.resend\.com(:[0-9]+)?")"
  if [ -z "$dsns" ]; then echo "FAIL - $(basename "$f"): no Resend DSN found"; FAIL=$((FAIL + 1)); return; fi
  while IFS= read -r d; do
    case "$d" in smtps://*) ;; *) echo "FAIL - $(basename "$f"): '$d' is not smtps:// (implicit TLS required)"; bad=1 ;; esac
    port="$(printf '%s' "$d" | grep -oE ':[0-9]+$' | tr -d :)"
    case " $ALLOWED_TLS_PORTS " in *" $port "*) ;; *) echo "FAIL - $(basename "$f"): port '${port:-none}' in '$d' (allowed: $ALLOWED_TLS_PORTS)"; bad=1 ;; esac
  done <<<"$dsns"
  if grep -qE "'plugin' => 'smtp'|'port' => [0-9]+" "$f"; then echo "FAIL - $(basename "$f"): uses the smtp plugin/port form (always smtp://)"; bad=1; fi
  if [ "$bad" -eq 0 ]; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); fi
}
smtp_check "$INSTALL"
smtp_check "$SETTINGS"


# 7. The fee wallet service runs unless FEE_WALLET_SCALE=0.
#    The rule held here: a missing or ignored setting means the fee service runs, as it always has; only an
#    explicit 0 stops it. Nobody has seen the hosting panel honour COMPOSE_PROFILES, and every funds-on site made
#    before this setting existed has no such row, so a switch that needed a row to keep the service would drop it on redeploy.
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
RELAYER_SVC="gas-sponsor-relayer"
SCALE_DEFAULT='${FEE_WALLET_SCALE:-1}'
RELAYER_SCALE="$(jq -r --arg s "$RELAYER_SVC" '(.services[$s].scale // "") | tostring' <<<"$COMPOSE_JSON" 2>/dev/null)"
if [ "$RELAYER_SCALE" = "$SCALE_DEFAULT" ]; then PASS=$((PASS + 1));
else FAIL=$((FAIL + 1)); echo "FAIL - docker-compose.yaml: the relayer scale is '${RELAYER_SCALE:-unreadable}', not '$SCALE_DEFAULT' (unset must mean 1, the service runs)"; fi
check_jq() {  # check_jq JSON JQ_EXPR FAIL_TEXT [--arg NAME VALUE]  (JQ_EXPR must print true)
  local json="$1" q="$2" msg="$3"; shift 3
  if [ "$(jq -r "$@" "$q" <<<"$json" 2>/dev/null)" = true ]; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL - $msg"; fi
}
# No service has profiles (a profile would need a setting to run). The services must be listed, or the absence proves nothing.
check_jq "$COMPOSE_JSON" '(.services | length) > 0 and ([.services[] | has("profiles")] | any | not)' \
  "docker-compose.yaml: a service has profiles (or the services could not be listed); no service may need a setting to run"
check_jq "$COMPOSE_JSON" '(.services.opensocial.depends_on // []) | (if type == "object" then keys else . end) | map(select(. == $s)) | length == 0' \
  "docker-compose.yaml: opensocial must not depend_on $RELAYER_SVC" --arg s "$RELAYER_SVC"
check_jq "$COMPOSE_JSON" '.volumes | has("relayer-data")' "docker-compose.yaml: the relayer-data volume is no longer declared at top level"
check_jq "$COMPOSE_JSON" '.services.opensocial.environment | (if type == "object" then .SPONSOR_RELAYER_URL else (map(select(startswith("SPONSOR_RELAYER_URL="))) | .[0] // "" | ltrimstr("SPONSOR_RELAYER_URL=")) end) == $u' \
  "docker-compose.yaml: opensocial SPONSOR_RELAYER_URL is not the default of the fee wallet service" \
  --arg u '${SPONSOR_RELAYER_URL:-http://gas-sponsor-relayer:8080}'

# What the writer workflows write for group funds and the fee service count is held by runs against the stand-in API, not
# here: provision-workflow.test.sh (practice writes and reads back WEB3_ENABLED=true and FEE_WALLET_SCALE=1; off writes
# and reads back false and 0) and funds-on-workflow.test.sh (funds on stores true and 1).

# CI (e): the governance judge has no switch in its env; neither it nor the nightly sets COMPOSE_PROFILES or FEE_WALLET_SCALE.
GJ_JSON="$(yaml_json "$GJ_WF" 2>/dev/null)"
check_jq "$GJ_JSON" '(.jobs["governance-judge"].steps | length) > 0 and ([(.env // {}), (.jobs["governance-judge"].env // {})] | map(has("COMPOSE_PROFILES") or has("FEE_WALLET_SCALE")) | any | not)' \
  "governance-judge.yml: the workflow or job env sets COMPOSE_PROFILES or FEE_WALLET_SCALE (or the job could not be read); the relayer runs by default there"
check_jq "$GJ_JSON" '(.jobs["governance-judge"].steps | length) > 0 and ([.jobs["governance-judge"].steps[] | ((.env // {}) | has("COMPOSE_PROFILES") or has("FEE_WALLET_SCALE")) or ((.run // "") | contains("COMPOSE_PROFILES") or contains("FEE_WALLET_SCALE"))] | any | not)' \
  "governance-judge.yml: a step sets COMPOSE_PROFILES or FEE_WALLET_SCALE (or the steps could not be read)"
# The compose up guards for the CI workflows were removed on purpose: they read shell text; a guard that reads the workflow as data is to replace them.
if [ -r "$NIGHTLY_WF" ] && ! grep -qE 'COMPOSE_PROFILES|FEE_WALLET_SCALE' "$NIGHTLY_WF"; then PASS=$((PASS + 1));
else FAIL=$((FAIL + 1)); echo "FAIL - nightly-wallet-e2e.yml names COMPOSE_PROFILES or FEE_WALLET_SCALE (or could not be read); the nightly never touches the fee service"; fi
CI_JSON="$(yaml_json "$CI_OVERRIDE" 2>/dev/null)"
check_jq "$CI_JSON" '(.services | length) > 0 and ([.services[] | has("profiles")] | any | not)' "compose.ci.yaml: a service has profiles (or the services could not be listed); the nightly override must never profile anything"

# No file that ships or tests the switch still names the retired profile switch.
for F in "$COMPOSE" "$WF" "$FUNDS_WF" "$GJ_WF" "$NIGHTLY_WF" "$CI_OVERRIDE" "$ENV_TEMPLATE"; do
  if [ -s "$F" ] && [ "$(grep -c 'COMPOSE_PROFILES' "$F")" = 0 ]; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - $(basename "$F") still names COMPOSE_PROFILES (the profile switch is retired), or could not be read"; fi
done

if docker compose version >/dev/null 2>&1; then
  # config and dry-run only: nothing is started. An empty project dir keeps any project env file of the checkout out of it.
  mkdir -p "$WORK/proj"
  echo "FEE_WALLET_SCALE=0" >"$WORK/off.vars"
  dc_unset() { env -u FEE_WALLET_SCALE docker compose -f "$COMPOSE" --project-directory "$WORK/proj" -p compose-env-check "$@" 2>/dev/null; }
  dc_file() { env -u FEE_WALLET_SCALE docker compose --env-file "$WORK/off.vars" -f "$COMPOSE" --project-directory "$WORK/proj" -p compose-env-check "$@" 2>/dev/null; }
  dc_env() { FEE_WALLET_SCALE=0 docker compose -f "$COMPOSE" --project-directory "$WORK/proj" -p compose-env-check "$@" 2>/dev/null; }
  rendered_scale() { jq -r --arg s "$RELAYER_SVC" '.services[$s].scale | tostring' 2>/dev/null; }
  RELAYER_IMAGE="ghcr.io/localnodes/gas-sponsor-relayer"
  # (b) With the variable unset the relayer is listed with its image and volume, renders scale 1 and a dry-run creates it.
  # Absence or presence only counts when the query succeeded and lists the baseline (a failed or empty query proves nothing).
  SVC_UNSET="$(dc_unset config --services)"; RC_SVC=$?
  if [ "$RC_SVC" -eq 0 ] && grep -qx opensocial <<<"$SVC_UNSET" && grep -qx "$RELAYER_SVC" <<<"$SVC_UNSET"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: with FEE_WALLET_SCALE unset, config --services must succeed and list opensocial and $RELAYER_SVC (exit $RC_SVC)"; fi
  IMG_UNSET="$(dc_unset config --images)"; RC_IMG=$?
  if [ "$RC_IMG" -eq 0 ] && grep -q '^mariadb:' <<<"$IMG_UNSET" && grep -q "$RELAYER_IMAGE" <<<"$IMG_UNSET"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: with FEE_WALLET_SCALE unset, config --images must succeed, list the mariadb image and name the relayer image (exit $RC_IMG)"; fi
  VOL_UNSET="$(dc_unset config --volumes)"; RC_VOL=$?
  if [ "$RC_VOL" -eq 0 ] && grep -qx mariadb-data <<<"$VOL_UNSET" && grep -qx relayer-data <<<"$VOL_UNSET"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: with FEE_WALLET_SCALE unset, config --volumes must succeed, list mariadb-data and relayer-data (exit $RC_VOL)"; fi
  # Services, images and volumes are listed even at scale 0, so the default is held by the rendered scale and by what a dry run creates.
  SCALE_UNSET="$(dc_unset config --format json | rendered_scale)"
  if [ "$SCALE_UNSET" = 1 ]; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: with FEE_WALLET_SCALE unset the relayer must render scale 1 (the service runs), got '${SCALE_UNSET:-nothing}'"; fi
  # A dry run often ends with "opensocial is missing dependency mariadb" (Compose's dry-run has no real containers to wait for,
  # so its wait step fails about four runs in ten). That error comes in the start phase, after Compose has created every container.
  # So a run counts as complete (and only a complete run can prove a container is absent) when it exits 0, or when it fails with
  # exactly that error as its last line, every other line is Compose progress, every container it began creating was created
  # (each named service among them) and a container was starting after the last creation line (the create phase had ended).
  # A timeout (124/137) or any other error is incomplete, and an incomplete run is tried again, up to three times.
  # --no-deps keeps it to the services as written. DRY_TIMEOUT (seconds, default 240) is for the failure-injection tests.
  DRY_TIMEOUT="${DRY_TIMEOUT:-240}"
  dry_complete() {  # dry_complete RC OUTPUT SERVICE... -> 0 when the run completed and created each SERVICE; else 1 with DRY_WHY
    local rc="$1" out="$2" s last; shift 2
    case "$rc" in 124|137) DRY_WHY="timed out (exit $rc)"; return 1 ;; esac
    for s in "$@"; do
      grep -q "Container compose-env-check-$s-1 Created" <<<"$out" || { DRY_WHY="exit $rc, no container created for $s"; return 1; }
    done
    [ "$rc" -eq 0 ] && return 0
    last="$(grep -v '^[[:space:]]*$' <<<"$out" | tail -n 1)"
    grep -qxE '[A-Za-z0-9._-]+ is missing dependency [A-Za-z0-9._-]+' <<<"$last" || { DRY_WHY="exit $rc, ended with: $last"; return 1; }
    if [ -n "$(grep -v '^[[:space:]]*$' <<<"$out" | sed '$d' | grep -vE '^ (Image|Volume|Network|Container) |^ [A-Za-z0-9._-]+ ==> ')" ]; then
      DRY_WHY="exit $rc, a line other than Compose progress before the dependency error"; return 1
    fi
    awk '/^ Container [^ ]+ Creating *$/ { c[$2] = 1; lc = NR } /^ Container [^ ]+ Created *$/ { d[$2] = 1; lc = NR }
         /^ Container [^ ]+ Starting *$/ { ls = NR }
         END { for (k in c) if (!(k in d)) exit 1; exit !(lc > 0 && ls > lc) }' <<<"$out" \
      || { DRY_WHY="exit $rc, the dependency error came before the create phase ended"; return 1; }
    return 0
  }
  dry_up() {  # dry_up "SERVICE..." COMPOSE_ARGS... -> DRY_OUT, DRY_RC, DRY_OK (1 when complete), DRY_WHY, DRY_TRIES
    local n want="$1"; shift; DRY_OUT=""; DRY_RC=1; DRY_OK=0; DRY_WHY=""; DRY_TRIES=0
    for n in 1 2 3; do
      DRY_TRIES=$n
      DRY_OUT="$(timeout "$DRY_TIMEOUT" env -u FEE_WALLET_SCALE docker compose "$@" -p compose-env-check up --dry-run -d --no-deps 2>&1)"; DRY_RC=$?
      # shellcheck disable=SC2086
      if dry_complete "$DRY_RC" "$DRY_OUT" $want; then DRY_OK=1; DRY_WHY=""; break; fi
    done
  }
  # The services a complete run creates: every listed service with the variable unset; all but the relayer with FEE_WALLET_SCALE=0.
  WANT_UNSET="$(tr '\n' ' ' <<<"$SVC_UNSET")"
  WANT_OFF="$(grep -vx "$RELAYER_SVC" <<<"$SVC_UNSET" | tr '\n' ' ')"
  dry_up "$WANT_UNSET" -f "$COMPOSE" --project-directory "$WORK/proj"; UP_UNSET="$DRY_OUT"; RC_UP=$DRY_RC
  if grep -q 'Container compose-env-check-mariadb-1 Created' <<<"$UP_UNSET" && grep -q 'Container compose-env-check-opensocial-1 Created' <<<"$UP_UNSET" && grep -q "Container compose-env-check-$RELAYER_SVC-1 Created" <<<"$UP_UNSET"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: with FEE_WALLET_SCALE unset, up --dry-run -d must create the mariadb, opensocial and $RELAYER_SVC containers (exit $RC_UP; last line: $(tail -n 1 <<<"$UP_UNSET"))"; fi
  # (c) With FEE_WALLET_SCALE=0 in an --env-file (and in the environment) the relayer renders scale 0 and a dry run creates no relayer container.
  SCALE_FILE="$(dc_file config --format json | rendered_scale)"
  if [ "$SCALE_FILE" = 0 ]; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: with FEE_WALLET_SCALE=0 in an --env-file the relayer must render scale 0, got '${SCALE_FILE:-nothing}'"; fi
  SCALE_ENV="$(dc_env config --format json | rendered_scale)"
  if [ "$SCALE_ENV" = 0 ]; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: with FEE_WALLET_SCALE=0 in the environment the relayer must render scale 0, got '${SCALE_ENV:-nothing}'"; fi
  # Absence proves something only in a complete run (an incomplete one may have stopped before the relayer).
  dry_up "$WANT_OFF" --env-file "$WORK/off.vars" -f "$COMPOSE" --project-directory "$WORK/proj"; UP_OFF="$DRY_OUT"; RC_UP_OFF=$DRY_RC
  if [ "$DRY_OK" = 1 ] && grep -q 'Container compose-env-check-mariadb-1 Created' <<<"$UP_OFF" && grep -q 'Container compose-env-check-opensocial-1 Created' <<<"$UP_OFF" && ! grep -q "Container compose-env-check-$RELAYER_SVC-1" <<<"$UP_OFF"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: with FEE_WALLET_SCALE=0, up --dry-run -d must complete, create the mariadb and opensocial containers and no $RELAYER_SVC container (exit $RC_UP_OFF, tries $DRY_TRIES, ${DRY_WHY:-complete}; last line: $(tail -n 1 <<<"$UP_OFF"))"; fi
  # (e) The nightly and governance merges (base + override files), as the workflows run them.
  dc_merge() {  # dc_merge CONFIG_OPTION FILE... -> `config CONFIG_OPTION` of the merged files (--services or --format=json), FEE_WALLET_SCALE unset
    local opt="$1" args=() f; shift
    for f in "$@"; do args+=(-f "$f"); done
    env -u FEE_WALLET_SCALE SEPOLIA_RPC_URL= SPONSOR_RELAYER_TOKEN_CI=compose-env-throwaway-token \
      docker compose "${args[@]}" --project-directory "$WORK/proj" -p compose-env-check config "$opt" 2>/dev/null
  }
  SVC_NIGHTLY="$(dc_merge --services "$COMPOSE" "$CI_OVERRIDE")"; RC_NIGHTLY=$?
  if [ "$RC_NIGHTLY" -eq 0 ] && grep -qx opensocial <<<"$SVC_NIGHTLY"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: the nightly merge must render and list opensocial (exit $RC_NIGHTLY); it starts only the services its \`up\` names"; fi
  SVC_GOV="$(dc_merge --services "$COMPOSE" "$CI_OVERRIDE" "$GOV_OVERRIDE")"; RC_GOV=$?
  if [ "$RC_GOV" -eq 0 ] && grep -qx opensocial <<<"$SVC_GOV" && grep -qx "$RELAYER_SVC" <<<"$SVC_GOV"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: the governance merge, with no switch set, must list opensocial and $RELAYER_SVC (exit $RC_GOV)"; fi
  # A listed service can still be stopped (config --services lists it at scale 0), so the judge's fee service is held by its rendered scale.
  SCALE_GOV="$(dc_merge --format=json "$COMPOSE" "$CI_OVERRIDE" "$GOV_OVERRIDE" | rendered_scale)"
  if [ "$SCALE_GOV" = 1 ]; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - compose: the governance merge, with no switch set, must render $RELAYER_SVC at scale 1 (the judge runs the fee service), got '${SCALE_GOV:-nothing}'"; fi
else
  echo "skip - docker compose is not available: the fee-wallet Compose checks did not run"
fi

# 7b. A funds-off site that never got a FEE_WALLET_SCALE row keeps its fee service (it runs by default) and the service
#     stays keyless and idle there. The facts that make that safe, held statically: the relayer's mode
#     comes from WEB3_ENABLED with a funds-off default, its key comes only from the SPONSOR_RELAYER_PRIVATE_KEY setting
#     (empty by default, no generator anywhere), and the site check runs only under WEB3_ENABLED=true.
#     The relayer's own source (keyless and inert with web3 off) is outside this repo; the SUMMARY records it as a source fact.
RELAYER_ENV_VAL() {  # RELAYER_ENV_VAL NAME -> the relayer's environment value for NAME
  jq -r --arg s "$RELAYER_SVC" --arg k "$1" '.services[$s].environment | (if type == "object" then .[$k] else (map(select(startswith($k + "="))) | .[0] // "" | ltrimstr($k + "=")) end) // ""' <<<"$COMPOSE_JSON" 2>/dev/null
}
f7() {  # f7 ID OK(0|1) TEXT -> one ok/FAIL line per named check
  if [ "$2" = 1 ]; then PASS=$((PASS + 1)); echo "ok - $1 $3"; else FAIL=$((FAIL + 1)); echo "FAIL - $1 $3"; fi
}
MODE_VAL="$(RELAYER_ENV_VAL WEB3_ENABLED)"
if [ "$MODE_VAL" = '${WEB3_ENABLED:-false}' ]; then OK=1; else OK=0; fi
f7 fee-off-1 "$OK" "the relayer's WEB3_ENABLED comes from \${WEB3_ENABLED:-false} (found: ${MODE_VAL:-unreadable})"
KEY_VAL="$(RELAYER_ENV_VAL SPONSOR_RELAYER_PRIVATE_KEY)"
KEY_MENTIONS="$(grep -c 'SPONSOR_RELAYER_PRIVATE_KEY' "$COMPOSE" 2>/dev/null)"
if [ "$KEY_VAL" = '${SPONSOR_RELAYER_PRIVATE_KEY:-}' ] && [ "${KEY_MENTIONS:-x}" = 1 ]; then OK=1; else OK=0; fi
f7 fee-off-2 "$OK" "the relayer's key comes only from \${SPONSOR_RELAYER_PRIVATE_KEY:-} and the compose file names it nowhere else (found: ${KEY_VAL:-unreadable}, mentions: ${KEY_MENTIONS:-unreadable})"
# No generator: the entrypoint never names the key or makes secrets that could become one, and the compose file runs no generator.
ENTRY_KEY_LINES="$(grep -v '^[[:space:]]*#' "$ENTRYPOINT" 2>/dev/null | grep -cE 'SPONSOR_RELAYER_PRIVATE_KEY|SPONSOR_RELAYER_TOKEN')"
GEN_COMPOSE="$(grep -cE 'openssl|urandom|keygen|generate-key|generate_key|cast wallet' "$COMPOSE" 2>/dev/null)"
if [ -s "$ENTRYPOINT" ] && [ -s "$COMPOSE" ] && [ "${ENTRY_KEY_LINES:-x}" = 0 ] && [ "${GEN_COMPOSE:-x}" = 0 ]; then OK=1; else OK=0; fi
f7 fee-off-3 "$OK" "no relayer key or token is made or named by the entrypoint or the compose file (entrypoint lines: ${ENTRY_KEY_LINES:-unreadable}, compose generator lines: ${GEN_COMPOSE:-unreadable})"
# The site check (localnodes-web3:check) runs only when group funds are on. That is held by a run, not by reading text:
# scripts/ops/tests/install-resume.test.sh section 11 runs the entrypoint with a stand-in drush and counts the check
# under each funds mode (on: called before the install marker and before ready; off, unset or false: never called).

# 8. Docs: an operator learns the fee service runs unless FEE_WALLET_SCALE=0, and the funds-on workflow.
doc_has() {  # doc_has FILE TEXT FAIL_TEXT  (TEXT is a fixed string)
  if [ -f "$1" ] && grep -qF -- "$2" "$1"; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL - $3"; fi
}
doc_has "$CLAUDE_MD" 'FEE_WALLET_SCALE=0' "CLAUDE.md does not name FEE_WALLET_SCALE=0, the setting that stops the fee wallet service"
doc_has "$CLAUDE_MD" 'funds-on.yml' "CLAUDE.md does not name funds-on.yml, the workflow that turns group funds on later"
# Every doc that starts, updates or restores a site names the setting.
doc_has "$SELF_HOST_MD" 'FEE_WALLET_SCALE=0' "docs/SELF-HOST.md does not tell a self-hoster with group funds off to set FEE_WALLET_SCALE=0"
doc_has "$UPDATES_MD" 'FEE_WALLET_SCALE=0' "docs/hosting/UPDATES.md does not say the fee wallet service runs unless FEE_WALLET_SCALE=0"
doc_has "$RESTORE_MD" 'FEE_WALLET_SCALE=0' "RESTORE.md does not say the fee wallet service runs unless FEE_WALLET_SCALE=0"
# The env template carries the setting commented out: a copied template must not stop the fee wallet by default.
if [ -f "$ENV_TEMPLATE" ] && grep -qxF '# FEE_WALLET_SCALE=0' "$ENV_TEMPLATE" && ! grep -qE '^[[:space:]]*(export[[:space:]]+)?FEE_WALLET_SCALE[[:space:]]*=' "$ENV_TEMPLATE"; then PASS=$((PASS + 1));
else FAIL=$((FAIL + 1)); echo "FAIL - the env template must carry '# FEE_WALLET_SCALE=0' as a comment and no uncommented FEE_WALLET_SCALE line"; fi
# None of the docs names the retired profile switch (each must be readable and name the new one).
for F in "$CLAUDE_MD" "$SELF_HOST_MD" "$UPDATES_MD" "$RESTORE_MD"; do
  if [ -s "$F" ] && grep -qF 'FEE_WALLET_SCALE' "$F" && [ "$(grep -c 'COMPOSE_PROFILES' "$F")" = 0 ]; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - $(basename "$F") still names COMPOSE_PROFILES (retired), or does not name FEE_WALLET_SCALE, or could not be read"; fi
done

# 9. A funds-off site gets no fee page: gas_sponsor and its fee page are enabled only through
#    localnodes_web3, which the entrypoint enables only when WEB3_ENABLED=true (held by install-resume.test.sh section 11).
w6() {  # w6 ID OK(0|1) TEXT  -> one ok/FAIL line per named check
  if [ "$2" = 1 ]; then PASS=$((PASS + 1)); echo "ok - $1 $3"; else FAIL=$((FAIL + 1)); echo "FAIL - $1 $3"; fi
}
# Every custom *.info.yml is read as data (yaml_json), so a block list, a flow list ([a, b]) and a quoted entry are all
# seen: each entry of its dependencies list, with a <project>: prefix and a version constraint taken off
# (drupal:views (>=8.x-3.0) is views). A file that cannot be parsed, or whose dependencies is not a list, fails both checks.
DEP_TABLE=""; DEP_UNREADABLE=""; DEP_FILES_READ=0
while IFS= read -r f; do
  [ -n "$f" ] || continue
  if J="$(yaml_json "$f" 2>/dev/null)" && D="$(jq -r 'if type != "object" then error("not a map") else . end
      | (.dependencies // []) | if type != "array" then error("not a list") else .[] end
      | tostring | sub("^\\s+"; "") | sub("^[A-Za-z0-9_]+:"; "") | sub("\\s*\\(.*$"; "") | sub("\\s+$"; "")' <<<"$J" 2>/dev/null)"; then
    DEP_FILES_READ=$((DEP_FILES_READ + 1))
    while IFS= read -r d; do [ -n "$d" ] && DEP_TABLE+="$f"$'\t'"$d"$'\n'; done <<<"$D"
  else
    DEP_UNREADABLE+="$f "
  fi
done < <(find "$CUSTOM_MODULES" -name '*.info.yml' -type f 2>/dev/null | sort)
dep_files() {  # dep_files MODULE -> every custom *.info.yml whose dependencies name MODULE
  awk -F'\t' -v m="$1" '$2 == m { print $1 }' <<<"$DEP_TABLE" | sort -u
}
DEPS_OK=0; [ "$DEP_FILES_READ" -gt 0 ] && [ -z "$DEP_UNREADABLE" ] && DEPS_OK=1
GAS_DEP_FILES="$(dep_files gas_sponsor)"
if [ "$DEPS_OK" = 1 ] && [ "$(grep -c . <<<"$GAS_DEP_FILES")" = 1 ] && case "$GAS_DEP_FILES" in */localnodes_web3/localnodes_web3.info.yml) true ;; *) false ;; esac; then OK=1; else OK=0; fi
w6 no-fee-page-1 "$OK" "only localnodes_web3.info.yml requires gas_sponsor (found: $(tr '\n' ' ' <<<"${GAS_DEP_FILES:-none}"); files read: $DEP_FILES_READ, unreadable: ${DEP_UNREADABLE:-none})"
WEB3_DEP_FILES="$(dep_files localnodes_web3)"
if [ "$DEPS_OK" = 1 ] && [ -z "$WEB3_DEP_FILES" ]; then OK=1; else OK=0; fi
w6 no-fee-page-2 "$OK" "no custom module requires localnodes_web3 (found: $(tr '\n' ' ' <<<"${WEB3_DEP_FILES:-none}"); files read: $DEP_FILES_READ, unreadable: ${DEP_UNREADABLE:-none})"
# The entrypoint side of this rule (localnodes_web3 enabled only with WEB3_ENABLED=true, on a fresh install and on a
# redeploy; gas_sponsor named in no drush call) is held by a run, not by reading text: scripts/ops/tests/install-resume.test.sh
# section 11 runs the entrypoint with a stand-in drush and counts what each funds mode enables.

echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
