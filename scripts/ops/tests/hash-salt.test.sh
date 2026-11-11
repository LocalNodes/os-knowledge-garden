#!/usr/bin/env bash
# Run the real startup block with system operations confined to owned fixtures.
# PHP snippets deliberately keep PHP variables literal for the inner interpreter.
# shellcheck disable=SC2016
set -euo pipefail
salt_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.." && pwd -P)"
salt_tmp=$(mktemp -d /tmp/localnodes-hash-salt.XXXXXX)
trap 'rm -rf -- "$salt_tmp"' EXIT
export SALT_HELPER="$salt_root/html/sites/default/hash-salt.php" SALT_PRIVATE="$salt_tmp/private"
awk '/^# --- One private salt/ {copy=1} /^# --- Wait for services/ {copy=0} copy' \
  "$salt_root/docker/entrypoint.sh" > "$salt_tmp/startup"
[[ -s $salt_tmp/startup ]]
mkdir() { [[ $* == '-p /var/www/private' ]]; }
chown() { [[ $* == 'www-data:www-data /var/www/private' ]]; }
su() {
  [[ $* == '-s /bin/sh www-data -c php /var/www/html/html/sites/default/hash-salt.php' ]]
  php -r 'require $argv[1]; localnodes_hash_salt($argv[2], TRUE);' "$SALT_HELPER" "$SALT_PRIVATE"
}
export -f mkdir chown su
command mkdir "$SALT_PRIVATE"
env -u DRUPAL_HASH_SALT bash -e "$salt_tmp/startup"
salt_file="$SALT_PRIVATE/.localnodes/hash-salt"
[[ $(stat -c %a "$salt_file") == 600 ]]
before=$(sha256sum "$salt_file")
DRUPAL_HASH_SALT=generate-random bash -e "$salt_tmp/startup"
[[ $(sha256sum "$salt_file") == "$before" ]]
env -u DRUPAL_HASH_SALT php -r 'require $argv[1]; exit(localnodes_hash_salt($argv[2]) === file_get_contents($argv[2] . "/.localnodes/hash-salt") ? 0 : 1);' "$SALT_HELPER" "$SALT_PRIVATE"
DRUPAL_HASH_SALT=explicit-test-salt SALT_PRIVATE="$salt_tmp/not-created" bash -e "$salt_tmp/startup"
[[ ! -e $salt_tmp/not-created ]]
printf '%s\n' 'PASS startup creates once, a second process reuses, exec reads it, explicit salt skips, mode 600'
