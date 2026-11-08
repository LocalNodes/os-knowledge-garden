#!/usr/bin/env bash
# Lane check (D-23): a fresh install must leave the tracked
# html/sites/default/settings.php untouched.
#
# Drupal's installer rewrites settings.php (appending $databases and a
# generated hash_salt) whenever it decides the settings are not "verified".
# One of its checks is that the config sync directory settings.php names
# (../config/sync) exists. This script runs an install and fails if any
# tracked file under html/sites/default/ changed, or if a generated hash_salt
# landed in a tracked file.
#
# Usage (from the lane root, DDEV running):
#   bash tests/phase222/settings-clean-after-install.sh ["<install command>"]
# Default install command:
#   ddev drush si social --account-pass=admin -y && ddev drush en localnodes_platform -y
#
# It starts from what a fresh checkout has: if an earlier install left an
# untracked config/ directory behind (only installer-written .htaccess files
# in it), that leftover is removed first, so it can't hide the bug.
#
# Prints PASS, or "FAIL: <what changed>" and exits 1. A FAIL leaves the
# changed files in place so you can inspect them.
# Used by plans 22.2-01 and 22.2-05.
set -u
cd "$(git rev-parse --show-toplevel)" || { echo "FAIL: not inside a git checkout"; exit 1; }

INSTALL_CMD="${1:-ddev drush si social --account-pass=admin -y && ddev drush en localnodes_platform -y}"
SETTINGS=html/sites/default/settings.php

if [ -n "$(git status --porcelain -- html/sites/default/)" ]; then
  echo "FAIL: html/sites/default/ is not clean before the install; commit or restore it first:"
  git status --porcelain -- html/sites/default/
  exit 1
fi

# Fresh-checkout state for config/: remove an untracked leftover from an
# earlier install, but only if it holds nothing except installer .htaccess files.
if [ -d config ] && [ -z "$(git ls-files config)" ]; then
  if [ -z "$(find config -type f ! -name .htaccess -print -quit)" ]; then
    rm -rf config
    echo "note: removed untracked config/ left by an earlier install (fresh-checkout state)"
  else
    echo "FAIL: untracked config/ holds files other than .htaccess; move them away first:"
    find config -type f ! -name .htaccess
    exit 1
  fi
fi

before_sha="$(sha256sum "$SETTINGS" | cut -d' ' -f1)"

echo "install: $INSTALL_CMD"
if ! bash -c "$INSTALL_CMD"; then
  echo "FAIL: the install command failed"
  exit 1
fi

fail=0
after_sha="$(sha256sum "$SETTINGS" | cut -d' ' -f1)"
if [ "$before_sha" != "$after_sha" ]; then
  echo "FAIL: $SETTINGS changed (sha256 $before_sha -> $after_sha); lines the install added:"
  # Show what was written, with any salt value masked.
  git diff -U0 -- "$SETTINGS" | grep '^+[^+]' | sed -E "s/(hash_salt'\] = ')[^']*/\1<masked>/"
  fail=1
fi

modified="$(git status --porcelain -- html/sites/default/ | grep -v '^??' || true)"
if [ -n "$modified" ]; then
  echo "FAIL: tracked files under html/sites/default/ were modified:"
  echo "$modified"
  fail=1
fi

salt_hits="$(git grep -n "hash_salt'\] = '[A-Za-z0-9_-]\{20,\}'" -- html/sites || true)"
if [ -n "$salt_hits" ]; then
  echo "FAIL: a generated hash_salt is in a tracked file:"
  echo "$salt_hits" | sed -E "s/(hash_salt'\] = ')[^']*/\1<masked>/"
  fail=1
fi

if [ "$fail" -ne 0 ]; then
  exit 1
fi
echo "PASS"
