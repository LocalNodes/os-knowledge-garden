#!/usr/bin/env bash
# Install-time config checks for localnodes_platform.
#  1. group_assistant's error_message and all specific_error_messages are non-empty.
#     DeepChatApi.php falls back to error_message only on null (??), so an empty
#     specific message reaches the user as {"error":""}.
#  2. localnodes_platform depends on drupal:dblog, so a fresh install keeps the
#     watchdog log (the only place the AI provider's real error is recorded).
#  3. localnodes_platform ships symfony_mailer's test-email policy with a
#     non-empty subject. Open Social deletes the module's own copy at install,
#     and Resend refuses mail without a subject (550, staging 2026-11-06).
# Usage: bash scripts/ops/tests/install-config.test.sh   (env ASSISTANT=..., INFO=... to test other copies)
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
M="$REPO/html/modules/custom/localnodes_platform"
ASSISTANT="${ASSISTANT:-$M/config/install/ai_assistant_api.ai_assistant.group_assistant.yml}"
INFO="${INFO:-$M/localnodes_platform.info.yml}"
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); }
bad() { FAIL=$((FAIL + 1)); echo "FAIL - $1"; }

OUT="$(ruby -ryaml -e '
  a = YAML.load_file(ARGV[0])
  puts "error_message" if a["error_message"].to_s.strip.empty?
  sm = a["specific_error_messages"] || {}
  %w[AiBadRequestException AiRateLimitException AiQuotaException AiSetupFailureException AiRequestErrorException].each do |k|
    puts "specific_error_messages.#{k}" if sm[k].to_s.strip.empty?
  end' "$ASSISTANT")" || { bad "could not parse $ASSISTANT"; OUT=""; }
if [ -z "$OUT" ]; then ok; else while IFS= read -r k; do bad "group_assistant $k is empty"; done <<<"$OUT"; fi

if ruby -ryaml -e 'exit((YAML.load_file(ARGV[0])["dependencies"] || []).include?("drupal:dblog") ? 0 : 1)' "$INFO"; then ok
else bad "localnodes_platform.info.yml does not depend on drupal:dblog"; fi

POLICY="${POLICY:-$M/config/optional/symfony_mailer.mailer_policy.symfony_mailer.test.yml}"
if [ ! -f "$POLICY" ]; then bad "symfony_mailer test-email policy not shipped ($POLICY)"
elif ruby -ryaml -e '
  p = YAML.load_file(ARGV[0])
  ok = p["id"] == "symfony_mailer.test" &&
       !p.dig("configuration", "email_subject", "value").to_s.strip.empty? &&
       !p.dig("configuration", "email_body", "content", "value").to_s.strip.empty? &&
       (p.dig("dependencies", "module") || []).include?("symfony_mailer")
  exit(ok ? 0 : 1)' "$POLICY"; then ok
else bad "symfony_mailer test-email policy has no id/subject/body/module dependency"; fi

echo "passed: $PASS  failed: $FAIL"
[ "$FAIL" -eq 0 ]
