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
#     environment (IMAGE_TAG is interpolation-only: it selects the image tag).
#  2. Every getenv('KEY') in settings.php, trusted-proxies.php and localnodes_platform.install is in it.
#  3. The entrypoint's mail, organizer and WEB3_ENABLED variables are in it.
#  4. Resend SMTP is smtps:// (implicit TLS) on 2465 in the install hook and settings.php.
#  5. Every Phase 23 variable (provision identity, page types, AI choice and keys, backup, host
#     report address) is listed, and the shared callback secret is NOT: a container holds only
#     its own per-provision token (23-08, T-23-28).
#  6. settings.php maps HOST_REPORT_EMAIL, AI_BACKUP_PROVIDER/AI_BACKUP_MODEL and OPENROUTER_BASE_URL
#     to their Drupal settings (run with php, skipped where php is missing).
# IS_DDEV_PROJECT and DDEV_HOSTNAME are exempt (DDEV-only). LOCALNODES_DEFER_INDEXING
# is exempt too: the entrypoint sets it for one drush command, never for the container.
#
# Usage: bash scripts/ops/tests/compose-env.test.sh   (env COMPOSE=... to test another copy)
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
COMPOSE="${COMPOSE:-$REPO/docker-compose.yaml}"
WF="$REPO/.github/workflows/provision-instance.yml"
SETTINGS="${SETTINGS:-$REPO/html/sites/default/settings.php}"
INSTALL="${INSTALL:-$REPO/html/modules/custom/localnodes_platform/localnodes_platform.install}"
PROXIES="${PROXIES:-$REPO/html/sites/default/trusted-proxies.php}"

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

PASS=0; FAIL=0
need() {  # need SOURCE KEY
  if grep -qx "$2" <<<"$ENV_KEYS"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - $2 (read by $1) is not in opensocial.environment"; fi
}

for K in $(grep -oE 'update_env "[A-Z0-9_]+"' "$WF" | cut -d'"' -f2 | sort -u); do
  case " $INTERPOLATION_ONLY " in
    *" $K "*) grep -q "\${$K" "$COMPOSE" && PASS=$((PASS + 1)) || { FAIL=$((FAIL + 1)); echo "FAIL - $K not referenced in compose"; } ;;
    *)
      case " $RELAYER_ONLY " in
        *" $K "*) if grep -qx "$K" <<<"$RELAYER_KEYS"; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL - $K is not in gas-sponsor-relayer.environment"; fi ;;
        *) need provision-instance.yml "$K" ;;
      esac ;;
  esac
done
for K in $(grep -ohE "getenv\('[A-Z0-9_]+'\)" "$SETTINGS" "$INSTALL" "$PROXIES" | cut -d"'" -f2 | sort -u); do
  case " $LOCAL_ONLY " in *" $K "*) continue ;; esac
  need "settings.php/localnodes_platform.install" "$K"
done
for K in RESEND_API_KEY MAIL_FROM ORGANIZER_EMAIL ORGANIZER_NAME PROVISION_CALLBACK_URL PROVISION_CALLBACK_TOKEN STRIPE_SESSION_ID WEB3_ENABLED AI_PROVIDER_SWITCH; do
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
if grep -qE 'update_env "PROVISION_CALLBACK_SECRET"' "$WF"; then
  FAIL=$((FAIL + 1)); echo "FAIL - provision-instance.yml still writes the shared PROVISION_CALLBACK_SECRET into a container"
else
  PASS=$((PASS + 1))
fi

# 6. settings.php env mappings, run for real with php (no Drupal needed: it only fills $settings/$config).
settings_probe() {  # settings_probe NAME=VALUE ... -> JSON {"s": $settings, "c": $config}
  env -i PATH="$PATH" HOME="${TMPDIR:-/tmp}" "$@" php -r '
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
  dsns="$(grep -oE "smtps?://[^;]*smtp\.resend\.com(:[0-9]+)?" "$f")"
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

echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
