#!/usr/bin/env node
/**
 * Derives the socialblue contrast pairings from socialblue's own stylesheets
 * (22.1-05, DES-01, D-09, T-22.1-15), so no pair is hand-picked.
 *
 * socialblue paints the 15 colour-scheme fields (color/color.inc) into three
 * compiled stylesheets: assets/css/brand.css, brand--sky.css and tokens.css.
 * drupal/color later swaps the stock hexes for the palette. This script reads
 * the stock files, maps each stock hex to its field (the 15 stock values are
 * distinct), and for every rule records which field is drawn on which:
 *
 *   P1  a rule sets a field as `color`/`fill` AND a field as `background`:
 *       fg field on bg field.
 *   P2  a rule sets a field as `color`/`fill` and no background: the field on
 *       the surface it lands on (see SURFACES below).
 *   P3  a rule sets a field as `background` and no text colour: the text that
 *       sits on it (white for a primary or secondary fill, the paired text
 *       field for a navigation or accent fill) or, for a pure fill (a
 *       ::before bar, a progress or checkbox fill), the fill against its
 *       surface at the non-text bar.
 *
 * The result is committed as scripts/tokens/socialblue-pairings.json so CI,
 * where Open Social's contrib files are not installed, still checks every
 * pair. check_contrast.mjs (via pairings.mjs) reads that file.
 *
 * The stock sources are also committed, as test fixtures under
 * scripts/tokens/fixtures/socialblue-<version>/ (color.inc and the three
 * stylesheets, Open Social's GPL-2.0-or-later; SOURCES.md names version, paths
 * and licence). That is what lets --check run in CI (WR-02): it derives from the
 * installed theme when there is one and from the fixtures when there isn't.
 *
 * Usage:
 *   node scripts/tokens/derive_socialblue_pairings.mjs                 write the JSON
 *   node scripts/tokens/derive_socialblue_pairings.mjs --check         compare, exit 1 on a difference
 *   node scripts/tokens/derive_socialblue_pairings.mjs --refresh-fixtures
 *                                                                      write the JSON and refresh the fixtures
 * Options:
 *   --socialblue <dir>     the socialblue theme directory (default: the repo's
 *                          html/profiles/contrib/social/themes/socialblue)
 *   --json <file>          the pairings file (default: scripts/tokens/socialblue-pairings.json)
 *   --fixtures-root <dir>  where socialblue-<version>/ lives (default: scripts/tokens/fixtures)
 *   --refresh-fixtures     with a write: copy the installed theme's four source files into
 *                          <fixtures-root>/socialblue-<version>/, write SOURCES.md, and remove any
 *                          other socialblue-* directory there
 * --check derives from the installed theme, or from the fixtures when the theme is absent (CI), and prints
 * `OK ...` or `DIFF ...`. When both exist it first requires the installed files to equal the fixtures and
 * fails with a "refresh the fixtures" message if Open Social moved. With neither it fails (exit 1): a check
 * that passes on nothing is no check. Exit 0 ok, 1 differs, 2 bad usage or no sources to write from.
 * Node built-ins only.
 *
 * Phase 22.2's Open Social upgrade reruns this script with --refresh-fixtures, and commits the fixtures in the
 * same change.
 */
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, resolve as resolvePath } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolvePath(here, '../..');

export const THEME_REL = 'html/profiles/contrib/social/themes/socialblue';
export const JSON_REL = 'scripts/tokens/socialblue-pairings.json';
export const GENERATOR_REL = 'scripts/tokens/derive_socialblue_pairings.mjs';
export const CSS_FILES = ['assets/css/brand.css', 'assets/css/brand--sky.css', 'assets/css/tokens.css'];
export const COLOR_INC = 'color/color.inc';
export const FIXTURES_REL = 'scripts/tokens/fixtures';
export const FIXTURES_PREFIX = 'socialblue-';

/** Where a foreground with no background of its own lands, by selector. */
const LIGHT_SURFACES = ['semantic.surface', 'semantic.canvas', 'semantic.tint'];
const field = (name) => `targets.socialblue.${name}`;
const WHITE_TEXT = 'semantic.on-primary';

/** Selectors that sit on socialblue's dark main navigation bar. */
const ON_NAVBAR = /\.navbar-default|\.header-top|\.navbar-collapse|\.search-take-over|\.social-gtranslate|#skip-link|\.navbar-nav__icon|\.navbar-toggle|\.navbar__open-search-block|\.ai-deepchat--header/;
/** Selectors whose background is the plain white page (the sky tabs and the "more" list). */
const ON_WHITE = /\.hidden-list|\.socialblue--sky[^,]*\.navbar-secondary/;
/** A fill with no text of its own: a pseudo-element bar, a progress or checkbox fill, an icon disc. */
const PURE_FILL = /::?(before|after)\b|\.dz-upload|\.foreground|\[type=|\.teaser__image|\.icon\b|\.marker-cluster|\.spinner|\.checkmark/;

/** The navigation fields; every other field is a brand colour. */
const CHROME = /^(navbar|second-line)-/;

const isHex = (v) => /^#[0-9a-f]{6}$/.test(v);
const normHex = (h) => {
  const x = h.toLowerCase();
  return x.length === 4 ? '#' + [...x.slice(1)].map((c) => c + c).join('') : x;
};

/** The stock scheme from color.inc: { fieldName: '#hex' } and { '#hex': fieldName }. */
export function parseStockScheme(colorInc) {
  const block = colorInc.match(/'default'\s*=>\s*\[[\s\S]*?'colors'\s*=>\s*\[([\s\S]*?)\]/);
  if (!block) throw new Error('color.inc: no default scheme found');
  const byField = {};
  const byHex = {};
  for (const [, name, hex] of block[1].matchAll(/'([a-z0-9-]+)'\s*=>\s*'(#[0-9a-fA-F]{3,6})'/g)) {
    const h = normHex(hex);
    if (!isHex(h)) throw new Error(`color.inc: bad hex ${hex} for ${name}`);
    if (byHex[h]) throw new Error(`color.inc: ${h} is used by both ${byHex[h]} and ${name} (drupal/color can't tell them apart)`);
    byField[name] = h;
    byHex[h] = name;
  }
  if (Object.keys(byField).length !== 15) throw new Error(`color.inc: expected 15 fields, found ${Object.keys(byField).length}`);
  return { byField, byHex };
}

/** Flat list of { selector, body } for every rule, at any @media depth. Comments dropped. */
export function parseRules(css) {
  const out = [];
  const stack = [];
  let buf = '';
  for (const ch of css.replace(/\/\*[\s\S]*?\*\//g, '')) {
    if (ch === '{') {
      stack.push(buf.trim());
      buf = '';
    } else if (ch === '}') {
      const selector = stack.pop();
      if (selector !== undefined && buf.trim() && !selector.startsWith('@')) out.push({ selector, body: buf });
      buf = '';
    } else {
      buf += ch;
    }
  }
  return out;
}

/** Top-level comma split of a selector list. */
const splitSelectors = (list) => list.split(',').map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean);

/** { fg: Map<field, 'color'|'fill'>, bg: Set<field> } for the scheme hexes a rule body uses. */
function usesIn(body, byHex) {
  const fg = new Map();
  const bg = new Set();
  for (const decl of body.split(';')) {
    const at = decl.indexOf(':');
    if (at < 0) continue;
    const prop = decl.slice(0, at).trim().toLowerCase();
    const isFg = prop === 'color' || prop === 'fill';
    const isBg = prop === 'background' || prop === 'background-color';
    if (!isFg && !isBg) continue;
    for (const m of decl.slice(at + 1).matchAll(/#[0-9a-fA-F]{3,6}\b/g)) {
      const name = byHex[normHex(m[0])];
      if (!name) continue;
      if (isBg) bg.add(name);
      else if (prop === 'color' || !fg.has(name)) fg.set(name, prop);
    }
  }
  return { fg, bg };
}

const shortPath = (p) => p.replace(/^(semantic|targets)\./, '');
const pairId = (family, fg, bg) => `${family}.${shortPath(fg)}.on.${shortPath(bg)}`;

/**
 * Pairings from the stock stylesheets.
 *   scheme  from parseStockScheme()
 *   files   [{ name, css }] in reading order
 * Returns entries sorted by id: { id, family, fg, bg, kind, rules, example }.
 */
export function derivePairings(scheme, files) {
  const { byHex } = scheme;
  const rules = [];
  for (const f of files) {
    for (const r of parseRules(f.css)) {
      const uses = usesIn(r.body, byHex);
      if (uses.fg.size || uses.bg.size) rules.push({ ...r, ...uses, file: f.name });
    }
  }

  const entries = new Map();
  const add = (family, fg, bg, kind, rule, selector, property) => {
    const key = `${fg}|${bg}`;
    const hit = entries.get(key);
    if (hit) {
      hit.rules += 1;
      if (kind === 'text') hit.kind = 'text';
      return;
    }
    entries.set(key, {
      id: pairId(family, fg, bg),
      family,
      fg,
      bg,
      kind,
      rules: 1,
      example: { selector: selector.slice(0, 120), property, file: rule.file },
    });
  };

  // P1: a rule that sets both a foreground field and a background field.
  const partnersOfFg = new Map(); // field used as fg over a field bg -> Set of bg fields
  const partnersOfBg = new Map();
  for (const r of rules) {
    if (!r.fg.size || !r.bg.size) continue;
    for (const [fg, prop] of r.fg) {
      for (const bg of r.bg) {
        if (fg === bg) continue;
        add('P1', field(fg), field(bg), prop === 'color' ? 'text' : 'non-text', r, splitSelectors(r.selector)[0], prop);
        if (!partnersOfFg.has(fg)) partnersOfFg.set(fg, new Set());
        partnersOfFg.get(fg).add(bg);
        if (!partnersOfBg.has(bg)) partnersOfBg.set(bg, new Set());
        partnersOfBg.get(bg).add(fg);
      }
    }
  }

  const seen = (r, key) => (r.seen ??= new Set()).has(key) || (r.seen.add(key), false);

  // P2: a foreground with no background in its rule lands on a surface.
  for (const r of rules) {
    if (!r.fg.size || r.bg.size) continue;
    for (const selector of splitSelectors(r.selector)) {
      for (const [fg, prop] of r.fg) {
        const kind = prop === 'color' ? 'text' : 'non-text';
        let bgs;
        if (partnersOfFg.has(fg)) bgs = [...partnersOfFg.get(fg)].sort().map(field); // a navigation text field: its own bar
        else if (ON_WHITE.test(selector)) bgs = ['semantic.surface'];
        else if (ON_NAVBAR.test(selector)) bgs = [field('navbar-bg')];
        else bgs = LIGHT_SURFACES;
        for (const bg of bgs) {
          if (seen(r, `P2|${field(fg)}|${bg}`)) continue;
          add('P2', field(fg), bg, kind, r, selector, prop);
        }
      }
    }
  }

  // P3: a background with no text colour in its rule.
  for (const r of rules) {
    if (r.fg.size || !r.bg.size) continue;
    for (const selector of splitSelectors(r.selector)) {
      for (const bg of r.bg) {
        const pure = PURE_FILL.test(selector);
        let pairs; // [fg path, bg path, kind]
        if (partnersOfFg.has(bg)) {
          // The field is itself a text colour (a spinner dot): a graphic on its own bar.
          pairs = [...partnersOfFg.get(bg)].sort().map((p) => [field(bg), field(p), 'non-text']);
        } else if (pure && !CHROME.test(bg)) {
          // A bar, disc or progress fill: the fill itself against the surface it sits on (SC 1.4.11).
          const surfaces = ON_WHITE.test(selector) ? ['semantic.surface'] : ON_NAVBAR.test(selector) ? [field('navbar-bg')] : LIGHT_SURFACES;
          pairs = surfaces.map((s) => [field(bg), s, 'non-text']);
        } else if (partnersOfBg.has(bg)) {
          // A navigation or accent fill: the text field paired with it in P1 sits on it.
          pairs = [...partnersOfBg.get(bg)].sort().map((p) => [field(p), field(bg), 'text']);
        } else {
          pairs = [[WHITE_TEXT, field(bg), 'text']]; // socialbase paints white text on a brand fill
        }
        for (const [fg, bgPath, kind] of pairs) {
          if (seen(r, `P3|${fg}|${bgPath}`)) continue;
          add('P3', fg, bgPath, kind, r, selector, 'background');
        }
      }
    }
  }

  return [...entries.values()].sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
}

/** The text of the committed JSON for the given sources. */
export function render({ scheme, files, version }) {
  const doc = {
    $generated:
      `GENERATED by ${GENERATOR_REL} from ${COLOR_INC}, ${CSS_FILES.join(', ')} under ${THEME_REL} (Open Social ${version}). ` +
      `GENERATED, do not edit by hand: rerun the script (Phase 22.2's Open Social upgrade does). Check with: node ${GENERATOR_REL} --check`,
    openSocialVersion: version,
    stockScheme: Object.fromEntries(Object.entries(scheme.byField)),
    pairings: derivePairings(scheme, files),
  };
  return JSON.stringify(doc, null, 2) + '\n';
}

/**
 * Reads a theme directory (or a fixtures directory, same layout); null when the stylesheets are not there.
 * The version comes from `knownVersion` when given (fixtures: the directory name), else from the profile's
 * social.info.yml two levels above the theme.
 */
export function readTheme(themeDir, knownVersion) {
  const incPath = resolvePath(themeDir, COLOR_INC);
  const cssPaths = CSS_FILES.map((f) => resolvePath(themeDir, f));
  if (![incPath, ...cssPaths].every((p) => existsSync(p))) return null;
  const info = resolvePath(themeDir, '../../social.info.yml');
  const version = knownVersion ?? (existsSync(info) ? (readFileSync(info, 'utf8').match(/^version:\s*'?([^'\s]+)'?/m)?.[1] ?? 'unknown') : 'unknown');
  const inc = readFileSync(incPath, 'utf8');
  return {
    scheme: parseStockScheme(inc),
    files: CSS_FILES.map((f, i) => ({ name: f.split('/').pop(), css: readFileSync(cssPaths[i], 'utf8') })),
    version,
    dir: themeDir,
    // Every source file by its path under the theme, for the byte comparison of installed against fixtures.
    sources: Object.fromEntries([COLOR_INC, ...CSS_FILES].map((f) => [f, f === COLOR_INC ? inc : readFileSync(resolvePath(themeDir, f), 'utf8')])),
  };
}

/** The fixtures under `root`: { dir, version } for the one socialblue-<version> directory, null for none. Throws on several. */
export function findFixtures(root) {
  if (!existsSync(root)) return null;
  const hits = readdirSync(root).filter((n) => n.startsWith(FIXTURES_PREFIX) && statSync(resolvePath(root, n)).isDirectory());
  if (hits.length === 0) return null;
  if (hits.length > 1) throw new Error(`more than one ${FIXTURES_PREFIX}* directory under ${root} (${hits.join(', ')}): keep only the one for the Open Social in use`);
  return { dir: resolvePath(root, hits[0]), version: hits[0].slice(FIXTURES_PREFIX.length) };
}

/** The SOURCES.md that sits in the fixtures directory. */
export function renderSources(version) {
  const files = [COLOR_INC, ...CSS_FILES];
  return [
    `# socialblue stock sources (Open Social ${version})`,
    '',
    `Test fixtures for \`${GENERATOR_REL} --check\` (WR-02). Open Social is not installed in CI, so \`--check\``,
    'derives the contrast pairings from these four files when the theme is absent. When Open Social is installed',
    '--check also requires its copy to equal these files byte for byte.',
    '',
    `- Version: Open Social ${version} (drupal/social).`,
    '- Licence: GPL-2.0-or-later, the same as the rest of this repository.',
    '- Copied unchanged from:',
    ...files.map((f) => `  - \`${THEME_REL}/${f}\` -> \`${f}\``),
    '',
    '## Refreshing',
    '',
    `When Open Social moves (Phase 22.2), refresh the fixtures in the same change: \`node ${GENERATOR_REL} --refresh-fixtures\``,
    'with the new Open Social installed. It rewrites socialblue-pairings.json, replaces this directory with',
    `${FIXTURES_PREFIX}<new version>/ and rewrites this file. Commit all three.`,
    '',
  ].join('\n');
}

/** Copies the installed theme's source files into <root>/socialblue-<version>/ and drops other socialblue-* directories. */
export function writeFixtures(theme, root) {
  const target = resolvePath(root, `${FIXTURES_PREFIX}${theme.version}`);
  mkdirSync(root, { recursive: true });
  for (const n of readdirSync(root)) {
    if (n.startsWith(FIXTURES_PREFIX) && resolvePath(root, n) !== target) rmSync(resolvePath(root, n), { recursive: true, force: true });
  }
  rmSync(target, { recursive: true, force: true });
  for (const [rel, text] of Object.entries(theme.sources)) {
    mkdirSync(dirname(resolvePath(target, rel)), { recursive: true });
    writeFileSync(resolvePath(target, rel), text);
  }
  writeFileSync(resolvePath(target, 'SOURCES.md'), renderSources(theme.version));
  return target;
}

function parseArgs(argv) {
  const opts = {
    check: false,
    refreshFixtures: false,
    theme: resolvePath(repoRoot, THEME_REL),
    json: resolvePath(repoRoot, JSON_REL),
    fixturesRoot: resolvePath(repoRoot, FIXTURES_REL),
  };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--check') opts.check = true;
    else if (argv[i] === '--refresh-fixtures') opts.refreshFixtures = true;
    else if (argv[i] === '--fixtures-root' && argv[i + 1] !== undefined) opts.fixturesRoot = resolvePath(process.cwd(), argv[++i]);
    else if (argv[i] === '--socialblue' && argv[i + 1] !== undefined) opts.theme = resolvePath(process.cwd(), argv[++i]);
    else if (argv[i] === '--json' && argv[i + 1] !== undefined) opts.json = resolvePath(process.cwd(), argv[++i]);
    else {
      console.error(`usage: derive_socialblue_pairings.mjs [--check] [--refresh-fixtures] [--socialblue <dir>] [--json <file>] [--fixtures-root <dir>] (bad argument: ${argv[i]})`);
      process.exit(2);
    }
  }
  return opts;
}

/** Source files whose text differs between two read themes. */
const differingSources = (a, b) => Object.keys(a.sources).filter((f) => a.sources[f] !== b.sources[f]);

function main() {
  const opts = parseArgs(process.argv.slice(2));
  const installed = readTheme(opts.theme);
  if (opts.refreshFixtures && opts.check) {
    console.error('--refresh-fixtures writes; it cannot be combined with --check');
    process.exit(2);
  }
  if (opts.check) return check(opts, installed);
  if (!installed) {
    console.error(`socialblue stylesheets not found under ${opts.theme} (install Open Social, or pass --socialblue <dir>)`);
    process.exit(2);
  }
  const want = render(installed);
  writeFileSync(opts.json, want);
  console.log(`wrote ${opts.json} (${JSON.parse(want).pairings.length} pairings, Open Social ${installed.version})`);
  if (opts.refreshFixtures) console.log(`refreshed fixtures: ${writeFixtures(installed, opts.fixturesRoot)}`);
}

function check(opts, installed) {
  let fixtureDir = null;
  try {
    fixtureDir = findFixtures(opts.fixturesRoot);
  } catch (e) {
    console.error(e.message);
    process.exit(2);
  }
  const fixtures = fixtureDir ? readTheme(fixtureDir.dir, fixtureDir.version) : null;
  if (!installed && !fixtures) {
    console.log(`FAIL no socialblue sources: neither the installed theme (${opts.theme}) nor fixtures (${opts.fixturesRoot}/${FIXTURES_PREFIX}<version>) are there, so nothing can be checked`);
    process.exitCode = 1;
    return;
  }
  if (installed && fixtures) {
    const moved = differingSources(installed, fixtures);
    if (installed.version !== fixtures.version || moved.length) {
      const what = [installed.version !== fixtures.version ? `version ${fixtures.version} (fixtures) vs ${installed.version} (installed)` : '', moved.length ? moved.join(', ') : ''].filter(Boolean).join('; ');
      console.log(`STALE the socialblue fixtures differ from the installed socialblue ${installed.version} (${what}): Open Social moved, so refresh the fixtures and commit them with the pairings: node ${GENERATOR_REL} --refresh-fixtures`);
      process.exitCode = 1;
      return;
    }
  }
  const source = installed ?? fixtures;
  const label = installed ? `socialblue ${installed.version}` : `the committed fixtures (socialblue ${fixtures.version}; the theme is not installed)`;
  const want = render(source);
  const have = existsSync(opts.json) ? readFileSync(opts.json, 'utf8') : null;
  if (have === want) {
    console.log(`OK ${JSON_REL} matches ${label}`);
    return;
  }
  console.log(`${have === null ? 'MISSING' : 'DIFF'} ${JSON_REL} is not what ${GENERATOR_REL} derives from ${label} (rerun it)`);
  // process.exitCode, not process.exit(): exit() drops output still queued on a slow pipe (WINDOWS 51).
  process.exitCode = 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolvePath(process.argv[1])).href) main();
