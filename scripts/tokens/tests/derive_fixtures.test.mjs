/**
 * WR-02: `derive_socialblue_pairings.mjs --check` runs in CI (22.1 review disposition).
 *
 * The derivation reads socialblue's stock stylesheets, which are Open Social contrib files and are not installed
 * in CI. The orchestrator ruled: commit the three stock stylesheets and color.inc (Open Social 13.1.0, GPL-2.0-or-later
 * like the rest of the repo) under scripts/tokens/fixtures/socialblue-<version>/, with a SOURCES.md. --check then
 *   - falls back to the fixtures when the theme is absent (the CI shape), instead of printing SKIP;
 *   - when both exist, requires the installed copy to equal the fixtures and says "refresh the fixtures" if not.
 *
 * Every test works in a temp copy or a temp dir; none writes inside the repo.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copyRepo } from './helpers/repo_copy.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
const FIXTURES_ROOT = resolve(repoRoot, 'scripts/tokens/fixtures');
const DERIVER_REL = 'scripts/tokens/derive_socialblue_pairings.mjs';
const sandbox = () => mkdtempSync(join(tmpdir(), 'ln-derive-fixtures-'));

const run = (cwdRepo, args) => {
  const r = spawnSync(process.execPath, [join(cwdRepo, DERIVER_REL), ...args], { cwd: tmpdir(), encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
};

/** The committed fixtures directory (socialblue-<version>), or null. */
const fixturesDir = (root = FIXTURES_ROOT) => {
  if (!existsSync(root)) return null;
  const hits = readdirSync(root).filter((n) => n.startsWith('socialblue-'));
  return hits.length === 1 ? join(root, hits[0]) : null;
};

const mustFixtures = () => {
  const dir = fixturesDir();
  assert.ok(dir, 'precondition: exactly one scripts/tokens/fixtures/socialblue-<version>/ directory exists');
  return dir;
};

/** An "installed" Open Social: <root>/social/{social.info.yml,themes/socialblue} copied from a fixtures directory. */
function installedFrom(fixtures, version = '13.1.0') {
  const root = sandbox();
  const theme = join(root, 'social/themes/socialblue');
  mkdirSync(dirname(theme), { recursive: true });
  cpSync(join(fixtures, 'color'), join(theme, 'color'), { recursive: true });
  cpSync(join(fixtures, 'assets'), join(theme, 'assets'), { recursive: true });
  writeFileSync(join(root, 'social/social.info.yml'), `name: Open Social\nversion: '${version}'\n`);
  return theme;
}

test('the fixtures are committed: color.inc, the three stylesheets and a SOURCES.md naming version, paths and licence', () => {
  const dir = fixturesDir();
  assert.ok(dir, 'exactly one scripts/tokens/fixtures/socialblue-<version>/ directory exists');
  for (const f of ['color/color.inc', 'assets/css/brand.css', 'assets/css/brand--sky.css', 'assets/css/tokens.css', 'SOURCES.md']) {
    assert.ok(existsSync(join(dir, f)), `${f} is in the fixtures`);
  }
  const sources = readFileSync(join(dir, 'SOURCES.md'), 'utf8');
  assert.match(sources, /Open Social 13\.1\.0/);
  assert.match(sources, /html\/profiles\/contrib\/social\/themes\/socialblue\/assets\/css\/brand\.css/);
  assert.match(sources, /html\/profiles\/contrib\/social\/themes\/socialblue\/color\/color\.inc/);
  assert.match(sources, /GPL-2\.0-or-later/);
  assert.match(sources, /refresh/i);
});

test('CI shape (no theme installed): --check runs on the fixtures, passes, and does not SKIP', () => {
  const { repo } = copyRepo('ln-derive-ci-');
  assert.ok(!existsSync(join(repo, 'html/profiles')), 'the copy has no Open Social');
  const r = run(repo, ['--check']);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^OK .*fixtures/m);
  assert.doesNotMatch(r.out, /SKIP/);
});

test('CI shape: a hand edit that deletes a pair from socialblue-pairings.json fails --check', () => {
  const { repo } = copyRepo('ln-derive-ci-');
  const json = join(repo, 'scripts/tokens/socialblue-pairings.json');
  const doc = JSON.parse(readFileSync(json, 'utf8'));
  assert.ok(doc.pairings.length > 10, 'precondition: the committed file has pairings to delete');
  doc.pairings.splice(3, 1);
  writeFileSync(json, JSON.stringify(doc, null, 2) + '\n');
  const r = run(repo, ['--check']);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /DIFF /);
});

test('CI shape: with the fixtures missing too, --check fails loudly rather than passing on nothing', () => {
  const { repo } = copyRepo('ln-derive-ci-');
  const dir = fixturesDir(join(repo, 'scripts/tokens/fixtures'));
  assert.ok(dir, 'precondition: the copy has fixtures');
  rmSync(dir, { recursive: true, force: true });
  const r = run(repo, ['--check']);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /no socialblue sources/i);
});

test('both present and equal: --check passes (installed copy equals the fixtures)', () => {
  const dir = mustFixtures();
  const theme = installedFrom(dir);
  const r = run(repoRoot, ['--check', '--socialblue', theme]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^OK /m);
});

test('a fixture that differs from the installed theme fails --check with the refresh message', () => {
  const dir = mustFixtures();
  const theme = installedFrom(dir);
  // Open Social "moved": the installed brand.css gains a rule the fixture does not have.
  writeFileSync(join(theme, 'assets/css/brand.css'), readFileSync(join(dir, 'assets/css/brand.css'), 'utf8') + '\n/* moved */\n');
  const r = run(repoRoot, ['--check', '--socialblue', theme]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /refresh the fixtures/i);
  assert.match(r.out, /brand\.css/);
});

test('a fixtures directory that differs by one byte in color.inc fails the same way', () => {
  const root = sandbox();
  const copy = join(root, 'socialblue-13.1.0');
  cpSync(mustFixtures(), copy, { recursive: true });
  const theme = installedFrom(copy);
  writeFileSync(join(copy, 'color/color.inc'), readFileSync(join(copy, 'color/color.inc'), 'utf8') + ' ');
  const r = run(repoRoot, ['--check', '--socialblue', theme, '--fixtures-root', root]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /refresh the fixtures/i);
  assert.match(r.out, /color\.inc/);
});

test('--refresh-fixtures writes socialblue-<version>/ with SOURCES.md, drops an older fixtures directory, and the result checks OK', () => {
  const theme = installedFrom(mustFixtures(), '99.0.0');
  const root = sandbox();
  mkdirSync(join(root, 'socialblue-13.1.0'));
  writeFileSync(join(root, 'socialblue-13.1.0/stale.txt'), 'old');
  const json = join(sandbox(), 'pairs.json');
  const w = run(repoRoot, ['--socialblue', theme, '--json', json, '--refresh-fixtures', '--fixtures-root', root]);
  assert.equal(w.code, 0, w.out);
  assert.deepEqual(readdirSync(root), ['socialblue-99.0.0'], 'the old directory is gone');
  const sources = readFileSync(join(root, 'socialblue-99.0.0/SOURCES.md'), 'utf8');
  assert.match(sources, /Open Social 99\.0\.0/);
  for (const f of ['color/color.inc', 'assets/css/brand.css', 'assets/css/brand--sky.css', 'assets/css/tokens.css']) {
    assert.equal(readFileSync(join(root, 'socialblue-99.0.0', f), 'utf8'), readFileSync(join(theme, f), 'utf8'), `${f} is a byte copy`);
  }
  // Theme gone (CI shape): the fresh fixtures stand in for it.
  const c = run(repoRoot, ['--check', '--socialblue', join(sandbox(), 'absent'), '--json', json, '--fixtures-root', root]);
  assert.equal(c.code, 0, c.out);
  assert.match(c.out, /^OK .*fixtures/m);
});

test('the raw-colour guard does not reach the fixtures: its default scan is html/modules/custom, and passes', () => {
  const r = spawnSync(process.execPath, [join(repoRoot, 'scripts/tokens/check_raw_colors.mjs')], { cwd: tmpdir(), encoding: 'utf8' });
  const out = `${r.stdout}${r.stderr}`;
  assert.equal(r.status, 0, out);
  assert.match(out, /RESULT: PASS/);
  assert.doesNotMatch(out, /fixtures\/socialblue-/);
});
