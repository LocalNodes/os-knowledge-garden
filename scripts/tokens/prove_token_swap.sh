#!/usr/bin/env bash
# Palette-swap proof (22.1-09, DES-01, D-03, criterion 3): swapping the palette is a token change only.
#
# Usage:
#   scripts/tokens/prove_token_swap.sh <fixture.json>      (fixtures: scripts/tokens/fixtures/palette-{a,b,c}.json)
#
# What it does, in throwaway directories named ln-swap-XXXXXX under $TMPDIR (or /tmp):
#   1. fresh3: `git worktree add --detach` of this repo's HEAD.
#   2. Nuxt:   a READ-ONLY `git archive` of one ref of the onboarding repo, unpacked into a temp directory
#              that gets its own throwaway git repo (so `git status` can list changes). The onboarding repo
#              itself is never written to: no worktree, no checkout, no fetch. Its node_modules is copied
#              (a copy, so a test run cannot write into the original) and `nuxt prepare` regenerates .nuxt.
#   3. Writes the fixture's values into the worktree's token source (the "token-value edit"), runs the
#      generator on both copies, and lists every changed file in both.
#   4. Fails with `LEAK <path>` for any changed path that is not the token source or a generated target
#      (the generator names its own target list), then runs every check against the swapped copies.
#   5. Removes the worktree and the temp directories, on success or failure (a trap).
# No DDEV, no container, no package install (D-12).
#
# Environment:
#   LN_SWAP_NUXT_REF    the ref of the Nuxt repo to read (default origin/main). Its resolved SHA is printed
#                       on the `NUXT ref=<ref> sha=<sha>` line.
#   LN_SWAP_NUXT_REPO   where that repo is (default ../localnodes-onboarding, next to this repo).
#   LN_SWAP_TMP_PREFIX  the name prefix of the throwaway directory (default ln-swap-; it must start with ln-swap-, the
#                       rm -rf guard below depends on it). A caller that runs the proof concurrently with others gives
#                       each run its own prefix, so it can tell its own leftovers from another run's. The directory
#                       is printed on the `WORKDIR <path>` line.
#   LN_SWAP_NO_LEAK_CHECK  test seam: with 1, a changed file outside the allowed list is printed as CHANGED but is not a LEAK. It
#                       lets a test show that the later checks catch a planted colour on their own (WR-01).
#   LN_SWAP_PLANT       test seam: append a raw colour to this file AFTER generation. A repo-relative path
#                       is in the fresh3 worktree; `nuxt:<path>` is in the Nuxt copy. The proof must then
#                       exit 1 (a LEAK line names the file, or a check fails).
#
# Output: `WORKDIR ...`, `NUXT ...`, `TOKEN-EDIT ...`, `CHANGED fresh3|nuxt <path>` per changed file, `LEAK <path>`
# (`LEAK nuxt:<path>` for the Nuxt copy), `OK <step>` / `FAIL <step>` per check, then `RESULT: PASS (n checks)`
# or `RESULT: FAIL (k failed)`. Exit 0 pass, 1 fail, 2 usage or setup error.
set -euo pipefail

USAGE='usage: prove_token_swap.sh <fixture.json>'
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

if [ "$#" -ne 1 ]; then echo "$USAGE" >&2; exit 2; fi
if [ ! -f "$1" ]; then echo "$USAGE (fixture not found: $1)" >&2; exit 2; fi
FIXTURE="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"

TMP_PREFIX="${LN_SWAP_TMP_PREFIX:-ln-swap-}"
case "$TMP_PREFIX" in
  ln-swap-*) ;;
  *) echo "$USAGE (LN_SWAP_TMP_PREFIX must start with ln-swap-, got: ${TMP_PREFIX})" >&2; exit 2 ;;
esac
case "$TMP_PREFIX" in */*) echo "$USAGE (LN_SWAP_TMP_PREFIX must not contain a slash)" >&2; exit 2 ;; esac

NUXT_REF="${LN_SWAP_NUXT_REF:-origin/main}"
NUXT_REPO="${LN_SWAP_NUXT_REPO:-$ROOT/../localnodes-onboarding}"
PLANT="${LN_SWAP_PLANT:-}"
NO_LEAK_CHECK="${LN_SWAP_NO_LEAK_CHECK:-}"

SOURCE_REL='html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json'
OVERRIDES_REL='html/modules/custom/localnodes_platform/css/ln-overrides.css'
IMAGES_REL='html/modules/custom/localnodes_platform/images'

if ! NUXT_SHA="$(git -C "$NUXT_REPO" rev-parse --verify -q "${NUXT_REF}^{commit}")"; then
  echo "$USAGE (Nuxt ref ${NUXT_REF} not found in ${NUXT_REPO}; set LN_SWAP_NUXT_REF / LN_SWAP_NUXT_REPO)" >&2
  exit 2
fi
if [ ! -d "$NUXT_REPO/node_modules" ]; then
  echo "$USAGE (no node_modules in ${NUXT_REPO}: the Nuxt unit tests need them)" >&2
  exit 2
fi

TMP="$(mktemp -d "${TMPDIR:-/tmp}/${TMP_PREFIX}XXXXXX")"
W="$TMP/fresh3"
N="$TMP/nuxt"

echo "WORKDIR ${TMP}"

cleanup() {
  git -C "$ROOT" worktree remove --force "$W" >/dev/null 2>&1 || true
  git -C "$ROOT" worktree prune >/dev/null 2>&1 || true
  case "$TMP" in */ln-swap-*) rm -rf "$TMP" ;; esac
}
trap cleanup EXIT
trap 'echo "ERROR setup failed (line $LINENO)" >&2; exit 2' ERR

FAILS=0
CHECKS=0

# step <label> <command...>: run it, print OK or FAIL (with the tail of its output).
step() {
  local label="$1"; shift
  local log="$TMP/step-$((++CHECKS)).log"
  if "$@" >"$log" 2>&1; then
    echo "OK $label"
    if [ "${STEP_SHOW_LOG:-}" = 1 ]; then sed 's/^/    /' "$log"; fi
  else
    echo "FAIL $label"
    tail -n 25 "$log" | sed 's/^/    /'
    FAILS=$((FAILS + 1))
  fi
}

in_w() { (cd "$W" && "$@"); }
in_n() { (cd "$N" && "$@"); }
quiet_git() { git -c user.name=ln-swap -c user.email=ln-swap@localhost -c commit.gpgsign=false -c core.hooksPath=/dev/null "$@"; }

# --- 1 and 2: the two throwaway copies ---------------------------------------------------------

git -C "$ROOT" worktree add --detach -q "$W" HEAD
mkdir "$N"
git -C "$NUXT_REPO" archive "$NUXT_REF" | tar -x -C "$N"
echo "NUXT ref=${NUXT_REF} sha=${NUXT_SHA}"

# The archive is a snapshot with no history: give it a throwaway repo so `git status` lists changes.
(cd "$N" && git init -q 2>/dev/null && git add -A && quiet_git commit -q -m baseline)
cp -a "$NUXT_REPO/node_modules" "$N/node_modules"
(cd "$N" && NUXT_TELEMETRY_DISABLED=1 node_modules/.bin/nuxt prepare >"$TMP/prepare.log" 2>&1) || { tail -n 20 "$TMP/prepare.log" >&2; exit 2; }

# The Nuxt unit tests before the swap: the ref may carry tests that already fail (another lane's work in
# progress), so the proof compares failures before and after instead of asking for a clean run.
(cd "$N" && node_modules/.bin/vitest run --reporter=json --outputFile="$TMP/nuxt-before.json" >"$TMP/nuxt-before.log" 2>&1) || true
[ -s "$TMP/nuxt-before.json" ] || { echo "ERROR the Nuxt unit tests did not run before the swap" >&2; tail -n 20 "$TMP/nuxt-before.log" >&2; exit 2; }

# --- 3: the token-value edit, then the generator ------------------------------------------------

TOKEN_EDIT_LINE="$(node - "$FIXTURE" "$W/$SOURCE_REL" <<'NODE'
const fs = require('node:fs');
const [fixtureFile, sourceFile] = process.argv.slice(2);
const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
// Same rules as the generator's overlay merge: deep merge, JSON null deletes a key, scalars replace.
function merge(base, over) {
  if (!isObject(base) || !isObject(over)) return over;
  const out = { ...base };
  for (const [k, v] of Object.entries(over)) {
    if (v === null) delete out[k];
    else if (isObject(v) && isObject(out[k])) out[k] = merge(out[k], v);
    else out[k] = v;
  }
  return out;
}
const source = JSON.parse(fs.readFileSync(sourceFile, 'utf8'));
const fixture = JSON.parse(fs.readFileSync(fixtureFile, 'utf8'));
delete fixture.$description;
const merged = merge(source, fixture);
const count = (a, b) => (isObject(a) && isObject(b)
  ? Object.keys({ ...a, ...b }).reduce((n, k) => n + count(a[k], b[k]), 0)
  : (JSON.stringify(a) === JSON.stringify(b) ? 0 : 1));
const changed = count(source, merged);
if (changed === 0) {
  console.log('TOKEN-EDIT 0 values change: the fixture holds the values the token source already holds; the source file is left as it is');
} else {
  fs.writeFileSync(sourceFile, `${JSON.stringify(merged, null, 2)}\n`);
  console.log(`TOKEN-EDIT ${changed} token values changed in ${require('node:path').basename(sourceFile)}`);
}
NODE
)"
echo "$TOKEN_EDIT_LINE"
TOKEN_EDIT_COUNT="$(sed -E 's/^TOKEN-EDIT ([0-9]+).*/\1/' <<<"$TOKEN_EDIT_LINE")"

step "generator writes every target from the swapped source" node "$W/scripts/tokens/build_tokens.mjs" --sibling "$N"

# Test seam: a raw colour appears in a file after generation. The proof must catch it.
if [ -n "$PLANT" ]; then
  case "$PLANT" in
    nuxt:*) PLANT_FILE="$N/${PLANT#nuxt:}" ;;
    *) PLANT_FILE="$W/$PLANT" ;;
  esac
  [ -f "$PLANT_FILE" ] || { echo "ERROR LN_SWAP_PLANT: no such file: $PLANT" >&2; exit 2; }
  printf '\n/* planted by LN_SWAP_PLANT */ .planted { color: #123456; }\n' >>"$PLANT_FILE"
  echo "PLANT $PLANT"
fi

# --- 4: only the token source and the generated targets may have changed ---------------------------

# The generator names its own targets: "fresh3 <path>" or "nuxt <path>", relative to each copy.
ALLOWED="$(node --input-type=module - "$W" "$N" <<'NODE'
import { relative, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const [w, n] = process.argv.slice(2);
const lib = await import(pathToFileURL(resolve(w, 'scripts/tokens/lib/tokens.mjs')).href);
console.log(`fresh3 ${lib.SOURCE_REL}`);
for (const t of await lib.discoverTargets()) {
  const p = t.path(lib.repoRoot, n);
  console.log(t.sibling ? `nuxt ${relative(n, p)}` : `fresh3 ${relative(w, p)}`);
}
NODE
)"

check_changes() { # check_changes <fresh3|nuxt> <dir> <leak prefix>
  local repo="$1" dir="$2" prefix="$3" path
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    echo "CHANGED $repo $path"
    if [ "$NO_LEAK_CHECK" != 1 ] && ! grep -qxF "$repo $path" <<<"$ALLOWED"; then
      echo "LEAK ${prefix}${path}"
      FAILS=$((FAILS + 1))
    fi
  done < <(git -C "$dir" status --porcelain --untracked-files=all | cut -c4-)
}
check_changes fresh3 "$W" ''
check_changes nuxt "$N" 'nuxt:'

# --- 5: every check, against the swapped copies --------------------------------------------------

step "generated files match the token source" node "$W/scripts/tokens/build_tokens.mjs" --check --sibling "$N"
step "contrast gate (WCAG 2.2 AA)" node "$W/scripts/tokens/check_contrast.mjs" --nuxt "$N"
# --paths names the worktree's own tree: this step must look at the swapped copy, whatever directory the proof was started from.
step "no raw colours in custom modules" node "$W/scripts/tokens/check_raw_colors.mjs" --paths "$W/html/modules/custom"
step "token parity (socialblue scheme, Drupal custom properties, Nuxt)" node "$W/scripts/check_token_parity.mjs" --sibling "$N"

# Open Social's contrib files are gitignored, so a worktree has none: read this checkout's, if it has them.
SOCIALBLUE="$ROOT/html/profiles/contrib/social/themes/socialblue"
step "derived socialblue pairings are current" node "$W/scripts/tokens/derive_socialblue_pairings.mjs" --check --socialblue "$SOCIALBLUE"

no_literal_in_overrides() {
  ! grep -nE '#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(' "$W/$OVERRIDES_REL"
}
step "ln-overrides.css holds no colour literal (its rules read var(--ln-*))" no_literal_in_overrides

# `step` calls this as an `if` condition, where bash suspends `set -e`: only the last command's status would count. Every
# command is checked by hand (WR-04: the git diff and every svg but the last were ignored).
logos_unchanged() {
  local svg
  git -C "$W" diff --quiet -- "$IMAGES_REL" || { echo "the logo SVGs differ from HEAD:"; git -C "$W" diff --stat -- "$IMAGES_REL"; return 1; }
  for svg in "$W/$IMAGES_REL"/*.svg; do
    grep -qE '#[0-9a-fA-F]{6}' "$svg" || { echo "no fixed brand colour left in $svg"; return 1; }
  done
}
step "logo SVGs keep their fixed brand colours (reasoned ALLOW lines): a palette swap does not recolour them" logos_unchanged

# The token suite, minus this proof's own test (which would run the proof again, inside itself).
token_suite() {
  local files=() f
  for f in "$W"/scripts/tokens/tests/*.test.mjs; do
    case "$f" in */prove_token_swap.test.mjs) ;; *) files+=("$f") ;; esac
  done
  # env -u NODE_TEST_CONTEXT: when this proof is started by a test, that variable says "you are a child of a test runner",
  # and a nested `node --test` then skips every file and exits 0, so the step would pass without running anything.
  (cd "$W" && env -u NODE_TEST_CONTEXT LN_SWAP_INNER=1 node --test "${files[@]}")
}
step "token test suite" token_suite

(cd "$N" && node_modules/.bin/vitest run --reporter=json --outputFile="$TMP/nuxt-after.json" >"$TMP/nuxt-after.log" 2>&1) || true
# Known gaps: Nuxt tests that are DESIGNED to fail when the palette changes, because the Nuxt repo holds a
# second, hand-written copy of palette values that the generator does not write. The list (KNOWN_NUXT_GAPS),
# the before/after comparison and the rule for when a gap closes live in scripts/tokens/lib/nuxt_gaps.mjs, where
# they are unit-tested: a gap prints as a GAP line, never a pass; it stops the proof (STALE GAP) the moment it
# closes, so the list cannot rot; any other new failure is a NEW FAILURE. Recorded in .planning/WINDOWS.md (44).
nuxt_tests_unchanged() {
  [ -s "$TMP/nuxt-after.json" ] || { echo "the Nuxt unit tests did not run after the swap"; tail -n 20 "$TMP/nuxt-after.log"; return 1; }
  node "$W/scripts/tokens/lib/nuxt_gaps.mjs" "$TMP/nuxt-before.json" "$TMP/nuxt-after.json" "$TOKEN_EDIT_COUNT"
}
STEP_SHOW_LOG=1 step "Nuxt unit tests: no failure the swap introduced (failures already in the ref are unchanged)" nuxt_tests_unchanged

if [ "$FAILS" -eq 0 ]; then
  echo "RESULT: PASS ($CHECKS checks)"
  exit 0
fi
echo "RESULT: FAIL ($FAILS failed)"
exit 1
