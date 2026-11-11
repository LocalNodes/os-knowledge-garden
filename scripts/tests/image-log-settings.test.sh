#!/bin/bash
# Checks a built LocalNodes image, not the Dockerfile text, for the two log
# settings that keep members' words out of the container log:
#
# - PHP's effective zend.exception_ignore_args is 1, so a logged backtrace
#   shows calls without their arguments (a question, a page's text).
# - Apache's access log line for a request whose query string and Referer
#   hold canaries keeps the method, path and status but neither canary.
# - A search page's path (/search/all/<words>, /<language>/search/...), which
#   Drupal's own rewrite sends to index.php, is logged with "-" for the
#   words, and no line carries the Referer.
#
# apache-access-log.test.sh checks the script on the base image and greps the
# Dockerfile; this one runs against what ships.
#
# Needs docker and a built image. Output is TAP; exit status is non-zero when
# any check fails.
#
# Usage: bash scripts/tests/image-log-settings.test.sh <image>
#   e.g. docker build -f docker/Dockerfile -t localnodes:check . &&
#        bash scripts/tests/image-log-settings.test.sh localnodes:check

set -u

IMAGE="${1:-}"
ARG_CANARY="QZXARG6610"
QUERY_CANARY="QZXQUERY6611"
REFERER_CANARY="QZXREFERER6612"
SEARCH_CANARY="QZXSEARCH6613"
LANG_CANARY="QZXLANG6614"

if [ -z "$IMAGE" ]; then
  echo "Usage: $0 <image>" >&2
  exit 2
fi

PASS=0
FAILED=0
N=0

echo "TAP version 13"
echo "1..9"

ok() { N=$((N + 1)); PASS=$((PASS + 1)); echo "ok $N - $1"; }
not_ok() {
  N=$((N + 1)); FAILED=$((FAILED + 1))
  echo "not ok $N - $1"
  echo "  ---"
  echo "  detail: '$(printf '%s' "$2" | sed "s/'/''/g")'"
  echo "  ..."
}

# --- PHP ---
INI="$(docker run --rm --entrypoint php "$IMAGE" -r 'echo ini_get("zend.exception_ignore_args");' 2>&1)"
if [ "$INI" = "1" ]; then
  ok "zend.exception_ignore_args is 1 in the image"
else
  not_ok "zend.exception_ignore_args is 1 in the image" "$INI"
fi

TRACE="$(docker run --rm --entrypoint php "$IMAGE" -r "function f(\$s) { throw new Exception('x'); } try { f('$ARG_CANARY'); } catch (Exception \$e) { echo \$e->getTraceAsString(); }" 2>&1)"
if printf '%s' "$TRACE" | grep -q 'f()' && ! printf '%s' "$TRACE" | grep -q "$ARG_CANARY"; then
  ok "a backtrace shows the call without its argument"
else
  not_ok "a backtrace shows the call without its argument" "$TRACE"
fi

# --- Apache ---
# The image's entrypoint waits for the database, so start Apache directly.
OUT="$(docker run --rm --entrypoint bash "$IMAGE" -c "
  apache2-foreground >/tmp/apache.out 2>&1 &
  for i in \$(seq 1 50); do
    php -r 'exit(@fsockopen(\"127.0.0.1\", 80) ? 0 : 1);' && break
    sleep 0.2
  done
  php -r '\$c = stream_context_create([\"http\" => [\"header\" => \"Referer: http://example.test/search/all?keys=$REFERER_CANARY\r\n\", \"ignore_errors\" => true]]); @file_get_contents(\"http://127.0.0.1/robots.txt?keys=$QUERY_CANARY\", false, \$c);'
  php -r '\$c = stream_context_create([\"http\" => [\"header\" => \"Referer: http://example.test/search/all/$REFERER_CANARY\r\n\", \"ignore_errors\" => true, \"timeout\" => 20]]);
    foreach ([\"/search/all/$SEARCH_CANARY\", \"/nl/search/users/$LANG_CANARY\", \"/index.php/search/all/$SEARCH_CANARY\"] as \$p) { @file_get_contents(\"http://127.0.0.1\$p\", false, \$c); }'
  sleep 0.5
  apache2ctl -k stop >/dev/null 2>&1
  cat /tmp/apache.out
" 2>&1)"

LINE="$(printf '%s\n' "$OUT" | grep '/robots.txt' | tail -n 1)"
if printf '%s' "$LINE" | grep -q '"GET /robots.txt HTTP/1.[01]" 200'; then
  ok "access log keeps method, path and status"
else
  not_ok "access log keeps method, path and status" "$OUT"
fi

if printf '%s' "$OUT" | grep -q "$QUERY_CANARY"; then
  not_ok "query canary absent from the container log" "$LINE"
else
  ok "query canary absent from the container log"
fi

if printf '%s' "$OUT" | grep -q "$REFERER_CANARY"; then
  not_ok "referer canary absent from the container log" "$LINE"
else
  ok "referer canary absent from the container log"
fi

if printf '%s\n' "$OUT" | grep -q '"GET /search/all/- HTTP/1.[01]"'; then
  ok "search path logged without the words"
else
  not_ok "search path logged without the words" "$(printf '%s\n' "$OUT" | grep 'search/')"
fi

if printf '%s\n' "$OUT" | grep -q '"GET /nl/search/users/- HTTP/1.[01]"'; then
  ok "language-prefixed search path logged without the words"
else
  not_ok "language-prefixed search path logged without the words" "$(printf '%s\n' "$OUT" | grep 'search/')"
fi

if printf '%s\n' "$OUT" | grep -q '"GET /index.php/search/all/- HTTP/1.[01]"'; then
  ok "search path behind /index.php logged without the words"
else
  not_ok "search path behind /index.php logged without the words" "$(printf '%s\n' "$OUT" | grep 'index.php/')"
fi

if printf '%s' "$OUT" | grep -qE "$SEARCH_CANARY|$LANG_CANARY"; then
  not_ok "search words absent from the container log" "$(printf '%s\n' "$OUT" | grep -E "$SEARCH_CANARY|$LANG_CANARY")"
else
  ok "search words absent from the container log"
fi

echo "# pass $PASS"
echo "# fail $FAILED"
[ "$FAILED" -eq 0 ]
