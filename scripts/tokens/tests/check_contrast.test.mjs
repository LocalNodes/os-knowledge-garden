/**
 * Guard tests for the contrast gate (22.1-05, DES-01, D-09, T-22.1-15/16).
 *
 * Each guard in scripts/tokens/check_contrast.mjs has a test that shows it
 * firing: the test builds its bad input as a --palette overlay in a temp dir,
 * runs the script as a child process and asserts the exit code and the line it
 * prints. No test writes inside the repo.
 *
 * Run: node --test scripts/tokens/tests/*.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { contrast, luminance, meets } from '../lib/contrast.mjs';
import { THRESHOLDS } from '../pairings.mjs';
import { loadTokens, resolve as resolveSource } from '../lib/tokens.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../..');
const checker = resolve(repoRoot, 'scripts/tokens/check_contrast.mjs');
const stockFixture = resolve(repoRoot, 'scripts/tokens/fixtures/stock-socialblue.json');

const sandbox = () => testTmpDir('contrast');

// The tests that run against the repo's own palette read their expected colours and ratios from the token
// source instead of quoting them, so swapping the palette (22.1-09, scripts/tokens/prove_token_swap.sh) keeps
// them true. A test that needs a fixed palette passes its own --palette overlay.
const SOURCE = resolveSource(loadTokens(null));
const HIGHLIGHT = SOURCE.semantic.highlight;
const ratio2 = (fg, bg) => contrast(fg, bg).toFixed(2);

// Hermetic (WINDOWS 51): the dark-mode tripwire reads a Nuxt checkout, and the default is the live ../localnodes-onboarding,
// which another lane edits. A run that does not pass its own --nuxt gets a directory with no Nuxt checkout in it, so the
// tripwire SKIPs. The tripwire's page scan has its own tests below, against fake checkouts.
const NO_NUXT = testTmpDir('contrast-no-nuxt');

function run(args) {
  const argv = args.includes('--nuxt') ? args : [...args, '--nuxt', NO_NUXT];
  const r = spawnSync(process.execPath, [checker, ...argv], { cwd: tmpdir(), encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}

function overlay(name, body) {
  const file = join(sandbox(), name);
  writeFileSync(file, JSON.stringify(body));
  return file;
}

const lines = (out, prefix) => out.split('\n').filter((l) => l.startsWith(prefix));

test('the formula reproduces the numbers UI-SPEC and CANVAS-BRIEF measured (to 2 dp)', () => {
  assert.equal(contrast('#ffffff', '#29abe2').toFixed(2), '2.62'); // white on the stock primary
  assert.equal(contrast('#0f766e', '#ffffff').toFixed(2), '5.47'); // the provisional primary on white
  assert.equal(contrast('#343434', '#ffffff').toFixed(2), '12.45'); // text on white
  assert.equal(contrast('#176c93', '#ffffff').toFixed(2), '5.83'); // palette B primary on white
  assert.equal(contrast('#ffffff', '#ffffff'), 1);
  assert.equal(contrast('#000000', '#ffffff'), 21);
});

test('order of the two colours does not matter, and luminance spans 0 to 1', () => {
  assert.equal(contrast('#176c93', '#f3f3f3'), contrast('#f3f3f3', '#176c93'));
  assert.equal(luminance('#000000'), 0);
  assert.equal(luminance('#ffffff'), 1);
  assert.throws(() => luminance('#fff'), /not a 6-digit hex colour/);
});

test('4.4999 fails 4.5: the comparison is on the unrounded ratio', () => {
  assert.equal(meets(4.4999, 4.5), false);
  assert.equal(meets(4.5, 4.5), true);
  assert.equal(meets(2.9999, 3), false);
  // A real pair: #737496 on white is 4.49917, which prints as "4.50" at 2 dp but is a fail.
  const ratio = contrast('#737496', '#ffffff');
  assert.ok(ratio < 4.5, `ratio ${ratio}`);
  assert.equal(ratio.toFixed(2), '4.50');
  assert.equal(meets(ratio, THRESHOLDS.text), false);
});

test('the thresholds are WCAG 2.2 AA: 4.5 for text, 3 for non-text and focus (T-22.1-16)', () => {
  assert.deepEqual({ ...THRESHOLDS }, { text: 4.5, 'non-text': 3, focus: 3 });
});

// Q2 (22.1-05, orchestrator answer 2026-11-08) closed the 7 findings the first run reported: Group A (brand-accent as
// text, white on the amber secondary) is re-coloured with the text role and proved through the override path; Group B
// (Nuxt dark --ui-bg-accented) is a reasoned EXCLUDED entry. The tests for both are at the end of this file.
test('the checker passes on the repo: no FAIL, no UNCOVERED, no STALE, exit 0, and the excluded pairs are not counted as OK', () => {
  const r = run([]);
  assert.ok(lines(r.out, 'OK ').length > 100, 'many pairings are checked');
  assert.equal(lines(r.out, 'FAIL ').length, 0, r.out);
  assert.equal(lines(r.out, 'UNCOVERED ').length, 0, r.out);
  assert.equal(lines(r.out, 'STALE ').length, 0, r.out);
  assert.equal(lines(r.out, 'TRIPWIRE ').length, 0, r.out);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /RESULT: PASS \(\d+ OK, 3 EXCLUDED\)/);
});

test('stock socialblue fails: exit 1, FAIL lines, RESULT: FAIL', () => {
  const r = run(['--palette', stockFixture]);
  assert.equal(r.code, 1, r.out);
  assert.ok(lines(r.out, 'FAIL ').length >= 1, r.out);
  assert.match(r.out, /RESULT: FAIL \(\d+ FAIL\)/);
});

test('a ratio that rounds up to the threshold but is below it prints as a FAIL', () => {
  const body = { semantic: { 'text-muted': { $value: '#737496' } } };
  const r = run(['--palette', overlay('muted.json', body)]);
  assert.equal(r.code, 1, r.out);
  const fail = lines(r.out, 'FAIL ').find((l) => l.includes('=#737496 on ') && l.includes('=#ffffff'));
  assert.ok(fail, `a FAIL for #737496 on #ffffff in:\n${r.out}`);
  assert.match(fail, /4\.50 < 4\.5/);
});

test('usage errors exit 2: an unknown flag and a missing --palette file', () => {
  assert.equal(run(['--nope']).code, 2);
  assert.equal(run(['--palette', join(sandbox(), 'missing.json')]).code, 2);
  assert.equal(run(['--palette']).code, 2);
});

// ---------------------------------------------------------------------------
// 22.1-05 task 2: socialblue pairings derived from the stock stylesheets, the
// override family (P4), controls (P6) and status (P7).
// ---------------------------------------------------------------------------

const deriver = resolve(repoRoot, 'scripts/tokens/derive_socialblue_pairings.mjs');
const committedJson = resolve(repoRoot, 'scripts/tokens/socialblue-pairings.json');

function derive(args) {
  const r = spawnSync(process.execPath, [deriver, ...args], { cwd: tmpdir(), encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
}

// WR-02: --check falls back to the committed stock stylesheets under scripts/tokens/fixtures when the theme is
// absent, and compares the two when both exist. The fake-theme tests below are about the theme they pass, so
// they point the fixtures at a directory that does not exist.
const NO_FIXTURES = ['--fixtures-root', join(tmpdir(), 'ln-no-such-fixtures-root')];

const STOCK_INC = `<?php
$info = ['schemes' => ['default' => ['title' => t('Social Blue (default)'), 'colors' => [
  'brand-primary' => '#29abe2', 'brand-secondary' => '#1f80aa', 'brand-accent' => '#ffc142',
  'brand-accent-text' => '#343434', 'brand-link' => '#33b5e5', 'navbar-bg' => '#333333',
  'navbar-text' => '#ffffff', 'navbar-active-bg' => '#1f1f1f', 'navbar-active-text' => '#f3f3f3',
  'navbar-sec-bg' => '#1f7ea7', 'navbar-sec-text' => '#f9f9f9', 'second-line-navbar-bg' => '#004494',
  'second-line-navbar-text' => '#fffff1', 'second-line-navbar-active-bg' => '#f2f5f9',
  'second-line-navbar-active-text' => '#002f67',
]]]];
`;

/** A tiny socialblue theme directory (in a social/ profile with a version) built from CSS strings. */
function fakeTheme(css = {}) {
  const root = sandbox();
  const theme = join(root, 'social/themes/socialblue');
  mkdirSync(join(theme, 'color'), { recursive: true });
  mkdirSync(join(theme, 'assets/css'), { recursive: true });
  writeFileSync(join(root, 'social/social.info.yml'), "name: Open Social\nversion: '13.1.0'\n");
  writeFileSync(join(theme, 'color/color.inc'), STOCK_INC);
  for (const f of ['brand.css', 'brand--sky.css', 'tokens.css']) writeFileSync(join(theme, 'assets/css', f), css[f] ?? '');
  return theme;
}

const deriveFrom = (css) => {
  const theme = fakeTheme(css);
  const json = join(sandbox(), 'out.json');
  const r = derive(['--socialblue', theme, '--json', json]);
  assert.equal(r.code, 0, r.out);
  return JSON.parse(readFileSync(json, 'utf8')).pairings;
};

test('the committed socialblue pairings hold the six P1 families RESEARCH found and record the Open Social version', () => {
  const doc = JSON.parse(readFileSync(committedJson, 'utf8'));
  assert.match(doc.$generated, /GENERATED by scripts\/tokens\/derive_socialblue_pairings\.mjs/);
  assert.match(doc.$generated, /brand\.css, assets\/css\/brand--sky\.css, assets\/css\/tokens\.css/);
  assert.match(doc.openSocialVersion, /^\d+\.\d+\.\d+/);
  const p1 = doc.pairings.filter((p) => p.family === 'P1');
  assert.ok(p1.length >= 6, `${p1.length} P1 entries`);
  const has = (fg, bg) => p1.some((p) => p.fg === `targets.socialblue.${fg}` && p.bg === `targets.socialblue.${bg}`);
  assert.ok(has('brand-accent-text', 'brand-accent'), 'accent text on accent');
  assert.ok(has('navbar-active-text', 'navbar-active-bg'), 'navbar active text on its bg');
  assert.ok(has('navbar-sec-text', 'navbar-sec-bg'), 'secondary navbar text on its bg');
  assert.ok(has('second-line-navbar-active-text', 'second-line-navbar-active-bg'), 'second-line active text on its bg');
  assert.ok(has('navbar-text', 'navbar-bg'), 'navbar text on navbar bg');
  assert.ok(has('second-line-navbar-text', 'second-line-navbar-bg'), 'second-line text on its bg');
  const ids = doc.pairings.map((p) => p.id);
  assert.deepEqual(ids, [...ids].sort(), 'entries are sorted by id');
  assert.equal(new Set(ids).size, ids.length, 'ids are unique');
});

test('derive: a rule with a field colour and a field background is a P1 pair; scope decides the surface of a lone colour', () => {
  const pairs = deriveFrom({
    'brand.css': `
.btn-accent { color: #343434; background-color: #ffc142; }
.navbar-default .navbar-nav > li > a { color: #ffffff; }
.navbar-default { background-color: #333333; color: #ffffff; }
.back-link { color: #1f80aa; }
.progress-fill::before { background: #29abe2; }
.btn-primary { background-color: #29abe2; }
`,
    'brand--sky.css': `.socialblue--sky .navbar-secondary .navbar-nav li a.is-active { color: #29abe2; }`,
  });
  const at = (id) => pairs.find((p) => p.id === id);
  assert.ok(at('P1.socialblue.brand-accent-text.on.socialblue.brand-accent'), 'P1 accent');
  assert.equal(at('P1.socialblue.brand-accent-text.on.socialblue.brand-accent').kind, 'text');
  // navbar-text is only ever drawn on its own bar, never on the white page (no white-on-white false failure).
  assert.ok(at('P1.socialblue.navbar-text.on.socialblue.navbar-bg'));
  assert.equal(pairs.filter((p) => p.fg === 'targets.socialblue.navbar-text' && p.bg.startsWith('semantic.')).length, 0);
  // A brand colour used as text lands on the three light surfaces.
  for (const s of ['surface', 'canvas', 'tint']) assert.ok(at(`P2.socialblue.brand-secondary.on.${s}`), `secondary on ${s}`);
  // A sky secondary-navbar active tab is painted white by navbar--sky.css, so it sits on the surface only.
  assert.ok(at('P2.socialblue.brand-primary.on.surface'));
  // A brand fill with no text of its own gets white text (socialbase paints it).
  assert.ok(at('P3.on-primary.on.socialblue.brand-primary') || at('P2.socialblue.brand-primary.on.surface'));
  for (const p of pairs) {
    assert.ok(p.rules >= 1 && p.example.selector && p.example.file, `${p.id} carries a rule count and an example`);
  }
});

test('derive: a stock hex used for two fields is refused (drupal/color cannot tell them apart)', () => {
  const theme = fakeTheme();
  writeFileSync(join(theme, 'color/color.inc'), STOCK_INC.replace("'brand-link' => '#33b5e5'", "'brand-link' => '#29abe2'"));
  const r = derive(['--socialblue', theme, '--json', join(sandbox(), 'x.json')]);
  assert.notEqual(r.code, 0, r.out);
  assert.match(r.out, /#29abe2 is used by both brand-primary and brand-link/);
});

test('derive --check: fires on a changed byte, passes on the file it wrote, and is deterministic', () => {
  const theme = fakeTheme({ 'brand.css': '.btn-accent { color: #343434; background-color: #ffc142; }' });
  const json = join(sandbox(), 'pairs.json');
  assert.equal(derive(['--socialblue', theme, '--json', json]).code, 0);
  const first = readFileSync(json, 'utf8');
  assert.equal(derive(['--socialblue', theme, '--json', json]).code, 0);
  assert.equal(readFileSync(json, 'utf8'), first, 'two runs give identical bytes');
  const ok = derive(['--check', '--socialblue', theme, '--json', json, ...NO_FIXTURES]);
  assert.equal(ok.code, 0, ok.out);
  assert.match(ok.out, /^OK /m);
  writeFileSync(json, first.replace('"text"', '"nope"'));
  const bad = derive(['--check', '--socialblue', theme, '--json', json, ...NO_FIXTURES]);
  assert.equal(bad.code, 1, bad.out);
  assert.match(bad.out, /DIFF /);
  assert.equal(derive(['--check', '--socialblue', theme, '--json', join(sandbox(), 'missing.json'), ...NO_FIXTURES]).code, 1);
});

test('derive --check with neither a theme nor fixtures fails (it no longer SKIPs, WR-02); writing without a theme exits 2', () => {
  const empty = sandbox();
  const r = derive(['--check', '--socialblue', empty, ...NO_FIXTURES]);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /no socialblue sources/i);
  assert.doesNotMatch(r.out, /SKIP/);
  assert.equal(derive(['--socialblue', empty, '--json', join(sandbox(), 'x.json')]).code, 2);
  assert.equal(derive(['--nope']).code, 2);
});

test('the committed pairings still match the installed socialblue, when it is installed', () => {
  const r = derive(['--check']);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^(OK|SKIP) /m);
});

test('P4: every ln-overrides.css rule that sets both a colour and a background is a pairing; borders pair against the surface', () => {
  const css = readFileSync(resolve(repoRoot, 'html/modules/custom/localnodes_platform/css/ln-overrides.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  const expected = new Set();
  const borders = new Set();
  for (const block of css.split('}')) {
    const body = block.split('{')[1];
    if (!body) continue;
    const v = (prop) => body.match(new RegExp(`(?:^|[;\\s])${prop}:\\s*var\\(--ln-color-([a-z-]+)\\)`))?.[1];
    const fg = v('color');
    const bg = v('background-color') ?? v('background');
    if (fg && bg) expected.add(`P4.drupal-css.--ln-color-${fg}.on.drupal-css.--ln-color-${bg}`);
    const bc = v('border-color') ?? body.match(/border:[^;]*var\(--ln-color-([a-z-]+)\)/)?.[1];
    if (bc) borders.add(`P4.drupal-css.--ln-color-${bc}.on.surface`);
  }
  assert.ok(expected.size >= 8, `found ${expected.size} colour + background rules in ln-overrides.css`);
  assert.ok(borders.size >= 3, `found ${borders.size} border colours`);
  const r = run([]);
  const ids = new Set(r.out.split('\n').map((l) => l.split(' ')[1]));
  for (const id of [...expected, ...borders]) assert.ok(ids.has(id), `${id} is a pairing`);
});

test('P6 controls and P7 status pairings are checked', () => {
  const r = run([]);
  const ids = r.out.split('\n').map((l) => l.split(' ')[1]).filter(Boolean);
  for (const id of [
    'P6.border-control.on.surface',
    'P6.border-control.on.canvas',
    'P6.primary.on.surface',
    'P6.primary.on.canvas',
    'P7.danger.on.surface',
    'P7.danger.on.danger-bg',
    'P7.success.on.success-bg',
    'P7.warning-text.on.warning-bg',
    'P7.on-danger.on.danger',
    'P7.text.on.danger-bg',
    'P7.link.on.warning-bg',
  ]) {
    assert.ok(ids.includes(id), `${id} is a pairing`);
  }
});

test('stock socialblue now yields at least 10 FAIL lines, including the derived socialblue families', () => {
  const r = run(['--palette', stockFixture]);
  assert.equal(r.code, 1, r.out);
  const fails = lines(r.out, 'FAIL ');
  assert.ok(fails.length >= 10, `${fails.length} FAIL lines`);
  assert.ok(fails.some((l) => l.includes('P1.socialblue.navbar-sec-text.on.socialblue.navbar-sec-bg') || l.includes('P1.socialblue.second-line-navbar-text')), 'a derived P1 pair fails on stock');
  assert.ok(fails.some((l) => l.includes('P3.')), 'a derived P3 pair fails on stock');
});

// ---------------------------------------------------------------------------
// 22.1-05 task 3: Nuxt light and dark (P8, P9), DeepChat (P10), email (P11), the
// landing page's dark band (PB) and the coverage assertion.
// ---------------------------------------------------------------------------

const idsOf = (out) => out.split('\n').map((l) => l.split(' ')[1]).filter((x) => x && /^P/.test(x));

test('the output covers families P1 to P11 and PB', () => {
  const ids = idsOf(run([]).out);
  for (const fam of ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9', 'P10', 'P11', 'PB']) {
    assert.ok(ids.some((id) => id.startsWith(`${fam}.`)), `family ${fam} appears`);
  }
});

test('P8 and P9 check every Nuxt text role on every background role, light and dark', () => {
  const ids = new Set(idsOf(run([]).out));
  for (const mode of ['light', 'dark']) {
    const fam = mode === 'light' ? 'P8' : 'P9';
    for (const text of ['text', 'text-muted', 'text-toned', 'text-dimmed', 'text-highlighted', 'primary', 'error', 'success', 'warning']) {
      for (const bg of ['bg', 'bg-muted', 'bg-elevated', 'bg-accented']) {
        assert.ok(ids.has(`${fam}.nuxt.${mode}.--ui-${text}.on.nuxt.${mode}.--ui-${bg}`), `${fam} ${text} on ${bg}`);
      }
    }
    for (const solid of ['primary', 'secondary', 'error', 'success', 'warning']) {
      assert.ok(ids.has(`${fam}.nuxt.${mode}.--ui-text-inverted.on.nuxt.${mode}.--ui-${solid}`), `${fam} inverted text on solid ${solid}`);
    }
    assert.ok(ids.has(`${fam}.nuxt.${mode}.--ui-border-accented.on.nuxt.${mode}.--ui-bg`), `${fam} accented border on bg`);
    assert.ok(ids.has(`${fam}.nuxt.${mode}.--ui-border-accented.on.nuxt.${mode}.--ui-bg-muted`), `${fam} accented border on muted bg`);
  }
});

test('P10 DeepChat, P11 email (including the button-hover role) and PB band pairings are present', () => {
  const ids = new Set(idsOf(run([]).out));
  for (const id of [
    'P10.deepchat.text.on.deepchat.surface',
    'P10.deepchat.text.on.deepchat.canvas',
    'P10.deepchat.link.on.deepchat.surface',
    'P10.deepchat.surface.on.deepchat.submit',
    'P10.deepchat.surface.on.deepchat.submit-hover',
    'P10.deepchat.border-control.on.deepchat.surface',
    'P11.email.text.on.email.body',
    'P11.email.text.on.email.card',
    'P11.email.link.on.email.card',
    'P11.email.button-text.on.email.button',
    'P11.email.button-text.on.email.button-hover',
    'P11.email.footer-text.on.email.footer-bg',
    'PB.band.text.on.band.bg',
    'PB.band.text-muted.on.band.bg',
    'PB.band.link.on.band.bg',
    'PB.band.button.on.band.bg',
    'PB.band.button-text.on.band.button',
    'PB.band.focus.on.band.bg',
    'PB.band.search-text.on.band.search-field',
    'PB.band.answer-card-text.on.band.answer-card',
    'PB.band.source-chip-text.on.band.source-chip',
    'PB.band.coming-chip-text.on.band.coming-chip',
    'PB.band.coming-chip.on.band.answer-card',
  ]) {
    assert.ok(ids.has(id), `${id} is a pairing`);
  }
});

test('coverage: a semantic colour in no pairing is UNCOVERED and fails the run; the base token set has none', () => {
  assert.equal(lines(run([]).out, 'UNCOVERED ').length, 0, 'the repo token set is fully covered');
  const r = run(['--palette', overlay('ghost.json', { semantic: { ghost: { $value: '#123456' } } })]);
  assert.equal(r.code, 1, r.out);
  assert.ok(lines(r.out, 'UNCOVERED ').some((l) => l.includes('semantic.ghost')), r.out);
  // The coverage failure is the only new failure: nothing else changed.
  assert.deepEqual(lines(r.out, 'FAIL ').map((l) => l.split(' ')[1]), []);
});

test('coverage: a socialblue field in no pairing is UNCOVERED too', () => {
  const r = run(['--palette', overlay('field.json', { targets: { socialblue: { 'brand-extra': '#123456' } } })]);
  assert.ok(lines(r.out, 'UNCOVERED ').some((l) => l.includes('targets.socialblue.brand-extra')), r.out);
});

test('coverage: a decorative colour needs a reason; with one it is not UNCOVERED, without one it still is', () => {
  const withReason = run(['--palette', overlay('dec.json', { semantic: { ghost: { $value: '#123456', decorative: true, reason: 'a separator, never a control boundary' } } })]);
  assert.equal(lines(withReason.out, 'UNCOVERED ').length, 0, withReason.out);
  const noReason = run(['--palette', overlay('dec2.json', { semantic: { ghost: { $value: '#123456', decorative: true } } })]);
  assert.ok(lines(noReason.out, 'UNCOVERED ').some((l) => l.includes('semantic.ghost') && /reason/.test(l)), noReason.out);
});

test('the dark family is checked: an overlay that makes the dark muted text fail adds a P9 FAIL and nothing in P8', () => {
  const r = run(['--palette', overlay('dark.json', { semantic: { dark: { 'text-muted': { $value: '#3f3f46' } } } })]);
  assert.equal(r.code, 1, r.out);
  const fails = lines(r.out, 'FAIL ').map((l) => l.split(' ')[1]);
  // On the page colour the muted text passes in the repo, so this FAIL is new and comes from the overlay.
  assert.ok(fails.includes('P9.nuxt.dark.--ui-text-muted.on.nuxt.dark.--ui-bg'), `a new P9 FAIL for the muted text on the page in ${fails.join(' ')}`);
  assert.ok(!fails.some((id) => id.startsWith('P8.') && id.includes('text-muted')), 'light is untouched');
});

test('the email footer rule: footer text on the email body (the footer sits on the body colour) is checked too', () => {
  const ids = new Set(idsOf(run([]).out));
  assert.ok(ids.has('P11.email.footer-text.on.email.body'));
  assert.ok(ids.has('P11.email.heading.on.email.card'));
});

// ---------------------------------------------------------------------------
// 22.1-05 Q2: the seven findings, resolved. Group A (brand-accent as text, white text on the amber secondary) goes
// through the override path and is proved by the gate; Group B (Nuxt dark --ui-bg-accented) is EXCLUDED with a reason
// and a tripwire. Each test below fails before the fix.
// ---------------------------------------------------------------------------

const overridesCss = resolve(repoRoot, 'html/modules/custom/localnodes_platform/css/ln-overrides.css');
const ACCENT_IDS = [
  'P2.socialblue.brand-accent.on.surface',
  'P2.socialblue.brand-accent.on.canvas',
  'P2.socialblue.brand-accent.on.tint',
];
const NUXT_SECONDARY_ID = 'P8.nuxt.light.--ui-text-inverted.on.nuxt.light.--ui-secondary';
// 22.1-12: the muted text is the band's muted text now (7.87:1 on this fill), so its exclusion is gone.
// The teal palette's dark greys lift the last two dark pairs over 4.5:1, so no dark pair is excluded any more.
const DARK_IDS = [
  'P9.nuxt.dark.--ui-text-dimmed.on.nuxt.dark.--ui-bg-accented',
  'P9.nuxt.dark.--ui-error.on.nuxt.dark.--ui-bg-accented',
];
const FILL_IDS = [
  'P12.sidebar-card-button.socialblue.brand-accent.on.surface',
  'P12.featured-link.socialblue.brand-accent.on.surface',
  'P12.course-nav-icon.socialblue.brand-accent.on.surface',
];
const FOCUS_IDS = [
  'P12.sidebar-card-button.focus.on.surface',
  'P12.featured-link.focus.on.canvas',
  'P12.course-nav-icon.focus.on.surface',
];
const DECORATIVE_REASON = 'decorative hover affordance; the control keeps its 3:1 focus ring and text';
const lineFor = (out, id) => out.split('\n').find((l) => l.split(' ')[1] === id);

for (const id of ACCENT_IDS) {
  test(`Group A: ${id} passes because ln-overrides.css re-colours the accent-as-text rules`, () => {
    const l = lineFor(run([]).out, id);
    assert.ok(l, `${id} is still a pairing (never removed)`);
    assert.ok(l.startsWith('OK '), l);
    assert.match(l, /overridden by ln-overrides\.css \(9 rules -> --ln-color-text\)/);
    assert.match(l, /3 amber bar fills/, 'the line says the bar fills are not measured here');
    const ratio = Number(l.split(' ')[2]);
    // The text role (#27272a since the app look) is 13.6 to 14.9 on the light surfaces; the amber accent is under 2.
    assert.ok(ratio >= 4.5 && ratio <= 15, `measured as the text role, not the accent: ${ratio}`);
  });
}

test(`Group A: ${NUXT_SECONDARY_ID} passes as the text role on the secondary fill, and says what the Nuxt repo must wire`, () => {
  const l = lineFor(run([]).out, NUXT_SECONDARY_ID);
  assert.ok(l, 'the stock pairing keeps its id');
  assert.ok(l.startsWith('OK '), l);
  assert.match(l, /--ui-text/);
  assert.match(l, /text-default/);
  assert.ok(Number(l.split(' ')[2]) >= 4.5, l);
});

test('Group A guard: the accent pairs measure the override, so with no override file they FAIL on the stock accent', () => {
  const empty = join(sandbox(), 'none.css');
  writeFileSync(empty, '/* nothing */\n');
  const r = run(['--overrides', empty]);
  assert.equal(r.code, 1, r.out);
  for (const id of ACCENT_IDS) {
    const l = lineFor(r.out, id);
    assert.ok(l?.startsWith('FAIL '), `${id}: ${l}`);
    assert.ok(l.includes(HIGHLIGHT), `${l} names the stock accent ${HIGHLIGHT}`);
  }
  assert.match(lineFor(r.out, ACCENT_IDS[0]), /NOT overridden/);
});

test('Group A guard: one missing override rule is enough to fail the pairs, and the FAIL names the selector', () => {
  const css = readFileSync(overridesCss, 'utf8');
  const rule = /\.course__navigation \.course_nav-toggle:hover svg,[^{]*\{[^}]*\}\n?/;
  assert.match(css, rule, 'the rule exists in ln-overrides.css');
  const file = join(sandbox(), 'minus-one.css');
  writeFileSync(file, css.replace(rule, ''));
  const r = run(['--overrides', file]);
  assert.equal(r.code, 1, r.out);
  const l = lineFor(r.out, ACCENT_IDS[0]);
  assert.ok(l?.startsWith('FAIL '), l);
  assert.match(l, /NOT overridden: \.course__navigation \.course_nav-toggle:hover svg/);
});

test('Group A guard: the gate measures the colour the override uses, so an override to the accent itself still FAILs', () => {
  const css = readFileSync(overridesCss, 'utf8');
  assert.ok(css.includes('.card__featured-items .teaser__info svg:hover'), 'the accent override block exists');
  const bad = css.replace(/(\.card__featured-items \.teaser__info svg:hover[^{]*\{[^}]*?)var\(--ln-color-text\)/, '$1var(--ln-color-highlight)');
  assert.notEqual(bad, css, 'the mutation changed the file');
  const file = join(sandbox(), 'wrong-colour.css');
  writeFileSync(file, bad);
  const r = run(['--overrides', file]);
  assert.equal(r.code, 1, r.out);
  assert.ok(lineFor(r.out, ACCENT_IDS[0])?.startsWith('FAIL '), lineFor(r.out, ACCENT_IDS[0]));
});

test('Group A completeness: every rule in the stock stylesheets that paints brand-accent as text or an icon is one the gate expects (skips when socialblue is absent)', async (t) => {
  const theme = resolve(repoRoot, 'html/profiles/contrib/social/themes/socialblue');
  if (!existsSync(resolve(theme, 'assets/css/brand.css'))) return t.skip('socialblue is not installed here');
  const { parseRules } = await import('../derive_socialblue_pairings.mjs');
  const { ACCENT_TEXT_RULES } = await import('../pairings.mjs');
  const norm = (sel) => sel.replace(/\s+/g, ' ').split(',').map((x) => x.trim()).join(', ');
  const found = new Set();
  for (const f of ['assets/css/brand.css', 'assets/css/brand--sky.css', 'assets/css/tokens.css']) {
    for (const r of parseRules(readFileSync(resolve(theme, f), 'utf8'))) {
      if (r.body.split(';').some((d) => /^\s*(color|fill)\s*:\s*#ffc142\b/i.test(d))) found.add(norm(r.selector));
    }
  }
  assert.deepEqual([...found].sort(), ACCENT_TEXT_RULES.map((x) => x.selector).sort());
});

test('Group A: every re-colouring rule in ln-overrides.css uses a var(), never a colour literal, and only color or fill', () => {
  const css = readFileSync(overridesCss, 'utf8');
  const start = css.indexOf('.card__featured-items .teaser__info svg:hover');
  assert.ok(start > 0, 'the block exists');
  const end = css.indexOf('/* Motion', start);
  const block = css.slice(start, end);
  assert.ok(!/#[0-9a-f]{3,8}\b/i.test(block), 'no colour literal');
  assert.equal([...block.matchAll(/\{[^}]*\}/g)].length, 9, 'nine rules');
});

test('Group B: the two dark pairs that used to be excluded are measured and OK at 4.5:1 or better', () => {
  const r = run([]);
  for (const id of DARK_IDS) {
    const l = lineFor(r.out, id);
    assert.ok(l, `${id} is printed`);
    assert.ok(l.startsWith(`OK ${id} `), l);
    assert.ok(Number(l.split(' ')[2]) >= 4.5, l);
  }
  assert.equal(lines(r.out, 'EXCLUDED ').filter((l) => DARK_IDS.includes(l.split(' ')[1])).length, 0);
});

test('Group B: the exclusion list is frozen, has an id and a reason on every entry, and an id that is no pairing is an error', async () => {
  const { EXCLUDED, declaredPairings, assertExclusionsKnown } = await import('../pairings.mjs');
  assert.ok(Object.isFrozen(EXCLUDED));
  assert.deepEqual(EXCLUDED.map((e) => e.id), FILL_IDS);
  for (const e of EXCLUDED) assert.ok(typeof e.reason === 'string' && e.reason.length > 20, `${e.id} has a reason`);
  assert.throws(() => assertExclusionsKnown([{ id: 'P9.nuxt.dark.--ui-nothing.on.nuxt.dark.--ui-bg', reason: 'x'.repeat(30) }], []), /no such pairing/);
  assert.throws(() => assertExclusionsKnown([{ id: 'a', reason: '' }], [{ id: 'a' }]), /reason/);
  void declaredPairings;
});

test('Group B tripwire: the token-source flag, when it says dark mode is rendered, still fails the gate; the decorative fills keep their exclusion', () => {
  const r = run(['--palette', overlay('dark-on.json', { targets: { nuxt: { 'dark-mode-rendered': { $value: true } } } })]);
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(lines(r.out, 'EXCLUDED ').map((l) => l.split(' ')[1]), FILL_IDS, 'the decorative fills do not depend on dark mode');
  assert.deepEqual(lines(r.out, 'FAIL ').map((l) => l.split(' ')[1]).filter((id) => DARK_IDS.includes(id)), []);
  assert.ok(lines(r.out, 'TRIPWIRE ').some((l) => l.includes('dark-mode-rendered')), r.out);
});

/** A fake onboarding checkout: nuxt.config.ts, pages, components. */
function fakeNuxt({ pages = { 'index.vue': "definePageMeta({ colorMode: 'light' })" }, components = {} } = {}) {
  const dir = sandbox();
  writeFileSync(join(dir, 'nuxt.config.ts'), "export default defineNuxtConfig({ colorMode: { preference: 'dark' } })\n");
  for (const [base, files] of [['app/pages', pages], ['app/components', components]]) {
    for (const [name, body] of Object.entries(files)) {
      const f = join(dir, base, name);
      mkdirSync(dirname(f), { recursive: true });
      writeFileSync(f, body);
    }
  }
  return dir;
}

test('Group B tripwire: a Nuxt page without colorMode light renders in the default dark mode, so the gate fails and names the page', () => {
  const dir = fakeNuxt({ pages: { 'index.vue': "definePageMeta({ colorMode: 'light' })", 'new-page.vue': '<template><div/></template>' } });
  const r = run(['--nuxt', dir]);
  assert.equal(r.code, 1, r.out);
  assert.ok(lines(r.out, 'TRIPWIRE ').some((l) => l.includes('app/pages/new-page.vue')), r.out);
  assert.deepEqual(lines(r.out, 'EXCLUDED ').map((l) => l.split(' ')[1]), FILL_IDS, 'the decorative fills keep their exclusion');
  assert.deepEqual(lines(r.out, 'FAIL ').map((l) => l.split(' ')[1]), [], 'every dark pair passes, so only the TRIPWIRE line fails the run');
});

test('Group B tripwire: a dark-scoped component that uses the accented fill (bg-accented, soft or subtle buttons) fails the gate', () => {
  for (const body of ['<section class="dark"><div class="bg-accented"/></section>', '<section class="dark"><UButton variant="soft"/></section>']) {
    const r = run(['--nuxt', fakeNuxt({ components: { 'Band.vue': body } })]);
    assert.equal(r.code, 1, `${body}\n${r.out}`);
    assert.ok(lines(r.out, 'TRIPWIRE ').some((l) => l.includes('app/components/Band.vue')), r.out);
  }
  const clean = run(['--nuxt', fakeNuxt({ components: { 'Band.vue': '<section class="dark"><UButton variant="outline" color="neutral"/></section>' } })]);
  assert.equal(clean.code, 0, clean.out);
  assert.equal(lines(clean.out, 'TRIPWIRE ').length, 0, clean.out);
});

test('Group B tripwire: with no Nuxt checkout the page scan is skipped, visibly, and the token-source flag still guards', () => {
  const r = run(['--nuxt', join(sandbox(), 'not-there')]);
  assert.equal(r.code, 0, r.out);
  assert.match(r.out, /^SKIP dark-mode tripwire \(nuxt checkout not present\)/m);
});

test('Group B: an exclusion that no longer fails is STALE and fails the run, so the list cannot rot', () => {
  const r = run(['--palette', overlay('accent-fixed.json', { targets: { socialblue: { 'brand-accent': { $value: '#8a6100' } } } })]);
  assert.equal(r.code, 1, r.out);
  assert.deepEqual(lines(r.out, 'STALE ').map((l) => l.split(' ')[1]), FILL_IDS);
  assert.match(lines(r.out, 'STALE ')[0], /remove it from EXCLUDED/);
});

test('stock socialblue still fails with the exclusions and overrides in place (the floor is unchanged: at least 10)', () => {
  const r = run(['--palette', stockFixture]);
  assert.equal(r.code, 1, r.out);
  assert.ok(lines(r.out, 'FAIL ').length >= 10, r.out);
});

// ---------------------------------------------------------------------------
// 22.1-05 Q3 follow-up (orchestrator answer 2026-11-08, option a). Three stock fills draw the brand accent on white at
// 1.62:1: the sidebar card button's :hover:before bar, the featured-link paragraph's :hover:before bar and the
// course-navigation icon disc. They are declared as non-text pairs (3:1) and excluded as decorative, and the exclusion
// rests on something measured: each control keeps a focus ring at 3:1 or better, a pair the gate checks beside it.
// ---------------------------------------------------------------------------

test('Q3: the three amber fills are declared as non-text pairs at 3:1 and are EXCLUDED lines with their accent-on-white ratio, the written reason, and the focus ring that backs them', () => {
  const r = run([]);
  assert.equal(r.code, 0, r.out);
  const focusRatio = new Map(FOCUS_IDS.map((id) => [id, Number(lineFor(r.out, id)?.split(' ')[2])]));
  FILL_IDS.forEach((id, i) => {
    const l = lineFor(r.out, id);
    assert.ok(l, `${id} is printed`);
    assert.ok(l.startsWith(`EXCLUDED ${id} ${ratio2(HIGHLIGHT, SOURCE.semantic.surface)} (${DECORATIVE_REASON})`), l);
    assert.ok(l.endsWith(` | focus ring ${FOCUS_IDS[i]} ${focusRatio.get(FOCUS_IDS[i]).toFixed(2)} >= 3`), `the line carries the measured focus ring: ${l}`);
    assert.ok(!r.out.split('\n').some((x) => (x.startsWith('OK ') || x.startsWith('FAIL ')) && x.split(' ')[1] === id), `${id} is not also OK or FAIL`);
  });
  assert.equal(lines(r.out, 'EXCLUDED ').length, 3);
});

test('Q3: each of the three controls has a focus pair, the colour that applies to it on the background it sits on, and it is OK at 3:1 or better', () => {
  const r = run([]);
  const { focus, surface, canvas } = SOURCE.semantic;
  const expected = [[FOCUS_IDS[0], ratio2(focus, surface)], [FOCUS_IDS[1], ratio2(focus, canvas)], [FOCUS_IDS[2], ratio2(focus, surface)]];
  for (const [id, ratio] of expected) {
    const l = lineFor(r.out, id);
    assert.ok(l, `${id} is printed`);
    assert.ok(l.startsWith(`OK ${id} ${ratio} >= 3`), l);
  }
});

test('Q3: the declared pairs use the right kind, bar and tokens (T-22.1-16)', async () => {
  const { declaredPairings, THRESHOLDS } = await import('../pairings.mjs');
  const { loadTokens, resolve: resolveTokens } = await import('../lib/tokens.mjs');
  const ps = declaredPairings(resolveTokens(loadTokens(null)));
  for (const id of FILL_IDS) {
    const p = ps.find((x) => x.id === id);
    assert.ok(p, id);
    assert.equal(p.kind, 'non-text');
    assert.equal(p.min, THRESHOLDS['non-text']);
    assert.equal(p.min, 3);
    assert.equal(p.fg, 'targets.socialblue.brand-accent');
    assert.equal(p.bg, 'semantic.surface');
  }
  for (const id of FOCUS_IDS) {
    const p = ps.find((x) => x.id === id);
    assert.ok(p, id);
    assert.equal(p.kind, 'focus');
    assert.equal(p.min, 3);
    assert.equal(p.fg, 'semantic.focus', 'the global :focus-visible ring colour, --ln-color-focus');
  }
  assert.deepEqual(FOCUS_IDS.map((id) => ps.find((x) => x.id === id).bg), ['semantic.surface', 'semantic.canvas', 'semantic.surface']);
  assert.equal(new Set(ps.map((x) => x.id)).size, ps.length, 'the six ids are distinct, so none collapses into another pair');
});

test('Q3: each decorative exclusion names its focus twin, and nothing else is excluded', async () => {
  const { EXCLUDED } = await import('../pairings.mjs');
  assert.ok(Object.isFrozen(EXCLUDED));
  const byId = new Map(EXCLUDED.map((e) => [e.id, e]));
  FILL_IDS.forEach((id, i) => {
    const e = byId.get(id);
    assert.ok(e, id);
    assert.equal(e.reason, DECORATIVE_REASON);
    assert.equal(e.focusTwin, FOCUS_IDS[i]);
    assert.ok(Object.isFrozen(e));
  });
  assert.equal(byId.size, FILL_IDS.length);
});

test('Q3 guard: an exclusion with a focus twin fails the gate when the twin is missing or is not a focus pair', async () => {
  const { assertExclusionsKnown } = await import('../pairings.mjs');
  const reason = DECORATIVE_REASON;
  const fill = { id: FILL_IDS[0], kind: 'non-text' };
  const entry = { id: FILL_IDS[0], reason, focusTwin: FOCUS_IDS[0] };
  assert.doesNotThrow(() => assertExclusionsKnown([entry], [fill, { id: FOCUS_IDS[0], kind: 'focus' }]));
  assert.throws(() => assertExclusionsKnown([entry], [fill]), /focus ring pair .* is missing/);
  assert.throws(() => assertExclusionsKnown([entry], [fill, { id: FOCUS_IDS[0], kind: 'text' }]), /not a focus pair/);
  assert.throws(() => assertExclusionsKnown([{ id: FILL_IDS[0], reason }], [fill]), /tripwire or a focus twin/);
});

test('Q3 guard: when the focus ring drops below 3:1 the twins FAIL, the exclusion is void, and the three fills FAIL too', () => {
  // semantic.focus set to the accent itself: 1.62:1 on white, so the ring no longer backs anything.
  const r = run(['--palette', overlay('focus-weak.json', { semantic: { focus: { $value: '#ffc142' } } })]);
  assert.equal(r.code, 1, r.out);
  const failed = lines(r.out, 'FAIL ').map((l) => l.split(' ')[1]);
  for (const id of [...FOCUS_IDS, ...FILL_IDS]) assert.ok(failed.includes(id), `${id} FAILs: ${r.out}`);
  assert.equal(lines(r.out, 'EXCLUDED ').filter((l) => FILL_IDS.includes(l.split(' ')[1])).length, 0, 'the exclusion rests on the ring, so it is void');
});

test('Q3 guard: an exclusion that no longer fails is STALE: an accent that is 3:1 on white turns the three fills STALE', () => {
  const r = run(['--palette', overlay('accent-dark.json', { targets: { socialblue: { 'brand-accent': { $value: '#8a6100' } } } })]);
  assert.equal(r.code, 1, r.out);
  const stale = lines(r.out, 'STALE ').map((l) => l.split(' ')[1]);
  for (const id of FILL_IDS) assert.ok(stale.includes(id), `${id} is STALE: ${r.out}`);
});

test('Q3: the three selectors are socialblue rules that draw the accent as a background (skips when socialblue is absent)', async (t) => {
  const theme = resolve(repoRoot, 'html/profiles/contrib/social/themes/socialblue');
  if (!existsSync(resolve(theme, 'assets/css/brand.css'))) return t.skip('socialblue is not installed here');
  const { parseRules } = await import('../derive_socialblue_pairings.mjs');
  const { DECORATIVE_FILLS } = await import('../pairings.mjs');
  assert.deepEqual(DECORATIVE_FILLS.map((d) => d.key), ['sidebar-card-button', 'featured-link', 'course-nav-icon']);
  const norm = (sel) => sel.replace(/\s+/g, ' ').split(',').map((x) => x.trim());
  const rules = [];
  for (const f of ['assets/css/brand.css', 'assets/css/brand--sky.css']) rules.push(...parseRules(readFileSync(resolve(theme, f), 'utf8')));
  for (const d of DECORATIVE_FILLS) {
    const rule = rules.find((r) => norm(r.selector).includes(d.selector));
    assert.ok(rule, `socialblue has the rule ${d.selector}`);
    assert.ok(rule.body.split(';').some((x) => /^\s*background(-color)?\s*:\s*#ffc142\b/i.test(x)), `${d.selector} draws the accent as a background`);
  }
});

test('Q3: the focus ring the twins measure is what applies: one global :focus-visible rule in --ln-color-focus, !important, and none of the three controls sits in the navigation scopes or sets its own outline', async () => {
  const css = readFileSync(overridesCss, 'utf8');
  const { parseRules } = await import('../derive_socialblue_pairings.mjs');
  const { DECORATIVE_FILLS } = await import('../pairings.mjs');
  const rules = parseRules(css);
  const global = rules.find((r) => r.selector.trim() === ':focus-visible');
  assert.ok(global, 'the global :focus-visible rule');
  assert.match(global.body, /outline:\s*2px solid var\(--ln-color-focus\) !important/);
  const dark = rules.find((r) => /\.navbar-default :focus-visible/.test(r.selector));
  assert.ok(dark, 'the navigation override exists');
  for (const d of DECORATIVE_FILLS) {
    assert.ok(!/navbar/.test(d.controlSelector), `${d.key} is not in a navigation bar, so it gets the global ring`);
    for (const r of rules) {
      if (r === global || r === dark) continue;
      if (/\boutline\b/.test(r.body) && r.selector.includes(d.controlSelector)) assert.fail(`${d.key}: ln-overrides.css sets its own outline in ${r.selector}`);
    }
  }
});

// ---------------------------------------------------------------------------
// 22.1-17 (review WR-06, 17-H1): the pairs the module stylesheets draw, measured against the colour the browser
// renders. The map and the parser are tested on fixtures in stylesheet_pairs.test.mjs; these run the gate.
// ---------------------------------------------------------------------------

const LNID = (name) => `drupal-css.--ln-color-${name}`;
const HEADER_NAME_ID = `P13.overrides.text.${LNID('on-primary')}.on.socialblue.navbar-bg`;
const BAR_TEXT_ID = `P13.overrides.text.${LNID('on-primary')}.on.${LNID('text')}`;
const BAR_RING_ID = `P13.deepchat.focus.${LNID('focus-on-dark')}.on.${LNID('text')}`;
const LINK_RING_IDS = [`P13.deepchat.focus.${LNID('focus')}.on.${LNID('surface')}`, `P13.deepchat.focus.${LNID('focus')}.on.${LNID('canvas')}`];

test('22.1-17: the header name, the bar text and the bar rings are declared P13 pairs, and every one is OK', () => {
  const r = run([]);
  assert.equal(r.code, 0, r.out);
  for (const id of [HEADER_NAME_ID, BAR_TEXT_ID, BAR_RING_ID, ...LINK_RING_IDS]) {
    const l = lineFor(r.out, id);
    assert.ok(l, `${id} is declared: ${r.out.split('\n').filter((x) => x.includes('P13.')).join('\n')}`);
    assert.ok(l.startsWith('OK '), l);
  }
  assert.ok(lines(r.out, 'OK P13.').length >= 5);
  assert.equal(lines(r.out, 'EXCLUDED ').length, 3, 'no exclusion was added (Q12)');
});

test('22.1-17: the bar ring pair names both bar controls, and the header text sits on --ln-color-text', async () => {
  const { declaredPairings } = await import('../pairings.mjs');
  const pairs = declaredPairings(SOURCE, { tree: loadTokens(null) });
  const ring = pairs.find((p) => p.id === BAR_RING_ID);
  assert.ok(ring, BAR_RING_ID);
  assert.equal(ring.kind, 'focus');
  assert.equal(ring.min, 3);
  assert.match(ring.source, /\.ai-deepchat \.ln-chat-bar \.ai-deepchat--header:focus-visible/);
  assert.match(ring.source, /\.ai-deepchat \.ln-chat-bar \.chat-dropdown-button:focus-visible/);
  const name = pairs.find((p) => p.id === HEADER_NAME_ID);
  assert.equal(name.kind, 'text');
  assert.equal(name.min, 4.5);
  assert.equal(name.bg, 'targets.socialblue.navbar-bg');
  assert.match(name.source, /ln-overrides\.css \.navbar-brand \.ln-site-name color/);
});

test('22.1-17: deepchat.css asks the dropdown links for the amber ring, the browser renders --ln-color-focus, so the gate prints OVERRIDDEN and measures what is rendered', () => {
  const r = run([]);
  const l = lines(r.out, 'OVERRIDDEN ');
  assert.ok(l.length >= 1, r.out);
  const link = l.find((x) => x.includes('chat-dropdown-link:focus-visible'));
  assert.ok(link, l.join('\n'));
  assert.match(link, /^OVERRIDDEN deepchat\.css /);
  assert.match(link, /outline/);
  assert.match(link, /--ln-color-focus-on-dark/);
  assert.match(link, /renders --ln-color-focus\b/);
  assert.match(link, /ln-overrides\.css/);
  // The two bar controls draw what deepchat.css asks for (amber, inset), so they are pairs, not OVERRIDDEN.
  assert.ok(!l.some((x) => /ai-deepchat--header:focus-visible|chat-dropdown-button:focus-visible/.test(x)), l.join('\n'));
});

test('22.1-17: an overlay with navbar-bg #808080 fails the header name at 3.95, and the FAIL names the stylesheet, selector and property', () => {
  const r = run(['--palette', overlay('navbar-grey.json', { primitive: { color: { chrome: { 'navbar-bg': { $value: '#808080' } } } } })]);
  assert.equal(r.code, 1, r.out);
  const l = lineFor(r.out, HEADER_NAME_ID);
  assert.ok(l?.startsWith('FAIL '), l);
  assert.match(l, /3\.95 < 4\.5/);
  assert.match(l, /ln-overrides\.css .*\.navbar-brand \.ln-site-name.* color/);
});

test('22.1-17: an overlay with semantic.text #767676 fails the bar ring, and the FAIL names the stylesheet, selector and property', () => {
  const r = run(['--palette', overlay('text-grey.json', { semantic: { text: { $value: '#767676' } } })]);
  assert.equal(r.code, 1, r.out);
  const l = lineFor(r.out, BAR_RING_ID);
  assert.ok(l?.startsWith('FAIL '), l);
  assert.match(l, /deepchat\.css .*ai-deepchat--header:focus-visible.* outline/);
});

test('22.1-17: the bar rule colours the header text with a token, not the contrib stylesheet literal white', () => {
  const css = readFileSync(overridesCss, 'utf8');
  assert.match(css, /\.ai-deepchat \.ln-chat-bar \.ai-deepchat--header\s*\{[^}]*color:\s*var\(--ln-color-on-primary\)/);
});

test('22.1-17: the chat bar ring rules in ln-overrides.css are !important, inset, and use existing tokens only', () => {
  const css = readFileSync(overridesCss, 'utf8');
  assert.match(css, /\.ai-deepchat \.ln-chat-bar \.ai-deepchat--header:focus-visible[^{]*\{[^}]*outline-color:\s*var\(--ln-color-focus-on-dark\) !important;[^}]*outline-offset:\s*-4px !important/);
  assert.match(css, /\.ai-deepchat \.ln-chat-bar \.chat-dropdown-link:focus-visible\s*\{[^}]*outline-offset:\s*-4px !important/);
});

// ---------------------------------------------------------------------------
// 22.1-17 Task 2: nothing a module stylesheet draws slips under AA unseen. Each test below builds a private copy of the
// repo (helpers/repo_copy.mjs), changes one CSS file or the map in the copy, and runs the copy's gate.
// ---------------------------------------------------------------------------

import { copyRepo } from './helpers/repo_copy.mjs';
import { testTmpDir } from './helpers/tmp_dir.mjs';

const copyGate = (repo, args = []) => {
  const r = spawnSync(process.execPath, [join(repo, 'scripts/tokens/check_contrast.mjs'), '--nuxt', NO_NUXT, ...args], { cwd: repo, encoding: 'utf8' });
  return { code: r.status, out: `${r.stdout}${r.stderr}` };
};
const IMPORT_CSS = 'html/modules/custom/localnodes_import/css/import.css';
const DEEPCHAT_CSS = 'html/modules/custom/social_ai_indexing/css/deepchat.css';
const MAP = 'scripts/tokens/lib/stylesheet_pairs.mjs';
const edit = (repo, rel, fn) => {
  const f = join(repo, rel);
  const before = readFileSync(f, 'utf8');
  const after = fn(before);
  assert.notEqual(after, before, `${rel} was changed`);
  writeFileSync(f, after);
};

test('22.1-17: the gate prints no UNMAPPED and no STALE-PARTNER on the repo, and OK is above the tracer count', () => {
  const r = run([]);
  assert.equal(r.code, 0, r.out);
  assert.equal(lines(r.out, 'UNMAPPED ').length, 0, r.out);
  assert.equal(lines(r.out, 'STALE-PARTNER ').length, 0, r.out);
  assert.ok(lines(r.out, 'OK ').length > 216, 'every declaration of the four stylesheets adds pairs');
  assert.ok(lines(r.out, 'DECORATIVE ').length >= 5, 'the divider lines print DECORATIVE');
  assert.match(r.out, /RESULT: PASS \(\d+ OK, 3 EXCLUDED\)/);
});

test('22.1-17 guard: a colour rule that no partner names is UNMAPPED, the line says where to name it, and the run FAILs', () => {
  const { repo } = copyRepo('contrast-unmapped');
  edit(repo, IMPORT_CSS, (css) => `${css}\n.localnodes-import-new-thing { color: var(--ln-color-text); }\n`);
  const r = copyGate(repo);
  assert.equal(r.code, 1, r.out);
  const l = lines(r.out, 'UNMAPPED ');
  assert.equal(l.length, 1, r.out);
  assert.match(l[0], /^UNMAPPED import\.css \.localnodes-import-new-thing color --ln-color-text /);
  assert.match(l[0], /scripts\/tokens\/lib\/stylesheet_pairs\.mjs/);
  assert.match(r.out, /RESULT: FAIL \(1 UNMAPPED\)/);
});

test('22.1-17 guard: a background-only rule that names neither foreground nor boundary is UNMAPPED (review 17-M1)', () => {
  const { repo } = copyRepo('contrast-bg-only');
  edit(repo, IMPORT_CSS, (css) => `${css}\n.localnodes-import-new-fill { background: var(--ln-color-primary); }\n`);
  const r = copyGate(repo);
  assert.equal(r.code, 1, r.out);
  assert.match(lines(r.out, 'UNMAPPED ')[0], /^UNMAPPED import\.css \.localnodes-import-new-fill background --ln-color-primary /);
});

// Review 17-M1 (Codex round 2): a fixture that changes ONLY the CSS background of an ancestor that is already mapped
// and leaves the partner map as it is. The gate must FAIL, or say the partner is stale.
const BAR_RULE = /(\.ai-deepchat \.ln-chat-bar \{[^}]*background:) var\(--ln-color-text\);/;

test('22.1-17 / 17-M1: changing only an ancestor\'s CSS background, with the map untouched, fails the gate and names the stale partner', () => {
  const control = copyRepo('contrast-ancestor-ctl').repo;
  assert.match(readFileSync(join(control, DEEPCHAT_CSS), 'utf8'), BAR_RULE, 'the fixture ancestor is the bar rule');
  const ok = copyGate(control);
  assert.equal(ok.code, 0, `the unchanged copy passes: ${ok.out}`);

  // A background that would still pass contrast with the amber ring and the white text: only the staleness can fail it.
  const passing = copyRepo('contrast-ancestor-pass').repo;
  edit(passing, DEEPCHAT_CSS, (css) => css.replace(BAR_RULE, '$1 var(--ln-color-primary-strong);'));
  const rPass = copyGate(passing);
  assert.equal(rPass.code, 1, rPass.out);
  const stale = lines(rPass.out, 'STALE-PARTNER ');
  assert.ok(stale.length >= 1, rPass.out);
  assert.ok(stale.some((l) => /map says --ln-color-text, but \.ai-deepchat \.ln-chat-bar sets --ln-color-primary-strong in deepchat\.css/.test(l)), stale.join('\n'));
  assert.equal(lines(rPass.out, 'FAIL ').length, 0, 'it is the stale partner that fails, not a contrast ratio');
  assert.match(rPass.out, /RESULT: FAIL \(\d+ STALE-PARTNER\)/);

  // A background that fails: the pair is measured on what the CSS now draws, so it FAILs too.
  const failing = copyRepo('contrast-ancestor-fail').repo;
  edit(failing, DEEPCHAT_CSS, (css) => css.replace(BAR_RULE, '$1 var(--ln-color-surface);'));
  const rFail = copyGate(failing);
  assert.equal(rFail.code, 1, rFail.out);
  assert.ok(lines(rFail.out, 'FAIL ').some((l) => l.includes('P13.deepchat.focus') && l.includes('--ln-color-surface')), rFail.out);
  assert.ok(lines(rFail.out, 'STALE-PARTNER ').length >= 1, rFail.out);
});

test('22.1-17 / 17-M1 discrimination: the same CSS change passes once the map says the same, so the failure is the map and the CSS disagreeing', () => {
  const { repo } = copyRepo('contrast-ancestor-agree');
  edit(repo, DEEPCHAT_CSS, (css) => css.replace(BAR_RULE, '$1 var(--ln-color-primary-strong);'));
  edit(repo, MAP, (js) => js.replace("const barFill = { from: BAR, sheet: 'deepchat', bg: 'text' };", "const barFill = { from: BAR, sheet: 'deepchat', bg: 'primary-strong' };"));
  const r = copyGate(repo);
  assert.equal(r.code, 0, r.out);
  assert.equal(lines(r.out, 'STALE-PARTNER ').length, 0, r.out);
});

test('22.1-17 guard: a map entry for a selector that left its stylesheet fails the gate with a STALE-PARTNER line naming it', () => {
  const { repo } = copyRepo('contrast-stale-entry');
  edit(repo, DEEPCHAT_CSS, (css) => css.replace('.chat-dropdown-link:hover {', '.chat-dropdown-link:hover-renamed {'));
  const r = copyGate(repo);
  assert.equal(r.code, 1, r.out);
  assert.ok(lines(r.out, 'STALE-PARTNER ').some((l) => l.includes('chat-dropdown-link:hover')), r.out);
});

test('22.1-17: a drawn Drupal role counts as covered only by a pairing that names it (uncovered() no longer takes the via-semantic shortcut)', async () => {
  const { uncovered } = await import('../pairings.mjs');
  const tree = loadTokens(null);
  const pairs = [{ id: 'x', fg: 'semantic.text', bg: 'semantic.surface' }];
  const role = 'targets.drupal-css.--ln-color-text';
  assert.ok(!uncovered(tree, SOURCE, pairs).some((u) => u.path === role), 'undrawn: covered through its semantic colour, as before');
  assert.ok(uncovered(tree, SOURCE, pairs, { drawn: new Set([role]) }).some((u) => u.path === role), 'drawn: a pairing must name the role itself');
  const direct = [{ id: 'y', fg: role, bg: 'semantic.surface' }];
  assert.ok(!uncovered(tree, SOURCE, direct, { drawn: new Set([role]) }).some((u) => u.path === role), 'named: covered');
});

test('22.1-17: the base token set has no UNCOVERED role, with every role the stylesheets draw required to be named directly', () => {
  const r = run([]);
  assert.equal(lines(r.out, 'UNCOVERED ').length, 0, r.out);
});

test('Q14: the import alert bar is re-coloured in ln-overrides.css with the warning-text role, the gate prints OVERRIDDEN for it and passes (WR-06)', () => {
  const css = readFileSync(overridesCss, 'utf8');
  assert.match(css, /\.localnodes-import-alert\.localnodes-import-alert\s*\{[^}]*border-left-color:\s*var\(--ln-color-warning-text\)/);
  const r = run([]);
  assert.equal(r.code, 0, r.out);
  const l = lines(r.out, 'OVERRIDDEN ').find((x) => x.includes('.localnodes-import-alert '));
  assert.ok(l, r.out);
  assert.match(l, /^OVERRIDDEN import\.css \.localnodes-import-alert border-left: asks --ln-color-highlight, renders --ln-color-warning-text \(ln-overrides\.css \.localnodes-import-alert\.localnodes-import-alert\)/);
  const ok = lines(r.out, 'OK P13.import.border.drupal-css.--ln-color-warning-text.on.');
  assert.equal(ok.length, 2, ok.join('\n'));
  assert.equal(lines(r.out, 'FAIL ').length, 0, r.out);
});
