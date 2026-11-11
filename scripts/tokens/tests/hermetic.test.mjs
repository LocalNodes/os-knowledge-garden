/**
 * Hermetic-suite guards (WINDOWS 51, review WR-09, orchestrator ruling 2026-11-09).
 *
 * The token tests must depend on nothing they do not own:
 *   - they never read the live sibling checkout ../localnodes-onboarding, which another lane edits all the time
 *     (the dark-mode tripwire scans its pages and would fail the contrast suite on a correct tree);
 *   - they never write into the repo they run in (the generator's write mode rewrites the generated files, and a
 *     second suite run reading them at that moment sees a half-changed tree).
 * Each test below runs the real suite file from a private copy of the repo (helpers/repo_copy.mjs) that is set up
 * to break a test that is not hermetic.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copyRepo } from './helpers/repo_copy.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const inner = process.env.LN_HERMETIC_INNER === '1' || Boolean(process.env.LN_SWAP_INNER);

function runSuiteFiles(repo, files) {
  // NODE_TEST_CONTEXT marks a process as a child of a running test runner; a nested `node --test` must not inherit it.
  const env = { ...process.env, LN_HERMETIC_INNER: '1', LN_SWAP_NUXT_REPO: join(repo, '..', 'no-such-repo') };
  delete env.NODE_TEST_CONTEXT;
  const r = spawnSync(process.execPath, ['--test', ...files.map((f) => join(repo, 'scripts/tokens/tests', f))], {
    cwd: repo,
    encoding: 'utf8',
    // The copy has no sibling Nuxt repo of its own for the proof test to find, and must not recurse into this file.
    env,
    maxBuffer: 64 * 1024 * 1024,
  });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}

test('the contrast suite passes when the sibling Nuxt checkout holds a page that renders dark', { skip: inner && 'inside a copy (no recursion)' }, () => {
  const { root, repo } = copyRepo();
  // Where ../localnodes-onboarding is for the copy: a checkout whose new page has no colorMode: 'light'.
  const sibling = join(root, 'localnodes-onboarding');
  mkdirSync(join(sibling, 'app/pages'), { recursive: true });
  writeFileSync(join(sibling, 'nuxt.config.ts'), 'export default defineNuxtConfig({})\n');
  writeFileSync(join(sibling, 'app/pages/new-page.vue'), '<template><div>a page another lane just added</div></template>\n');
  const r = runSuiteFiles(repo, ['check_contrast.test.mjs']);
  assert.equal(r.code, 0, `the contrast suite read the sibling checkout:\n${r.out.split('\n').filter((l) => /✖|TRIPWIRE|AssertionError/.test(l)).slice(0, 8).join('\n')}`);
});

test('the Nuxt target tests leave the repo they run in untouched, even when its generated files are stale', { skip: inner && 'inside a copy (no recursion)' }, () => {
  const { repo } = copyRepo();
  // Stale in a way the parity checks do not read (they compare the stylesheet and the scheme, not the flat file), so only a
  // write by the generator can change it back.
  const stale = ['tokens/ln-tokens.flat.json'].map((rel) => join(repo, 'html/modules/custom/localnodes_platform', rel));
  const before = stale.map((f) => {
    const text = `${readFileSync(f, 'utf8')}\n`;
    writeFileSync(f, text);
    return text;
  });
  const r = runSuiteFiles(repo, ['nuxt_target.test.mjs', 'nuxt_mail_target.test.mjs']);
  assert.equal(r.code, 0, r.out.split('\n').filter((l) => /✖|AssertionError/.test(l)).slice(0, 8).join('\n'));
  stale.forEach((f, i) => assert.equal(readFileSync(f, 'utf8'), before[i], `${f.slice(repo.length + 1)} was rewritten by a test`));
});
