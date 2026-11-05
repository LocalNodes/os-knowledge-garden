#!/usr/bin/env bash
# Regression test: the chatbot's citation links must survive drupal/ai's
# HostnameFilter (drupal/ai >= 1.4).
#
# drupal/ai 1.4 strips every Markdown link in an AI answer whose host is not in
# ai.settings:allowed_hosts, keeping only the link text. The default list is
# empty, so on Boulder (8a8fdc8) every "[Title](https://boulder.localnodes.xyz/node/N)"
# source link was removed (dblog: "Removed disallowed Markdown link with URL").
# settings.php must therefore allow the instance's own host.
#
# Loads the real settings.php with a controlled environment and checks the
# resulting $config. Needs only a PHP 8.3 CLI.
#
# Usage: bash scripts/ops/tests/ai-allowed-hosts.test.sh
#   PHP=...       PHP command (default: php). Docker-only example:
#                 PHP="docker run --rm -i -v $PWD:/repo -w /repo php:8.3.36-cli php"
#                 Variables are set with putenv() inside PHP, so this works the
#                 same with a local php or a container.
#   SETTINGS=...  settings.php to test (default: the repo's).
set -u
cd "$(dirname "$0")/../../.."
PHP="${PHP:-php}"
SETTINGS="${SETTINGS:-html/sites/default/settings.php}"

PASS=0; FAIL=0
check() {  # check NAME EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then PASS=$((PASS + 1)); echo "PASS $1"
  else FAIL=$((FAIL + 1)); echo "FAIL $1 (expected $2, got $3)"; fi
}

# run "VAR=value ..." -> prints json_encode of $config['ai.settings']['allowed_hosts'] or "unset"
run() {
  # shellcheck disable=SC2086
  $PHP -- "$SETTINGS" "$@" <<'PHP'
<?php
$settings_file = $argv[1];
foreach (['SERVICE_FQDN_OPENSOCIAL', 'IS_DDEV_PROJECT', 'DDEV_HOSTNAME', 'DRUPAL_REVERSE_PROXY'] as $k) putenv($k);
foreach (array_slice($argv, 2) as $kv) putenv($kv);
$app_root = getcwd() . '/html'; $site_path = 'sites/default';
$settings = []; $config = []; $databases = [];
// settings.ddev.php is DDEV-generated and absent here; settings.php guards it.
require $settings_file;
echo array_key_exists('ai.settings', $config) && array_key_exists('allowed_hosts', $config['ai.settings'])
  ? json_encode($config['ai.settings']['allowed_hosts']) : 'unset';
PHP
}

check "instance host is allowed" '["boulder.localnodes.xyz"]' "$(run SERVICE_FQDN_OPENSOCIAL=boulder.localnodes.xyz)"
check "scheme is stripped" '["cascadia.localnodes.xyz"]' "$(run SERVICE_FQDN_OPENSOCIAL=https://cascadia.localnodes.xyz)"
check "no FQDN: ai.settings left alone" 'unset' "$(run)"
check "DDEV hostnames are allowed in DDEV" '["fresh3.ddev.site","alt.ddev.site"]' "$(run IS_DDEV_PROJECT=true DDEV_HOSTNAME=fresh3.ddev.site,alt.ddev.site)"
check "DDEV_HOSTNAME ignored outside DDEV" 'unset' "$(run DDEV_HOSTNAME=fresh3.ddev.site)"

echo "passed $PASS, failed $FAIL"
[ "$FAIL" -eq 0 ]
