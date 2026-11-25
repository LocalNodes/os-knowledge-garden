#!/usr/bin/env bash
# Shell smoke of the entrypoint's demo pack arm: check_demo_pack() vets
# DEMO_PACK, and load_demo_content() builds the social-demo:add command
# for a pack without changing the other demo modules' commands.
#
# Usage (from anywhere inside the checkout; no DDEV needed):
#   bash tests/phase222/demo-pack-arm.sh
#
# Both functions are cut out of docker/entrypoint.sh with awk and sourced in
# a subshell, with $DRUSH a stub that appends its arguments to a scratch
# file and mark_step a no-op. The stub answers drush localnodes-demo:plugins
# from the platform's DemoPlugins class itself (host php), so the list is
# the real one. Steps, each printing "PASS <step>" or
# "FAIL <step>: <why>":
#   1. unset        no DEMO_PACK: returns 1, says it is not set.
#   2. traversal    DEMO_PACK=../etc: returns 1 with the letters message.
#   3. case         DEMO_PACK=Bad-Name: returns 1.
#   4. missing      a name with no directory: returns 1, "no pack directory".
#   5. good         the smoke fixture's directory: returns 0.
#   6. command      the pack command loads pack_file first and ends with the
#                   extras and --profile=packs/smoke/.
#   7. other arms   localnodes_demo, boulder_demo, neighbors_demo and portland_demo load
#                   exactly their own plugins, with no --profile.
#   8. uninstall    the fresh install uninstalls the loader and social_demo
#                   for packs only (a live statement, not a comment), and a
#                   redeploy does not enable it again.
#
# Exit status: 0 when every step passed, 1 when any failed, 2 on bad usage.
set -uo pipefail

if [[ $# -ne 0 ]]; then
  echo "Usage: $0" >&2
  exit 2
fi
cd "$(git rev-parse --show-toplevel)" || { echo "FAIL setup: not inside a git checkout"; exit 1; }

ENTRY="${ENTRY:-docker/entrypoint.sh}"
if [ -z "${SCRATCH:-}" ]; then
  SCRATCH="$(mktemp -d)"
  trap 'rm -rf "$SCRATCH"' EXIT
fi
mkdir -p "$SCRATCH"
FUNCS="$SCRATCH/demo-pack-funcs.sh"
CALLS="$SCRATCH/demo-pack-calls.txt"
EMPTY="$SCRATCH/demo-pack-empty"
mkdir -p "$EMPTY"

FAIL=0
pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAIL=1; }

{
  awk '/^check_demo_pack\(\) \{/,/^\}/' "$ENTRY"
  awk '/^load_demo_content\(\) \{/,/^\}/' "$ENTRY"
} > "$FUNCS"
if ! grep -q '^check_demo_pack() {' "$FUNCS" || ! grep -q '^load_demo_content() {' "$FUNCS"; then
  echo "FAIL setup: could not cut both functions out of $ENTRY"
  exit 1
fi

# run_check: prints the function's output, then "rc=<n>".
run_check() {
  (
    unset DEMO_PACK LOCALNODES_PACKS_DIR
    for kv in "$@"; do export "${kv?}"; done
    # shellcheck source=/dev/null
    source "$FUNCS"
    check_demo_pack
    echo "rc=$?"
  ) 2>&1
}

# run_load MODULE [PACK]: the stub drush's arguments, one call per line.
run_load() {
  : > "$CALLS"
  (
    DEMO_MODULE="$1"
    DEMO_PACK="${2:-}"
    DRUSH="stub_drush"
    stub_drush() {
      echo "$*" >> "$CALLS"
      if [[ "$1" == localnodes-demo:plugins ]]; then
        php -r 'require $argv[1]; echo implode(" ", \Drupal\localnodes_platform\Demo\DemoPlugins::ids($argv[2]));' \
          html/modules/custom/localnodes_platform/src/Demo/DemoPlugins.php "$2"
      fi
    }
    mark_step() { :; }
    # shellcheck source=/dev/null
    source "$FUNCS"
    load_demo_content > /dev/null
  )
  grep 'social-demo:add' "$CALLS"
}

out="$(run_check)"
if [[ "$out" == *"rc=1"* && "$out" == *"DEMO_PACK is not set"* ]]; then pass "unset"; else fail "unset: $out"; fi

out="$(run_check DEMO_PACK=../etc)"
if [[ "$out" == *"rc=1"* && "$out" == *"lower-case letters, digits and underscores"* ]]; then pass "traversal"; else fail "traversal: $out"; fi

out="$(run_check DEMO_PACK=Bad-Name)"
if [[ "$out" == *"rc=1"* ]]; then pass "case"; else fail "case: $out"; fi

out="$(run_check DEMO_PACK=nosuch "LOCALNODES_PACKS_DIR=$EMPTY")"
if [[ "$out" == *"rc=1"* && "$out" == *"no pack directory"* ]]; then pass "missing"; else fail "missing: $out"; fi

out="$(run_check DEMO_PACK=smoke LOCALNODES_PACKS_DIR=html/modules/custom/localnodes_demo_packs/tests/fixtures/packs)"
if [[ "$out" == *"rc=0"* && "$out" == *"Demo pack: smoke"* ]]; then pass "good"; else fail "good: $out"; fi

cmd="$(run_load localnodes_demo_packs smoke | tr -s ' ' | sed 's/ *$//')"
# The how-to pages come from the platform module on every site, so a pack
# load no longer names a pack_howto plugin.
if [[ "$cmd" == *"social-demo:add pack_file"* && "$cmd" == *" pack_quick_poll --profile=packs/smoke/" && "$cmd" != *"pack_howto"* ]]; then
  pass "command"
else
  fail "command: $cmd"
fi

# The other demo modules load exactly these plugins, with no profile option.
base="file user_terms event_type user group topic event event_enrollment post comment like"
expect() {  # expect PREFIX [EXTRA...]
  local p=$1 out="social-demo:add" n; shift
  for n in $base "$@"; do out="$out ${p}_$n"; done
  echo "$out"
}
bad=""
for spec in "localnodes_demo localnodes quick_poll" "boulder_demo boulder quick_poll" "neighbors_demo neighbors quick_poll" "portland_demo portland"; do
  read -r m p extras <<< "$spec"
  # shellcheck disable=SC2086
  want="$(expect "$p" $extras)"
  c="$(run_load "$m" | tr -s ' ' | sed 's/ *$//')"
  [[ "$c" == "$want" ]] || bad="$bad $m:[$c]"
done
if [[ -z "$bad" ]]; then pass "other arms"; else fail "other arms:$bad"; fi

# The fresh-install uninstall is a live (uncommented) statement inside a
# packs-only if, and the redeploy enable block skips the packs module.
un="$(grep -E -B1 -A1 '^[[:space:]]*\$DRUSH pm:uninstall localnodes_demo_packs social_demo -y[[:space:]]*$' "$ENTRY")"
re="$(grep -B1 'echo "Ensuring demo module enabled' "$ENTRY" | head -1)"
if [[ "$(grep -c 'pm:uninstall localnodes_demo_packs' "$ENTRY")" == 1 ]] \
  && grep -qE '^[[:space:]]*if \[ "\$DEMO_MODULE" = "localnodes_demo_packs" \]; then$' <<< "$(head -1 <<< "$un")" \
  && grep -qE '^[[:space:]]*fi$' <<< "$(tail -1 <<< "$un")" \
  && [[ "$re" =~ ^[[:space:]]*if\  && "$re" == *'"$DEMO_MODULE" != "localnodes_demo_packs"'* ]]; then
  pass "uninstall"
else
  fail "uninstall: '$un' / '$re'"
fi

exit "$FAIL"
