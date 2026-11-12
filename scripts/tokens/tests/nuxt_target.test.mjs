/**
 * Guard tests for the Nuxt token target and the sibling handling (22.1-08, DES-01, D-03, D-08).
 *
 * The Nuxt target writes one file into a sibling checkout
 * (../localnodes-onboarding/app/assets/css/ln-tokens.css). These tests build a
 * fake sibling in a temp dir and hand it to the scripts through --sibling, so
 * no test ever touches the real Nuxt checkout. Each guard is shown firing.
 *
 * Run: node --test scripts/tokens/tests/*.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copyRepo } from './helpers/repo_copy.mjs';
import { testTmpDir } from './helpers/tmp_dir.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
// The generator runs in write mode here: run it from a private copy of the repo so a test never rewrites this repo's
// generated files (WINDOWS 51). Reads of the repo's own files (flat.json, the token source) stay on the real one.
const build = join(copyRepo().repo, 'scripts/tokens/build_tokens.mjs');
const parity = resolve(repoRoot, 'scripts/check_token_parity.mjs');
const flat = JSON.parse(readFileSync(resolve(repoRoot, 'html/modules/custom/localnodes_platform/tokens/ln-tokens.flat.json'), 'utf8'));
const source = JSON.parse(readFileSync(resolve(repoRoot, 'html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json'), 'utf8'));

const GENERATED_REL = 'app/assets/css/ln-tokens.css';

function run(script, args, cwd = tmpdir()) {
  const r = spawnSync(process.execPath, [script, ...args], { cwd, encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}

/** A directory that looks like a Nuxt checkout (it has nuxt.config.ts). */
function fakeSibling() {
  const dir = testTmpDir('nuxt-sibling');
  writeFileSync(join(dir, 'nuxt.config.ts'), 'export default defineNuxtConfig({})\n');
  return dir;
}

/** A sibling that already holds a freshly generated Nuxt token file. */
function generatedSibling() {
  const dir = fakeSibling();
  const r = run(build, ['--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  return dir;
}

const read = (dir) => readFileSync(join(dir, GENERATED_REL), 'utf8');

/** The text between `selector {` and its closing brace. */
function block(css, selector) {
  const start = css.indexOf(`${selector} {`);
  assert.ok(start >= 0, `${selector} block exists`);
  return css.slice(start, css.indexOf('\n}', start));
}

test('the generator writes the Nuxt token file into the sibling, with a GENERATED header', () => {
  const dir = generatedSibling();
  const css = read(dir);
  assert.match(css.split('\n').slice(0, 3).join('\n'), /GENERATED/);
  assert.match(css, /localnodes\.tokens\.json/);
  assert.match(css, /scripts\/tokens\/build_tokens\.mjs/);
});

test('it holds role-named palettes ln-brand, ln-accent and ln-neutral and no hue names', () => {
  const css = read(generatedSibling());
  const theme = block(css, '@theme static');
  assert.match(theme, /--font-sans: 'Montserrat', system-ui, sans-serif;/);
  for (const palette of ['brand', 'accent', 'neutral']) {
    for (const shade of [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]) {
      assert.match(theme, new RegExp(`--color-ln-${palette}-${shade}: #[0-9a-f]{6};`), `${palette}-${shade}`);
    }
  }
  assert.doesNotMatch(css, /ln-(teal|amber)/);
});

test('the palette values are the token source values', () => {
  const css = read(generatedSibling());
  const theme = block(css, '@theme static');
  const brand = source.primitive.color.brand;
  const accent = source.primitive.color.accent;
  for (const [shade, leaf] of Object.entries(brand)) {
    if (shade.startsWith('$')) continue;
    assert.ok(theme.includes(`--color-ln-brand-${shade}: ${leaf.$value.toLowerCase()};`), `brand ${shade}`);
  }
  for (const [shade, leaf] of Object.entries(accent)) {
    if (shade.startsWith('$')) continue;
    assert.ok(theme.includes(`--color-ln-accent-${shade}: ${leaf.$value.toLowerCase()};`), `accent ${shade}`);
  }
});

test(':root holds every light --ui-* role and .dark every dark one, from targets.nuxt', () => {
  const css = read(generatedSibling());
  const light = block(css, ':root');
  const dark = block(css, '.dark');
  const roles = (group) => Object.keys(source.targets.nuxt[group]);
  assert.ok(roles('light').length >= 17);
  for (const role of roles('light')) assert.match(light, new RegExp(`${role}: [^;]+;`), `light ${role}`);
  for (const role of roles('dark')) assert.match(dark, new RegExp(`${role}: [^;]+;`), `dark ${role}`);
  assert.ok(light.includes(`--ui-primary: ${flat.color.primary};`), 'light primary is the brand primary');
  assert.match(light, /--ui-radius: 6px;/, 'radius comes from the source');
  assert.match(dark, /--ui-primary: #[0-9a-f]{6};/);
});

test('the .dark roles are the landing page\'s dark band: near-black surfaces, mint primary, amber focus (BRAND.md section 5, 2026-11-12)', () => {
  const dark = block(read(generatedSibling()), '.dark');
  const d = (name) => source.primitive.color.dark[name].$value.toLowerCase();
  const hex = (shade) => source.primitive.color.brand[shade].$value.toLowerCase();
  // semantic.band.* names the dark group's background, text and muted text, and brand 950 for the button text.
  assert.equal(source.semantic.band.bg.$value, '{primitive.color.dark.bg}', 'the band the .dark roles must follow');
  assert.match(dark, new RegExp(`--ui-bg: ${d('bg')};`), 'the band background is the landing near-black');
  assert.match(dark, new RegExp(`--ui-text: ${d('text')};`), 'the band text');
  assert.match(dark, new RegExp(`--ui-text-muted: ${d('muted')};`), 'the band muted text');
  assert.match(dark, new RegExp(`--ui-text-inverted: ${hex('950')};`), 'the band button text is brand 950');
  assert.match(dark, new RegExp(`--ui-secondary: ${d('highlight')};`), 'the amber highlight');
  assert.doesNotMatch(dark, /--ui-bg: #082f49;/, 'the palette B band blue is gone');
});

test('--check passes on a fresh file and prints OK for the Nuxt target', () => {
  const dir = generatedSibling();
  const r = run(build, ['--check', '--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^OK .*nuxt/im);
  assert.doesNotMatch(r.out, /^SKIP nuxt /m, "the Nuxt stylesheet target is not skipped");
});

test('--check exits 1 and names the file when one value in the Nuxt file changes', () => {
  const dir = generatedSibling();
  const file = join(dir, GENERATED_REL);
  const original = readFileSync(file, 'utf8');
  const tampered = original.replace(/#[0-9a-f]{6}/, '#000001');
  assert.notEqual(tampered, original);
  writeFileSync(file, tampered);
  const r = run(build, ['--check', '--sibling', dir]);
  assert.equal(r.code, 1, r.out);
  assert.ok(r.out.includes(file), r.out);
  assert.match(r.out, /DIFF/);
  assert.match(r.out, /RESULT: FAIL/);
});

test('--check reports a missing Nuxt file when the sibling is present', () => {
  const r = run(build, ['--check', '--sibling', fakeSibling()]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /MISSING .*nuxt/i);
});

test('a plain run rewrites a hand-edited Nuxt file back to the source values', () => {
  const dir = generatedSibling();
  const file = join(dir, GENERATED_REL);
  const original = readFileSync(file, 'utf8');
  writeFileSync(file, original.replace(/#[0-9a-f]{6}/, '#000001'));
  assert.equal(run(build, ['--sibling', dir]).code, 0);
  assert.equal(readFileSync(file, 'utf8'), original);
});

test('a missing sibling prints SKIP nuxt, passes, and writes nothing', () => {
  const absent = join(testTmpDir('nuxt-absent'), 'no-such-dir');
  const check = run(build, ['--check', '--sibling', absent]);
  assert.equal(check.code, 0, check.out);
  assert.match(check.out, /SKIP nuxt \(sibling not present\)/);
  assert.match(check.out, /RESULT: PASS/);
  const write = run(build, ['--sibling', absent]);
  assert.equal(write.code, 0, write.out);
  assert.match(write.out, /SKIP nuxt \(sibling not present\)/);
  assert.equal(existsSync(absent), false, 'the generator does not create the sibling directory');
});

test('an existing directory that is not a Nuxt checkout counts as absent', () => {
  const empty = testTmpDir('nuxt-empty');
  const r = run(build, ['--check', '--sibling', empty]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /SKIP nuxt \(sibling not present\)/);
  assert.deepEqual(readdirSync(empty), [], 'nothing is written into it');
});

test('--sibling without a value is a usage error', () => {
  assert.equal(run(build, ['--sibling']).code, 2);
});

test('a palette swap reaches the Nuxt file: the primary and the radius follow the source', () => {
  const dir = fakeSibling();
  const palette = join(dir, 'swap.json');
  writeFileSync(palette, JSON.stringify({
    primitive: { color: { brand: { 700: { $value: '#123456' } } }, radius: { control: { $value: '10px' } } },
  }));
  const out = join(dir, 'out');
  const r = run(build, ['--palette', palette, '--out-dir', out, '--sibling', dir]);
  assert.equal(r.code, 0, r.out);
  const nuxtFile = join(out, '_external', 'localnodes-onboarding', GENERATED_REL);
  assert.ok(existsSync(nuxtFile), `${nuxtFile} exists`);
  const css = readFileSync(nuxtFile, 'utf8');
  assert.match(block(css, ':root'), /--ui-primary: #123456;/);
  assert.match(block(css, '@theme static'), /--color-ln-brand-700: #123456;/);
  assert.match(block(css, ':root'), /--ui-radius: 10px;/);
});

test('--out-dir mirrors the Nuxt file under _external/localnodes-onboarding and never touches the sibling', () => {
  const dir = fakeSibling();
  const out = testTmpDir('nuxt-out');
  assert.equal(run(build, ['--out-dir', out, '--sibling', dir]).code, 0);
  assert.ok(existsSync(join(out, '_external', 'localnodes-onboarding', GENERATED_REL)));
  assert.equal(existsSync(join(dir, 'app')), false, 'the sibling stays untouched');
});

/* ---- the parity wrapper (22.1-08 Task 2) ---- */

test('check_token_parity.mjs --nuxt checks the generated file, with no SKIP', () => {
  const dir = generatedSibling();
  const r = run(parity, ['--sibling', dir, '--nuxt', join(dir, GENERATED_REL)]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^OK .*nuxt/im);
  assert.doesNotMatch(r.out, /^SKIP nuxt /m, "the Nuxt stylesheet target is not skipped");
});

test('check_token_parity.mjs --nuxt follows main.css to the generated file it imports', () => {
  const dir = generatedSibling();
  const main = join(dir, 'app/assets/css/main.css');
  // The real main.css talks about the GENERATED file in its comment: that must not stop the follow.
  writeFileSync(main, '@import "tailwindcss";\n@import "@nuxt/ui";\n/* ln-tokens.css is GENERATED by fresh3 */\n@import "./ln-tokens.css";\n');
  const ok = run(parity, ['--sibling', dir, '--nuxt', main]);
  assert.equal(ok.code, 0, ok.out);
  assert.match(ok.out, /^OK .*nuxt/im);
  assert.doesNotMatch(ok.out, /^SKIP nuxt /m, "the Nuxt stylesheet target is not skipped");
  // The same command fails when the imported file drifts: it really read it.
  const gen = join(dir, GENERATED_REL);
  writeFileSync(gen, readFileSync(gen, 'utf8').replace(/#[0-9a-f]{6}/, '#000001'));
  const bad = run(parity, ['--sibling', dir, '--nuxt', main]);
  assert.equal(bad.code, 1, bad.out);
  assert.ok(bad.out.includes(gen), bad.out);
});

test('check_token_parity.mjs --nuxt exits 1 on a hand-written Nuxt stylesheet that imports nothing', () => {
  const dir = generatedSibling();
  const main = join(dir, 'app/assets/css/main.css');
  writeFileSync(main, '@theme static { --color-ln-teal-700: #0f766e; }\n');
  const r = run(parity, ['--sibling', dir, '--nuxt', main]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /DIFF/);
});

test('check_token_parity.mjs prints SKIP nuxt and passes when the sibling is absent', () => {
  const absent = join(testTmpDir('nuxt-absent'), 'no-such-dir');
  const r = run(parity, ['--sibling', absent, '--nuxt', join(absent, GENERATED_REL)]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /SKIP nuxt \(sibling not present\)/);
  assert.match(r.out, /RESULT: PASS/);
});

test('check_token_parity.mjs exits 1 on a missing Nuxt file when the sibling is present', () => {
  const dir = fakeSibling();
  const r = run(parity, ['--sibling', dir, '--nuxt', join(dir, GENERATED_REL)]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /MISSING/);
});

test('every file the generator mirrors under --out-dir still starts with GENERATED', () => {
  const out = testTmpDir('nuxt-head');
  assert.equal(run(build, ['--out-dir', out, '--sibling', fakeSibling()]).code, 0);
  const walk = (d) => readdirSync(d).flatMap((n) => (statSync(join(d, n)).isDirectory() ? walk(join(d, n)) : [join(d, n)]));
  for (const p of walk(out)) assert.match(readFileSync(p, 'utf8').split('\n').slice(0, 6).join('\n'), /GENERATED/, p);
});
