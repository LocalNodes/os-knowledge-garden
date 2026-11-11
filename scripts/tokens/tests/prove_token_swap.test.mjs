/**
 * Guard tests for the palette-swap proof (22.1-09, DES-01, D-03, criterion 3).
 *
 * scripts/tokens/prove_token_swap.sh <fixture> writes another palette's values into
 * the token source of a throwaway git worktree of fresh3 (HEAD) and a throwaway copy
 * of the Nuxt repo (a read-only `git archive` of one ref), runs the generator, and
 * requires that nothing changed except the token source and the generated files and
 * that every check still passes.
 *
 * These tests check the three fixtures, run the proof for each, and show the proof
 * FIRING: a raw colour planted after generation (LN_SWAP_PLANT) must make it exit 1
 * and name the file, in a hand-written CSS file, in a file no other check reads, in
 * the Nuxt copy, and in a generated file. Each run must leave no worktree and no
 * temp directory behind (T-22.1-28).
 *
 * The Nuxt side is read from the ref in env LN_SWAP_NUXT_REF (default origin/main) of
 * the repo in LN_SWAP_NUXT_REPO (default ../localnodes-onboarding), never written to.
 * With no such repo or ref, or no node_modules in it (CI), the proof tests SKIP and
 * the fixture tests still run. Inside the proof's own token-suite step the proof tests
 * skip too (LN_SWAP_INNER), so the suite cannot recurse.
 *
 * Run: node --test scripts/tokens/tests/*.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
const proof = resolve(repoRoot, 'scripts/tokens/prove_token_swap.sh');
const fixtureDir = resolve(repoRoot, 'scripts/tokens/fixtures');
const SOURCE = resolve(repoRoot, 'html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json');
const OVERRIDES_REL = 'html/modules/custom/localnodes_platform/css/ln-overrides.css';
const IMAGES_REL = 'html/modules/custom/localnodes_platform/images';
const GENERATED_CSS_REL = 'html/modules/custom/localnodes_platform/css/ln-tokens.css';
const OPTIONS = ['a', 'b', 'c'];
/** The option Tom chose (BRAND.md section 12, card P, 2026-11-06). */
const CHOSEN = 'b';

const nuxtRepo = process.env.LN_SWAP_NUXT_REPO ?? resolve(repoRoot, '../localnodes-onboarding');
const nuxtRef = process.env.LN_SWAP_NUXT_REF ?? 'origin/main';

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const fixturePath = (o) => join(fixtureDir, `palette-${o}.json`);
const readJson = (p) => JSON.parse(readFileSync(p, 'utf8'));

/** Every { path, raw } leaf in a token tree: an object with $value, or a bare string (targets.* use those). */
function leaves(node, path = '') {
  if (isObject(node) && Object.prototype.hasOwnProperty.call(node, '$value')) return [{ path, raw: node.$value }];
  if (typeof node === 'string' && path) return [{ path, raw: node }];
  if (!isObject(node)) return [];
  return Object.entries(node)
    .filter(([k]) => !k.startsWith('$'))
    .flatMap(([k, v]) => leaves(v, path ? `${path}.${k}` : k));
}

const norm = (v) => (typeof v === 'string' && v.startsWith('#') ? v.toLowerCase() : v);
const sourceLeaves = () => new Map(leaves(readJson(SOURCE)).map((l) => [l.path, norm(l.raw)]));

function git(args, cwd = repoRoot) {
  const r = spawnSync('git', args, { cwd, encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}`.trim() };
}

/** Why the proof tests cannot run here, or false. */
function skipReason() {
  if (process.env.LN_SWAP_INNER) return 'inside the proof (no recursion)';
  if (git(['-C', nuxtRepo, 'rev-parse', '--verify', '-q', `${nuxtRef}^{commit}`]).code !== 0) {
    return `Nuxt ref ${nuxtRef} not found in ${nuxtRepo} (CI has no sibling)`;
  }
  if (!existsSync(join(nuxtRepo, 'node_modules'))) return `no node_modules in ${nuxtRepo}`;
  return false;
}
const skip = skipReason();

function runProof(fixture, env = {}) {
  const prefix = env.LN_SWAP_TMP_PREFIX ?? freshPrefix();
  const r = spawnSync('bash', [proof, fixture], {
    cwd: repoRoot,
    encoding: 'utf8',
    env: { ...process.env, ...env, LN_SWAP_TMP_PREFIX: prefix },
    maxBuffer: 64 * 1024 * 1024,
  });
  return { code: r.status, out: `${r.stdout}${r.stderr}`, prefix };
}

/**
 * A prefix no other run shares. The proof names its work directory with it (LN_SWAP_TMP_PREFIX), so a test can ask for
 * its OWN leftovers. Comparing the global worktree list (it carries the checkout's HEAD sha) or every ln-swap- entry in the
 * temp directory fails whenever another proof run, an agent or a commit lands while the suite runs (WINDOWS 51).
 */
let runs = 0;
const freshPrefix = () => `ln-swap-t${process.pid}-${++runs}-`;

/** What a run named `prefix` left behind: temp entries and worktrees that carry its prefix. */
const leftovers = (prefix) => ({
  tmp: readdirSync(tmpdir()).filter((n) => n.startsWith(prefix)).sort(),
  worktrees: git(['worktree', 'list']).out.split('\n').filter((l) => l.includes(`/${prefix}`)),
});

test('the cleanup check sees only its own run: another run\'s temp directory does not count (and does count for that run)', () => {
  const own = freshPrefix();
  const other = freshPrefix();
  const foreign = mkdtempSync(join(tmpdir(), other));
  try {
    assert.deepEqual(leftovers(own), { tmp: [], worktrees: [] });
    assert.deepEqual(leftovers(other).tmp, [foreign.split('/').pop()]);
  } finally {
    rmSync(foreign, { recursive: true, force: true });
  }
});

// --- fixtures (no sibling needed) ------------------------------------------------

test('the chosen palette fixture holds exactly the values the token source holds', () => {
  const have = sourceLeaves();
  const fixture = leaves(readJson(fixturePath(CHOSEN)));
  assert.ok(fixture.length >= 30, `palette-${CHOSEN}.json has ${fixture.length} leaves, expected the brand, accent and chrome scales and the socialblue fields`);
  for (const { path, raw } of fixture) {
    assert.ok(have.has(path), `palette-${CHOSEN}.json sets ${path}, which the token source does not have`);
    assert.equal(norm(raw), have.get(path), `palette-${CHOSEN}.json ${path} is not the token source's value`);
  }
});

test('the other two fixtures change at least one source value each, so the swap is not vacuous', () => {
  const have = sourceLeaves();
  for (const o of OPTIONS.filter((x) => x !== CHOSEN)) {
    const changed = leaves(readJson(fixturePath(o))).filter(({ path, raw }) => have.get(path) !== norm(raw));
    assert.ok(changed.length >= 5, `palette-${o}.json changes only ${changed.length} source leaves`);
  }
});

test('every fixture cites CANVAS-BRIEF.md, sets only token leaves and never flips dark mode on', () => {
  for (const o of OPTIONS) {
    const text = readFileSync(fixturePath(o), 'utf8');
    const json = JSON.parse(text);
    assert.match(String(json.$description), /CANVAS-BRIEF\.md/, `palette-${o}.json $description must cite CANVAS-BRIEF.md`);
    assert.ok(!/dark-mode-rendered/.test(text), `palette-${o}.json must not set targets.nuxt.dark-mode-rendered (the tripwire guards it)`);
    for (const { path, raw } of leaves(json)) {
      assert.ok(typeof raw === 'string' && (/^#[0-9a-fA-F]{6}$/.test(raw) || /^\{[a-z0-9.-]+\}$/.test(raw)), `palette-${o}.json ${path} is "${raw}", not a 6-digit hex or a reference`);
    }
  }
});

// --- the proof -------------------------------------------------------------------

for (const o of OPTIONS) {
  test(`proof passes for palette ${o.toUpperCase()}, changes only token files in both repos and cleans up`, { skip }, () => {
    const r = runProof(fixturePath(o));
    assert.equal(r.code, 0, r.out);
    assert.match(r.out, /RESULT: PASS/);
    assert.doesNotMatch(r.out, /^LEAK /m, r.out);
    assert.doesNotMatch(r.out, /^FAIL /m, r.out);
    assert.match(r.out, new RegExp(`^NUXT ref=${nuxtRef.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')} sha=[0-9a-f]{40}$`, 'm'), 'the proof must print the Nuxt ref and its resolved SHA');
    // Only the token source and generated targets may be listed as changed.
    const changed = [...r.out.matchAll(/^CHANGED (fresh3|nuxt) (\S+)$/gm)].map((m) => `${m[1]}:${m[2]}`);
    const allowed = new Set([
      'fresh3:html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json',
      'fresh3:html/modules/custom/localnodes_platform/css/ln-tokens.css',
      'fresh3:html/modules/custom/localnodes_platform/tokens/ln-tokens.flat.json',
      'fresh3:html/modules/custom/localnodes_platform/config/optional/color.theme.socialblue.yml',
      'nuxt:app/assets/css/ln-tokens.css',
      // 22.1-14: against a Nuxt ref that has adopted the mail colours, the regenerated file is a legitimate change.
      'nuxt:server/utils/mail-colors.generated.ts',
    ]);
    for (const c of changed) assert.ok(allowed.has(c), `unexpected changed file ${c}`);
    if (o !== CHOSEN) assert.ok(changed.length >= 4, `swapping to ${o} changed only ${changed.length} files: ${changed.join(', ')}`);
    // The two logo SVGs keep their fixed brand colours, and the proof says so.
    assert.match(r.out, /^OK logo SVGs keep their fixed brand colours/m);
    assert.deepEqual(leftovers(r.prefix), { tmp: [], worktrees: [] }, 'the proof left a worktree or a temp directory behind');
  });
}

test('a raw colour planted in a hand-written CSS file makes the proof exit 1 and name the file', { skip }, () => {
  const r = runProof(fixturePath('a'), { LN_SWAP_PLANT: OVERRIDES_REL });
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, new RegExp(`^LEAK ${OVERRIDES_REL.replace(/\./g, '\\.')}$`, 'm'), r.out);
  assert.match(r.out, /RESULT: FAIL/);
  assert.deepEqual(leftovers(r.prefix), { tmp: [], worktrees: [] }, 'a failing run must still clean up');
});

test('the no-raw-colours step scans the swapped worktree on its own: a colour planted there fails it with the leak check off (WR-01)', { skip }, () => {
  const r = runProof(fixturePath('a'), { LN_SWAP_PLANT: OVERRIDES_REL, LN_SWAP_NO_LEAK_CHECK: '1' });
  assert.equal(r.code, 1, r.out);
  assert.doesNotMatch(r.out, /^LEAK /m, 'the leak check is off in this test');
  assert.match(r.out, /^FAIL no raw colours in custom modules/m, r.out);
  assert.ok(r.out.includes(`RAW ${OVERRIDES_REL}:`), `the step names the planted file:\n${r.out}`);
});

test('a changed logo SVG fails the logo step, also when it is not the last SVG (WR-04)', { skip }, () => {
  // localnodes-mark-reversed.svg sorts before localnodes-mark.svg: only the LAST svg's grep decided the step's status.
  const r = runProof(fixturePath('a'), { LN_SWAP_PLANT: `${IMAGES_REL}/localnodes-mark-reversed.svg`, LN_SWAP_NO_LEAK_CHECK: '1' });
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^FAIL logo SVGs keep their fixed brand colours/m, r.out);
  assert.doesNotMatch(r.out, /^OK logo SVGs/m);
});

test('a raw colour planted in a file no other check reads is caught by the leak check alone', { skip }, () => {
  const r = runProof(fixturePath('a'), { LN_SWAP_PLANT: 'CLAUDE.md' });
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^LEAK CLAUDE\.md$/m, r.out);
});

test('a raw colour planted in the Nuxt copy is caught and named', { skip }, () => {
  const r = runProof(fixturePath('a'), { LN_SWAP_PLANT: 'nuxt:app/app.vue' });
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^LEAK nuxt:app\/app\.vue$/m, r.out);
});

test('a raw colour planted in a generated file after generation fails the generated-files check', { skip }, () => {
  const r = runProof(fixturePath('a'), { LN_SWAP_PLANT: GENERATED_CSS_REL });
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /^FAIL generated files match the token source/m, r.out);
  assert.match(r.out, /RESULT: FAIL/);
});

// --- the proof's work directory is named by the caller (WINDOWS 51) --------------------------------------

test('the proof names its work directory with LN_SWAP_TMP_PREFIX, prints it and removes it', { skip }, () => {
  const prefix = freshPrefix();
  const r = runProof(fixturePath(CHOSEN), { LN_SWAP_TMP_PREFIX: prefix });
  assert.equal(r.code, 0, r.out);
  const workdir = r.out.match(/^WORKDIR (\S+)$/m)?.[1];
  assert.ok(workdir, `the proof prints a WORKDIR line:\n${r.out}`);
  assert.ok(workdir.split('/').pop().startsWith(prefix), `${workdir} does not start with ${prefix}`);
  assert.equal(existsSync(workdir), false, 'the work directory is gone after the run');
  assert.deepEqual(leftovers(prefix), { tmp: [], worktrees: [] });
});

test('an LN_SWAP_TMP_PREFIX that does not start with ln-swap- is a usage error (the rm -rf guard depends on it)', () => {
  const r = runProof(fixturePath(CHOSEN), { LN_SWAP_TMP_PREFIX: 'precious-' });
  assert.equal(r.code, 2, r.out);
  assert.match(r.out, /LN_SWAP_TMP_PREFIX/);
});

test('a bad fixture path is a usage error (exit 2), not a pass', () => {
  const r = runProof(join(fixtureDir, 'palette-none.json'));
  assert.equal(r.code, 2, r.out);
  assert.match(r.out, /usage|not found/i);
});
