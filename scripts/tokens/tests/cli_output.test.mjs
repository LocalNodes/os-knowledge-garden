/**
 * Output-completeness guard for the token CLIs (WINDOWS 51, review WR-09).
 *
 * A CLI that ends with process.exit() right after its last console.log loses output that is still queued on a pipe
 * whose reader is slow. A test that parses such a CLI's stdout then fails now and then, under load, on a correct
 * tree: the RESULT line or half of the pairing list is missing (observed: check_contrast.mjs cut off at 11 to 13 KB of
 * 14 KB, status 0). These tests run each CLI with stdout and stderr deferred (helpers/slow-stdio.cjs), which makes the
 * loss deterministic, and require the same bytes and the same exit code as a normal run.
 *
 * Run: node --test scripts/tokens/tests/*.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
const preload = join(here, 'helpers/slow-stdio.cjs');
// A sibling that does not exist: the Nuxt targets SKIP, so no test depends on the real checkout next door.
const absent = join(tmpdir(), 'ln-cli-output-no-such-sibling');

const CLIS = [
  ['check_contrast.mjs', ['scripts/tokens/check_contrast.mjs', '--nuxt', absent], /^RESULT: (PASS|FAIL)/m],
  ['check_raw_colors.mjs', ['scripts/tokens/check_raw_colors.mjs', '--paths', 'html/modules/custom/localnodes_platform'], /^RESULT: (PASS|FAIL)/m],
  ['build_tokens.mjs --check', ['scripts/tokens/build_tokens.mjs', '--check', '--sibling', absent], /^RESULT: (PASS|FAIL)/m],
  ['check_token_parity.mjs', ['scripts/check_token_parity.mjs', '--sibling', absent], /^RESULT: (PASS|FAIL)/m],
];

function run(args, withPreload) {
  const node = withPreload ? ['--require', preload, ...args] : args;
  const r = spawnSync(process.execPath, node, { cwd: repoRoot, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  return { code: r.status, stdout: r.stdout, stderr: r.stderr };
}

for (const [name, args, result] of CLIS) {
  test(`${name} prints all of its output and its exit code even when its stdio is slow`, () => {
    const normal = run(args, false);
    assert.match(normal.stdout, result, `a normal run prints a RESULT line:\n${normal.stdout}${normal.stderr}`);
    const slow = run(args, true);
    assert.equal(slow.code, normal.code, 'the exit code does not depend on how fast the reader is');
    assert.equal(slow.stdout, normal.stdout, `${name}: stdout lost ${normal.stdout.length - slow.stdout.length} of ${normal.stdout.length} bytes`);
    assert.equal(slow.stderr, normal.stderr);
  });
}
