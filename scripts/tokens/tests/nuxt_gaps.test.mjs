/**
 * Tests for the Nuxt known-gap comparator (22.1-14, DES-01, WINDOWS 44).
 *
 * scripts/tokens/prove_token_swap.sh runs the Nuxt repo's unit tests before and after a palette
 * swap and hands both vitest JSON reports to scripts/tokens/lib/nuxt_gaps.mjs. This file feeds
 * the comparator synthetic reports in the shape vitest writes (testResults with name, status
 * and assertionResults; numPassedTests, numFailedTests, numTotalTests) and shows each rule
 * firing: GAP, STALE GAP, NEW FAILURE, a changed test count, and the no-edit case.
 *
 * Run: node --test scripts/tokens/tests/*.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { KNOWN_NUXT_GAPS, judge } from '../lib/nuxt_gaps.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const script = resolve(here, '../lib/nuxt_gaps.mjs');

const MAIL_FILE = 'tests/unit/mail-copy.test.ts';
const MAIL_TEST = 'the mail look every colour is a role in the generated token file';
const MAIL_ENTRY = `${MAIL_FILE} :: ${MAIL_TEST}`;
/** The full name vitest gives the real test: describe + it, which the entry matches as a prefix. */
const MAIL_FULL = `${MAIL_TEST}, so a palette change shows up here`;

/** A vitest JSON report. files: [{ file, status?, tests: [[fullName, 'passed'|'failed'], ...] }]. */
function report(files) {
  const testResults = files.map(({ file, status, tests }) => ({
    name: `/tmp/ln-swap-abc123/nuxt/${file}`,
    status: status ?? (tests.some(([, s]) => s === 'failed') ? 'failed' : 'passed'),
    assertionResults: tests.map(([fullName, s]) => ({ fullName, status: s })),
  }));
  const all = testResults.flatMap((f) => f.assertionResults);
  return {
    numTotalTests: all.length,
    numPassedTests: all.filter((a) => a.status === 'passed').length,
    numFailedTests: all.filter((a) => a.status === 'failed').length,
    testResults,
  };
}

const otherTests = [['billing mail sends the invite', 'passed'], ['checkout creates a session', 'passed']];
const withMail = (status) => report([{ file: MAIL_FILE, tests: [[MAIL_FULL, status]] }, { file: 'tests/unit/billing.test.ts', tests: otherTests }]);

const has = (lines, re) => lines.some((l) => re.test(l));

test('the list holds one entry: the mail-copy test, WINDOWS 44, with its reason and the rule that closes it', () => {
  assert.equal(KNOWN_NUXT_GAPS.length, 1);
  const [gap] = KNOWN_NUXT_GAPS;
  assert.equal(gap.test, MAIL_ENTRY, 'the entry the proof listed in 22.1-09');
  assert.equal(gap.windows, 44);
  assert.match(gap.reason, /literal|hand-written/i);
  assert.match(gap.closes, /import/i, 'it closes with Phase 23\'s import');
  assert.match(gap.closes, /link/i, 'and the re-pinned link');
  assert.doesNotMatch(gap.closes, /generated file (exists|lands)\b/i, 'not when the generated file merely exists');
});

test('unwired: the mail test passes before and fails after a real swap (3 edits): one GAP line, not bad', () => {
  const { lines, bad } = judge(withMail('passed'), withMail('failed'), 3, KNOWN_NUXT_GAPS);
  assert.equal(bad, false, lines.join('\n'));
  const gaps = lines.filter((l) => l.startsWith('GAP '));
  assert.equal(gaps.length, 1, lines.join('\n'));
  assert.match(gaps[0], /^GAP tests\/unit\/mail-copy\.test\.ts :: the mail look every colour is a role in the generated token file, so a palette change shows up here/);
  assert.equal(has(lines, /STALE GAP|NEW FAILURE/), false);
});

test('the count lines are printed before and after', () => {
  const { lines } = judge(withMail('passed'), withMail('failed'), 3, KNOWN_NUXT_GAPS);
  assert.ok(lines.includes('before the swap: 3 passed, 0 failed of 3'), lines.join('\n'));
  assert.ok(lines.includes('after the swap:  2 passed, 1 failed of 3'), lines.join('\n'));
});

test('wired and re-pinned: the mail test passes before and after a real swap: STALE GAP naming the entry, bad', () => {
  const { lines, bad } = judge(withMail('passed'), withMail('passed'), 3, KNOWN_NUXT_GAPS);
  assert.equal(bad, true);
  const stale = lines.filter((l) => l.startsWith('STALE GAP '));
  assert.equal(stale.length, 1, lines.join('\n'));
  assert.ok(stale[0].includes(MAIL_ENTRY), stale[0]);
  assert.match(stale[0], /nuxt_gaps\.mjs/, 'it says where the entry is deleted');
  assert.match(stale[0], /WINDOWS 44/, 'and what to mark fixed');
  assert.equal(has(lines, /^GAP /), false);
});

test('palette B (0 edits): the mail test passes after, so there is no STALE GAP and the run is not bad', () => {
  const { lines, bad } = judge(withMail('passed'), withMail('passed'), 0, KNOWN_NUXT_GAPS);
  assert.equal(bad, false, lines.join('\n'));
  assert.equal(has(lines, /STALE GAP|^GAP |NEW FAILURE/), false);
});

test('a test not on the list that fails only after the swap is a NEW FAILURE naming it, bad', () => {
  const after = report([
    { file: MAIL_FILE, tests: [[MAIL_FULL, 'failed']] },
    { file: 'tests/unit/billing.test.ts', tests: [['billing mail sends the invite', 'failed'], ['checkout creates a session', 'passed']] },
  ]);
  const { lines, bad } = judge(withMail('passed'), after, 3, KNOWN_NUXT_GAPS);
  assert.equal(bad, true);
  assert.ok(lines.includes('NEW FAILURE after the swap: tests/unit/billing.test.ts :: billing mail sends the invite'), lines.join('\n'));
  assert.equal(lines.filter((l) => l.startsWith('GAP ')).length, 1, 'the real gap is still named as a GAP');
});

test('a failure already in the ref before the swap is not new', () => {
  const before = report([{ file: 'tests/unit/billing.test.ts', tests: [['billing mail sends the invite', 'failed'], ['checkout creates a session', 'passed']] }]);
  const { lines, bad } = judge(before, before, 3, KNOWN_NUXT_GAPS);
  assert.equal(bad, false, lines.join('\n'));
  assert.equal(has(lines, /NEW FAILURE/), false);
});

test('a test file that fails to load only after the swap is a NEW FAILURE', () => {
  const after = report([
    { file: MAIL_FILE, tests: [[MAIL_FULL, 'failed']] },
    { file: 'tests/unit/broken.test.ts', status: 'failed', tests: [] },
    { file: 'tests/unit/billing.test.ts', tests: otherTests },
  ]);
  const { lines, bad } = judge(withMail('passed'), after, 3, KNOWN_NUXT_GAPS);
  assert.equal(bad, true);
  assert.ok(lines.includes('NEW FAILURE after the swap: tests/unit/broken.test.ts (file failed to load)'), lines.join('\n'));
});

test('a changed total test count is bad, even when nothing else differs', () => {
  const after = report([{ file: MAIL_FILE, tests: [[MAIL_FULL, 'passed']] }, { file: 'tests/unit/billing.test.ts', tests: [otherTests[0]] }]);
  const { bad } = judge(withMail('passed'), after, 0, KNOWN_NUXT_GAPS);
  assert.equal(bad, true);
});

test('the gap test absent from the ref (origin/main today): neither GAP nor STALE GAP, not bad', () => {
  const noMail = report([{ file: 'tests/unit/billing.test.ts', tests: otherTests }]);
  const { lines, bad } = judge(noMail, noMail, 3, KNOWN_NUXT_GAPS);
  assert.equal(bad, false, lines.join('\n'));
  assert.equal(has(lines, /STALE GAP|^GAP /), false);
});

test('a gap whose test failed before the swap does not become a STALE GAP when it still fails after', () => {
  const { lines, bad } = judge(withMail('failed'), withMail('failed'), 3, KNOWN_NUXT_GAPS);
  assert.equal(bad, false, lines.join('\n'));
  assert.equal(has(lines, /STALE GAP|^GAP /), false);
});

test('with an empty list the same mail failure is a NEW FAILURE: the deleted entry makes the proof insist on a re-pin', () => {
  const { lines, bad } = judge(withMail('passed'), withMail('failed'), 3, []);
  assert.equal(bad, true);
  assert.equal(has(lines, /^NEW FAILURE after the swap: tests\/unit\/mail-copy\.test\.ts/), true, lines.join('\n'));
});

/* ---- the CLI the proof calls ---- */

function cli(before, after, editCount) {
  const dir = mkdtempSync(join(tmpdir(), 'ln-gaps-'));
  writeFileSync(join(dir, 'before.json'), JSON.stringify(before));
  writeFileSync(join(dir, 'after.json'), JSON.stringify(after));
  const args = [script, join(dir, 'before.json'), join(dir, 'after.json')];
  if (editCount !== undefined) args.push(String(editCount));
  const r = spawnSync(process.execPath, args, { encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}

test('the CLI prints the same lines as judge() and exits 0 on a GAP', () => {
  const want = judge(withMail('passed'), withMail('failed'), 3, KNOWN_NUXT_GAPS).lines.join('\n');
  const r = cli(withMail('passed'), withMail('failed'), 3);
  assert.equal(r.code, 0, r.out);
  assert.equal(r.out.trimEnd(), want);
  assert.match(r.out, /^GAP tests\/unit\/mail-copy\.test\.ts/m);
});

test('the CLI exits 1 and prints STALE GAP when the gap has closed', () => {
  const r = cli(withMail('passed'), withMail('passed'), 3);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^STALE GAP /m);
});

test('the CLI exits 1 on a NEW FAILURE and 0 when nothing differs', () => {
  const after = report([{ file: MAIL_FILE, tests: [[MAIL_FULL, 'passed']] }, { file: 'tests/unit/billing.test.ts', tests: [['billing mail sends the invite', 'failed'], otherTests[1]] }]);
  const bad = cli(withMail('passed'), after, 0);
  assert.equal(bad.code, 1, bad.out);
  assert.match(bad.out, /^NEW FAILURE after the swap: tests\/unit\/billing\.test\.ts/m);
  const same = cli(withMail('passed'), withMail('passed'), 0);
  assert.equal(same.code, 0, same.out);
});

test('the CLI with missing arguments is a usage error (exit 2)', () => {
  const r = spawnSync(process.execPath, [script], { encoding: 'utf8' });
  assert.equal(r.status, 2);
  assert.match(`${r.stdout}${r.stderr}`, /usage/i);
});
