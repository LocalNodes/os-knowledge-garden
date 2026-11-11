#!/usr/bin/env bash
#
# LocalNodes Instance Smoke Check (anonymous, read-mostly)
#
# Usage:
#   ./scripts/ops/smoke-instance.sh <host> [word=solstice] [expect="Solstice Gathering"] [--no-chat]
#
#   ./scripts/ops/smoke-instance.sh cascadia.localnodes.xyz --no-chat     # GET checks only
#   ./scripts/ops/smoke-instance.sh staging.localnodes.xyz                # GET checks + chatbot
#   ./scripts/ops/smoke-instance.sh boulder.localnodes.xyz solstice "Summer Solstice Gathering"
#
#   --no-chat may appear in any position.
#
# Environment:
#   SMOKE_CHAT_OUT=<path>   write the body of the last chatbot response to <path>
#                           (also on a failed check, so the shape can be inspected)
#
# Checks (each prints one "PASS <name>" or "FAIL <name> ..." line):
#   home          GET /                     200 and the group_assistant chat block's settings, read from
#                                           what the page renders (drupal-settings-json and the <deep-chat>
#                                           connect attribute); needs jq
#   search hit    GET /search/all/<word>    200 and the body contains <expect>
#   search miss   GET /search/all/zqxjkvnonsense   200 and "No results found"
#   chatbot       POST /api/deepchat/session (CSRF token), then POST /api/deepchat?token=...
#                 repeated with "dummy_loading" while should_continue is true (max 8 calls);
#                 PASS when the final html has "Sources" and at least one <a href=
#                 The request body is built from the rendered chat block settings, never hard-coded:
#                   drupal/ai 1.5.x: chat_processor_plugin and plugin_configuration exactly as the block
#                                    renders them in the <deep-chat> connect attribute;
#                   drupal/ai 1.4.x: the legacy body, from drupalSettings.ai_deepchat.assistant_id.
#                 HTTP 400 fails as "request refused: chat format". Settings not found: FAIL.
#
# The chatbot check is NOT read-only: it writes a temp store entry and spends Gemini quota.
# Use --no-chat against production (Cascadia) except for a single post-change verify.
# A chatbot FAIL within ~15 min of a fresh install can mean the vector queue has not
# drained yet (20-RESEARCH.md P14): retry later before treating it as a verdict.
#
# Exit status: 0 when every check passed, 1 when any check failed, 2 on bad usage.
#
# Sources: ai_chatbot.routing.yml:16-31, DeepChatApi.php::api() (1.5.2), DeepChatFormBlock.php
#          getDeepChatParameters() (the connect attribute) and build() (drupalSettings)
# Prerequisites: curl; jq (home and chatbot checks)
#
set -uo pipefail

usage() {
  echo "Usage: $0 <host> [word=solstice] [expect=\"Solstice Gathering\"] [--no-chat]" >&2
}

CHAT="yes"
ARGS=()
for a in "$@"; do
  if [[ "$a" == "--no-chat" ]]; then
    CHAT="no"
  else
    ARGS+=("$a")
  fi
done

HOST="${ARGS[0]:-}"
WORD="${ARGS[1]:-solstice}"
EXPECT="${ARGS[2]:-Solstice Gathering}"

if [[ -z "$HOST" ]]; then
  usage
  exit 2
fi

BASE="https://$HOST"
FAIL=0
JAR=$(mktemp "${TMPDIR:-/tmp}/smoke-jar.XXXXXX")
trap 'rm -f "$JAR"' EXIT

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAIL=1; }

# First 300 bytes of a body on one line (Cloudflare error pages are HTML 5xx, P11).
snippet() { printf '%s' "$1" | head -c 300 | tr '\r\n' '  '; }

# GET a URL; sets CODE and BODY. curl prints 000 on connection failure or timeout.
fetch() {
  local out
  out=$(curl -sS --max-time 60 -w $'\n%{http_code}' "$1" 2>&1)
  CODE="${out##*$'\n'}"
  BODY="${out%$'\n'*}"
}

# (a) Home page with the chat block present.
#
# The chat block's settings are read from what the page renders:
#   - drupalSettings (<script data-drupal-selector="drupal-settings-json">), key ai_deepchat;
#   - the <deep-chat connect='{...}'> attribute, whose additionalBodyProps drupal/ai 1.5.x fills
#     with chat_processor_plugin and plugin_configuration (the body the block's own JavaScript posts).
# Parsed with jq only, never evaluated. The values are sent back to the same host's /api/deepchat.
CHAT_MODE=""
CHAT_SETTINGS=""
fetch "$BASE/"
HOME_CODE="$CODE"
HOME_BODY="$BODY"
if [[ "$HOME_CODE" != "200" ]]; then
  fail "home code=$HOME_CODE body=$(snippet "$HOME_BODY")"
elif ! command -v jq >/dev/null 2>&1; then
  fail "home code=$HOME_CODE body=jq not installed"
else
  DS_JSON=$(grep -oE '<script type="application/json" data-drupal-selector="drupal-settings-json">[^<]*</script>' <<<"$HOME_BODY" \
    | head -n 1 | sed -E 's#^<script[^>]*>##; s#</script>$##')
  DS_ASSISTANT=$(jq -r '.ai_deepchat.assistant_id // empty' <<<"${DS_JSON:-null}" 2>/dev/null || true)
  DS_PRESENT=$(jq -r 'if (.ai_deepchat|type)=="object" then "yes" else "no" end' <<<"${DS_JSON:-null}" 2>/dev/null || echo no)
  # The <deep-chat> block for group_assistant: 1.5.x names it in plugin_configuration.
  while IFS= read -r attr; do
    [[ -n "$attr" ]] || continue
    j="${attr#connect=\'}"; j="${j%\'}"
    if jq -e '.additionalBodyProps.plugin_configuration.assistant_id == "group_assistant"
              and ((.additionalBodyProps.chat_processor_plugin // "") != "")' <<<"$j" >/dev/null 2>&1; then
      CHAT_SETTINGS=$(jq -c '{chat_processor_plugin: .additionalBodyProps.chat_processor_plugin,
                              plugin_configuration: .additionalBodyProps.plugin_configuration}' <<<"$j")
      CHAT_MODE="1.5"
      break
    fi
  done < <(grep -oE "connect='[^']*'" <<<"$HOME_BODY")
  if [[ -z "$CHAT_MODE" && "$DS_ASSISTANT" == "group_assistant" ]]; then
    # Instance still on drupal/ai 1.4.x: the assistant id is a drupalSettings value.
    CHAT_SETTINGS=$(jq -nc --arg a "$DS_ASSISTANT" '{assistant_id: $a}')
    CHAT_MODE="legacy"
  fi
  if [[ "$DS_PRESENT" == "yes" && -n "$CHAT_MODE" ]]; then
    pass "home (chat block group_assistant, $CHAT_MODE settings)"
  else
    fail "home code=$HOME_CODE chat block settings not found (drupalSettings ai_deepchat: $DS_PRESENT, mode: ${CHAT_MODE:-none})"
  fi
fi

# (b) Search hit for a known demo word.
fetch "$BASE/search/all/$WORD"
if [[ "$CODE" == "200" ]] && grep -qF "$EXPECT" <<<"$BODY"; then
  pass "search hit '$WORD'"
else
  fail "search hit '$WORD' code=$CODE body=$(snippet "$BODY")"
fi

# (c) Search miss: proves search is not just listing everything.
fetch "$BASE/search/all/zqxjkvnonsense"
if [[ "$CODE" == "200" ]] && grep -qF 'No results found' <<<"$BODY"; then
  pass "search miss"
else
  fail "search miss code=$CODE body=$(snippet "$BODY")"
fi

# (d) Chatbot answer with sources (skipped with --no-chat).
if [[ "$CHAT" == "yes" ]]; then
  if ! command -v jq >/dev/null 2>&1; then
    fail "chatbot code=n/a body=jq not installed"
  elif [[ -z "$CHAT_MODE" ]]; then
    fail "chatbot chat block settings not found"
  else
    out=$(curl -sS --max-time 120 -c "$JAR" -b "$JAR" -X POST -w $'\n%{http_code}' \
      "$BASE/api/deepchat/session" 2>&1)
    CODE="${out##*$'\n'}"
    TOKEN="${out%$'\n'*}"
    if [[ "$CODE" != "200" || -z "$TOKEN" ]]; then
      fail "chatbot session code=$CODE body=$(snippet "$TOKEN")"
    else
      TID="smoke-$(date +%s)-$RANDOM"
      TEXT="What events are happening and where? Cite sources."
      RESP=""
      for i in 1 2 3 4 5 6 7 8; do
        # The block's own settings, plus the thread id and the messages.
        PAYLOAD=$(jq -nc --argjson s "$CHAT_SETTINGS" --arg t "$TEXT" --arg tid "$TID" \
          '$s + {thread_id:$tid,messages:[{role:"user",text:$t}]}')
        out=$(curl -sS --max-time 120 -c "$JAR" -b "$JAR" -X POST \
          -H 'Content-Type: application/json' -w $'\n%{http_code}' \
          --data "$PAYLOAD" "$BASE/api/deepchat?token=$TOKEN" 2>&1)
        CODE="${out##*$'\n'}"
        RESP="${out%$'\n'*}"
        [[ "$CODE" == "200" ]] || break
        [[ "$(jq -r '.should_continue // false' <<<"$RESP" 2>/dev/null)" == "true" ]] || break
        TEXT="dummy_loading"
      done
      if [[ -n "${SMOKE_CHAT_OUT:-}" ]]; then
        printf '%s' "$RESP" >"$SMOKE_CHAT_OUT"
      fi
      HTML=$(jq -r '.html // empty' <<<"$RESP" 2>/dev/null)
      if [[ "$CODE" == "400" ]]; then
        fail "chatbot request refused: chat format ($CHAT_MODE body) code=400 body=$(snippet "$RESP")"
      elif [[ "$CODE" == "200" && -n "$HTML" ]] && grep -qi 'sources' <<<"$HTML" && grep -qF '<a href=' <<<"$HTML"; then
        pass "chatbot answer with sources"
      else
        fail "chatbot code=$CODE body=$(snippet "$RESP")"
      fi
    fi
  fi
fi

exit "$FAIL"
