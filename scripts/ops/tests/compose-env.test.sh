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
#  2. Every getenv('KEY') in settings.php and localnodes_platform.install is in it.
#  3. The entrypoint's mail, organizer and WEB3_ENABLED variables are in it.
#  4. Resend SMTP is smtps:// (implicit TLS) on 2465 in the install hook and settings.php.
# IS_DDEV_PROJECT is exempt (DDEV-only).
#
# Usage: bash scripts/ops/tests/compose-env.test.sh   (env COMPOSE=... to test another copy)
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
COMPOSE="${COMPOSE:-$REPO/docker-compose.yaml}"
WF="$REPO/.github/workflows/provision-instance.yml"
SETTINGS="${SETTINGS:-$REPO/html/sites/default/settings.php}"
INSTALL="${INSTALL:-$REPO/html/modules/custom/localnodes_platform/localnodes_platform.install}"

INTERPOLATION_ONLY="IMAGE_TAG"
LOCAL_ONLY="IS_DDEV_PROJECT"   # set by DDEV only; never present in production

ENV_KEYS="$(ruby -ryaml -e '
  env = YAML.load_file(ARGV[0])["services"]["opensocial"]["environment"]
  keys = env.is_a?(Hash) ? env.keys : env.map { |e| e.split("=", 2)[0] }
  puts keys' "$COMPOSE")" || { echo "FAIL - could not parse $COMPOSE"; exit 1; }

PASS=0; FAIL=0
need() {  # need SOURCE KEY
  if grep -qx "$2" <<<"$ENV_KEYS"; then PASS=$((PASS + 1));
  else FAIL=$((FAIL + 1)); echo "FAIL - $2 (read by $1) is not in opensocial.environment"; fi
}

for K in $(grep -oE 'update_env "[A-Z0-9_]+"' "$WF" | cut -d'"' -f2 | sort -u); do
  case " $INTERPOLATION_ONLY " in
    *" $K "*) grep -q "\${$K" "$COMPOSE" && PASS=$((PASS + 1)) || { FAIL=$((FAIL + 1)); echo "FAIL - $K not referenced in compose"; } ;;
    *) need provision-instance.yml "$K" ;;
  esac
done
for K in $(grep -ohE "getenv\('[A-Z0-9_]+'\)" "$SETTINGS" "$INSTALL" | cut -d"'" -f2 | sort -u); do
  case " $LOCAL_ONLY " in *" $K "*) continue ;; esac
  need "settings.php/localnodes_platform.install" "$K"
done
for K in RESEND_API_KEY ORGANIZER_EMAIL ORGANIZER_NAME PROVISION_CALLBACK_URL PROVISION_CALLBACK_SECRET STRIPE_SESSION_ID WEB3_ENABLED; do
  need entrypoint.sh "$K"
done

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
