#!/usr/bin/env bash
# Lane check (22.2 D-25): the whole site with big_pipe off or on.
#
# Usage (from the lane's repo root, DDEV running, a localnodes_demo site installed):
#   bash tests/phase222/bigpipe-site-check.sh <host> --expect=off|on [--compare=<file>]
#   bash tests/phase222/bigpipe-site-check.sh fresh3-p222.ddev.site --expect=off | tee /tmp/p222-16-off.txt
#   bash tests/phase222/bigpipe-site-check.sh fresh3-p222.ddev.site --expect=on --compare=/tmp/p222-16-off.txt
#
# --expect says which mode the site is in. The script first checks that the site
# really is in that mode (drush pm:list), so a run against the wrong mode can't
# pass. --compare=<file> takes the output of an earlier --expect=off run and
# fails when the visitor cache lines differ from it (visitor caching must be the
# same off and on).
#
# Targets: /tmp/p222-16-targets.txt (override with BIGPIPE_TARGETS), one line:
#   GROUP=<path> TOPIC=<path> UID=<n> [WORD=<a word from the demo>]
# GROUP is a group the member belongs to. TOPIC is a public Topic (a visitor can open it).
#
# Each line is "PASS <name>", "FAIL <name> <detail>" or "INFO <text>".
#   mode           the site's big_pipe state matches --expect
#   visitor        for each page: the status is the same on two requests, there is no
#                  BigPipe placeholder in the body, and (when page_cache is enabled)
#                  the second request is X-Drupal-Cache: HIT. A page a visitor may not
#                  open (a members-only group) must redirect to the login page, twice.
#                  INFO lines print page_cache, dynamic page cache and whether the
#                  response sets a cookie, so the off and on runs can be compared.
#   member         logged in through `drush uli`: each page answers 200. off: no
#                  placeholder. on: every placeholder id has a replacement in the same
#                  response (the placeholder count is an INFO line).
#   chat block     the member's group page carries "assistant_id":"group_assistant"
#   overview       /api/ai/overview?q=<word> as the member answers 200 with JSON
#   log            no watchdog entry of severity error or worse was written during the run
#
# The overview request calls Gemini once per run.
# TLS: desktop-dev doesn't trust DDEV's certificate, so curl runs with -k. This
# script is for the lane's local test host only.
#
# Exit status: 0 when every check passed, 1 when any check failed, 2 on bad usage.
# Used by plans 22.2-16 and 22.2-14.
set -uo pipefail

usage() {
  echo "Usage: $0 <host> --expect=off|on [--compare=<file>]" >&2
}

HOST=""
EXPECT=""
COMPARE=""
for a in "$@"; do
  case "$a" in
    --expect=off|--expect=on) EXPECT="${a#--expect=}" ;;
    --compare=*) COMPARE="${a#--compare=}" ;;
    -*) usage; exit 2 ;;
    *) if [[ -z "$HOST" ]]; then HOST="$a"; else usage; exit 2; fi ;;
  esac
done
if [[ -z "$HOST" || -z "$EXPECT" ]]; then
  usage
  exit 2
fi
if [[ -n "$COMPARE" && ! -r "$COMPARE" ]]; then
  echo "bigpipe-site-check: --compare file not readable: $COMPARE" >&2
  exit 2
fi

cd "$(git rev-parse --show-toplevel)" || { echo "FAIL setup: not inside a git checkout"; exit 1; }

TARGETS="${BIGPIPE_TARGETS:-/tmp/p222-16-targets.txt}"
DRUSH="${DRUSH:-ddev drush}"
BASE="https://$HOST"
FAIL=0
pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAIL=1; }
info() { echo "INFO $*"; }

if [[ ! -r "$TARGETS" ]]; then
  echo "FAIL setup: targets file $TARGETS not found (see the usage comment)"
  exit 1
fi
GROUP=""; TOPIC=""; UID_=""; WORD="solstice"
for kv in $(cat "$TARGETS"); do
  case "$kv" in
    GROUP=*) GROUP="${kv#GROUP=}" ;;
    TOPIC=*) TOPIC="${kv#TOPIC=}" ;;
    UID=*) UID_="${kv#UID=}" ;;
    WORD=*) WORD="${kv#WORD=}" ;;
  esac
done
if [[ -z "$GROUP" || -z "$TOPIC" || -z "$UID_" ]]; then
  echo "FAIL setup: $TARGETS needs GROUP=, TOPIC= and UID="
  exit 1
fi
for tool in curl jq; do
  command -v "$tool" >/dev/null 2>&1 || { echo "FAIL setup: $tool is required"; exit 1; }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
CURL=(curl -sk --max-time 60)

# --- helpers -----------------------------------------------------------------

# Number of BigPipe placeholder spans (JS and no-JS) in a file.
placeholder_count() {
  grep -oE 'data-big-pipe-(nojs-)?placeholder-id="[^"]*"' "$1" | wc -l | tr -d ' '
}
# Placeholder ids in a file, one per line, sorted.
placeholder_ids() {
  grep -oE 'data-big-pipe-(nojs-)?placeholder-id="[^"]*"' "$1" | sed -E 's/^[^"]*"//; s/"$//' | sort -u
}
# Replacement ids in a file, one per line, sorted. The JS strategy streams a
# <script ... data-big-pipe-replacement-for-placeholder-with-id="ID">. The no-JS
# strategy streams the markup into the same attribute on a script wrapper too.
replacement_ids() {
  grep -oE 'data-big-pipe-replacement-for-placeholder-with-id="[^"]*"' "$1" | sed -E 's/^[^"]*"//; s/"$//' | sort -u
}
header_value() { # <header file> <name>  (last occurrence, trimmed)
  grep -i "^$2:" "$1" | tail -n1 | sed -E "s/^[^:]*:[[:space:]]*//" | tr -d '\r'
}
status_of() { head -n1 "$1" | awk '{print $2}'; }

# --- mode --------------------------------------------------------------------

ENABLED="$($DRUSH pm:list --status=enabled --format=list 2>/dev/null | tr -d '\r')"
if [[ -z "$ENABLED" ]]; then
  echo "FAIL setup: drush pm:list printed nothing (is DDEV up?)"
  exit 1
fi
BP_ON="no"; PC_ON="no"; DPC_ON="no"
grep -qx big_pipe <<<"$ENABLED" && BP_ON="yes"
grep -qx page_cache <<<"$ENABLED" && PC_ON="yes"
grep -qx dynamic_page_cache <<<"$ENABLED" && DPC_ON="yes"
if [[ "$EXPECT" == "on" && "$BP_ON" == "yes" ]] || [[ "$EXPECT" == "off" && "$BP_ON" == "no" ]]; then
  pass "mode big_pipe $EXPECT"
else
  fail "mode big_pipe is enabled=$BP_ON but --expect=$EXPECT"
fi
info "page_cache enabled=$PC_ON dynamic_page_cache enabled=$DPC_ON"

LOG_START="$($DRUSH sql:query 'SELECT COALESCE(MAX(wid),0) FROM watchdog' 2>/dev/null | tr -d '\r\n ')"
LOG_START="${LOG_START:-0}"

# --- visitor pages -------------------------------------------------------------

VISITOR_PAGES=("/" "/user/login" "/all-topics" "/all-groups" "/community-events" "$GROUP" "$TOPIC")
CACHE_LINES="$TMP/cache-lines.txt"
: >"$CACHE_LINES"
for p in "${VISITOR_PAGES[@]}"; do
  rm -f "$TMP/vjar.txt"
  "${CURL[@]}" -c "$TMP/vjar.txt" -D "$TMP/v1.h" -o "$TMP/v1.b" "$BASE$p"
  "${CURL[@]}" -D "$TMP/v2.h" -o "$TMP/v2.b" "$BASE$p"
  s1="$(status_of "$TMP/v1.h")"; s2="$(status_of "$TMP/v2.h")"
  detail=""
  ok=1
  if [[ "$s1" != "$s2" ]]; then ok=0; detail="status $s1 then $s2"; fi
  if [[ "$s1" == "200" ]]; then
    :
  elif [[ "$s1" =~ ^30[1278]$ ]] && grep -qi '^location:.*/user/login' "$TMP/v1.h"; then
    : # a members-only page: the visitor is sent to the login page, twice
  else
    ok=0; detail="${detail:+$detail; }status $s1 is neither 200 nor a redirect to the login page"
  fi
  n1="$(placeholder_count "$TMP/v1.b")"; n2="$(placeholder_count "$TMP/v2.b")"
  if [[ "$n1" != "0" || "$n2" != "0" ]]; then ok=0; detail="${detail:+$detail; }BigPipe placeholder in visitor HTML ($n1, $n2)"; fi
  pc="$(header_value "$TMP/v2.h" X-Drupal-Cache)"; dpc="$(header_value "$TMP/v2.h" X-Drupal-Dynamic-Cache)"
  ck="no"; grep -qi '^set-cookie:' "$TMP/v2.h" && ck="yes"
  if [[ "$PC_ON" == "yes" && "$s1" == "200" && "$pc" != "HIT" ]]; then ok=0; detail="${detail:+$detail; }page_cache is enabled but the second request was X-Drupal-Cache: ${pc:-absent}"; fi
  if [[ $ok -eq 1 ]]; then pass "visitor $p ($s1)"; else fail "visitor $p $detail"; fi
  # The cache line is what the off and on runs are compared on. Printed with INFO.
  line="visitor-cache $p status=$s1 x-drupal-cache=${pc:--} x-drupal-dynamic-cache=${dpc:--} sets-cookie=$ck"
  echo "$line" >>"$CACHE_LINES"
  info "$line"
  # A visitor who comes back with the session cookie the first response set has a
  # session, so BigPipe may stream to them (core only streams to requests with a
  # session). Not a cache question; every placeholder must still be replaced.
  if [[ -s "$TMP/vjar.txt" && "$s1" == "200" ]]; then
    "${CURL[@]}" -b "$TMP/vjar.txt" -D "$TMP/v3.h" -o "$TMP/v3.b" "$BASE$p"
    n3="$(placeholder_count "$TMP/v3.b")"
    placeholder_ids "$TMP/v3.b" >"$TMP/ph.txt"; replacement_ids "$TMP/v3.b" >"$TMP/rp.txt"
    miss3="$(comm -23 "$TMP/ph.txt" "$TMP/rp.txt" | wc -l | tr -d ' ')"
    info "visitor-with-cookie $p status=$(status_of "$TMP/v3.h") placeholders=$n3 unreplaced=$miss3"
    if [[ "$miss3" != "0" ]]; then fail "visitor-with-cookie $p has $miss3 placeholder id(s) without a replacement"; fi
    if [[ "$EXPECT" == "off" && "$n3" != "0" ]]; then fail "visitor-with-cookie $p has $n3 placeholders with big_pipe off"; fi
  fi
done

if [[ -n "$COMPARE" ]]; then
  grep '^INFO visitor-cache ' "$COMPARE" | sed 's/^INFO //' >"$TMP/off-lines.txt"
  if [[ ! -s "$TMP/off-lines.txt" ]]; then
    fail "visitor cache compare: $COMPARE has no visitor-cache lines"
  elif diff -u "$TMP/off-lines.txt" "$CACHE_LINES" >"$TMP/cache.diff"; then
    pass "visitor cache lines are the same as in $COMPARE"
  else
    fail "visitor cache lines differ from $COMPARE: $(grep -E '^[+-]visitor' "$TMP/cache.diff" | tr '\n' '|')"
  fi
fi

# --- member pages --------------------------------------------------------------

JAR="$TMP/jar.txt"
ULI="$($DRUSH uli --uid="$UID_" --no-browser 2>/dev/null | tr -d '\r\n')"
ULI_PATH="$(sed -E 's#^https?://[^/]+##' <<<"$ULI")"
if [[ -z "$ULI_PATH" ]]; then
  fail "member login: drush uli --uid=$UID_ printed no link"
  exit 1
fi
LOGIN_CODE="$("${CURL[@]}" -c "$JAR" -b "$JAR" -L -o /dev/null -w '%{http_code}' "$BASE$ULI_PATH")"
if [[ "$LOGIN_CODE" == "200" ]]; then pass "member login uid=$UID_"; else fail "member login uid=$UID_ answered $LOGIN_CODE"; exit 1; fi

MEMBER_PAGES=("/" "/stream" "/user/login" "/all-topics" "/all-groups" "/community-events" "$GROUP" "$TOPIC")
TOTAL_PLACEHOLDERS=0
for p in "${MEMBER_PAGES[@]}"; do
  code="$("${CURL[@]}" -b "$JAR" -c "$JAR" -L -D "$TMP/m.h" -o "$TMP/m.b" -w '%{http_code}' "$BASE$p")"
  if [[ "$code" != "200" ]]; then fail "member $p answered $code"; continue; fi
  n="$(placeholder_count "$TMP/m.b")"
  chunked="no"; grep -qi '^transfer-encoding:.*chunked' "$TMP/m.h" && chunked="yes"
  if [[ "$EXPECT" == "off" ]]; then
    if [[ "$n" == "0" ]]; then pass "member $p (200, no placeholders)"; else fail "member $p has $n BigPipe placeholders with big_pipe off"; fi
  else
    TOTAL_PLACEHOLDERS=$((TOTAL_PLACEHOLDERS + n))
    placeholder_ids "$TMP/m.b" >"$TMP/ph.txt"
    replacement_ids "$TMP/m.b" >"$TMP/rp.txt"
    missing="$(comm -23 "$TMP/ph.txt" "$TMP/rp.txt" | wc -l | tr -d ' ')"
    info "member $p placeholders=$n replacements=$(wc -l <"$TMP/rp.txt" | tr -d ' ') chunked=$chunked"
    if [[ "$missing" == "0" ]]; then pass "member $p (200, every placeholder replaced)"; else fail "member $p has $missing placeholder id(s) without a replacement: $(comm -23 "$TMP/ph.txt" "$TMP/rp.txt" | head -3 | tr '\n' ' ')"; fi
  fi
done
if [[ "$EXPECT" == "on" ]]; then info "member placeholders in total=$TOTAL_PLACEHOLDERS"; fi

# --- chat block ------------------------------------------------------------------

"${CURL[@]}" -b "$JAR" -c "$JAR" -L -o "$TMP/g.b" "$BASE$GROUP"
# With big_pipe on the chat block can arrive in a streamed replacement, where the
# block's JSON is escaped (\u0022 for a double quote). Either spelling counts; the
# INFO line says which one the page had.
if grep -qF '"assistant_id":"group_assistant"' "$TMP/g.b"; then
  pass "chat block on the member's group page"
  info "chat block marker is in the page HTML as plain JSON"
elif grep -qF '\u0022assistant_id\u0022:\u0022group_assistant\u0022' "$TMP/g.b"; then
  pass "chat block on the member's group page"
  info "chat block marker is in an escaped (streamed or script-held) form"
else
  fail "chat block marker \"assistant_id\":\"group_assistant\" not found on $GROUP as the member"
fi

# --- overview --------------------------------------------------------------------

ocode="$("${CURL[@]}" --max-time 120 -b "$JAR" -H 'Accept: application/json' -o "$TMP/o.json" -w '%{http_code}' "$BASE/api/ai/overview?q=$WORD")"
if [[ "$ocode" == "200" ]] && jq -e 'type == "object"' "$TMP/o.json" >/dev/null 2>&1; then
  pass "overview as the member (200, JSON)"
else
  fail "overview as the member answered $ocode, body: $(head -c 120 "$TMP/o.json" | tr '\n' ' ')"
fi

# --- log -------------------------------------------------------------------------

LOG_ERRORS="$($DRUSH sql:query "SELECT COUNT(*) FROM watchdog WHERE wid > $LOG_START AND severity <= 3" 2>/dev/null | tr -d '\r\n ')"
if [[ "$LOG_ERRORS" == "0" ]]; then
  pass "log: no error-level watchdog entries during the run"
else
  fail "log: ${LOG_ERRORS:-unknown} error-level watchdog entries since wid $LOG_START: $($DRUSH sql:query "SELECT CONCAT(type, ': ', LEFT(message, 100)) FROM watchdog WHERE wid > $LOG_START AND severity <= 3 LIMIT 3" 2>/dev/null | tr -d '\r' | tr '\n' '|')"
fi

exit "$FAIL"
