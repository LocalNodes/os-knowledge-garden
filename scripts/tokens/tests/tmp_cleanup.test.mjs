/**
 * The token tests leave nothing in the temp directory (2026-11-11 ruling).
 *
 * Before this, each full run left about 1,600 `ln-*` directories in /tmp (1,754 had built up between a reboot
 * and 11-11). Every test temp directory now comes from helpers/tmp_dir.mjs, which names it `ln-<name>-test-XXXXXX`
 * and removes it when the test file finishes. These tests pin both halves:
 *   - a child test file that makes directories through the helper, run with its own TMPDIR, leaves that TMPDIR
 *     empty, whether its tests pass or fail;
 *   - no test file calls mkdtempSync itself, so nothing can bypass the helper (one listed exception, which
 *     removes its directory in a finally block and needs a prefix of its own).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const helper = join(here, 'helpers', 'tmp_dir.mjs');

/**
 * Runs `body` as a node:test file in a child process whose TMPDIR is a private directory, and returns what the
 * child left there (ignoring node's own compile cache). The private directory sits under the real temp directory
 * and is removed here, outside the helper, so this check doesn't depend on the thing it checks.
 */
function leftoversOf(body) {
  const box = mkdtempSync(join(tmpdir(), 'ln-tmp-cleanup-box-'));
  const work = mkdtempSync(join(tmpdir(), 'ln-tmp-cleanup-src-'));
  try {
    const file = join(work, 'child.test.mjs');
    writeFileSync(file, `import { test } from 'node:test';\nimport assert from 'node:assert/strict';\nimport { testTmpDir } from ${JSON.stringify(helper)};\n${body}\n`);
    const env = { ...process.env, TMPDIR: box };
    delete env.NODE_TEST_CONTEXT; // run the child as its own test run, not as a subtest of this one
    const run = spawnSync(process.execPath, ['--test', file], { env, encoding: 'utf8' });
    const left = readdirSync(box).filter((n) => n !== 'node-compile-cache').sort();
    return { status: run.status, left, out: run.stdout + run.stderr };
  } finally {
    rmSync(box, { recursive: true, force: true });
    rmSync(work, { recursive: true, force: true });
  }
}

test('a test file that makes temp directories through the helper leaves its TMPDIR empty', () => {
  const r = leftoversOf(`
const top = testTmpDir('cleanup-probe');
test('one', () => { testTmpDir('cleanup-probe'); testTmpDir('cleanup-probe'); });
test('two', () => { assert.ok(top); testTmpDir('cleanup-other'); });
`);
  assert.equal(r.status, 0, r.out);
  assert.deepEqual(r.left, []);
});

test('a failing test still leaves its TMPDIR empty', () => {
  const r = leftoversOf(`
test('fails', () => { testTmpDir('cleanup-probe'); assert.fail('on purpose'); });
`);
  assert.notEqual(r.status, 0, 'the child test was meant to fail');
  assert.match(r.out, /on purpose/, 'the child failed for another reason:\n' + r.out);
  assert.deepEqual(r.left, []);
});

test('the helper names each directory ln-<name>-test-XXXXXX, so the ruled /tmp/ln-*-test-* glob covers it', async () => {
  const { testTmpDir } = await import(helper);
  const dir = testTmpDir('naming-probe');
  assert.match(dir.split('/').pop(), /^ln-naming-probe-test-[A-Za-z0-9]{6}$/);
  assert.throws(() => testTmpDir('Bad Name'), /name/);
});

// Files allowed to call mkdtempSync directly, with the reason. Everything else goes through the helper.
const DIRECT_MKDTEMP = {
  'helpers/tmp_dir.mjs': 'the helper itself',
  'tmp_cleanup.test.mjs': 'the check above must not depend on the helper it checks; it removes its own directories in finally',
  'prove_token_swap.test.mjs': 'needs a prefix of its own to test the swap script\'s leftover check; removes it in finally',
};

test('no test file calls mkdtempSync except through the helper', () => {
  const files = [
    ...readdirSync(here).filter((n) => n.endsWith('.mjs')),
    ...readdirSync(join(here, 'helpers')).filter((n) => n.endsWith('.mjs')).map((n) => `helpers/${n}`),
  ];
  const offenders = files.filter((f) => !(f in DIRECT_MKDTEMP) && /\bmkdtempSync\s*\(/.test(readFileSync(join(here, f), 'utf8')));
  assert.deepEqual(offenders, []);
});
