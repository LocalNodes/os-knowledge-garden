#!/bin/bash
# Shell test for scripts/check_copy_rules.mjs (Phase 22, plan 22-28, D-04,
# PLACE-01, UI-SPEC Part 6).
#
# Plants deliberately bad copy in a temp folder and checks the script flags it
# (exit 1, a FLAG line naming file:line), checks that code identifiers and
# copy-allow lines are not flagged, then checks the real Phase 22 sources run
# clean (exit 0). Output is TAP; exit status is non-zero when any check fails.
#
# Usage: bash scripts/tests/check_copy_rules.test.sh

set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CHECK="$REPO_ROOT/scripts/check_copy_rules.mjs"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAILED=0
N=0
TOTAL=12

echo "TAP version 13"
echo "1..$TOTAL"

ok() {
  N=$((N + 1))
  PASS=$((PASS + 1))
  echo "ok $N - $1"
}

not_ok() {
  N=$((N + 1))
  FAILED=$((FAILED + 1))
  echo "not ok $N - $1"
  echo "  ---"
  echo "  message: 'FAIL'"
  echo "  ..."
}

# check <name> <command...>: ok when the command succeeds.
check() {
  local name="$1"
  shift
  if "$@"; then ok "$name"; else not_ok "$name"; fi
}

run_extra() {
  # Runs the check with only the planted folder (--extra adds it; --only-extra
  # skips the real sources so each case is judged on its own).
  node "$CHECK" --only-extra --extra "$1" >"$TMP/out.txt" 2>&1
  echo $? >"$TMP/status.txt"
}

status() { cat "$TMP/status.txt"; }
out_has() { grep -qE "$1" "$TMP/out.txt"; }
out_lacks() { ! grep -qE "$1" "$TMP/out.txt"; }

# 1-3: the plan's planted case (a template with a place phrase and a lone
# "Submit" button) fails, naming both lines.
mkdir -p "$TMP/bad"
printf '<p>For residents near you</p>\n<button>Submit</button>\n' >"$TMP/bad/bad.html.twig"
run_extra "$TMP/bad"
check 'planted template: exit 1' [ "$(status)" = "1" ]
check 'planted template: "near you" flagged at bad.html.twig:1' out_has '^FLAG .*bad\.html\.twig:1 .*near you'
check 'planted template: lone "Submit" button flagged at bad.html.twig:2' out_has '^FLAG .*bad\.html\.twig:2 .*Submit'

# 4: a t() literal assuming a place, in PHP.
mkdir -p "$TMP/php"
printf '<?php\n$x = $this->t("Find groups in your town");\n' >"$TMP/php/Bad.php"
run_extra "$TMP/php"
check 'PHP t() literal "your town" flagged' out_has '^FLAG .*Bad\.php:2 .*your town'

# 4b: prose held in a class constant (how CitedAnswer keeps its copy); a
# machine-id constant is not copy.
mkdir -p "$TMP/const"
printf '<?php
class A {
  public const NO_ANSWER = "Ask the neighbours instead.";
  public const CID_PREFIX = "social_ai_overview:v2:";
}
' >"$TMP/const/A.php"
run_extra "$TMP/const"
check 'PHP const prose "neighbours" flagged, machine-id const not' bash -c "grep -qE '^FLAG .*A\.php:3 .*neighbours' '$TMP/out.txt' && ! grep -qE 'A\.php:4' '$TMP/out.txt'"

# 5: a system word set as textContent in JS.
mkdir -p "$TMP/js"
printf "el.textContent = 'Indexing your pages';\nvar s = Drupal.t('Waiting in the queue');\n" >"$TMP/js/bad.js"
run_extra "$TMP/js"
check 'JS textContent "Indexing" flagged' out_has '^FLAG .*bad\.js:1 .*[Ii]ndexing'
check 'JS Drupal.t() "queue" flagged' out_has '^FLAG .*bad\.js:2 .*queue'

# 6: a YAML label that is a lone "OK", and a message with "Solr".
mkdir -p "$TMP/yml"
printf "label: 'OK'\nmessage: 'Pages go to Solr'\nid: node_index\n" >"$TMP/yml/bad.yml"
run_extra "$TMP/yml"
check 'YAML lone "OK" label flagged' out_has '^FLAG .*bad\.yml:1 .*OK'
check 'YAML message "Solr" flagged, machine id not' bash -c "grep -qE '^FLAG .*bad\.yml:2 .*Solr' '$TMP/out.txt' && ! grep -qE 'bad\.yml:3' '$TMP/out.txt'"

# 7: code identifiers and a copy-allow line are not copy.
mkdir -p "$TMP/clean"
cat >"$TMP/clean/Clean.php" <<'PHP'
<?php
$index = $this->entityTypeManager->getStorage('node');
$queue = \Drupal::queue('localnodes_import');
// copy-allow: organizer-only help text names the background worker.
$help = $this->t('If nothing changes, the cron worker may have stopped.');
$label = $this->t('Refresh search status');
PHP
printf '{# A node template comment: not copy #}\n<p class="node__title">{{ node.label }}</p>\n<p>{{ '"'"'Import pages'"'"'|t }}</p>\n' >"$TMP/clean/clean.html.twig"
run_extra "$TMP/clean"
check 'identifiers, comments and copy-allow lines are not flagged (exit 0)' [ "$(status)" = "0" ]

# 8: "Try again later." in a sentence is fine; "Try again" alone is not.
mkdir -p "$TMP/retry"
printf '<?php\n$a = $this->t("The site didn'"'"'t respond in time. Try again later.");\n$b = $this->t("Try again");\n' >"$TMP/retry/Retry.php"
run_extra "$TMP/retry"
check 'lone "Try again" flagged, sentence with "Try again later." not' bash -c "grep -qE '^FLAG .*Retry\.php:3' '$TMP/out.txt' && ! grep -qE 'Retry\.php:2' '$TMP/out.txt'"

# 9: the real Phase 22 sources are clean.
node "$CHECK" >"$TMP/real.txt" 2>&1
REAL=$?
if [ "$REAL" != "0" ]; then sed 's/^/# /' "$TMP/real.txt"; fi
check 'real Phase 22 sources: exit 0, no FLAG lines' bash -c "[ '$REAL' = '0' ] && ! grep -q '^FLAG' '$TMP/real.txt'"

echo "# pass $PASS, fail $FAILED"
[ "$FAILED" = "0" ]
