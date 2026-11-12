#!/usr/bin/env bash
# Security-advisory check on the lock file.
#
# Runs `composer audit --locked` on composer.json and composer.lock in the given directory
# (default: the repo root). Nothing is installed and no plugin runs; the only network use is
# Composer's advisory lookup (Packagist and packages.drupal.org).
#
# Exit 0: no advisory, apart from those listed with a reason under config.audit.ignore in
#         composer.json (each one names why it is accepted and what ends it).
# Exit non-zero: an advisory affects a locked package, or the lookup itself failed. A failed
#         lookup is red on purpose: a check that cannot see advisories must not pass.
# Abandoned packages are reported, never a failure (--abandoned=report, matching composer.json).
set -euo pipefail

dir="${1:-$(cd "$(dirname "$0")/../.." && pwd)}"
for f in composer.json composer.lock; do
  [ -f "$dir/$f" ] || { echo "advisory-check: $dir/$f not found" >&2; exit 2; }
done

composer --version
cd "$dir"
set +e
composer audit --locked --no-plugins --no-interaction --abandoned=report --format=table
status=$?
set -e
if [ "$status" -eq 0 ]; then
  echo "advisory-check: PASS (no advisory outside the ignore list in composer.json)"
else
  echo "advisory-check: FAIL (composer audit exit $status)"
fi
exit "$status"
