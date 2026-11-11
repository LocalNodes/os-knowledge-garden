#!/usr/bin/env node
/**
 * Token generator: one source, many files (22.1-03, DES-01, D-03, D-08).
 *
 * Reads the one token source
 *   html/modules/custom/localnodes_platform/tokens/localnodes.tokens.json
 * and renders every generated file from it. Each generated file lives in a
 * target module under scripts/tokens/targets/ (discovered in sorted file-name
 * order, so a new target is a new file and the generator needs no edit):
 *   - socialblue : config/optional/color.theme.socialblue.yml  (the 15-field scheme, brand-primary
 *                  through second-line-navbar-active-text, in color.inc order)
 *   - drupal-css : css/ln-tokens.css                           (:root custom properties only)
 *   - flat       : tokens/ln-tokens.flat.json                  (name -> value map read by PHP)
 *   - nuxt       : <sibling>/app/assets/css/ln-tokens.css      (the Nuxt sign-up site's palettes and
 *                  --ui-* roles; written into ../localnodes-onboarding, 22.1-08)
 *   - nuxt-mail  : <sibling>/server/utils/mail-colors.generated.ts (the sign-up site's MAIL_COLORS from
 *                  targets.email, 22.1-14). Written or checked only once the Nuxt repo has adopted it
 *                  (server/utils/mail-copy.ts imports ./mail-colors.generated, or the file exists);
 *                  before that: `SKIP nuxt-mail (not adopted: ...)`, nothing written.
 *
 * Changing the palette means editing the source (or giving --palette an
 * overlay) and running this script; nothing else holds a colour value.
 *
 * Usage:
 *   node scripts/tokens/build_tokens.mjs                    write every generated file
 *   node scripts/tokens/build_tokens.mjs --check            compare, write nothing
 *   node scripts/tokens/build_tokens.mjs --out-dir <dir>    write (or check) under <dir>,
 *                                                           mirroring repo-relative paths
 *   node scripts/tokens/build_tokens.mjs --palette <file>   deep-merge a JSON overlay (same shape
 *                                                           as the source; null deletes a key)
 *   node scripts/tokens/build_tokens.mjs --sibling <dir>    where the Nuxt checkout is (default
 *                                                           ../localnodes-onboarding). Without a
 *                                                           nuxt.config.ts there the Nuxt target
 *                                                           prints `SKIP nuxt (sibling not present)`
 *                                                           and the run still passes (CI has no
 *                                                           sibling). With --out-dir the Nuxt file is
 *                                                           always rendered, under
 *                                                           <dir>/_external/localnodes-onboarding/
 *                                                           (the nuxt-mail adoption gate does not apply).
 *
 * Output: `OK <label>`, `WROTE <label>`, `DIFF <label>: <path> is not what
 * <source> generates (run build_tokens.mjs)` or `MISSING <label>: <path>`,
 * then `RESULT: PASS (n OK)` or `RESULT: FAIL`. A guard failure (missing
 * reference, reference loop, alpha hex, duplicate or stock socialblue
 * palette...) prints `ERROR <message>` and writes nothing.
 * Exit 0 pass, 1 fail, 2 usage. Files are written to a temp file and renamed,
 * so a reader never sees a half-written file. Two runs on the same source
 * produce byte-identical files. No dependencies; runs from any cwd.
 */
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { DEFAULT_SIBLING, TokenError, discoverTargets, loadTokens, meta, repoRoot, resolve as resolveTokens, siblingPresent } from './lib/tokens.mjs';

const USAGE = 'usage: build_tokens.mjs [--check] [--out-dir <dir>] [--palette <file>] [--sibling <dir>]';

function parseArgs(argv) {
  const opts = { check: false, outDir: null, palette: null, sibling: DEFAULT_SIBLING };
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--check') {
      opts.check = true;
    } else if (['--out-dir', '--palette', '--sibling'].includes(arg) && argv[i + 1] !== undefined && !argv[i + 1].startsWith('--')) {
      opts[{ '--out-dir': 'outDir', '--palette': 'palette', '--sibling': 'sibling' }[arg]] = resolve(process.cwd(), argv[++i]);
    } else {
      console.error(`${USAGE} (bad argument: ${arg})`);
      process.exit(2);
    }
  }
  if (opts.palette && !existsSync(opts.palette)) {
    console.error(`${USAGE} (palette file not found: ${opts.palette})`);
    process.exit(2);
  }
  return opts;
}

/** Where a target's file lands: its real path, or the same repo-relative path under --out-dir. */
function destination(target, outDir, sibling) {
  const real = target.path(repoRoot, sibling);
  if (!outDir) return real;
  // A target in the Nuxt checkout lands under _external/localnodes-onboarding/,
  // whatever directory --sibling named, so mirrors compare byte for byte.
  if (target.sibling) return join(outDir, '_external', 'localnodes-onboarding', relative(sibling, real));
  return join(outDir, relative(repoRoot, real));
}

function writeAtomic(path, text) {
  mkdirSync(dirname(path), { recursive: true });
  const tmp = join(dirname(path), `.${process.pid}.${Date.now()}.tmp`);
  writeFileSync(tmp, text);
  renameSync(tmp, path);
}

const opts = parseArgs(process.argv.slice(2));
let rendered;
try {
  const resolved = resolveTokens(loadTokens(opts.palette));
  const targets = await discoverTargets();
  // Render everything before touching the disk: a guard failure writes nothing.
  rendered = targets.map((t) => {
    // The Nuxt checkout is somebody else's repo: touch it only when it is there.
    if (t.sibling && !opts.outDir && !siblingPresent(opts.sibling)) return { t, skip: 'sibling not present' };
    // A target the Nuxt repo has not adopted yet (22.1-14): no write, no check, a named SKIP.
    if (t.sibling && !opts.outDir && t.adopted && !t.adopted(opts.sibling)) return { t, skip: t.notAdoptedReason };
    return { t, path: destination(t, opts.outDir, opts.sibling), text: t.render(resolved, meta) };
  });
} catch (e) {
  if (!(e instanceof TokenError)) throw e;
  console.log(`ERROR ${e.message}`);
  console.log('RESULT: FAIL');
  process.exit(1);
}

let ok = 0;
let failed = false;
for (const { t, path, text, skip } of rendered) {
  if (skip) {
    console.log(`SKIP ${t.id} (${skip})`);
    continue;
  }
  const have = existsSync(path) ? readFileSync(path, 'utf8') : null;
  if (opts.check) {
    if (have === null) {
      failed = true;
      console.log(`MISSING ${t.label}: ${path}`);
    } else if (have !== text) {
      failed = true;
      console.log(`DIFF ${t.label}: ${path} is not what ${meta.source} generates (run build_tokens.mjs)`);
    } else {
      ok++;
      console.log(`OK ${t.label}`);
    }
  } else if (have === text) {
    ok++;
    console.log(`OK ${t.label}`);
  } else {
    writeAtomic(path, text);
    ok++;
    console.log(`WROTE ${t.label}: ${path}`);
  }
}

console.log(failed ? `RESULT: FAIL (${ok} OK)` : `RESULT: PASS (${ok} OK)`);
// process.exitCode, not process.exit(): exit() right after the last console.log drops output still queued on a
// pipe whose reader is slow (WINDOWS 51). The event loop drains the queue, then the process ends with this code.
process.exitCode = failed ? 1 : 0;
