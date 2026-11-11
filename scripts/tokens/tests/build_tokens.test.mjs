/**
 * Guard tests for the token generator (22.1-03, DES-01, D-08).
 *
 * Every guard in scripts/tokens/build_tokens.mjs has a test here that shows it
 * firing: the test builds its bad input in a temp dir, runs the script as a
 * child process and asserts the exit code and the message. No test writes
 * inside the repo.
 *
 * Run: node --test scripts/tokens/tests/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadTokens, resolve as resolveSource } from '../lib/tokens.mjs';
import { testTmpDir } from './helpers/tmp_dir.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
const build = resolve(repoRoot, 'scripts/tokens/build_tokens.mjs');
const parity = resolve(repoRoot, 'scripts/check_token_parity.mjs');
const stockFixture = resolve(repoRoot, 'scripts/tokens/fixtures/stock-socialblue.json');

const sandbox = () => testTmpDir('tokens');

// Colours these tests expect come from the token source, not from quotes of today's palette, so a palette swap
// (22.1-09, scripts/tokens/prove_token_swap.sh) keeps them true.
const SOURCE = resolveSource(loadTokens(null));
const PRIMARY = SOURCE.semantic.primary;
/** PRIMARY with its last hex digit changed: one step of drift. */
const DRIFTED_PRIMARY = PRIMARY.slice(0, -1) + (PRIMARY.endsWith('0') ? '1' : '0');

function run(script, args, cwd = tmpdir()) {
  const r = spawnSync(process.execPath, [script, ...args], { cwd, encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}

function overlay(dir, name, body) {
  const file = join(dir, name);
  writeFileSync(file, JSON.stringify(body));
  return file;
}

/** All files under dir, as absolute paths, sorted. */
function walk(dir) {
  const out = [];
  for (const name of readdirSync(dir).sort()) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...walk(p));
    else out.push(p);
  }
  return out;
}

test('--check passes on the repo and lists targets in sorted order', () => {
  const r = run(build, ['--check']);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /RESULT: PASS \(\d+ OK\)/);
  const labels = [...r.out.matchAll(/^OK (.+)$/gm)].map((m) => m[1]);
  assert.ok(labels.length >= 1, 'at least one target reported');
});

test('two runs produce byte-identical files', () => {
  const a = sandbox();
  const b = sandbox();
  assert.equal(run(build, ['--out-dir', a]).code, 0);
  assert.equal(run(build, ['--out-dir', b]).code, 0);
  const fa = walk(a);
  const fb = walk(b);
  assert.deepEqual(fa.map((p) => p.slice(a.length)), fb.map((p) => p.slice(b.length)));
  assert.ok(fa.length >= 1);
  fa.forEach((p, i) => assert.equal(readFileSync(p, 'utf8'), readFileSync(fb[i], 'utf8'), p));
});

test('every generated file starts with a GENERATED header naming the source', () => {
  const dir = sandbox();
  assert.equal(run(build, ['--out-dir', dir]).code, 0);
  for (const p of walk(dir)) {
    const head = readFileSync(p, 'utf8').split('\n').slice(0, 6).join('\n');
    assert.match(head, /GENERATED/, p);
    assert.match(head, /localnodes\.tokens\.json/, p);
  }
});

test('--check exits 1 and names the file when one hex in a generated file changes', () => {
  const dir = sandbox();
  assert.equal(run(build, ['--out-dir', dir]).code, 0);
  const files = walk(dir);
  assert.ok(files.length >= 1);
  for (const p of files) {
    const original = readFileSync(p, 'utf8');
    const tampered = original.replace(/#[0-9a-f]{6}/, '#000001');
    assert.notEqual(tampered, original, `${p} holds a hex to tamper with`);
    writeFileSync(p, tampered);
    const r = run(build, ['--check', '--out-dir', dir]);
    assert.equal(r.code, 1, `${p}\n${r.out}`);
    assert.ok(r.out.includes(p), `output names ${p}\n${r.out}`);
    assert.match(r.out, /RESULT: FAIL/);
    writeFileSync(p, original);
  }
  assert.equal(run(build, ['--check', '--out-dir', dir]).code, 0, 'restored files pass again');
});

test('--check reports a missing generated file', () => {
  const dir = sandbox();
  const r = run(build, ['--check', '--out-dir', dir]);
  assert.equal(r.code, 1);
  assert.match(r.out, /MISSING/);
});

test('two socialblue fields with the same hex exit 1 naming both fields', () => {
  const dir = sandbox();
  const palette = overlay(dir, 'dupe.json', { targets: { socialblue: { 'navbar-bg': PRIMARY } } });
  const r = run(build, ['--palette', palette, '--out-dir', join(dir, 'out')]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /brand-primary/);
  assert.match(r.out, /navbar-bg/);
  assert.deepEqual(walk(dir).filter((p) => p.includes('/out/')), [], 'nothing written on a failed run');
});

test('the stock socialblue palette exits 1 and names the scheme deletion', () => {
  const dir = sandbox();
  const r = run(build, ['--palette', stockFixture, '--out-dir', join(dir, 'out')]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /drupal\/color/);
  assert.match(r.out, /delete/);
});

test('fewer than 15 socialblue fields exits 1', () => {
  const dir = sandbox();
  const palette = overlay(dir, 'short.json', { targets: { socialblue: { 'navbar-sec-text': null } } });
  const r = run(build, ['--palette', palette, '--out-dir', join(dir, 'out')]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /14 of 15/);
  assert.match(r.out, /navbar-sec-text/);
});

test('a reference to a missing token exits 1 naming the token', () => {
  const dir = sandbox();
  const palette = overlay(dir, 'dangling.json', { semantic: { primary: { $value: '{primitive.color.brand.nope}' } } });
  const r = run(build, ['--palette', palette, '--out-dir', join(dir, 'out')]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /primitive\.color\.brand\.nope/);
});

test('a reference loop exits 1 naming the loop', () => {
  const dir = sandbox();
  const palette = overlay(dir, 'loop.json', {
    semantic: {
      primary: { $value: '{semantic.link}' },
      link: { $value: '{semantic.primary}' },
    },
  });
  const r = run(build, ['--palette', palette, '--out-dir', join(dir, 'out')]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /loop/);
  assert.match(r.out, /semantic\.primary/);
});

test('4- and 8-digit alpha hex is rejected with exit 1', () => {
  for (const bad of ['#176c93ff', '#abcd']) {
    const dir = sandbox();
    const palette = overlay(dir, 'alpha.json', { primitive: { color: { brand: { 700: { $value: bad } } } } });
    const r = run(build, ['--palette', palette, '--out-dir', join(dir, 'out')]);
    assert.equal(r.code, 1, `${bad}\n${r.out}`);
    assert.match(r.out, /alpha/);
    assert.match(r.out, /primitive\.color\.brand\.700/);
  }
});

test('an upper-case 3-digit value comes out as lowercase 6-digit in every target', () => {
  const dir = sandbox();
  const palette = overlay(dir, 'short-hex.json', { primitive: { color: { brand: { 700: { $value: '#ABC' } } } } });
  const out = join(dir, 'out');
  const r = run(build, ['--palette', palette, '--out-dir', out]);
  assert.equal(r.code, 0, r.out);
  const files = walk(out);
  assert.ok(files.length >= 1);
  for (const p of files) {
    const text = readFileSync(p, 'utf8');
    assert.ok(text.includes('#aabbcc'), `${p} holds #aabbcc`);
    assert.ok(!/#ABC\b/i.test(text.replace(/#aabbcc/g, '')), `${p} holds no 3-digit form`);
  }
});

test('the source itself holds no hue-named keys', () => {
  const src = readFileSync(resolve(repoRoot, 'html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json'), 'utf8');
  assert.equal((src.match(/"(teal|amber|zinc|sky|blue|cyan)"/gi) || []).length, 0);
});

test('an unknown flag exits 2', () => {
  const r = run(build, ['--bogus']);
  assert.equal(r.code, 2);
  assert.match(r.out, /usage/);
});

test('an unreadable --palette file exits 2', () => {
  const r = run(build, ['--palette', join(sandbox(), 'nope.json')]);
  assert.equal(r.code, 2);
});

// ---- drupal-css target and the overrides split (22.1-03 task 2) ----

const moduleDir = resolve(repoRoot, 'html/modules/custom/localnodes_platform');

// Every --ln-* custom property the Phase 22 CSS reads (names must not change).
const PHASE22_NAMES = [
  '--ln-chat-clearance', '--ln-color-border-control', '--ln-color-canvas', '--ln-color-danger',
  '--ln-color-danger-bg', '--ln-color-divider', '--ln-color-focus', '--ln-color-focus-on-dark',
  '--ln-color-highlight', '--ln-color-link', '--ln-color-on-highlight', '--ln-color-primary',
  '--ln-color-primary-strong', '--ln-color-success', '--ln-color-success-bg', '--ln-color-surface',
  '--ln-color-text', '--ln-color-text-muted', '--ln-color-tint', '--ln-color-warning-bg',
  '--ln-color-warning-text', '--ln-radius-card', '--ln-radius-control', '--ln-radius-pill',
  '--ln-space-2xl', '--ln-space-3xl', '--ln-space-lg', '--ln-space-md', '--ln-space-sm',
  '--ln-space-xl', '--ln-space-xs',
];
const NEW_NAMES = ['--ln-color-on-primary', '--ln-color-on-danger', '--ln-color-danger-strong'];

const stripComments = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '');

test('ln-tokens.css holds one :root block, every Phase 22 name and the three new ones', () => {
  const css = readFileSync(resolve(moduleDir, 'css/ln-tokens.css'), 'utf8');
  assert.match(css.split('\n').slice(0, 6).join('\n'), /GENERATED/);
  const code = stripComments(css);
  const selectors = [...code.matchAll(/([^{}]+)\{/g)].map((m) => m[1].trim());
  assert.deepEqual(selectors, [':root'], 'the only rule is :root');
  const defined = new Set([...code.matchAll(/(--ln-[a-z0-9-]+)\s*:/g)].map((m) => m[1]));
  for (const name of [...PHASE22_NAMES, ...NEW_NAMES]) assert.ok(defined.has(name), `${name} is defined`);
  assert.match(code, new RegExp(`--ln-color-primary:\\s*${PRIMARY};`));
  assert.match(code, /--ln-color-danger-strong:\s*#991e14;/);
});

test('ln-overrides.css has no colour literal and reaches colours only through defined var(--ln-*)', () => {
  const css = readFileSync(resolve(moduleDir, 'css/ln-overrides.css'), 'utf8');
  assert.doesNotMatch(css, /#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(|color-mix\(/, 'no colour literal, comments included');
  const tokens = stripComments(readFileSync(resolve(moduleDir, 'css/ln-tokens.css'), 'utf8'));
  const defined = new Set([...tokens.matchAll(/(--ln-[a-z0-9-]+)\s*:/g)].map((m) => m[1]));
  const used = [...stripComments(css).matchAll(/var\(\s*(--[a-z0-9-]+)/g)].map((m) => m[1]);
  assert.ok(used.length > 20);
  for (const name of new Set(used)) assert.ok(defined.has(name), `${name} is defined in ln-tokens.css`);
  for (const sel of ['.btn-danger', '.alert-danger', '.badge-warning', ':focus-visible', '.ln-tabs-more', '.hidden-list']) {
    assert.ok(css.includes(sel), `${sel} rule moved into ln-overrides.css`);
  }
});

test('a palette change reaches the generated ln-tokens.css', () => {
  const dir = sandbox();
  const palette = overlay(dir, 'swap.json', { primitive: { color: { brand: { 700: { $value: '#123456' } } } } });
  const out = join(dir, 'out');
  assert.equal(run(build, ['--palette', palette, '--out-dir', out]).code, 0);
  const css = readFileSync(join(out, 'html/modules/custom/localnodes_platform/css/ln-tokens.css'), 'utf8');
  assert.match(css, /--ln-color-primary:\s*#123456;/);
  assert.match(css, /--ln-color-focus:\s*#123456;/);
});

// ---- flat file, radius from tokens, the parity wrapper (22.1-03 task 3) ----

const flatPath = resolve(moduleDir, 'tokens/ln-tokens.flat.json');

test('the flat file has a $generated header, sorted keys and the radius PHP reads', () => {
  const flat = JSON.parse(readFileSync(flatPath, 'utf8'));
  assert.match(flat.$generated, /GENERATED/);
  assert.match(flat.$generated, /localnodes\.tokens\.json/);
  assert.deepEqual(Object.keys(flat), ['$generated', 'color', 'deepchat', 'email', 'font', 'radius', 'space']);
  assert.deepEqual(flat.radius, { card: '8px', control: '6px', pill: '999px' });
  assert.equal(flat.color.primary, PRIMARY);
  assert.equal(flat.email.link, SOURCE.targets.email.link);
  assert.equal(flat.deepchat.submit, SOURCE.targets.deepchat.submit);
  for (const [group, map] of Object.entries(flat)) {
    if (group === '$generated') continue;
    assert.deepEqual(Object.keys(map), [...Object.keys(map)].sort(), `${group} keys are sorted`);
  }
});

test('a palette swap reaches the flat file', () => {
  const dir = sandbox();
  const palette = overlay(dir, 'swap.json', { primitive: { radius: { control: { $value: '10px' } } } });
  const out = join(dir, 'out');
  assert.equal(run(build, ['--palette', palette, '--out-dir', out]).code, 0);
  const flat = JSON.parse(readFileSync(join(out, 'html/modules/custom/localnodes_platform/tokens/ln-tokens.flat.json'), 'utf8'));
  assert.equal(flat.radius.control, '10px');
});

test('the install hook reads the radius from the flat file, not from literals', () => {
  const php = readFileSync(resolve(moduleDir, 'localnodes_platform.install'), 'utf8');
  assert.match(php, /ln-tokens\.flat\.json/);
  assert.doesNotMatch(php, /'button_radius' => '[0-9]+'/);
  assert.doesNotMatch(php, /'card_radius' => '[0-9]+'/);
  assert.doesNotMatch(php, /hook_update_\d|function localnodes_platform_update_\d/);
});

test('check_token_parity.mjs keeps --yml, --drupal-css and --nuxt and passes on the repo', () => {
  // The Nuxt checkout may be absent (CI): point --sibling at a missing directory, so the
  // run is the same either way. The Nuxt check itself is covered in nuxt_target.test.mjs.
  const dir = sandbox();
  const absent = join(dir, 'no-such-sibling');
  const r = run(parity, [
    '--yml', resolve(moduleDir, 'config/optional/color.theme.socialblue.yml'),
    '--drupal-css', resolve(moduleDir, 'css/ln-tokens.css'),
    '--nuxt', join(absent, 'app/assets/css/main.css'),
    '--sibling', absent,
  ]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /RESULT: PASS \(\d+ OK\)/);
  assert.match(r.out, /SKIP nuxt \(sibling not present\)/);
});

test('check_token_parity.mjs defaults still run from any cwd', () => {
  const r = run(parity, []);
  assert.equal(r.code, 0, r.out);
});

test('check_token_parity.mjs exits 1 on a drifted hex and names the file', () => {
  const dir = sandbox();
  const yml = join(dir, 'scheme.yml');
  writeFileSync(yml, readFileSync(resolve(moduleDir, 'config/optional/color.theme.socialblue.yml'), 'utf8').replace(`'${PRIMARY}'`, `'${DRIFTED_PRIMARY}'`));
  const r = run(parity, ['--yml', yml]);
  assert.equal(r.code, 1, r.out);
  assert.ok(r.out.includes(yml), r.out);
  assert.match(r.out, /RESULT: FAIL/);
});

test('check_token_parity.mjs exits 1 on a missing file and 2 on an unknown flag', () => {
  const missing = run(parity, ['--drupal-css', join(sandbox(), 'nope.css')]);
  assert.equal(missing.code, 1, missing.out);
  assert.match(missing.out, /MISSING/);
  const bogus = run(parity, ['--bogus', 'x']);
  assert.equal(bogus.code, 2);
  assert.match(bogus.out, /usage/);
  assert.equal(run(parity, ['--nuxt']).code, 2, 'a flag without a value is a usage error');
});
