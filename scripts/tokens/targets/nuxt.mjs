/**
 * Generator target: the Nuxt sign-up site's token stylesheet (22.1-08, DES-01, D-03, D-08).
 *
 * Renders <sibling>/app/assets/css/ln-tokens.css in the localnodes-onboarding
 * checkout from `primitive.color` and `targets.nuxt` in the token source:
 *   - an `@theme static` block: --font-sans and the role-named palettes
 *     --color-ln-brand-*, --color-ln-accent-*, --color-ln-neutral-* (50..950),
 *   - a `:root` block: the light --ui-* roles (the task pages),
 *   - a `.dark` block: the dark --ui-* roles (the landing page's dark bands).
 * The Nuxt repo's main.css imports this file and app.config.ts names the three
 * palettes, so a palette swap is a token change and a regenerate; no selector
 * or component changes. The Nuxt repo is built alone on Vercel, so the file is
 * committed there (no submodule, no package).
 *
 * `sibling = true`: the generator writes or checks this file only when the
 * Nuxt checkout exists, and prints `SKIP nuxt (sibling not present)` when it
 * does not (CI).
 */
import { resolve } from 'node:path';
import { TokenError, headerLines } from '../lib/tokens.mjs';

export const id = 'nuxt';
export const label = 'Nuxt token stylesheet (nuxt)';
export const sibling = true;

export const GENERATED_REL = 'app/assets/css/ln-tokens.css';
export const path = (repoRoot, siblingDir) => resolve(siblingDir, GENERATED_REL);

const SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950'];
const PALETTES = [
  ['brand', 'Brand: primary actions, links and focus'],
  ['accent', 'Accent: highlights'],
  ['neutral', 'Neutral: text, surfaces and borders'],
];

function need(value, where) {
  if (value === undefined || value === null || typeof value !== 'string' || value === '') {
    throw new TokenError(`${where} is missing from the token source`);
  }
  if (/[;{}]/.test(value)) throw new TokenError(`${where} resolves to "${value}", which would break out of its declaration`);
  return value;
}

function roles(resolved, group) {
  const map = resolved?.targets?.nuxt?.[group];
  if (!map || typeof map !== 'object' || Object.keys(map).length === 0) {
    throw new TokenError(`targets.nuxt.${group} is missing from the token source`);
  }
  return Object.entries(map).map(([name, value]) => {
    if (!/^--ui-[a-z0-9-]+$/.test(name)) throw new TokenError(`targets.nuxt.${group}.${name} is not a --ui-* custom property name`);
    return [name, need(value, `targets.nuxt.${group}.${name}`)];
  });
}

export function render(resolved) {
  const theme = [`  --font-sans: ${need(resolved?.primitive?.font?.sans, 'primitive.font.sans')};`];
  for (const [name, title] of PALETTES) {
    const scale = resolved?.primitive?.color?.[name];
    theme.push('', `  /* ${title} */`);
    for (const shade of SHADES) theme.push(`  --color-ln-${name}-${shade}: ${need(scale?.[shade], `primitive.color.${name}.${shade}`)};`);
  }
  const light = roles(resolved, 'light').map(([name, value]) => `  ${name}: ${value};`);
  const dark = roles(resolved, 'dark').map(([name, value]) => `  ${name}: ${value};`);

  return [
    '/*',
    ...headerLines().map((l) => ` * ${l}`),
    ' *',
    ' * LocalNodes design tokens for the Nuxt sign-up site (DES-01). main.css',
    ' * imports this file after tailwindcss and @nuxt/ui; app.config.ts names the',
    ' * ln-brand, ln-accent and ln-neutral palettes defined here.',
    ' */',
    '',
    '/* Palettes: Nuxt UI builds its --ui-color-*-NNN scales from these. */',
    '@theme static {',
    ...theme,
    '}',
    '',
    '/* Light: the task pages (/onboarding, /success, /cancel) force this mode. */',
    ':root {',
    ...light,
    '}',
    '',
    '/*',
    ' * Dark: the landing page\'s dark bands, scoped with the `dark` class.',
    ' * Every colour role set in :root above is restated here, because these',
    ' * unlayered rules outrank Nuxt UI\'s own layered .dark values.',
    ' */',
    '.dark {',
    ...dark,
    '}',
    '',
  ].join('\n');
}
