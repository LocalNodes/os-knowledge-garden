#!/usr/bin/env node
/**
 * Token parity check between the Drupal community site and the Nuxt
 * onboarding app (DES-01, D-03, D-08; 22-UI-SPEC Part 1 and UI Considerations
 * row "Token parity between Drupal and Nuxt").
 *
 * Since 22.1-03 the files no longer hold the mapping: one token source
 * (html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json)
 * generates them, and this script is a thin wrapper that asks the generator
 * (scripts/tokens/build_tokens.mjs) whether each given file is what the source
 * renders. It keeps the CLI Phase 22's 22-28 calls by name:
 *   - the socialblue colour scheme   (config/optional/color.theme.socialblue.yml)
 *   - the Drupal tokens stylesheet   (css/ln-tokens.css)
 *   - the Nuxt onboarding main.css   (../localnodes-onboarding/app/assets/css/main.css)
 * and fails (exit 1) when a file differs from what the source generates or is
 * missing.
 *
 * The Nuxt file is the generated app/assets/css/ln-tokens.css. `--nuxt` takes
 * that file, or a stylesheet that `@import`s it (main.css does): the wrapper
 * follows the import and checks the generated file, so the command line 22-28
 * uses (`--nuxt ../localnodes-onboarding/app/assets/css/main.css`) keeps
 * working. When the Nuxt checkout is absent (CI) it prints
 * `SKIP nuxt (sibling not present)` and passes.
 *
 * The sign-up site's mail colours are a second generated file (22.1-14):
 * server/utils/mail-colors.generated.ts, checked through `--mail-colors` (default: the
 * sibling's file). Same skip rules as build_tokens.mjs: `SKIP nuxt-mail (sibling not
 * present)` with no checkout, `SKIP nuxt-mail (not adopted: ...)` while the Nuxt repo's
 * mail-copy.ts does not import the file and the file does not exist. Both pass.
 *
 * Usage:
 *   node scripts/check_token_parity.mjs [--yml <path>] [--drupal-css <path>] [--nuxt <path>]
 *                                       [--mail-colors <path>] [--sibling <dir>]
 * Defaults resolve from this script's location, so it runs from any cwd.
 * --sibling is where the Nuxt checkout is (default ../localnodes-onboarding).
 * Output: OK / DIFF / MISSING / SKIP lines, then `RESULT: PASS (n OK)` or
 * `RESULT: FAIL`. Exit 0 pass, 1 fail, 2 usage. No dependencies.
 */
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DEFAULT_SIBLING, TokenError, discoverTargets, loadTokens, meta, resolve as resolveTokens, siblingPresent } from './tokens/lib/tokens.mjs';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

// CLI option -> generator target id.
const TARGET_OF = { yml: 'socialblue', 'drupal-css': 'drupal-css', nuxt: 'nuxt', 'mail-colors': 'nuxt-mail' };

function parseArgs(argv, defaults) {
  const opts = { ...defaults };
  for (let i = 0; i < argv.length; i++) {
    const key = argv[i].replace(/^--/, '');
    if (!(key in defaults) || !argv[i].startsWith('--') || argv[i + 1] === undefined) {
      console.error(`usage: check_token_parity.mjs [--yml <path>] [--drupal-css <path>] [--nuxt <path>] [--mail-colors <path>] [--sibling <dir>] (bad argument: ${argv[i]})`);
      process.exit(2);
    }
    opts[key] = resolve(process.cwd(), argv[++i]);
  }
  return opts;
}

const bail = (e) => {
  if (!(e instanceof TokenError)) throw e;
  console.log(`ERROR ${e.message}`);
  console.log('RESULT: FAIL');
  process.exit(1);
};

let byId;
try {
  byId = new Map((await discoverTargets()).map((t) => [t.id, t]));
} catch (e) {
  bail(e);
}

const defaults = {
  yml: byId.get('socialblue').path(repoRoot),
  'drupal-css': byId.get('drupal-css').path(repoRoot),
  nuxt: null,
  'mail-colors': null,
  sibling: DEFAULT_SIBLING,
};
const opts = parseArgs(process.argv.slice(2), defaults);
// The default Nuxt file is the sibling's generated file.
opts.nuxt ??= byId.get('nuxt').path(repoRoot, opts.sibling);
if (byId.has('nuxt-mail')) opts['mail-colors'] ??= byId.get('nuxt-mail').path(repoRoot, opts.sibling);

/**
 * The generated file to compare: the given file itself, or, when it is a
 * stylesheet that imports ln-tokens.css (main.css), the file it imports.
 */
function followImport(file) {
  if (!existsSync(file)) return file;
  // The generated file never imports itself, so any import of ln-tokens.css means "follow it".
  const text = readFileSync(file, 'utf8');
  const m = text.match(/@import\s+["']([^"']*ln-tokens\.css)["']/);
  return m ? resolve(dirname(file), m[1]) : file;
}

let tokens;
try {
  tokens = resolveTokens(loadTokens());
} catch (e) {
  bail(e);
}

let ok = 0;
let failed = false;
for (const [option, targetId] of Object.entries(TARGET_OF)) {
  const target = byId.get(targetId);
  if (!target) {
    console.log(`SKIP ${option}: no ${targetId} target registered`);
    continue;
  }
  if (target.sibling && !siblingPresent(opts.sibling)) {
    console.log(`SKIP ${target.id} (sibling not present)`);
    continue;
  }
  if (target.sibling && target.adopted && !target.adopted(opts.sibling)) {
    console.log(`SKIP ${target.id} (${target.notAdoptedReason})`);
    continue;
  }
  // followImport reads a CSS @import: only the Nuxt stylesheet has one.
  const file = target.id === 'nuxt' ? followImport(opts[option]) : opts[option];
  let want;
  try {
    want = target.render(tokens, meta);
  } catch (e) {
    if (!(e instanceof TokenError)) throw e;
    failed = true;
    console.log(`ERROR ${e.message}`);
    continue;
  }
  if (!existsSync(file)) {
    failed = true;
    console.log(`MISSING file ${option}: ${file}`);
  } else if (readFileSync(file, 'utf8') !== want) {
    failed = true;
    console.log(`DIFF ${target.label}: ${file} is not what ${meta.source} generates (run build_tokens.mjs)`);
  } else {
    ok++;
    console.log(`OK ${target.label}`);
  }
}

console.log(failed ? `RESULT: FAIL (${ok} OK)` : `RESULT: PASS (${ok} OK)`);
// process.exitCode, not process.exit(): exit() right after the last console.log drops output still queued on a
// pipe whose reader is slow (WINDOWS 51). The event loop drains the queue, then the process ends with this code.
process.exitCode = failed ? 1 : 0;
