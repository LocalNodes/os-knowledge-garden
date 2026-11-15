#!/usr/bin/env bash
# Shell test of scripts/install.sh --demo=<value>: a named pack loads, a bad
# pack name stops the script before any DDEV call, and the older values keep
# their arms.
#
# Usage (from anywhere inside the checkout; no DDEV needed):
#   bash tests/phase222/install-sh-demo.sh
#
# Each case copies scripts/install.sh into a scratch root, next to a sentinel
# .ddev/.env (never the checkout's key) and a symlink to the checkout's
# packs. A stub "ddev" on PATH appends its arguments to a log and answers
# success, or failure for the load when FAIL_LOAD=1. Stdin is /dev/null, so a
# prompt for the key would end the script at once.
#
# Cases, each printing "PASS <case>" or "FAIL <case>: <why>":
#   lanternrow       enable, load, uninstall run in that order; the defer
#                    variable rides on the load only; no restart; the
#                    scratch .env is unchanged.
#   load fails       a failing load ends the script non-zero, with no
#                    uninstall after it.
#   Bad_Name, ../x, ../packs/lanternrow, nosuchpack
#                    exit 1, "install.sh: unknown demo pack", and no ddev
#                    call at all.
#   empty            --demo= makes no demo call.
#   cascadia         the older arm is as before.
# Last, the checkout's own .ddev/.env is compared by checksum only.
#
# Prints "install.sh demo: N of N" at the end. Exit 0 when all passed, 1
# when any failed, 2 on bad usage.
set -uo pipefail

if [[ $# -ne 0 ]]; then
  echo "Usage: $0" >&2
  exit 2
fi
ROOT="$(git rev-parse --show-toplevel)" || { echo "FAIL setup: not inside a git checkout"; exit 1; }
cd "$ROOT" || exit 1

SCRATCH="${SCRATCH:-$(mktemp -d)}"
PACKS_REAL="$ROOT/html/modules/custom/localnodes_demo_packs/packs"
SENTINEL="GEMINI_API_KEY=install-sh-test-sentinel"

pass=0
total=0
ok() { total=$((total + 1)); pass=$((pass + 1)); echo "PASS $1"; }
bad() { total=$((total + 1)); echo "FAIL $1: $2"; }

# Checkout .ddev/.env: checksum only, never read out.
env_sum() { if [ -f "$ROOT/.ddev/.env" ]; then sha256sum "$ROOT/.ddev/.env" | cut -d' ' -f1; else echo none; fi; }
ENV_BEFORE="$(env_sum)"

# new_root <name>: builds a scratch root, sets R, LOG and OUT.
new_root() {
  R="$SCRATCH/$1"
  rm -rf "$R"
  mkdir -p "$R/scripts" "$R/.ddev" "$R/bin" "$R/html/modules/custom/localnodes_demo_packs"
  cp "$ROOT/scripts/install.sh" "$R/scripts/install.sh"
  echo "$SENTINEL" > "$R/.ddev/.env"
  ln -s "$PACKS_REAL" "$R/html/modules/custom/localnodes_demo_packs/packs"
  LOG="$R/ddev.log"
  OUT="$R/out.txt"
  : > "$LOG"
  cat > "$R/bin/ddev" <<'STUB'
#!/usr/bin/env bash
echo "$*" >> "$STUB_LOG"
if [ "${FAIL_LOAD:-}" = "1" ] && [[ "$*" == *localnodes-demo:install* ]]; then
  exit 1
fi
exit 0
STUB
  chmod +x "$R/bin/ddev"
}

# run_install <arg>...: runs the scratch install.sh; sets RC.
run_install() {
  PATH="$R/bin:$PATH" STUB_LOG="$LOG" FAIL_LOAD="${FAIL_LOAD:-}" bash "$R/scripts/install.sh" "$@" < /dev/null > "$OUT" 2>&1
  RC=$?
}

line_of() { grep -n -- "$1" "$LOG" | head -1 | cut -d: -f1; }

env_unchanged() { [ "$(cat "$R/.ddev/.env")" = "$SENTINEL" ]; }

# --- lanternrow ---
new_root good
FAIL_LOAD="" run_install --demo=lanternrow
L_EN="$(line_of 'drush en localnodes_demo_packs -y')"
L_LOAD="$(line_of 'localnodes-demo:install localnodes_demo_packs --pack=lanternrow')"
L_UN="$(line_of 'drush pm:uninstall localnodes_demo_packs social_demo -y')"
why=""
[ "$RC" -eq 0 ] || why="exit $RC"
[ -n "$L_EN" ] && [ -n "$L_LOAD" ] && [ -n "$L_UN" ] || why="$why; a call is missing (en=$L_EN load=$L_LOAD uninstall=$L_UN)"
if [ -n "$L_EN" ] && [ -n "$L_LOAD" ] && [ -n "$L_UN" ]; then
  [ "$L_EN" -lt "$L_LOAD" ] && [ "$L_LOAD" -lt "$L_UN" ] || why="$why; calls out of order"
fi
grep -qxF 'exec env LOCALNODES_DEFER_INDEXING=1 drush localnodes-demo:install localnodes_demo_packs --pack=lanternrow' "$LOG" || why="$why; load is not 'ddev exec env LOCALNODES_DEFER_INDEXING=1 drush localnodes-demo:install ...'"
[ "$(grep -c LOCALNODES_DEFER_INDEXING "$LOG")" -eq 1 ] || why="$why; the defer variable is not on the load alone"
grep -q '^restart' "$LOG" && why="$why; ddev restart ran"
env_unchanged || why="$why; scratch .env changed"
[ -z "$why" ] && ok "lanternrow" || bad "lanternrow" "${why#; }"

# --- failing load ---
new_root failload
FAIL_LOAD=1 run_install --demo=lanternrow
why=""
[ "$RC" -ne 0 ] || why="exit 0 after a failed load"
grep -q 'pm:uninstall' "$LOG" && why="$why; uninstall ran after a failed load"
grep -q 'localnodes-demo:install localnodes_demo_packs --pack=lanternrow' "$LOG" || why="$why; the load never ran"
[ -z "$why" ] && ok "load fails" || bad "load fails" "${why#; }"

# --- bad pack names ---
for name in Bad_Name ../x ../packs/lanternrow nosuchpack; do
  new_root bad
  FAIL_LOAD="" run_install "--demo=$name"
  why=""
  [ "$RC" -eq 1 ] || why="exit $RC, wanted 1"
  grep -q '^install.sh: unknown demo pack' "$OUT" || why="$why; no 'install.sh: unknown demo pack' line"
  [ ! -s "$LOG" ] || why="$why; ddev was called"
  env_unchanged || why="$why; scratch .env changed"
  [ -z "$why" ] && ok "bad name $name" || bad "bad name $name" "${why#; }"
done

# --- empty value ---
new_root empty
FAIL_LOAD="" run_install --demo=
why=""
[ "$RC" -eq 0 ] || why="exit $RC"
grep -qE 'localnodes-demo:install|pack|social_demo|en localnodes_demo' "$LOG" && why="$why; a demo call ran"
[ -z "$why" ] && ok "empty" || bad "empty" "${why#; }"

# --- cascadia, as before ---
new_root cascadia
FAIL_LOAD="" run_install --demo=cascadia
why=""
[ "$RC" -eq 0 ] || why="exit $RC"
grep -qxF 'drush en localnodes_demo -y' "$LOG" || why="$why; localnodes_demo was not enabled"
grep -qxF 'drush localnodes-demo:install localnodes_demo' "$LOG" || why="$why; the cascadia load did not run"
grep -q 'localnodes_demo_packs' "$LOG" && why="$why; the pack module was touched"
[ -z "$why" ] && ok "cascadia" || bad "cascadia" "${why#; }"

# --- the checkout's key file, by checksum ---
if [ "$(env_sum)" = "$ENV_BEFORE" ]; then ok "checkout .ddev/.env unchanged"; else bad "checkout .ddev/.env unchanged" "checksum differs"; fi

echo "install.sh demo: $pass of $total"
[ "$pass" -eq "$total" ]
