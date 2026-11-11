/**
 * Token library for scripts/tokens/build_tokens.mjs (22.1-03, DES-01, D-08).
 *
 * Loads the one token source
 *   html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json
 * (DTCG-style: `$value` leaves, `{a.b.c}` references, three layers: primitive,
 * semantic, targets), merges an optional overlay, normalises every colour,
 * resolves references with cycle detection, and discovers the generator
 * targets in scripts/tokens/targets/.
 *
 * Contrast maths is NOT here: 22.1-05 owns it.
 *
 * Rules this file enforces for every target (so a target can't forget them):
 *   - A colour is lowercase 6-digit hex. 3-digit values expand. 4- and 8-digit
 *     alpha hex is an error (the socialblue scheme and the email template
 *     can't carry alpha).
 *   - A reference to a missing token, or a reference loop, is an error that
 *     names the token.
 *
 * Node built-ins only. Every failure is a TokenError; build_tokens.mjs turns
 * it into `ERROR ...` plus exit 1.
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve as resolvePath } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const repoRoot = resolvePath(dirname(fileURLToPath(import.meta.url)), '../../..');
export const SOURCE_REL = 'html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json';
export const GENERATOR_REL = 'scripts/tokens/build_tokens.mjs';
const TARGETS_DIR = resolvePath(dirname(fileURLToPath(import.meta.url)), '../targets');

export class TokenError extends Error {}

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const isLeafObject = (v) => isObject(v) && Object.prototype.hasOwnProperty.call(v, '$value');

/**
 * Lowercase 6-digit hex from #rgb or #rrggbb in any case.
 * Throws TokenError for 4- and 8-digit alpha hex and for anything else.
 */
export function normHex(value, where = 'value') {
  if (typeof value !== 'string' || !/^#[0-9a-fA-F]+$/.test(value)) {
    throw new TokenError(`${where}: "${value}" is not a hex colour`);
  }
  const hex = value.slice(1).toLowerCase();
  if (hex.length === 3) return '#' + [...hex].map((c) => c + c).join('');
  if (hex.length === 6) return '#' + hex;
  if (hex.length === 4 || hex.length === 8) {
    throw new TokenError(`${where}: "${value}" is ${hex.length}-digit alpha hex, which is not allowed (use 3- or 6-digit hex)`);
  }
  throw new TokenError(`${where}: "${value}" is not a 3- or 6-digit hex colour`);
}

/** Deep merge; JSON null in the overlay deletes the key. Arrays and scalars replace. */
function merge(base, over) {
  if (!isObject(base) || !isObject(over)) return over;
  const out = { ...base };
  for (const [k, v] of Object.entries(over)) {
    if (v === null) delete out[k];
    else if (isObject(v) && isObject(out[k])) out[k] = merge(out[k], v);
    else out[k] = v;
  }
  return out;
}

/** Normalise every string that starts with # (a colour) anywhere in the tree. */
function normaliseColours(node, path) {
  if (typeof node === 'string') return node.startsWith('#') ? normHex(node, path) : node;
  if (Array.isArray(node)) return node.map((v, i) => normaliseColours(v, `${path}[${i}]`));
  if (isObject(node)) {
    return Object.fromEntries(Object.entries(node).map(([k, v]) => [k, normaliseColours(v, path ? `${path}.${k}` : k)]));
  }
  return node;
}

/**
 * Read the source, merge the overlay (a file path, or null), normalise colours.
 * Returns the merged tree (still unresolved).
 */
export function loadTokens(paletteFile = null) {
  let tree = JSON.parse(readFileSync(resolvePath(repoRoot, SOURCE_REL), 'utf8'));
  if (paletteFile) tree = merge(tree, JSON.parse(readFileSync(paletteFile, 'utf8')));
  return normaliseColours(tree, '');
}

const REF = /\{([A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)\}/g;

function lookup(tree, path) {
  let node = tree;
  for (const part of path.split('.')) {
    if (!isObject(node) || !Object.prototype.hasOwnProperty.call(node, part)) return undefined;
    node = node[part];
  }
  return node;
}

/** The resolved value of the token at `path`, following references. */
function resolveAt(tree, path, stack, cache) {
  if (cache.has(path)) return cache.get(path);
  if (stack.includes(path)) {
    throw new TokenError(`reference loop: ${[...stack.slice(stack.indexOf(path)), path].join(' -> ')}`);
  }
  const node = lookup(tree, path);
  if (node === undefined) {
    throw new TokenError(`missing token ${path}${stack.length ? ` (referenced from ${stack[stack.length - 1]})` : ''}`);
  }
  const raw = isLeafObject(node) ? node.$value : node;
  if (isObject(raw) || Array.isArray(raw)) {
    throw new TokenError(`${path} is a group, not a token${stack.length ? ` (referenced from ${stack[stack.length - 1]})` : ''}`);
  }
  const value = resolveString(tree, raw, path, stack, cache);
  cache.set(path, value);
  return value;
}

function resolveString(tree, raw, path, stack, cache) {
  if (typeof raw !== 'string') return raw;
  const whole = raw.match(/^\{([^{}]+)\}$/);
  if (whole) return resolveAt(tree, whole[1], [...stack, path], cache);
  return raw.replace(REF, (_, ref) => String(resolveAt(tree, ref, [...stack, path], cache)));
}

/**
 * Fully resolved copy of the tree: every `{$value}` leaf becomes its value,
 * every `{ref}` is followed, `$`-keys on groups are dropped. A bad reference
 * anywhere in the tree fails here, whether or not a target uses it.
 */
export function resolve(tree) {
  const cache = new Map();
  const walk = (node, path) => {
    if (isLeafObject(node)) return resolveAt(tree, path, [], cache);
    if (isObject(node)) {
      const out = {};
      for (const [k, v] of Object.entries(node)) {
        if (k.startsWith('$')) continue;
        out[k] = walk(v, path ? `${path}.${k}` : k);
      }
      return out;
    }
    return resolveAt(tree, path, [], cache);
  };
  const resolved = walk(tree, '');
  // Colours reached through a reference are already normalised (the tree was);
  // assert it so a target can rely on it.
  const check = (node, path) => {
    if (typeof node === 'string' && node.startsWith('#')) normHex(node, path);
    else if (isObject(node)) Object.entries(node).forEach(([k, v]) => check(v, `${path}.${k}`));
  };
  check(resolved, '');
  return resolved;
}

/**
 * Targets in sorted file-name order. Each exports id, label, path(repoRoot, siblingDir),
 * render(resolved, meta). A target that writes into the sibling Nuxt checkout also exports
 * `sibling = true`; the generator skips it when that checkout is absent (see siblingPresent).
 * A sibling target may also export `adopted(siblingDir)` and `notAdoptedReason` (22.1-14): a
 * target the Nuxt repo has not adopted yet is skipped, in write mode and in --check alike, with
 * `SKIP <id> (<notAdoptedReason>)`. Both are null when a target has none.
 */
export async function discoverTargets() {
  const files = readdirSync(TARGETS_DIR).filter((f) => f.endsWith('.mjs')).sort();
  const targets = [];
  for (const file of files) {
    const mod = await import(pathToFileURL(resolvePath(TARGETS_DIR, file)).href);
    for (const key of ['id', 'label', 'path', 'render']) {
      if (!(key in mod)) throw new TokenError(`target ${file} does not export "${key}"`);
    }
    targets.push({ file, id: mod.id, label: mod.label, path: mod.path, render: mod.render, sibling: mod.sibling === true,
      adopted: typeof mod.adopted === 'function' ? mod.adopted : null,
      notAdoptedReason: typeof mod.notAdoptedReason === 'string' ? mod.notAdoptedReason : 'not adopted' });
  }
  return targets;
}

/** Where the Nuxt onboarding checkout lives by default: next to this repo. */
export const DEFAULT_SIBLING = resolvePath(repoRoot, '../localnodes-onboarding');

/**
 * Is `dir` a Nuxt checkout? A bare directory does not count (an empty mktemp
 * directory, or a CI runner with nothing next to the repo): nuxt.config.ts must
 * be there. 22.1-08.
 */
export const siblingPresent = (dir) => existsSync(resolvePath(dir, 'nuxt.config.ts'));

/** The header text (lines, no comment syntax) every generated file starts with. */
export function headerLines() {
  return [
    `GENERATED by ${GENERATOR_REL} from ${SOURCE_REL}`,
    'GENERATED, do not edit by hand: edit the source and run the generator.',
    `Check with: node ${GENERATOR_REL} --check`,
  ];
}

export const meta = Object.freeze({ source: SOURCE_REL, generator: GENERATOR_REL });
