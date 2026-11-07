#!/usr/bin/env node
/**
 * Token parity check between the Drupal community site and the Nuxt
 * onboarding app (DES-01, D-03; 22-UI-SPEC Part 1 and UI Considerations row
 * "Token parity between Drupal and Nuxt").
 *
 * Reads the hex values in:
 *   - the socialblue colour scheme   (localnodes_platform config/optional/color.theme.socialblue.yml)
 *   - the Drupal tokens stylesheet   (localnodes_platform css/ln-tokens.css)
 *   - the Nuxt onboarding main.css   (../localnodes-onboarding/app/assets/css/main.css)
 * and fails (exit 1) when any token that two or more of them share differs,
 * or when a mapped token is missing from a file.
 *
 * The files hold the values; this script only holds the mapping (which
 * name in which file is the same token), so changing a colour means editing
 * the three files, never this script.
 *
 * Usage:
 *   node scripts/check_token_parity.mjs [--yml <path>] [--drupal-css <path>] [--nuxt <path>]
 * Defaults resolve from this script's location, so it runs from any cwd.
 * No dependencies.
 */
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const moduleDir = resolve(repoRoot, 'html/modules/custom/localnodes_platform');

const defaults = {
  yml: resolve(moduleDir, 'config/optional/color.theme.socialblue.yml'),
  'drupal-css': resolve(moduleDir, 'css/ln-tokens.css'),
  nuxt: resolve(repoRoot, '../localnodes-onboarding/app/assets/css/main.css'),
};

function parseArgs(argv) {
  const opts = { ...defaults };
  for (let i = 0; i < argv.length; i++) {
    const key = argv[i].replace(/^--/, '');
    if (!(key in defaults) || !argv[i].startsWith('--') || argv[i + 1] === undefined) {
      console.error(`usage: check_token_parity.mjs [--yml <path>] [--drupal-css <path>] [--nuxt <path>] (bad argument: ${argv[i]})`);
      process.exit(2);
    }
    opts[key] = resolve(process.cwd(), argv[++i]);
  }
  return opts;
}

// Token name -> where it lives in each file (null = not in that file).
// Built from 22-UI-SPEC Part 1: "Colour tokens" table (socialblue field,
// Drupal var, Nuxt UI variable) and "Nuxt wiring (exact)". Brand colours map
// to the Nuxt palette shade (--color-ln-*), because --ui-primary is a var()
// reference to that shade, not a hex.
const MAPPING = [
  { token: 'primary',        yml: 'brand-primary',                drupal: '--ln-color-primary',        nuxt: '--color-ln-teal-700' },
  { token: 'primary-strong', yml: 'brand-secondary',              drupal: '--ln-color-primary-strong', nuxt: '--color-ln-teal-900' },
  { token: 'link',           yml: 'brand-link',                   drupal: '--ln-color-link',           nuxt: '--color-ln-teal-800' },
  { token: 'highlight',      yml: 'brand-accent',                 drupal: '--ln-color-highlight',      nuxt: '--color-ln-amber-400' },
  { token: 'on-highlight',   yml: 'brand-accent-text',            drupal: '--ln-color-on-highlight',   nuxt: '--ui-text' },
  { token: 'tint',           yml: 'second-line-navbar-active-bg', drupal: '--ln-color-tint',           nuxt: '--color-ln-teal-50' },
  { token: 'text',           yml: null,                           drupal: '--ln-color-text',           nuxt: '--ui-text' },
  { token: 'text-muted',     yml: null,                           drupal: '--ln-color-text-muted',     nuxt: '--ui-text-muted' },
  { token: 'border-control', yml: null,                           drupal: '--ln-color-border-control', nuxt: '--ui-border-accented' },
  { token: 'divider',        yml: null,                           drupal: '--ln-color-divider',        nuxt: '--ui-border' },
  { token: 'surface',        yml: null,                           drupal: '--ln-color-surface',        nuxt: '--ui-bg' },
  { token: 'canvas',         yml: null,                           drupal: '--ln-color-canvas',         nuxt: '--ui-bg-muted' },
  { token: 'danger',         yml: null,                           drupal: '--ln-color-danger',         nuxt: '--ui-error' },
  { token: 'success',        yml: null,                           drupal: '--ln-color-success',        nuxt: '--ui-success' },
  { token: 'warning-text',   yml: null,                           drupal: '--ln-color-warning-text',   nuxt: '--ui-warning' },
  { token: 'warning-bg',     yml: null,                           drupal: '--ln-color-warning-bg',     nuxt: '--color-ln-amber-50' },
  { token: 'focus',          yml: 'brand-primary',                drupal: '--ln-color-focus',          nuxt: '--color-ln-teal-700' },
  { token: 'focus-on-dark',  yml: 'brand-accent',                 drupal: '--ln-color-focus-on-dark',  nuxt: '--color-ln-amber-400' },
];

const SCHEME_FIELD_COUNT = 15;

const normHex = (h) => {
  let x = h.toLowerCase();
  if (/^#[0-9a-f]{3}$/.test(x)) x = '#' + [...x.slice(1)].map((c) => c + c).join('');
  return x;
};

function read(path, label) {
  try {
    return readFileSync(path, 'utf8');
  } catch (e) {
    console.log(`MISSING file ${label}: ${path} (${e.code || e.message})`);
    return null;
  }
}

/** Small line parser for the `palette:` mapping of color.theme.*.yml. */
function parsePalette(text) {
  const out = new Map();
  let inPalette = false;
  for (const line of text.split('\n')) {
    if (/^\S/.test(line)) {
      inPalette = /^palette:\s*$/.test(line);
      continue;
    }
    if (!inPalette) continue;
    const m = line.match(/^\s+([a-z0-9-]+):\s*['"]?(#[0-9a-fA-F]{3,8})['"]?\s*(#.*)?$/);
    if (m) out.set(m[1], [...(out.get(m[1]) || []), normHex(m[2])]);
  }
  return out;
}

/** `--name: #hex` declarations, each name -> list of values found. */
function parseCssVars(text) {
  const out = new Map();
  const noComments = text.replace(/\/\*[\s\S]*?\*\//g, '');
  for (const m of noComments.matchAll(/(--[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{3,8})\b/gi)) {
    out.set(m[1], [...(out.get(m[1]) || []), normHex(m[2])]);
  }
  return out;
}

/** Nuxt: drop .dark { ... } blocks (landing page only); parity is the light scheme. */
function stripDarkBlocks(text) {
  return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[\s,}])\.dark\s*\{[^}]*\}/g, '$1');
}

const opts = parseArgs(process.argv.slice(2));
let failed = false;
const fail = (line) => { failed = true; console.log(line); };

const ymlText = read(opts.yml, 'yml');
const drupalText = read(opts['drupal-css'], 'drupal-css');
const nuxtText = read(opts.nuxt, 'nuxt');
if (ymlText === null || drupalText === null || nuxtText === null) {
  console.log('RESULT: FAIL (a file could not be read)');
  process.exit(1);
}

const sources = {
  yml: parsePalette(ymlText),
  drupal: parseCssVars(drupalText),
  nuxt: parseCssVars(stripDarkBlocks(nuxtText)),
};

// One value per name, or report why not.
function lookup(src, name) {
  const vals = sources[src].get(name);
  if (!vals) return { missing: true };
  const uniq = [...new Set(vals)];
  if (uniq.length > 1) return { conflict: uniq };
  return { value: uniq[0] };
}

let ok = 0;
for (const row of MAPPING) {
  const found = {};
  const problems = [];
  for (const src of ['yml', 'drupal', 'nuxt']) {
    if (!row[src]) continue;
    const r = lookup(src, row[src]);
    if (r.missing) problems.push(`MISSING ${row.token}: ${src} has no ${row[src]}`);
    else if (r.conflict) problems.push(`DIFF ${row.token}: ${src} defines ${row[src]} more than once (${r.conflict.join(', ')})`);
    else found[src] = r.value;
  }
  const values = new Set(Object.values(found));
  if (problems.length) {
    problems.forEach(fail);
  } else if (values.size !== 1) {
    const parts = ['yml', 'drupal', 'nuxt'].map((s) => `${s}=${row[s] ? found[s] : '-'}`);
    fail(`DIFF ${row.token} ${parts.join(' ')}`);
  } else {
    ok++;
    console.log(`OK ${row.token} ${[...values][0]}`);
  }
}

// The scheme: drupal/color needs all 15 fields, each value distinct.
const palette = sources.yml;
const flat = [...palette.entries()].map(([k, v]) => [k, v[0]]);
const dupes = flat.filter(([, v], i) => flat.findIndex(([, w]) => w === v) !== i);
if (palette.size !== SCHEME_FIELD_COUNT) {
  fail(`MISSING palette: ${palette.size} of ${SCHEME_FIELD_COUNT} socialblue scheme fields in ${opts.yml}`);
} else if (dupes.length) {
  fail(`DIFF palette: values must be distinct (color.inc), repeated: ${dupes.map(([k, v]) => `${k}=${v}`).join(', ')}`);
} else {
  ok++;
  console.log(`OK palette ${SCHEME_FIELD_COUNT} distinct scheme values`);
}

// Nuxt --ui-primary must point at the primary-700 shade (the hex is checked above).
const light = stripDarkBlocks(nuxtText);
const uiPrimary = [...light.matchAll(/--ui-primary\s*:\s*([^;]+);/g)].map((m) => m[1].trim());
if (uiPrimary.length === 1 && uiPrimary[0] === 'var(--ui-color-primary-700)') {
  ok++;
  console.log('OK nuxt --ui-primary var(--ui-color-primary-700)');
} else {
  fail(`DIFF nuxt --ui-primary: expected var(--ui-color-primary-700), found ${uiPrimary.length ? uiPrimary.join(' | ') : 'none'}`);
}

console.log(failed ? `RESULT: FAIL (${ok} OK)` : `RESULT: PASS (${ok} OK)`);
process.exit(failed ? 1 : 0);
