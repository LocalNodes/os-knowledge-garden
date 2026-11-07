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
# Checks (each prints one "PASS <name>" or "FAIL <name> ..." line):
#   home          GET /                     200 and the chat block marker "assistant_id":"group_assistant"
#   search hit    GET /search/all/<word>    200 and the body contains <expect>
#   search miss   GET /search/all/zqxjkvnonsense   200 and "No results found"
#   chatbot       POST /api/deepchat/session (CSRF token), then POST /api/deepchat?token=...
#                 repeated with "dummy_loading" while should_continue is true (max 8 calls);
#                 PASS when the final html has "Sources" and at least one <a href=
#
# The chatbot check is NOT read-only: it writes a temp store entry and spends Gemini quota.
# Use --no-chat against production (Cascadia) except for a single post-change verify.
# A chatbot FAIL within ~15 min of a fresh install can mean the vector queue has not
# drained yet (20-RESEARCH.md P14): retry later before treating it as a verdict.
#
# Exit status: 0 when every check passed, 1 when any check failed, 2 on bad usage.
#
# Sources: ai_chatbot.routing.yml:16-31, DeepChatApi.php:123-301, deepchat-init.js:255-310
# Prerequisites: curl; jq (chatbot check only)
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
fetch "$BASE/"
if [[ "$CODE" == "200" ]] && grep -qF '"assistant_id":"group_assistant"' <<<"$BODY"; then
  pass "home"
else
  fail "home code=$CODE body=$(snippet "$BODY")"
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
        PAYLOAD=$(jq -nc --arg t "$TEXT" --arg tid "$TID" \
          '{assistant_id:"group_assistant",thread_id:$tid,messages:[{role:"user",text:$t}]}')
        out=$(curl -sS --max-time 120 -c "$JAR" -b "$JAR" -X POST \
          -H 'Content-Type: application/json' -w $'\n%{http_code}' \
          --data "$PAYLOAD" "$BASE/api/deepchat?token=$TOKEN" 2>&1)
        CODE="${out##*$'\n'}"
        RESP="${out%$'\n'*}"
        [[ "$CODE" == "200" ]] || break
        [[ "$(jq -r '.should_continue // false' <<<"$RESP" 2>/dev/null)" == "true" ]] || break
        TEXT="dummy_loading"
      done
      HTML=$(jq -r '.html // empty' <<<"$RESP" 2>/dev/null)
      if [[ "$CODE" == "200" && -n "$HTML" ]] && grep -qi 'sources' <<<"$HTML" && grep -qF '<a href=' <<<"$HTML"; then
        pass "chatbot answer with sources"
      else
        fail "chatbot code=$CODE body=$(snippet "$RESP")"
      fi
    fi
  fi
fi

exit "$FAIL"
