#!/usr/bin/env bash
# Guard: composer-managed code must reach the image only through composer
# install (+ patches/), never through `COPY html/ html/`.
#
# What went wrong (found 2026-11-05): four contrib files were force-added to
# git in February (drupal/ai dev-1.3.x era) although .gitignore ignores
# html/modules/contrib. The Dockerfile's `COPY html/ html/` runs after
# `composer install`, so those copies overwrote the installed, patched files:
#   - ai_chatbot DeepChatApi.php: the old copy lacks drupal/ai 1.4.11's
#     Xss::filter() on the structured-results dump, and it silently dropped
#     patches/ai-chatbot-provider-error-200.patch (PR #44);
#   - ai_assistant_api AgentRunner.php: 1.3-era code on 1.4.11;
#   - gemini_provider GeminiProvider.php and api_defaults.yml: dropped
#     patches/gemini-provider-map-api-errors.patch (PR #44).
# The build log still said "Applying patches", because the patches did apply;
# the COPY then undid them.
#
# Usage: bash scripts/ops/tests/no-tracked-contrib.test.sh
#   IMAGE=ghcr.io/localnodes/os-knowledge-garden:<sha7>  also checks that the
#   built image carries the 1.4.11 code and both PR #44 patches (needs docker).
set -u
cd "$(dirname "$0")/../../.."
MANAGED="html/core html/modules/contrib html/profiles/contrib html/themes/contrib html/libraries"

PASS=0; FAIL=0
check() {  # check NAME EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then PASS=$((PASS + 1)); echo "PASS $1"
  else FAIL=$((FAIL + 1)); echo "FAIL $1 (expected $2, got $3)"; fi
}

for d in $MANAGED; do
  n="$(git ls-files -- "$d" | wc -l | tr -d ' ')"
  check "no files tracked under $d" 0 "$n"
  [ "$n" = 0 ] || git ls-files -- "$d" | sed 's/^/  tracked: /'
  grep -qxF "$d/" .dockerignore && got=yes || got=no
  check ".dockerignore excludes $d/" yes "$got"
done

if [ -n "${IMAGE:-}" ]; then
  got="$(docker run --rm --entrypoint sh "$IMAGE" -c '
    c=/var/www/html/html/modules/contrib
    grep -q "Xss::filter(Yaml::dump(\$structured" $c/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php && echo xss-filter
    grep -q "setRunnerId" $c/ai/modules/ai_assistant_api/src/Service/AgentRunner.php && echo agent-runner-1.4
    grep -q "function mapClientException" $c/gemini_provider/src/Plugin/AiProvider/GeminiProvider.php && echo gemini-map
    grep -q "], 200);" $c/ai/modules/ai_chatbot/src/Controller/DeepChatApi.php && echo deepchat-200
  ' 2>&1 | tr '\n' ' ' | sed 's/ $//')"
  check "image carries 1.4.11 code and the PR #44 patches" "xss-filter agent-runner-1.4 gemini-map deepchat-200" "$got"
else
  echo "SKIP image check (set IMAGE to run it)"
fi

echo "passed $PASS, failed $FAIL"
[ "$FAIL" -eq 0 ]
