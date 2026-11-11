#!/usr/bin/env bash
# Regression test: the configuration sync directory must exist in every
# container, or Drupal's requirements check reports an error on every
# `drush updatedb` ("The directory ../config/sync does not exist").
#
# Why it went missing: site:install creates ../config/sync (core
# system_requirements(), install phase) inside the container's own layer, not a
# volume, so every redeploy starts without it. Unsetting the setting does not
# help: at runtime core reports "Not present" as an error too. The image
# therefore ships the directory, empty. It holds no config: nothing runs
# config:import (see CLAUDE.md), and drush cex stays an ad-hoc dev diff.
#
# Usage: bash scripts/ops/tests/config-sync-dir.test.sh
#   PHP=...    PHP command (default: php), as in ai-allowed-hosts.test.sh
#   IMAGE=...  optional built image, e.g. ghcr.io/localnodes/os-knowledge-garden:<sha7>;
#              when set, also checks the directory inside the image (needs docker).
set -u
cd "$(dirname "$0")/../../.." || exit 1
PHP="${PHP:-php}"
DOCKERFILE="${DOCKERFILE:-docker/Dockerfile}"
SETTINGS="${SETTINGS:-html/sites/default/settings.php}"
DIR=/var/www/html/config/sync

PASS=0; FAIL=0
check() {  # check NAME EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then PASS=$((PASS + 1)); echo "PASS $1"
  else FAIL=$((FAIL + 1)); echo "FAIL $1 (expected $2, got $3)"; fi
}

# 1. settings.php points at <app root>/../config/sync, which in the image is $DIR.
resolved="$($PHP -- "$SETTINGS" <<'PHP'
<?php
foreach (['SERVICE_FQDN_OPENSOCIAL', 'IS_DDEV_PROJECT', 'DDEV_HOSTNAME', 'DRUPAL_REVERSE_PROXY'] as $k) putenv($k);
$app_root = '/var/www/html/html'; $site_path = 'sites/default';
$settings = []; $config = []; $databases = [];
putenv('DRUPAL_HASH_SALT=fixture-explicit-hash-salt');
require $argv[1];
$d = $settings['config_sync_directory'] ?? '';
// Drupal resolves a relative path against DRUPAL_ROOT (the app root).
$abs = ($d !== '' && $d[0] !== '/') ? $app_root . '/' . $d : $d;
$parts = [];
foreach (explode('/', $abs) as $p) {
  if ($p === '' || $p === '.') continue;
  if ($p === '..') { array_pop($parts); continue; }
  $parts[] = $p;
}
echo '/' . implode('/', $parts);
PHP
)"
check "settings.php sync dir resolves to $DIR" "$DIR" "$resolved"

# 2. The Dockerfile creates it and gives it to www-data (core writes .htaccess into it).
grep -Eq "mkdir -p .*$DIR( |$|\\\\)" "$DOCKERFILE" && got=yes || got=no
check "Dockerfile creates $DIR" yes "$got"
grep -Eq "chown -R www-data:www-data .*$DIR( |$|\\\\)" "$DOCKERFILE" && got=yes || got=no
check "Dockerfile gives $DIR to www-data" yes "$got"

# 3. It ships empty: no config is copied into it.
grep -Eq "COPY .*config/sync" "$DOCKERFILE" && got=copied || got=empty
check "no config is copied into the sync dir" empty "$got"

# 4. Optional: the built image has it, empty and owned by www-data.
if [ -n "${IMAGE:-}" ]; then
  got="$(docker run --rm --entrypoint sh "$IMAGE" -c "test -d $DIR && stat -c %U $DIR && ls -A $DIR | wc -l" 2>&1 | tr '\n' ' ' | sed 's/ $//')"
  check "image has $DIR, owned by www-data, empty" "www-data 0" "$got"
  got="$(docker run --rm --user www-data --entrypoint sh "$IMAGE" -c "touch $DIR/.probe && rm $DIR/.probe && echo writable" 2>&1 | tail -1)"
  check "www-data can write in $DIR" writable "$got"
else
  echo "SKIP image check (set IMAGE to run it)"
fi

echo "passed $PASS, failed $FAIL"
[ "$FAIL" -eq 0 ]
