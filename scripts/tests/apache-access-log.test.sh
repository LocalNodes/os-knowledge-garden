#!/bin/bash
# Shell test for docker/apache-access-log.sh.
#
# Starts the image's base (php:8.3.36-apache), applies the script, sends one
# request whose query string and Referer hold canaries, and checks the access
# log line on stdout: it must keep the method, path and status but hold neither
# canary. Search and AI overview requests put a member's words in the query
# string, Open Social's search pages put them in the path (/search/all/<words>),
# and the container log is kept by the host and readable by operators. Search
# paths, with and without a language prefix, must be logged with "-" for the
# words, other paths unchanged, and an error line must not carry the Referer.
# Also checks that the Dockerfile runs the script and sets
# zend.exception_ignore_args, so logged backtraces carry no call arguments.
#
# Needs docker. Output is TAP; exit status is non-zero when any check fails.
#
# Usage: bash scripts/tests/apache-access-log.test.sh

set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${APACHE_LOG_SCRIPT:-$REPO_ROOT/docker/apache-access-log.sh}"
DOCKERFILE="$REPO_ROOT/docker/Dockerfile"
BASE_IMAGE="php:8.3.36-apache"
QUERY_CANARY="QZXQUERY5521"
REFERER_CANARY="QZXREFERER8830"
SEARCH_CANARY="QZXSEARCH4417"
LANG_CANARY="QZXLANG4418"
ERROR_REFERER_CANARY="QZXERRREF4419"

PASS=0
FAILED=0
N=0
TOTAL=13

echo "TAP version 13"
echo "1..$TOTAL"

ok() { N=$((N + 1)); PASS=$((PASS + 1)); echo "ok $N - $1"; }
not_ok() {
  N=$((N + 1)); FAILED=$((FAILED + 1))
  echo "not ok $N - $1"
  echo "  ---"
  echo "  message: 'FAIL'"
  echo "  detail: '$(printf '%s' "$2" | sed "s/'/''/g")'"
  echo "  ..."
}

# --- Dockerfile wiring ---
if grep -Eq "^RUN (bash|sh) /tmp/apache-access-log.sh" "$DOCKERFILE" \
  && grep -q "^COPY docker/apache-access-log.sh /tmp/apache-access-log.sh" "$DOCKERFILE"; then
  ok "Dockerfile copies and runs docker/apache-access-log.sh"
else
  not_ok "Dockerfile copies and runs docker/apache-access-log.sh" "no COPY/RUN lines found"
fi

if grep -Eq "echo 'zend\.exception_ignore_args = On'" "$DOCKERFILE"; then
  ok "Dockerfile sets zend.exception_ignore_args = On in drupal.ini"
else
  not_ok "Dockerfile sets zend.exception_ignore_args = On in drupal.ini" "line not found"
fi

# --- Live check in the base image ---
if [ ! -f "$SCRIPT" ]; then
  for name in "script exists" "request is logged" "log line keeps method and path" \
    "query canary absent" "referer canary absent" "search path logged without the words" \
    "language-prefixed search path logged without the words" "search path behind /index.php logged without the words" "search canaries absent" \
    "other path logged unchanged" "error line without the Referer"; do
    not_ok "$name" "docker/apache-access-log.sh is missing"
  done
  echo "# pass $PASS"; echo "# fail $FAILED"; exit 1
fi
ok "script exists"

OUT="$(docker run --rm -v "$SCRIPT:/tmp/apache-access-log.sh:ro" "$BASE_IMAGE" bash -c "
  set -e
  bash /tmp/apache-access-log.sh >/dev/null
  apache2-foreground >/tmp/apache.out 2>&1 &
  for i in \$(seq 1 50); do
    php -r 'exit(@fsockopen(\"127.0.0.1\", 80) ? 0 : 1);' && break
    sleep 0.2
  done
  php -r '\$c = stream_context_create([\"http\" => [\"header\" => \"Referer: http://example.test/search/all?keys=$REFERER_CANARY\r\n\", \"ignore_errors\" => true]]); @file_get_contents(\"http://127.0.0.1/api/ai/overview?q=$QUERY_CANARY\", false, \$c);'
  echo '<?php error_log(\"probe-error-line\");' > /var/www/html/probe.php
  php -r '\$c = stream_context_create([\"http\" => [\"header\" => \"Referer: http://example.test/search/all/$ERROR_REFERER_CANARY\r\n\", \"ignore_errors\" => true]]);
    foreach ([\"/search/all/$SEARCH_CANARY%20two\", \"/fr/search/content/$LANG_CANARY\", \"/index.php/search/groups/$SEARCH_CANARY\", \"/node/5\", \"/probe.php\"] as \$p) { @file_get_contents(\"http://127.0.0.1\$p\", false, \$c); }'
  sleep 0.5
  cat /tmp/apache.out
" 2>&1)"

LINE="$(printf '%s\n' "$OUT" | grep '/api/ai/overview' | tail -n 1)"
if [ -n "$LINE" ]; then ok "request is logged"; else not_ok "request is logged" "$OUT"; fi

if printf '%s' "$LINE" | grep -q '"GET /api/ai/overview HTTP/1.[01]" 404'; then
  ok "log line keeps method, path and status"
else
  not_ok "log line keeps method, path and status" "$LINE"
fi

if printf '%s' "$OUT" | grep -q "$QUERY_CANARY"; then
  not_ok "query canary absent" "$LINE"
else
  ok "query canary absent"
fi

if printf '%s' "$OUT" | grep -q "$REFERER_CANARY"; then
  not_ok "referer canary absent" "$LINE"
else
  ok "referer canary absent"
fi

if printf '%s\n' "$OUT" | grep -q '"GET /search/all/- HTTP/1.[01]" 404'; then
  ok "search path logged without the words"
else
  not_ok "search path logged without the words" "$(printf '%s\n' "$OUT" | grep 'search/all/')"
fi

if printf '%s\n' "$OUT" | grep -q '"GET /fr/search/content/- HTTP/1.[01]" 404'; then
  ok "language-prefixed search path logged without the words"
else
  not_ok "language-prefixed search path logged without the words" "$(printf '%s\n' "$OUT" | grep 'search/content')"
fi

if printf '%s\n' "$OUT" | grep -q '"GET /index.php/search/groups/- HTTP/1.[01]"'; then
  ok "search path behind /index.php logged without the words"
else
  not_ok "search path behind /index.php logged without the words" "$(printf '%s\n' "$OUT" | grep 'index.php/')"
fi

if printf '%s' "$OUT" | grep -qE "$SEARCH_CANARY|$LANG_CANARY"; then
  not_ok "search canaries absent" "$(printf '%s\n' "$OUT" | grep -E "$SEARCH_CANARY|$LANG_CANARY")"
else
  ok "search canaries absent"
fi

if printf '%s\n' "$OUT" | grep -q '"GET /node/5 HTTP/1.[01]" 404'; then
  ok "other path logged unchanged"
else
  not_ok "other path logged unchanged" "$(printf '%s\n' "$OUT" | grep 'node/5')"
fi

ERRLINE="$(printf '%s\n' "$OUT" | grep 'probe-error-line' | tail -n 1)"
if [ -n "$ERRLINE" ] && ! printf '%s' "$OUT" | grep -q "$ERROR_REFERER_CANARY"; then
  ok "error line without the Referer"
else
  not_ok "error line without the Referer" "${ERRLINE:-no error line}"
fi

echo "# pass $PASS"
echo "# fail $FAILED"
[ "$FAILED" -eq 0 ]
