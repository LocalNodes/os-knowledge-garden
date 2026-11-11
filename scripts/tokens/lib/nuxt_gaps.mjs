/**
 * Known Nuxt gaps and the before/after comparator for the palette-swap proof (22.1-14, 22.1-09, DES-01, WINDOWS 44).
 *
 * scripts/tokens/prove_token_swap.sh runs the Nuxt repo's unit tests (vitest JSON) before and after a
 * palette swap and hands both reports to this file. A test that fails only after the swap is a NEW
 * FAILURE, unless it is one of the KNOWN_NUXT_GAPS below: tests DESIGNED to fail when the palette
 * changes, because the Nuxt repo holds a second, hand-written copy of palette values that the
 * generator does not write. A gap is printed as a `GAP` line, never counted as a pass, and is
 * recorded in .planning/WINDOWS.md. It stops the proof (`STALE GAP`) the moment it closes, so this
 * list cannot rot (the same rule as EXCLUDED in pairings.mjs). A gap only applies when its test
 * exists and passes before the swap in the ref being read.
 *
 * WHEN THE MAIL GAP CLOSES (WINDOWS 44): when Phase 23's import lands and its test is re-pinned, not
 * when server/utils/mail-colors.generated.ts merely exists. The generated file alone changes nothing
 * the pinned test reads, so with the file present but unwired a swap still fails the test and the GAP
 * still prints. Once mail-copy.ts imports ./mail-colors.generated and tests/unit/mail-copy.test.ts
 * reads the generated values, the first proof run against that ref prints `STALE GAP` and fails.
 * Whoever sees it deletes the entry below (one line) and marks WINDOWS 44 fixed
 * (`gsd-tools windows fixed 44`). The token suite's proof tests read origin/main by default, so they
 * go red on that day by design: that is the reminder. If Phase 23 wires the import without re-pinning
 * `link` (the email link role is semantic.link, the old pin was --color-ln-brand-800), palette C prints
 * STALE GAP (its link equals brand 800), and once the entry is gone palette A prints NEW FAILURE on
 * `link`. Either way the proof forces the re-pin.
 *
 * CLI (what the proof calls):
 *   node scripts/tokens/lib/nuxt_gaps.mjs <before.json> <after.json> <editCount>
 * prints the comparison lines and exits 1 when the comparison is bad, 0 otherwise, 2 on a usage error.
 * editCount is how many token values the swap changed (0: the palette is already the source's).
 * Node built-ins only.
 */
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

/**
 * Each entry: `test` is the prefix of a failure key (`<file under tests/> :: <full test name>`),
 * `windows` the .planning/WINDOWS.md entry, `reason` why the test fails after a swap, `closes` the
 * rule that closes it.
 */
export const KNOWN_NUXT_GAPS = Object.freeze([
  Object.freeze({
    test: 'tests/unit/mail-copy.test.ts :: the mail look every colour is a role in the generated token file',
    windows: 44,
    reason: 'server/utils/mail-copy.ts MAIL_COLORS are literal hexes (mail clients cannot read CSS custom properties) and tests/unit/mail-copy.test.ts pins them to the generated token file',
    closes: "Phase 23's import of ./mail-colors.generated in mail-copy.ts plus the re-pinned link in the test, not the generated file existing",
  }),
]);

const prefixOf = (gap) => (typeof gap === 'string' ? gap : gap.test);

const testsRel = (name) => name.replace(/^.*\/tests\//, 'tests/');

/** The failures of a vitest JSON report: `file :: full name`, or `file (file failed to load)`. */
function failures(report) {
  const set = new Set();
  for (const file of report.testResults) {
    const name = testsRel(file.name);
    const failed = file.assertionResults.filter((a) => a.status === 'failed');
    for (const a of failed) set.add(`${name} :: ${a.fullName}`);
    if (file.status === 'failed' && failed.length === 0) set.add(`${name} (file failed to load)`);
  }
  return set;
}

/** The tests that passed in a vitest JSON report: `file :: full name`. */
function passes(report) {
  const set = new Set();
  for (const file of report.testResults) {
    const name = testsRel(file.name);
    for (const a of file.assertionResults.filter((x) => x.status === 'passed')) set.add(`${name} :: ${a.fullName}`);
  }
  return set;
}

/**
 * Compare the reports taken before and after a swap.
 * Returns { lines, bad }: the output lines, and whether the proof must fail.
 */
export function judge(before, after, editCount, gaps = KNOWN_NUXT_GAPS) {
  const prefixes = gaps.map(prefixOf);
  const lines = [
    `before the swap: ${before.numPassedTests} passed, ${before.numFailedTests} failed of ${before.numTotalTests}`,
    `after the swap:  ${after.numPassedTests} passed, ${after.numFailedTests} failed of ${after.numTotalTests}`,
  ];
  const failedBefore = failures(before);
  const failedAfter = failures(after);
  const passedBefore = passes(before);
  const isGap = (x) => prefixes.some((g) => x.startsWith(g));
  let bad = after.numTotalTests !== before.numTotalTests;
  if (bad) lines.push(`the number of Nuxt tests changed with the swap: ${before.numTotalTests} before, ${after.numTotalTests} after`);
  for (const x of [...failedAfter].filter((y) => !failedBefore.has(y))) {
    if (isGap(x)) {
      lines.push(`GAP ${x}: the Nuxt repo holds a hand-written copy of palette values; a swap needs it edited (see .planning/WINDOWS.md)`);
    } else {
      lines.push(`NEW FAILURE after the swap: ${x}`);
      bad = true;
    }
  }
  // A gap that no longer fails after a real swap is closed: delete it from KNOWN_NUXT_GAPS.
  if (Number(editCount) > 0) {
    for (const gap of gaps) {
      const g = prefixOf(gap);
      const applies = [...passedBefore].some((x) => x.startsWith(g));
      if (applies && ![...failedAfter].some((x) => x.startsWith(g))) {
        const windows = typeof gap === 'string' || !gap.windows ? '' : `, and mark WINDOWS ${gap.windows} fixed`;
        lines.push(`STALE GAP ${g}: it passes after the swap now; delete its entry from KNOWN_NUXT_GAPS in scripts/tokens/lib/nuxt_gaps.mjs${windows}`);
        bad = true;
      }
    }
  }
  return { lines, bad };
}

function main(argv) {
  if (argv.length < 3) {
    console.error('usage: nuxt_gaps.mjs <before.json> <after.json> <editCount>');
    return 2;
  }
  const [beforeFile, afterFile, editCount] = argv;
  const read = (f) => JSON.parse(readFileSync(f, 'utf8'));
  const { lines, bad } = judge(read(beforeFile), read(afterFile), editCount);
  console.log(lines.join('\n'));
  return bad ? 1 : 0;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  // process.exitCode, not process.exit(): exit() drops output still queued on a slow pipe (WINDOWS 51).
  process.exitCode = main(process.argv.slice(2));
}
