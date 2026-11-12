#!/usr/bin/env node
/**
 * Contrast gate for the token set (22.1-05, DES-01, D-09, criterion 4).
 *
 * Checks every pairing in scripts/tokens/pairings.mjs against WCAG 2.2 AA
 * (4.5:1 text, 3:1 non-text and focus) using the UNROUNDED ratio, for whatever
 * palette the token source holds, or for an overlay given with --palette
 * (same overlay shape as build_tokens.mjs, deep-merged over the source).
 *
 * Usage:
 *   node scripts/tokens/check_contrast.mjs [--palette <overlay.json>] [--overrides <file.css>] [--nuxt <dir>]
 *   --overrides  the Drupal override stylesheet the accent pairs are measured through
 *                (default: localnodes_platform/css/ln-overrides.css)
 *   --nuxt       the Nuxt checkout the dark-mode tripwire reads, read only (default: ../localnodes-onboarding)
 * Output, one line per pairing:
 *   OK <id> <ratio, 2 dp> >= <min>[ (overridden by ...)]
 *   FAIL <id> <fg path>=<hex> on <bg path>=<hex> <ratio, 2 dp> < <min> (<source>)
 *   EXCLUDED <id> <ratio, 2 dp> (<reason>)      a pair that is not rendered, or is decoration; never counted as OK.
 *                                               A decorative fill also prints ` | focus ring <twin id> <ratio> >= 3`,
 *                                               the measured ring its exclusion rests on; if that ring fails, the
 *                                               exclusion is void and the fill prints FAIL (22.1-05 Q3)
 *   STALE <id> ...                              an excluded pair that now passes: remove it from EXCLUDED
 * then, for every colour token no pairing checks and that is not marked
 * decorative with a reason, `UNCOVERED <token path> (...)`, `TRIPWIRE <what>` for each sign that
 * dark mode is rendered (which voids the dark exclusions only), then
 * `RESULT: PASS (n OK, m EXCLUDED)` or `RESULT: FAIL (n FAIL[, m UNCOVERED][, k STALE][, j TRIPWIRE])`.
 * Exit 0 pass, 1 any FAIL, UNCOVERED, STALE or TRIPWIRE, or a bad token source, 2 bad usage. Node built-ins only.
 *
 * The 2 dp figure is display only: the comparison is on the unrounded ratio, so
 * a pair that prints as 4.50 can still be a FAIL against 4.5.
 */
import { existsSync } from 'node:fs';
import { resolve as resolvePath } from 'node:path';
import { DEFAULT_SIBLING, TokenError, loadTokens, resolve as resolveTokens } from './lib/tokens.mjs';
import { contrast, meets } from './lib/contrast.mjs';
import { EXCLUDED, OVERRIDES_FILE, assertExclusionsKnown, declaredPairings, declaredStylesheets, uncovered } from './pairings.mjs';
import { darkTripwire } from './lib/dark_tripwire.mjs';
import { STYLESHEETS } from './lib/stylesheet_pairs.mjs';
import { ln } from './lib/pairing.mjs';

function parseArgs(argv) {
  const opts = { palette: null, overrides: OVERRIDES_FILE, nuxt: DEFAULT_SIBLING };
  const flags = { '--palette': 'palette', '--overrides': 'overrides', '--nuxt': 'nuxt' };
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] in flags && argv[i + 1] !== undefined) {
      opts[flags[argv[i]]] = resolvePath(process.cwd(), argv[++i]);
    } else {
      console.error(`usage: check_contrast.mjs [--palette <overlay.json>] [--overrides <file.css>] [--nuxt <dir>] (bad argument: ${argv[i]})`);
      process.exit(2);
    }
  }
  for (const key of ['palette', 'overrides']) {
    if (opts[key] && !existsSync(opts[key])) {
      console.error(`usage: --${key} file not found: ${opts[key]}`);
      process.exit(2);
    }
  }
  return opts;
}

/** The hex at a dotted token path in the resolved tree. */
function hexAt(resolved, path, id) {
  let node = resolved;
  for (const part of path.split('.')) node = node !== null && typeof node === 'object' ? node[part] : undefined;
  if (typeof node !== 'string' || !/^#[0-9a-f]{6}$/.test(node)) {
    throw new TokenError(`pairing ${id}: ${path} is not a colour in the token source`);
  }
  return node;
}

const opts = parseArgs(process.argv.slice(2));
let ok = 0;
let failed = 0;
let missing = 0;
let excluded = 0;
let stale = 0;
let unmapped = 0;
let stalePartner = 0;
let tripped = 0;
let errored = false;
try {
  const tree = loadTokens(opts.palette);
  const resolved = resolveTokens(tree);
  const pairings = declaredPairings(resolved, { overridesFile: opts.overrides, tree });
  assertExclusionsKnown(EXCLUDED, pairings);

  // The dark pairs are not rendered while dark mode is not. If it is, they are not excluded. The decorative fills
  // do not depend on dark mode: they are excluded while the focus ring on their control stays at 3:1 (below).
  const tripwire = darkTripwire(resolved, opts.nuxt);
  if (tripwire.skipped) console.log('SKIP dark-mode tripwire (nuxt checkout not present): only the token-source flag guards the excluded pairs');
  for (const f of tripwire.findings) {
    tripped++;
    console.log(`TRIPWIRE ${f}; the excluded dark pairs are void and must pass`);
  }
  const ratios = new Map(pairings.map((p) => [p.id, contrast(hexAt(resolved, p.fg, p.id), hexAt(resolved, p.bg, p.id))]));
  const minOf = new Map(pairings.map((p) => [p.id, p.min]));
  const live = new Map();
  for (const e of EXCLUDED) {
    if (e.voidedBy === 'dark-mode' && tripwire.findings.length) continue;
    // An exclusion that rests on a focus ring is void while that ring fails its bar: the fill then FAILs, and so does the ring.
    if (e.focusTwin && !meets(ratios.get(e.focusTwin), minOf.get(e.focusTwin))) continue;
    live.set(e.id, e);
  }

  for (const p of pairings) {
    const fg = hexAt(resolved, p.fg, p.id);
    const bg = hexAt(resolved, p.bg, p.id);
    const ratio = ratios.get(p.id);
    const pass = meets(ratio, p.min);
    if (live.has(p.id)) {
      const e = live.get(p.id);
      if (pass) {
        stale++;
        console.log(`STALE ${p.id} now passes (${ratio.toFixed(2)} >= ${p.min}): remove it from EXCLUDED in scripts/tokens/pairings.mjs`);
      } else {
        excluded++;
        const ring = e.focusTwin ? ` | focus ring ${e.focusTwin} ${ratios.get(e.focusTwin).toFixed(2)} >= ${minOf.get(e.focusTwin)}` : '';
        console.log(`EXCLUDED ${p.id} ${ratio.toFixed(2)} (${e.reason})${ring}`);
      }
    } else if (pass) {
      ok++;
      console.log(`OK ${p.id} ${ratio.toFixed(2)} >= ${p.min}${p.note ? ` (${p.note})` : ''}`);
    } else {
      failed++;
      console.log(`FAIL ${p.id} ${p.fg}=${fg} on ${p.bg}=${bg} ${ratio.toFixed(2)} < ${p.min} (${p.source})`);
    }
  }
  // What the five module stylesheets draw (treasury.css and signin.css are read the same way). The ring
  // that wins is what is measured; where a module stylesheet asks for another, say so. A colour with no partner, or a
  // partner the CSS no longer matches, fails.
  const sheets = declaredStylesheets({ overridesFile: opts.overrides, tree });
  for (const o of sheets.overridden) {
    console.log(`OVERRIDDEN ${STYLESHEETS[o.sheet].name} ${o.selector} ${o.property}: asks --ln-color-${o.asks}, renders --ln-color-${o.renders} (ln-overrides.css ${o.winner}); what renders is the one measured`);
  }
  for (const d of sheets.decorative) {
    console.log(`DECORATIVE ${STYLESHEETS[d.sheet].name} ${d.selector} ${d.property} --ln-color-${d.role} (${d.reason})`);
  }
  for (const u of sheets.unmapped) {
    unmapped++;
    console.log(`UNMAPPED ${STYLESHEETS[u.sheet].name} ${u.selector} ${u.property} --ln-color-${u.role} (name its partner in scripts/tokens/lib/stylesheet_pairs.mjs: the background behind it, its surroundings, or the foregrounds drawn on it)`);
  }
  for (const s of sheets.stale) {
    stalePartner++;
    console.log(`STALE-PARTNER ${STYLESHEETS[s.sheet].name} ${s.selector} ${s.kind}: ${s.message}`);
  }
  const drawn = new Set([...sheets.drawn].map((role) => ln(role)));
  for (const u of uncovered(tree, resolved, pairings, { drawn })) {
    missing++;
    console.log(`UNCOVERED ${u.path} (${u.why}; add a pairing in scripts/tokens/pairings.mjs or mark the token decorative with a reason)`);
  }
} catch (e) {
  if (!(e instanceof TokenError) && !(e instanceof SyntaxError)) throw e;
  console.log(`ERROR ${e.message}`);
  console.log('RESULT: FAIL');
  errored = true;
}

// process.exitCode, not process.exit(): exit() right after the last console.log drops output still queued on a
// pipe whose reader is slow (WINDOWS 51). The event loop drains the queue, then the process ends with this code.
if (errored) {
  process.exitCode = 1;
} else {
  const bad = failed + unmapped + stalePartner + missing + stale + tripped;
  const detail = [
    failed ? `${failed} FAIL` : '',
    unmapped ? `${unmapped} UNMAPPED` : '',
    stalePartner ? `${stalePartner} STALE-PARTNER` : '',
    missing ? `${missing} UNCOVERED` : '',
    stale ? `${stale} STALE` : '',
    tripped ? `${tripped} TRIPWIRE` : '',
  ].filter(Boolean).join(', ');
  console.log(bad ? `RESULT: FAIL (${detail})` : `RESULT: PASS (${ok} OK, ${excluded} EXCLUDED)`);
  process.exitCode = bad ? 1 : 0;
}
