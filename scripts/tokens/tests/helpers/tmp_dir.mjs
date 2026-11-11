/**
 * The one way the token tests make temp directories (2026-11-11 ruling; tests/tmp_cleanup.test.mjs pins it).
 *
 * `testTmpDir(name)` makes `<tmpdir>/ln-<name>-test-XXXXXX` and remembers it. Every remembered directory is removed
 * when the test file's tests finish (a root-level `after` hook), and again on process exit as a backstop, so a
 * failing or interrupted test leaves nothing behind. The `-test-` in the name keeps every one inside the
 * `/tmp/ln-*-test-*` glob used to clear old leftovers.
 */
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after } from 'node:test';

const made = new Set();

/** Removes every directory made so far in this process. Safe to call more than once. */
export function removeTestTmpDirs() {
  for (const dir of made) rmSync(dir, { recursive: true, force: true });
  made.clear();
}

// Imported at the top of a test file, so this hook belongs to the file's root test and runs after all its tests.
after(removeTestTmpDirs);
process.on('exit', removeTestTmpDirs);

/** Makes and returns a fresh directory named ln-<name>-test-XXXXXX in the temp directory. */
export function testTmpDir(name) {
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(name)) throw new Error(`testTmpDir: name must be lowercase words joined by '-', got ${JSON.stringify(name)}`);
  const dir = mkdtempSync(join(tmpdir(), `ln-${name}-test-`));
  made.add(dir);
  return dir;
}
