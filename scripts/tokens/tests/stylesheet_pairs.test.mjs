/**
 * Tests for scripts/tokens/lib/stylesheet_pairs.mjs (22.1-17, review WR-06, DES-01, D-09).
 *
 * The library reads the five stylesheets that draw --ln-color-* (ln-overrides.css, localnodes_import's import.css,
 * social_ai_indexing's ai-overview.css and deepchat.css, and localnodes_web3's treasury.css for the group fund and signin.css for the sign-in button), a
 * partner map (which background sits behind which
 * foreground, per single selector and per property) and gives the contrast pairings the stylesheets draw, measured
 * against the colour the browser renders.
 *
 * Every test builds its stylesheet as a fixture under its own mkdtemp prefix (removed in `after`) and passes its own
 * partner map, so it depends on nothing in the repo's real stylesheets. The repo-wide tests at the end read the real
 * ones.
 *
 * Run: node --test scripts/tokens/tests/*.test.mjs
 */
import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { loadTokens, resolve as resolveSource } from '../lib/tokens.mjs';
import * as leaf from '../lib/pairing.mjs';
import { ACCENT_TEXT_RULES, THRESHOLDS, declaredPairings, pairing } from '../pairings.mjs';
import { PARTNERS, STYLESHEETS, stylesheetFiles, stylesheetPairings } from '../lib/stylesheet_pairs.mjs';
import { testTmpDir } from './helpers/tmp_dir.mjs';

const dir = testTmpDir('stylesheet-pairs');
after(() => rmSync(dir, { recursive: true, force: true }));

const TREE = loadTokens(null);
let seq = 0;
const css = (text) => {
  const file = join(dir, `fixture-${++seq}.css`);
  writeFileSync(file, text);
  return file;
};

const ids = (r) => r.pairs.map((p) => p.id);
const ln = (name) => `drupal-css.--ln-color-${name}`;
const WHY = 'the fixture says so';

test('the pairing helpers live in the leaf module; pairings.mjs re-exports THRESHOLDS and pairing unchanged', () => {
  assert.equal(THRESHOLDS, leaf.THRESHOLDS);
  assert.equal(pairing, leaf.pairing);
  for (const name of ['short', 'ln', 'scheme', 'normSelector']) assert.equal(typeof leaf[name], 'function', `lib/pairing.mjs exports ${name}`);
  assert.equal(leaf.ln('text'), 'targets.drupal-css.--ln-color-text');
  assert.equal(leaf.scheme('navbar-bg'), 'targets.socialblue.navbar-bg');
});

test('a selector list is split into single selectors, each with its own partner (review 17-M2)', () => {
  const file = css('.a, .b { color: var(--ln-color-text); }');
  const r = stylesheetPairings({
    files: { import: file },
    tree: TREE,
    partners: {
      import: {
        '.a': { text: { on: ['surface'], why: WHY } },
        '.b': { text: { on: ['canvas'], why: WHY } },
      },
    },
  });
  const onSurface = r.pairs.find((p) => p.id === `P13.import.text.${ln('text')}.on.${ln('surface')}`);
  const onCanvas = r.pairs.find((p) => p.id === `P13.import.text.${ln('text')}.on.${ln('canvas')}`);
  assert.ok(onSurface, `the .a pair exists: ${ids(r)}`);
  assert.ok(onCanvas, `the .b pair exists: ${ids(r)}`);
  assert.match(onSurface.source, /\.a\b/);
  assert.doesNotMatch(onSurface.source, /\.b\b/, 'the surface pair is .a only');
  assert.match(onCanvas.source, /\.b\b/);
  assert.equal(onSurface.min, THRESHOLDS.text);
  assert.equal(r.pairs.length, 2);
});

test('a border is measured on the surroundings the map names, never on the rule own fill', () => {
  const file = css('.c { border: 1px solid var(--ln-color-danger); background: var(--ln-color-danger-bg); }');
  const r = stylesheetPairings({
    files: { import: file },
    tree: TREE,
    partners: { import: { '.c': { border: { against: ['surface'], why: WHY } } } },
  });
  assert.deepEqual(ids(r), [`P13.import.border.${ln('danger')}.on.${ln('surface')}`]);
  assert.equal(r.pairs[0].kind, 'non-text');
  assert.equal(r.pairs[0].min, THRESHOLDS['non-text']);
  assert.ok(!r.pairs.some((p) => p.bg.endsWith('danger-bg')), 'no pair against the rule own fill');
});

const GLOBAL_RING = `
:focus-visible { outline: 2px solid var(--ln-color-focus) !important; outline-offset: 2px !important; }
.d:focus-visible { outline-offset: -4px !important; }
`;

test('the ring is measured on the element own fill when the cascade renders it inset, and on the surroundings when outside', () => {
  const overrides = css(GLOBAL_RING);
  const file = css(`
.d:focus-visible { outline: 2px solid var(--ln-color-focus); outline-offset: -4px; }
.e:focus-visible { outline: 2px solid var(--ln-color-focus); outline-offset: -4px; }
`);
  const ring = { outline: { fill: ['tint'], surroundings: ['surface'], why: WHY } };
  const r = stylesheetPairings({
    files: { overrides, import: file },
    tree: TREE,
    partners: { import: { '.d:focus-visible': ring, '.e:focus-visible': ring } },
  });
  const forD = r.pairs.find((p) => p.source.includes('.d:focus-visible'));
  const forE = r.pairs.find((p) => p.source.includes('.e:focus-visible'));
  // .d: the !important override renders offset -4px (inset), so the ring sits on the fill. .e: the module asks -4px,
  // but the global !important 2px offset wins, so the ring is outside, on the surroundings.
  assert.equal(forD.bg, `targets.${ln('tint')}`, 'inset: measured on the fill');
  assert.equal(forE.bg, `targets.${ln('surface')}`, 'outside: measured on the surroundings');
  assert.equal(forD.kind, 'focus');
  assert.equal(forD.min, THRESHOLDS.focus);
});

test('a module outline that the !important ring replaces prints OVERRIDDEN, and the ring that wins is measured', () => {
  const overrides = css(GLOBAL_RING);
  const file = css(`
.d:focus-visible { outline: 2px solid var(--ln-color-focus-on-dark); outline-offset: -4px; }
.n:focus-visible { outline: 2px solid var(--ln-color-focus); outline-offset: 2px; }
`);
  const partner = { outline: { fill: ['surface'], surroundings: ['canvas'], why: WHY } };
  const r = stylesheetPairings({
    files: { overrides, import: file },
    tree: TREE,
    partners: { import: { '.d:focus-visible': partner, '.n:focus-visible': partner } },
  });
  assert.equal(r.overridden.length, 1, 'only .d is overridden: .n asks for the colour that wins');
  const o = r.overridden[0];
  assert.equal(o.sheet, 'import');
  assert.equal(o.selector, '.d:focus-visible');
  assert.equal(o.property, 'outline');
  assert.equal(o.asks, 'focus-on-dark');
  assert.equal(o.renders, 'focus');
  assert.match(o.winner, /:focus-visible/);
  const forD = r.pairs.find((p) => p.source.includes('.d:focus-visible'));
  assert.equal(forD.fg, `targets.${ln('focus')}`, 'the ring the browser renders is measured, not the one asked for');
  assert.equal(forD.bg, `targets.${ln('surface')}`);
});

test('a ring rule in ln-overrides.css itself is what renders: it is measured, not overridden', () => {
  const overrides = css(`
:focus-visible { outline: 2px solid var(--ln-color-focus) !important; outline-offset: 2px !important; }
.bar .ctl:focus-visible { outline-color: var(--ln-color-focus-on-dark) !important; outline-offset: -4px !important; }
`);
  const module = css('.bar .ctl:focus-visible { outline: 2px solid var(--ln-color-focus-on-dark); outline-offset: -4px; }');
  const r = stylesheetPairings({
    files: { overrides, deepchat: module },
    tree: TREE,
    partners: {
      overrides: { '.bar .ctl:focus-visible': { outline: { fill: ['text'], surroundings: ['canvas'], why: WHY } } },
      deepchat: { '.bar .ctl:focus-visible': { outline: { fill: ['text'], surroundings: ['canvas'], why: WHY } } },
    },
  });
  assert.deepEqual(r.overridden, [], 'the module asks for the ring the override renders');
  assert.ok(ids(r).includes(`P13.overrides.focus.${ln('focus-on-dark')}.on.${ln('text')}`), ids(r));
  assert.ok(ids(r).includes(`P13.deepchat.focus.${ln('focus-on-dark')}.on.${ln('text')}`), ids(r));
});

test('a text pair names the background of an ancestor rule through { from, bg }; a socialblue background is a full token path', () => {
  const file = css(`
.bar { background: var(--ln-color-text); }
.bar .name { color: var(--ln-color-on-primary); }
.head .title { color: var(--ln-color-on-primary); }
`);
  const r = stylesheetPairings({
    files: { overrides: file },
    tree: TREE,
    partners: {
      overrides: {
        '.bar .name': { text: { on: [{ from: '.bar', bg: 'text' }], why: WHY } },
        '.head .title': { text: { on: ['targets.socialblue.navbar-bg'], why: WHY } },
      },
    },
  });
  assert.ok(ids(r).includes(`P13.overrides.text.${ln('on-primary')}.on.${ln('text')}`), ids(r));
  assert.ok(ids(r).includes('P13.overrides.text.drupal-css.--ln-color-on-primary.on.socialblue.navbar-bg'), ids(r));
});

test('with a partner-map entry removed, its pair is gone', () => {
  const file = css('.a { color: var(--ln-color-text); }\n.b { color: var(--ln-color-text-muted); }');
  const full = {
    import: {
      '.a': { text: { on: ['surface'], why: WHY } },
      '.b': { text: { on: ['surface'], why: WHY } },
    },
  };
  const withBoth = stylesheetPairings({ files: { import: file }, tree: TREE, partners: full });
  assert.equal(withBoth.pairs.length, 2);
  const { '.b': removed, ...rest } = full.import;
  assert.ok(removed);
  const without = stylesheetPairings({ files: { import: file }, tree: TREE, partners: { import: rest } });
  assert.deepEqual(ids(without), [`P13.import.text.${ln('text')}.on.${ln('surface')}`], 'the .b pair is gone with its entry');
});

// ---------------------------------------------------------------------------
// Task 2: every --ln-color declaration is accounted for, per selector and property (review 17-M1, 17-M2; T-22.1-56/58)
// ---------------------------------------------------------------------------

const lib = (css_, partners, extra = {}) => stylesheetPairings({ files: { import: css(css_) }, tree: TREE, partners: { import: partners }, ...extra });

test('a colour-only selector that the map does not name is UNMAPPED, with its stylesheet, selector, property and role', () => {
  const r = lib('.lone { color: var(--ln-color-text); }', {});
  assert.deepEqual(r.unmapped, [{ sheet: 'import', selector: '.lone', property: 'color', role: 'text' }]);
  assert.deepEqual(r.pairs, []);
});

test('in a selector list, the member the map does not name is UNMAPPED while its sibling is measured (review 17-M2)', () => {
  const r = lib('.a, .b { color: var(--ln-color-text); }', { '.a': { text: { on: ['surface'], why: WHY } } });
  assert.equal(r.pairs.length, 1);
  assert.deepEqual(r.unmapped.map((u) => u.selector), ['.b']);
});

test('a background-only rule is UNMAPPED until it names the foregrounds drawn on it or its boundary (review 17-M1)', () => {
  const bare = lib('.fill { background: var(--ln-color-primary); }', {});
  assert.deepEqual(bare.unmapped.map((u) => [u.selector, u.property, u.role]), [['.fill', 'background', 'primary']]);

  const under = lib('.fill { background: var(--ln-color-primary); }', { '.fill': { background: { under: ['on-primary'], why: WHY } } });
  assert.deepEqual(under.unmapped, []);
  assert.deepEqual(ids(under), [`P13.import.fill.${ln('on-primary')}.on.${ln('primary')}`]);
  assert.equal(under.pairs[0].kind, 'text');

  const boundary = lib('.fill { background: var(--ln-color-primary); }', { '.fill': { background: { boundary: ['surface', 'canvas'], why: WHY } } });
  assert.deepEqual(boundary.unmapped, []);
  assert.deepEqual(ids(boundary), [`P13.import.fill.${ln('primary')}.on.${ln('surface')}`, `P13.import.fill.${ln('primary')}.on.${ln('canvas')}`]);
  assert.ok(boundary.pairs.every((p) => p.kind === 'non-text'));
});

test('a role that its token marks decorative prints DECORATIVE with the reason; it is neither a pair nor UNMAPPED', () => {
  const r = lib('.rule { border-top: 1px solid var(--ln-color-divider); }\n.sk { background: var(--ln-color-divider); }', {});
  assert.deepEqual(r.pairs, []);
  assert.deepEqual(r.unmapped, []);
  assert.deepEqual(r.decorative.map((d) => [d.selector, d.property, d.role]), [['.rule', 'border-top', 'divider'], ['.sk', 'background', 'divider']]);
  assert.match(r.decorative[0].reason, /Decorative separators only/);
});

test('"own" takes the rule own fill, and a text rule on its own fill accounts for the fill declaration too', () => {
  const r = lib('.badge { color: var(--ln-color-danger); background: var(--ln-color-danger-bg); }', { '.badge': { text: { on: ['own'], why: WHY } } });
  assert.deepEqual(r.unmapped, [], 'the background is accounted for by the text drawn on it');
  assert.deepEqual(ids(r), [`P13.import.text.${ln('danger')}.on.${ln('danger-bg')}`]);
});

test('"own" on a rule with no fill of its own is a stale entry, not a guess', () => {
  const r = lib('.badge { color: var(--ln-color-danger); }', { '.badge': { text: { on: ['own'], why: WHY } } });
  assert.equal(r.stale.length, 1);
  assert.match(r.stale[0].message, /no background/);
});

test('a map entry for a selector that is no longer in its stylesheet, or for a property it no longer sets, is a stale entry naming it', () => {
  const r = lib('.here { color: var(--ln-color-text); }', {
    '.here': { text: { on: ['surface'], why: WHY }, border: { against: ['surface'], why: WHY } },
    '.gone': { text: { on: ['surface'], why: WHY } },
  });
  const by = Object.fromEntries(r.stale.map((s) => [`${s.selector}|${s.kind}`, s.message]));
  assert.match(by['.gone|text'], /not in import\.css/);
  assert.match(by['.here|border'], /no border declaration/);
  assert.equal(r.stale.length, 2);
});

test('17-M1: an ancestor rule whose background no longer is the one the map says is a stale partner, and the pair is measured on what the CSS draws', () => {
  const same = lib('.bar { background: var(--ln-color-text); }\n.bar .name { color: var(--ln-color-on-primary); }', {
    '.bar': { background: { under: ['on-primary'], why: WHY } },
    '.bar .name': { text: { on: [{ from: '.bar', bg: 'text' }], why: WHY } },
  });
  assert.deepEqual(same.stale, []);

  const moved = lib('.bar { background: var(--ln-color-surface); }\n.bar .name { color: var(--ln-color-on-primary); }', {
    '.bar': { background: { under: ['on-primary'], why: WHY } },
    '.bar .name': { text: { on: [{ from: '.bar', bg: 'text' }], why: WHY } },
  });
  assert.equal(moved.stale.length, 1, JSON.stringify(moved.stale));
  assert.equal(moved.stale[0].selector, '.bar .name');
  assert.match(moved.stale[0].message, /map says --ln-color-text, but \.bar sets --ln-color-surface in import\.css/);
  assert.ok(ids(moved).includes(`P13.import.text.${ln('on-primary')}.on.${ln('surface')}`), 'the pair follows the CSS');
  assert.ok(!ids(moved).includes(`P13.import.text.${ln('on-primary')}.on.${ln('text')}`), 'and not the map');

  const missing = lib('.bar .name { color: var(--ln-color-on-primary); }', { '.bar .name': { text: { on: [{ from: '.bar', bg: 'text' }], why: WHY } } });
  assert.match(missing.stale[0].message, /\.bar.* no background/);
});

test('the nine accent rules are measured by P2: they need no map entry, and a claim names the P2 pairs', () => {
  const accent = [{ selector: '.x:hover, .x:focus', props: ['color'] }];
  const r = stylesheetPairings({ files: { overrides: css('.x:hover, .x:focus { color: var(--ln-color-text); }') }, tree: TREE, partners: {}, accentRules: accent });
  assert.deepEqual(r.unmapped, []);
  assert.deepEqual(r.pairs, []);
  assert.equal(r.claims.length, 2);
  assert.equal(r.claims[0].sheet, 'overrides');
  assert.deepEqual(r.claims[0].ids, ['P2.socialblue.brand-accent.on.surface', 'P2.socialblue.brand-accent.on.canvas', 'P2.socialblue.brand-accent.on.tint']);
});

test('a measuredBy claim is returned with its ids, for pairings.mjs to check against the pairs that exist', () => {
  const r = lib(':focus-visible { outline: 2px solid var(--ln-color-focus) !important; }', {
    ':focus-visible': { outline: { measuredBy: ['P5.focus.on.surface'], why: WHY } },
  });
  assert.deepEqual(r.unmapped, []);
  assert.deepEqual(r.claims, [{ sheet: 'import', selector: ':focus-visible', kind: 'outline', ids: ['P5.focus.on.surface'] }]);
});

test('every role a stylesheet draws is reported in `drawn`', () => {
  const r = lib('.a { color: var(--ln-color-text); border: 1px solid var(--ln-color-divider); }', { '.a': { text: { on: ['surface'], why: WHY } } });
  assert.deepEqual([...r.drawn].sort(), ['divider', 'text']);
});

// The real stylesheets, against the real map.
const REAL = () => stylesheetPairings({ files: stylesheetFiles(), tree: TREE, accentRules: ACCENT_TEXT_RULES });

test('the repo: every --ln-color declaration in the five stylesheets is a pair, DECORATIVE, OVERRIDDEN or an accent rule; none is UNMAPPED (WR-06)', () => {
  const r = REAL();
  assert.deepEqual(r.unmapped, [], `UNMAPPED:\n${r.unmapped.map((u) => `${u.sheet} ${u.selector} ${u.property} --ln-color-${u.role}`).join('\n')}`);
  assert.deepEqual(r.stale, [], `stale entries:\n${r.stale.map((s) => `${s.sheet} ${s.selector} ${s.kind}: ${s.message}`).join('\n')}`);
});

test('the repo: every partner-map entry names its reason, and the map covers only the five stylesheets', () => {
  assert.deepEqual(Object.keys(PARTNERS).sort(), Object.keys(STYLESHEETS).sort());
  let entries = 0;
  for (const [sheet, bySelector] of Object.entries(PARTNERS)) {
    for (const [selector, kinds] of Object.entries(bySelector)) {
      for (const [kind, entry] of Object.entries(kinds)) {
        entries++;
        assert.ok(typeof entry.why === 'string' && entry.why.trim().length > 15, `${sheet} ${selector} ${kind} has a why`);
      }
    }
  }
  assert.ok(entries > 50, `the map has ${entries} entries`);
});

test('the repo: every measuredBy claim names pairs that exist, and a made-up id is an error naming the entry', () => {
  const pairs = declaredPairings(resolveSource(TREE), { tree: TREE });
  const have = new Set(pairs.map((p) => p.id));
  for (const c of REAL().claims) for (const id of c.ids) assert.ok(have.has(id), `${c.sheet} ${c.selector} ${c.kind} is measured by ${id}, which no pairing has`);
  const lie = { overrides: { ':focus-visible': { outline: { measuredBy: ['P5.no.such.pair.on.surface'], why: WHY } } } };
  assert.throws(() => declaredPairings(resolveSource(TREE), { tree: TREE, partners: lie }), /ln-overrides\.css :focus-visible outline: measuredBy P5\.no\.such\.pair/);
});

test('the repo: the stylesheets under html/modules/custom that draw --ln-color-* are exactly the five the library reads', () => {
  const root = resolve(stylesheetFiles().overrides, '../../../../../..');
  const found = [];
  const walk = (d) => {
    for (const e of readdirSync(d, { withFileTypes: true })) {
      const f = join(d, e.name);
      if (e.isDirectory()) {
        if (e.name !== 'node_modules') walk(f);
      } else if (e.name.endsWith('.css') && e.name !== 'ln-tokens.css' && /var\(--ln-color-/.test(readFileSync(f, 'utf8'))) found.push(relative(root, f));
    }
  };
  walk(join(root, 'html/modules/custom'));
  assert.deepEqual(
    found.sort(),
    Object.values(STYLESHEETS).map((s) => s.rel).sort(),
    'a new stylesheet that reads --ln-color-* must be added to STYLESHEETS in lib/stylesheet_pairs.mjs',
  );
});

// WR-06b (WINDOWS 58, 22.1-15): the group fund's stylesheet was left out of WR-06; it is read and measured now.
test('the repo: treasury.css is read, and its status rules, badges and IDs are measured on what sits behind them (WR-06b)', () => {
  assert.deepEqual(STYLESHEETS.treasury, { rel: 'html/modules/custom/localnodes_platform/modules/localnodes_web3/css/treasury.css', name: 'treasury.css' });
  const ids = new Set(REAL().pairs.map((p) => p.id));
  const id = (kind, fg, bg) => `P13.treasury.${kind}.drupal-css.--ln-color-${fg}.on.drupal-css.--ln-color-${bg}`;
  for (const want of [
    // The left rules of the test-network banner and the status alerts, against the page or card around them.
    id('border', 'warning-text', 'surface'),
    id('border', 'success', 'canvas'),
    id('border', 'danger', 'tint'),
    // The banner's own fill, under the body text ln-overrides.css gives every .alert-warning.
    id('fill', 'text', 'warning-bg'),
    // A short or full ID: on the page or card, and inside the progress panel's alerts.
    id('text', 'text', 'surface'),
    id('text', 'text', 'warning-bg'),
    id('text', 'text', 'success-bg'),
    // Each status badge's text on its own fill, and the neutral badge's border on the page.
    id('text', 'on-highlight', 'highlight'),
    id('text', 'success', 'success-bg'),
    id('text', 'danger', 'danger-bg'),
    id('text', 'text-muted', 'canvas'),
    id('border', 'border-control', 'surface'),
  ]) {
    assert.ok(ids.has(want), `missing ${want}`);
  }
});

test('the repo: signin.css is read, and its "or" line, wallet button and waiting strip are measured on what sits behind them', () => {
  assert.deepEqual(STYLESHEETS.signin, { rel: 'html/modules/custom/localnodes_platform/modules/localnodes_web3/css/signin.css', name: 'signin.css' });
  const r = REAL();
  const mine = r.pairs.filter((p) => p.id.startsWith('P13.signin.'));
  const id = (kind, fg, bg) => `P13.signin.${kind}.drupal-css.--ln-color-${fg}.on.drupal-css.--ln-color-${bg}`;
  assert.deepEqual(
    mine.map((p) => p.id).sort(),
    [
      // The muted "Or use a password" line on each light surface the form page can draw.
      id('text', 'text-muted', 'surface'),
      id('text', 'text-muted', 'canvas'),
      id('text', 'text-muted', 'tint'),
      // "Use a browser wallet", link-coloured, on the same light surfaces.
      id('text', 'link', 'surface'),
      id('text', 'link', 'canvas'),
      id('text', 'link', 'tint'),
      // The waiting line's text on the strip's own surface fill.
      id('text', 'text', 'surface'),
      // The strip's bottom border against the page under it.
      id('border', 'border-control', 'surface'),
      id('border', 'border-control', 'canvas'),
      id('border', 'border-control', 'tint'),
    ].sort(),
  );
  assert.deepEqual(r.unmapped.filter((u) => u.sheet === 'signin'), []);
  assert.deepEqual(r.stale.filter((s) => s.sheet === 'signin'), []);
});

test('a signin.css rule whose colour has no partner is UNMAPPED, and a partner for a removed rule is stale', () => {
  const file = css('.ln-signin .ln-signin__or { color: var(--ln-color-text-muted); }\n.siwe-login-waap__waiting { border-bottom: 1px solid var(--ln-color-border-control); }');
  const r = stylesheetPairings({
    files: { signin: file },
    tree: TREE,
    partners: { signin: { '.ln-signin .ln-signin__or': { text: { on: ['surface'], why: WHY } }, '.ln-signin__gone': { text: { on: ['surface'], why: WHY } } } },
  });
  assert.deepEqual(r.unmapped.map((u) => [u.sheet, u.selector, u.property, u.role]), [['signin', '.siwe-login-waap__waiting', 'border-bottom', 'border-control']]);
  assert.deepEqual(r.stale.map((s) => [s.sheet, s.selector]), [['signin', '.ln-signin__gone']]);
  assert.ok(r.pairs.some((p) => p.source === 'signin.css .ln-signin .ln-signin__or color'));
});

// Q14 (orchestrator, 2026-11-09): a module border that an ln-overrides.css rule re-colours is measured as rendered.
test('a module border that a doubled-class rule in ln-overrides.css re-colours prints OVERRIDDEN, and the colour that renders is measured', () => {
  const overrides = css('.alert.alert { border-left-color: var(--ln-color-warning-text); }');
  const file = css('.alert { border-left: 4px solid var(--ln-color-highlight); }\n.other { border-left: 4px solid var(--ln-color-highlight); }');
  const partner = { border: { against: ['surface'], why: WHY } };
  const r = stylesheetPairings({
    files: { overrides, import: file },
    tree: TREE,
    partners: { import: { '.alert': partner, '.other': partner }, overrides: { '.alert.alert': partner } },
  });
  assert.deepEqual(r.overridden.map((o) => [o.selector, o.property, o.asks, o.renders]), [['.alert', 'border-left', 'highlight', 'warning-text']]);
  assert.match(r.overridden[0].winner, /\.alert\.alert/);
  const forAlert = r.pairs.find((p) => p.source.includes('import.css .alert '));
  assert.equal(forAlert.fg, `targets.${ln('warning-text')}`, 'what renders is measured');
  const forOther = r.pairs.find((p) => p.source.includes('.other'));
  assert.equal(forOther.fg, `targets.${ln('highlight')}`, 'a rule nothing overrides keeps its own colour');
  assert.deepEqual(r.stale, []);
  assert.deepEqual(r.unmapped, []);
});
