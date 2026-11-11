/**
 * The dark-mode tripwire for the excluded Nuxt dark pairs (22.1-05, Q2, Group B).
 *
 * scripts/tokens/pairings.mjs EXCLUDED lists three dark pairs on Nuxt UI's
 * --ui-bg-accented that fail contrast but are not rendered today: the sign-up
 * site's pages are light, and its dark bands only use neutral outline and primary
 * solid buttons, which never reach that fill. That is only true while dark mode
 * stays unrendered, so the gate checks it. Two signals, either one fails the gate
 * and voids the exclusions (the three pairs then FAIL):
 *
 *   1. The token source says so: `targets.nuxt.dark-mode-rendered` is true. Whoever
 *      ships dark mode flips this, and the gate goes red until the dark roles pass.
 *   2. The Nuxt checkout (read only; skipped, visibly, when absent, as in CI):
 *      - an app/pages/**.vue file that does not set `colorMode: 'light'`. nuxt.config.ts
 *        defaults to `colorMode.preference: 'dark'`, so such a page renders dark;
 *      - a component that scopes the `dark` class and uses `bg-accented` or a soft or
 *        subtle button (Nuxt UI hover-fills those with --ui-bg-accented).
 *
 * The scan is a text heuristic, not a render: it catches the plausible ways the
 * pair becomes visible, and the flag in (1) is the declared source of truth.
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { TokenError, siblingPresent } from './tokens.mjs';

export const FLAG = 'dark-mode-rendered';

function vueFiles(dir) {
  if (!existsSync(dir)) return [];
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
    const p = join(dir, e.name);
    return e.isDirectory() ? vueFiles(p) : e.name.endsWith('.vue') ? [p] : [];
  });
}

/** { skipped: bool, findings: [string] } for the resolved token tree and a Nuxt checkout directory. */
export function darkTripwire(resolved, nuxtDir) {
  const flag = resolved?.targets?.nuxt?.[FLAG];
  if (typeof flag !== 'boolean') {
    throw new TokenError(`targets.nuxt.${FLAG} must be true or false in the token source (the excluded dark pairs depend on it)`);
  }
  const findings = [];
  if (flag) findings.push(`targets.nuxt.${FLAG} is true in the token source: dark mode is rendered, so the excluded dark pairs must pass`);

  const skipped = !siblingPresent(nuxtDir);
  if (!skipped) {
    for (const file of vueFiles(join(nuxtDir, 'app/pages'))) {
      if (!/colorMode\s*:\s*['"]light['"]/.test(readFileSync(file, 'utf8'))) {
        findings.push(`${relative(nuxtDir, file)} does not set colorMode: 'light', so it renders in nuxt.config.ts's default dark mode`);
      }
    }
    for (const file of [...vueFiles(join(nuxtDir, 'app/components')), ...vueFiles(join(nuxtDir, 'app/pages'))]) {
      const text = readFileSync(file, 'utf8');
      if (!/class="[^"]*\bdark\b/.test(text)) continue;
      const hit = text.match(/\bbg-accented\b|variant="(?:soft|subtle)"/)?.[0];
      if (hit) findings.push(`${relative(nuxtDir, file)} scopes the dark class and uses ${hit}, which paints --ui-bg-accented`);
    }
  }
  return { skipped, findings };
}
