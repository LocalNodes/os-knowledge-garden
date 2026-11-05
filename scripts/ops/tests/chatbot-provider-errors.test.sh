#!/usr/bin/env bash
# Regression test: a provider error (e.g. Gemini's free-tier quota) must reach
# the chatbot user as the assistant's matching error message, not as a generic
# failure.
#
# Before the fix (Boulder, 2026-11-05 12:17Z): Gemini answered 429
# RESOURCE_EXHAUSTED "You exceeded your current quota". gemini_provider let the
# raw \Gemini\Exceptions\ErrorException through, so DeepChatApi used the
# generic error_message instead of group_assistant's quota text, and returned
# it with HTTP 500, which deepchat-init.js's verbose path throws away.
#
# Two patches:
#   patches/gemini-provider-map-api-errors.patch  maps Gemini API errors to the
#     AI module's typed exceptions. A 429 is a quota only when it names a
#     per-day limit; per-minute and ambiguous 429s are rate limits.
#   patches/ai-chatbot-provider-error-200.patch   DeepChatApi returns the
#     chosen message with HTTP 200 so both front-end paths render it.
#
# Needs a built image (drupal/ai, gemini_provider and the Gemini client).
#
# Usage:
#   IMAGE=ghcr.io/localnodes/os-knowledge-garden:<sha7> bash scripts/ops/tests/chatbot-provider-errors.test.sh
#     Checks the image as built: both patches must already be applied
#     (patch -R --dry-run succeeds). Passes on an image built with the
#     patches, fails on one built without them.
#   APPLY=1 IMAGE=<image built without the patches> bash ...
#     Applies the patches to copies of the image's module files inside a
#     throwaway container first, to test a change before it is merged.
set -u
cd "$(dirname "$0")/../../.."
: "${IMAGE:?set IMAGE to a built image tag}"
APPLY="${APPLY:-0}"

TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT
# Output goes to a file, not $(...): bash 3.2 (macOS) misparses quotes inside a
# heredoc within command substitution.
docker run --rm -i -e APPLY="$APPLY" -v "$PWD:/repo:ro" --entrypoint sh "$IMAGE" -s > "$TMP" 2>&1 <<'SH'
set -u
root=/var/www/html
mkdir -p /tmp/t && cp -r "$root/html/modules/contrib/gemini_provider" /tmp/t/gemini_provider && cp -r "$root/html/modules/contrib/ai" /tmp/t/ai
for pair in gemini_provider:gemini-provider-map-api-errors.patch ai:ai-chatbot-provider-error-200.patch; do
  dir=/tmp/t/${pair%%:*}; p=/repo/patches/${pair#*:}
  if (cd "$dir" && patch -p1 -R --dry-run --quiet < "$p" >/dev/null 2>&1); then
    echo "PATCH ${pair#*:} already-applied"
  elif [ "$APPLY" = 1 ] && (cd "$dir" && patch -p1 --forward --quiet < "$p"); then
    echo "PATCH ${pair#*:} applied-now"
  else
    echo "PATCH ${pair#*:} not-applied"
  fi
done
cat > /tmp/t/run.php <<'PHP'
<?php
$root = '/var/www/html';
$loader = require "$root/vendor/autoload.php";
$loader->addPsr4('Drupal\\gemini_provider\\', '/tmp/t/gemini_provider/src', TRUE);
$loader->addPsr4('Drupal\\ai\\', '/tmp/t/ai/src', TRUE);
$loader->addPsr4('Drupal\\Core\\', "$root/html/core/lib/Drupal/Core");
$loader->addPsr4('Drupal\\Component\\', "$root/html/core/lib/Drupal/Component");
$cls = \Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider::class;
$quota_text = 'You exceeded your current quota, please check your plan and billing details. For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits. To monitor your current usage, head to: https://ai.dev/rate-limit. ';
$failure = fn (string $quota_id, string $metric) => ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaMetric' => $metric, 'quotaId' => $quota_id, 'quotaDimensions' => ['location' => 'global', 'model' => 'gemini-3-flash'], 'quotaValue' => '20']]];
$retry = fn (string $delay) => ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $delay];
$metric = 'generativelanguage.googleapis.com/generate_content_free_tier_requests';
$cases = [
  // Message text exactly as Boulder logged it on 2026-11-05 12:35:17Z (dblog
  // keeps only the message, so the details here follow Gemini's documented
  // QuotaFailure/RetryInfo shape). Note the per-day error has a retry hint too.
  'per-day' => [[
    'code' => 429, 'status' => 'RESOURCE_EXHAUSTED',
    'message' => $quota_text . "\n* Quota exceeded for metric: $metric, limit: 20, model: gemini-3-flash, description: Generate content requests per day limit exceeded on Free Tier. See https://ai.google.dev/gemini-api/docs/rate-limits.\nPlease retry in 19h24m42.939394817s.",
    'details' => [$failure('GenerateRequestsPerDayPerProjectPerModel-FreeTier', $metric), $retry('69882s')],
  ], 'AiQuotaException'],
  // Per-minute (RPM) 429: same opening text, PerMinute quotaId, short retry.
  'per-minute' => [[
    'code' => 429, 'status' => 'RESOURCE_EXHAUSTED',
    'message' => $quota_text . "\n* Quota exceeded for metric: $metric, limit: 5, model: gemini-3-flash\nPlease retry in 41.6s.",
    'details' => [$failure('GenerateRequestsPerMinutePerProjectPerModel-FreeTier', $metric), $retry('41s')],
  ], 'AiRateLimitException'],
  // Per-minute token (TPM) limit.
  'per-minute-tokens' => [[
    'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => $quota_text,
    'details' => [$failure('GenerateContentInputTokensPerModelPerMinute-FreeTier', 'generativelanguage.googleapis.com/generate_content_free_tier_input_token_count'), $retry('12s')],
  ], 'AiRateLimitException'],
  // Per-day named only in the message (no details).
  'per-day-text-only' => [['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => $quota_text . ' description: Generate content requests per day limit exceeded on Free Tier.'], 'AiQuotaException'],
  // Ambiguous: quota text, retry hint, no per-day/per-minute marker.
  'ambiguous-retry' => [['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => $quota_text, 'details' => [$retry('30s')]], 'AiRateLimitException'],
  'ambiguous-bare' => [['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Resource has been exhausted (e.g. check quota).'], 'AiRateLimitException'],
  'bad-key' => [['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'Method does not allow unregistered callers.'], 'AiSetupFailureException'],
  'bad-input' => [['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Request contains an invalid argument.'], 'AiBadRequestException'],
  'overloaded' => [['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'The model is overloaded. Please try again later.'], 'AiRequestErrorException'],
  'unmapped' => [['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'models/x is not found.'], 'ErrorException'],
];
foreach ($cases as $name => [$contents, $want]) {
  $e = new \Gemini\Exceptions\ErrorException($contents);
  if (!method_exists($cls, 'mapClientException')) { echo "RESULT $name missing-mapper\n"; continue; }
  $out = $cls::mapClientException($e);
  $got = (new \ReflectionClass($out))->getShortName();
  $kept = ($out === $e || $out->getPrevious() === $e) ? 'kept' : 'lost';
  echo "RESULT $name $got $kept\n";
}
$src = file_get_contents('/tmp/t/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php');
preg_match('/\$this->getLogger\(\'ai_chatbot\'\)->error\(.*?return new JsonResponse\(\[\s*\'error\' => \$error_message,\s*\], (\d+)\);/s', $src, $m);
echo 'RESULT deepchat-status ' . ($m[1] ?? 'not-found') . "\n";
PHP
php /tmp/t/run.php 2>&1
SH
OUT="$(cat "$TMP")"
printf '%s\n' "$OUT" | grep -E '^(PATCH|PHP |Fatal|Warning)' || true

PASS=0; FAIL=0
check() {  # check NAME EXPECTED
  got="$(printf '%s\n' "$OUT" | sed -n "s/^RESULT $1 //p" | head -1)"
  if [ "$got" = "$2" ]; then PASS=$((PASS + 1)); echo "PASS $1: $got"
  else FAIL=$((FAIL + 1)); echo "FAIL $1 (expected $2, got ${got:-nothing})"; fi
}
for p in gemini-provider-map-api-errors.patch ai-chatbot-provider-error-200.patch; do
  if printf '%s\n' "$OUT" | grep -q "^PATCH $p not-applied"; then
    FAIL=$((FAIL + 1)); echo "FAIL $p is not applied in $IMAGE (APPLY=1 tests it on copies)"
  else PASS=$((PASS + 1)); echo "PASS $p $(printf '%s\n' "$OUT" | sed -n "s/^PATCH $p //p")"; fi
done
check per-day 'AiQuotaException kept'
check per-minute 'AiRateLimitException kept'
check per-minute-tokens 'AiRateLimitException kept'
check per-day-text-only 'AiQuotaException kept'
check ambiguous-retry 'AiRateLimitException kept'
check ambiguous-bare 'AiRateLimitException kept'
check bad-key 'AiSetupFailureException kept'
check bad-input 'AiBadRequestException kept'
check overloaded 'AiRequestErrorException kept'
check unmapped 'ErrorException kept'
check deepchat-status 200

echo "passed $PASS, failed $FAIL"
[ "$FAIL" -eq 0 ]
