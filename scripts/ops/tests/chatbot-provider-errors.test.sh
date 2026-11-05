#!/usr/bin/env bash
# Regression test: a provider error (e.g. Gemini's free-tier quota) must reach
# the chatbot user as the assistant's matching error message, not as a generic
# failure.
#
# Before the fix (Boulder, 2026-11-05 12:17Z): Gemini answered 429
# RESOURCE_EXHAUSTED "You exceeded your current quota". gemini_provider let the
# raw \Gemini\Exceptions\ErrorException through, so DeepChatApi used the
# generic error_message instead of group_assistant's AiQuotaException text, and
# returned it with HTTP 500, which deepchat-init.js's verbose path throws away.
#
# Two patches:
#   patches/gemini-provider-map-api-errors.patch  maps Gemini API errors to the
#     AI module's typed exceptions (quota, rate limit, setup, bad request,
#     request error).
#   patches/ai-chatbot-provider-error-200.patch   DeepChatApi returns the
#     chosen message with HTTP 200 so both front-end paths render it.
#
# Needs a built image (it carries drupal/ai, gemini_provider and the Gemini
# client). The patches are applied here, inside a throwaway container, on top
# of the image's already-patched module files; the image is not changed.
#
# Usage: IMAGE=ghcr.io/localnodes/os-knowledge-garden:<sha7> bash scripts/ops/tests/chatbot-provider-errors.test.sh
#   Set SKIP_PATCH=1 to run the same checks without applying the patches
#   (shows the failures the patches fix).
set -u
cd "$(dirname "$0")/../../.."
: "${IMAGE:?set IMAGE to a built image tag}"
SKIP_PATCH="${SKIP_PATCH:-0}"

TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT
# Output goes to a file, not $(...): bash 3.2 (macOS) misparses quotes inside a
# heredoc within command substitution.
docker run --rm -i -e SKIP_PATCH="$SKIP_PATCH" -v "$PWD:/repo:ro" --entrypoint sh "$IMAGE" -s > "$TMP" 2>&1 <<'SH'
set -u
root=/var/www/html
mkdir -p /tmp/t && cp -r "$root/html/modules/contrib/gemini_provider" /tmp/t/gemini_provider && cp -r "$root/html/modules/contrib/ai" /tmp/t/ai
if [ "$SKIP_PATCH" != 1 ]; then
  (cd /tmp/t/gemini_provider && patch -p1 --forward --quiet < /repo/patches/gemini-provider-map-api-errors.patch) && echo "APPLY gemini ok" || echo "APPLY gemini FAILED"
  (cd /tmp/t/ai && patch -p1 --forward --quiet < /repo/patches/ai-chatbot-provider-error-200.patch) && echo "APPLY ai ok" || echo "APPLY ai FAILED"
fi
cat > /tmp/t/run.php <<'PHP'
<?php
$root = '/var/www/html';
$loader = require "$root/vendor/autoload.php";
$loader->addPsr4('Drupal\\gemini_provider\\', '/tmp/t/gemini_provider/src', TRUE);
$loader->addPsr4('Drupal\\ai\\', '/tmp/t/ai/src', TRUE);
$loader->addPsr4('Drupal\\Core\\', "$root/html/core/lib/Drupal/Core");
$loader->addPsr4('Drupal\\Component\\', "$root/html/core/lib/Drupal/Component");
$cls = \Drupal\gemini_provider\Plugin\AiProvider\GeminiProvider::class;
$cases = [
  'quota'      => [429, 'RESOURCE_EXHAUSTED', 'You exceeded your current quota, please check your plan and billing details.', 'AiQuotaException'],
  'rate limit' => [429, 'RESOURCE_EXHAUSTED', 'Resource has been exhausted (e.g. check quota).', 'AiRateLimitException'],
  'bad key'    => [403, 'PERMISSION_DENIED', 'Method does not allow unregistered callers.', 'AiSetupFailureException'],
  'bad input'  => [400, 'INVALID_ARGUMENT', 'Request contains an invalid argument.', 'AiBadRequestException'],
  'overloaded' => [503, 'UNAVAILABLE', 'The model is overloaded. Please try again later.', 'AiRequestErrorException'],
  'unmapped'   => [404, 'NOT_FOUND', 'models/x is not found.', 'ErrorException'],
];
foreach ($cases as $name => [$code, $status, $msg, $want]) {
  $e = new \Gemini\Exceptions\ErrorException(['code' => $code, 'message' => $msg, 'status' => $status]);
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
printf '%s\n' "$OUT" | grep -E '^(APPLY|PHP |Fatal|Warning)' || true

PASS=0; FAIL=0
check() {  # check NAME EXPECTED
  got="$(printf '%s\n' "$OUT" | sed -n "s/^RESULT $1 //p" | head -1)"
  if [ "$got" = "$2" ]; then PASS=$((PASS + 1)); echo "PASS $1: $got"
  else FAIL=$((FAIL + 1)); echo "FAIL $1 (expected $2, got ${got:-nothing})"; fi
}
check quota 'AiQuotaException kept'
check 'rate limit' 'AiRateLimitException kept'
check 'bad key' 'AiSetupFailureException kept'
check 'bad input' 'AiBadRequestException kept'
check overloaded 'AiRequestErrorException kept'
check unmapped 'ErrorException kept'
check deepchat-status 200
printf '%s\n' "$OUT" | grep -q 'APPLY .* FAILED' && { FAIL=$((FAIL + 1)); echo "FAIL a patch did not apply"; }

echo "passed $PASS, failed $FAIL"
[ "$FAIL" -eq 0 ]
