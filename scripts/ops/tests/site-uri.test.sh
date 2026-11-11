#!/usr/bin/env bash
# The entrypoint's site address (docker/entrypoint.sh site_uri): drush's --uri,
# so links in mail the background worker sends. 22.2 export job, localhost flag.
set -u
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
FN="$(sed -n '/^site_uri()/,/^}/p' "$ROOT/docker/entrypoint.sh")"
[ -n "$FN" ] || { echo "FAIL site_uri() not found in docker/entrypoint.sh"; exit 1; }
pass=0; fail=0
check() {  # check EXPECTED VAR=VALUE...
  local want="$1"; shift
  local got; got="$(env -i "$@" bash -c "$FN; site_uri")"
  if [ "$got" = "$want" ]; then pass=$((pass+1)); echo "PASS $* -> $got"
  else fail=$((fail+1)); echo "FAIL $* -> $got (want $want)"; fi
}
check https://community.example.org SERVICE_URL_OPENSOCIAL=https://community.example.org/
check http://203.0.113.7:8080 SERVICE_URL_OPENSOCIAL=http://203.0.113.7:8080 SERVICE_FQDN_OPENSOCIAL=203.0.113.7
check https://a.example.org 'SERVICE_URL_OPENSOCIAL=https://a.example.org, https://b.example.org'
check https://b.example.org 'SERVICE_FQDN_OPENSOCIAL=b.example.org,c.example.org'
check https://d.example.org SERVICE_FQDN_OPENSOCIAL=https://d.example.org/
check http://localhost X=1
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ]
