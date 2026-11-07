#!/usr/bin/env bash
# Repo-root PHPUnit runner for the DDEV project (Phase 22 Wave 0).
#
# Runs PHPUnit in the DDEV-built webserver image, attached to the DDEV project
# network so Kernel/Functional tests reach the `db` service. The repo root is
# bind-mounted live, so no copy of the tree is needed. Only the DDEV db
# container has to be running; the DDEV web container does not.
#
# Usage (from anywhere inside the repo):
#   scripts/run-phpunit.sh --group phase22 html/modules/custom/social_ai_indexing
#   scripts/run-phpunit.sh --filter RenderedItemMembersOnlyTest html/modules/custom/social_ai_indexing
#
# Kernel tests use prefixed tables (test<random>), so dev site content is not
# modified. Exit status is phpunit's.
#
# Functional (BrowserTestBase) tests run inside the DDEV web container
# instead (22-21). The test user agent carries an HMAC keyed on
# bootstrap.inc's ctime and inode, and the sidecar's bind mount and the web
# container's Mutagen copy differ in both, so a sidecar-run drupalGet() gets
# a 403 "bad user agent". Inside the web container, phpunit and the web
# server share one filesystem. Web mode is picked automatically when an
# argument names a Functional path or the phase22_functional group, or
# --filter matches a class under tests/src/Functional/. PHPUNIT_MODE=web or
# PHPUNIT_MODE=sidecar forces a mode. In sidecar mode, runs that didn't ask
# for Functional tests exclude @group phase22_functional (they can't pass
# there), unless an --exclude-group is already given.
set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
NETWORK="${DDEV_NETWORK:-ddev-fresh3_default}"
IMAGE="${DDEV_WEBSERVER_IMAGE:-ddev/ddev-webserver:v1.24.6-fresh3-built}"

if ! docker network inspect "$NETWORK" >/dev/null 2>&1; then
  echo "run-phpunit: docker network '$NETWORK' not found. Start the DDEV db container: ddev start, or docker start ddev-fresh3-db" >&2
  exit 2
fi

# html/core/phpunit.xml is gitignored (local DDEV values); fall back to the
# shipped .dist file, with DB and base URL supplied through the environment.
PHPUNIT_CONFIG="/var/www/html/html/core/phpunit.xml"
if [ ! -f "$REPO_ROOT/html/core/phpunit.xml" ]; then
  PHPUNIT_CONFIG="/var/www/html/html/core/phpunit.xml.dist"
fi

MODE="${PHPUNIT_MODE:-auto}"
if [ "$MODE" = "auto" ]; then
  MODE="sidecar"
  prev=""
  for arg in "$@"; do
    case "$arg" in
      *Functional*|*phase22_functional*) MODE="web" ;;
    esac
    if [ "$prev" = "--filter" ] && [ -n "$arg" ]; then
      # A filter can name several classes: 'ATest|BTest'. Any Functional
      # class among them selects web mode.
      old_ifs="$IFS"
      IFS='|'
      for part in $arg; do
        name="${part%%::*}"
        name="${name##*\\}"
        name="${name#^}"
        name="${name//[()\$]/}"
        if [ -n "$name" ] && find "$REPO_ROOT/html/modules/custom" -path '*/tests/src/Functional/*' -name "${name}*.php" 2>/dev/null | grep -q .; then
          MODE="web"
        fi
      done
      IFS="$old_ifs"
    fi
    prev="$arg"
  done
fi

if [ "$MODE" = "web" ]; then
  WEB_CONTAINER="${DDEV_WEB_CONTAINER:-ddev-fresh3-web}"
  if ! docker inspect -f '{{.State.Running}}' "$WEB_CONTAINER" 2>/dev/null | grep -q true; then
    echo "run-phpunit: Functional tests need the DDEV web container '$WEB_CONTAINER' running: ddev start" >&2
    exit 2
  fi
  exec docker exec \
    --user "$(id -u):$(id -g)" \
    -e HOME=/tmp \
    -e SIMPLETEST_DB="${SIMPLETEST_DB:-mysql://db:db@db:3306/db}" \
    -e SIMPLETEST_BASE_URL="${SIMPLETEST_WEB_BASE_URL:-http://localhost}" \
    -e BROWSERTEST_OUTPUT_DIRECTORY="${BROWSERTEST_OUTPUT_DIRECTORY:-/tmp}" \
    -w /var/www/html \
    "$WEB_CONTAINER" \
    php vendor/bin/phpunit -c "$PHPUNIT_CONFIG" "$@"
fi

# Opt-in: PHPUNIT_IGNORE_CONTRIB_DEPRECATIONS=1 also ignores the contrib
# deprecations listed in tests/phpunit/contrib-deprecations.txt (Group 2.3.1's
# own notices, which fail every Kernel run that boots Group). Core's default
# (bootstrap.php: ignoreFile=core/.deprecation-ignore.txt) is kept by joining
# both lists into one gitignored file. Without the variable nothing changes.
DEPRECATIONS_ENV=()
if [ "${PHPUNIT_IGNORE_CONTRIB_DEPRECATIONS:-}" = "1" ]; then
  combined="$REPO_ROOT/tests/phpunit/.combined-deprecation-ignore.txt"
  cat "$REPO_ROOT/html/core/.deprecation-ignore.txt" "$REPO_ROOT/tests/phpunit/contrib-deprecations.txt" > "$combined"
  DEPRECATIONS_ENV=(-e "SYMFONY_DEPRECATIONS_HELPER=ignoreFile=/var/www/html/tests/phpunit/.combined-deprecation-ignore.txt")
fi

EXTRA=()
asked_functional=false
has_exclude=false
for arg in "$@"; do
  case "$arg" in
    *Functional*|*phase22_functional*) asked_functional=true ;;
    --exclude-group|--exclude-group=*) has_exclude=true ;;
  esac
done
if [ "$asked_functional" = false ] && [ "$has_exclude" = false ]; then
  EXTRA=(--exclude-group phase22_functional)
  echo "run-phpunit: sidecar mode, excluding @group phase22_functional (run those with PHPUNIT_MODE=web)" >&2
fi

exec docker run --rm --network "$NETWORK" \
  --user "$(id -u):$(id -g)" \
  -e HOME=/tmp \
  -e SIMPLETEST_DB="${SIMPLETEST_DB:-mysql://db:db@db:3306/db}" \
  -e SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-http://web}" \
  -e BROWSERTEST_OUTPUT_DIRECTORY="${BROWSERTEST_OUTPUT_DIRECTORY:-/tmp}" \
  ${DEPRECATIONS_ENV[@]+"${DEPRECATIONS_ENV[@]}"} \
  -v "$REPO_ROOT:/var/www/html" -w /var/www/html \
  --entrypoint "" "$IMAGE" \
  php8.3 vendor/bin/phpunit -c "$PHPUNIT_CONFIG" ${EXTRA[@]+"${EXTRA[@]}"} "$@"
