/**
 * Generator target: the Drupal custom properties (22.1-03, DES-01, D-08).
 *
 * Renders html/modules/custom/localnodes_platform/css/ln-tokens.css from
 * `targets.drupal-css` in the token source: ONE `:root` block of --ln-*
 * custom properties and nothing else. Every rule that reads a colour lives in
 * the hand-written css/ln-overrides.css and reaches the values by name, so a
 * palette swap never touches a selector.
 */
import { resolve } from 'node:path';
import { TokenError, headerLines } from '../lib/tokens.mjs';

export const id = 'drupal-css';
export const label = 'Drupal custom properties';

export const path = (repoRoot) => resolve(repoRoot, 'html/modules/custom/localnodes_platform/css/ln-tokens.css');

const GROUPS = [
  ['--ln-color-', 'Colour'],
  ['--ln-space-', 'Spacing scale (multiples of 4)'],
  ['--ln-radius-', 'Shape. The live radius on the community site comes from socialblue.settings, set in localnodes_platform_install() from tokens/ln-tokens.flat.json.'],
  ['--ln-font-', 'Type'],
  ['--ln-chat-', 'Floating DeepChat toggle clearance (WCAG 2.2 SC 2.4.11)'],
];

export function render(resolved) {
  const props = Object.entries(resolved?.targets?.['drupal-css'] ?? {});
  if (props.length === 0) throw new TokenError('targets.drupal-css is empty');
  for (const [name, value] of props) {
    if (!/^--ln-[a-z0-9-]+$/.test(name)) throw new TokenError(`targets.drupal-css.${name} is not a --ln-* custom property name`);
    if (/[;{}]/.test(String(value))) throw new TokenError(`targets.drupal-css.${name} resolves to "${value}", which would break out of its declaration`);
  }

  const lines = [];
  for (const [prefix, title] of GROUPS) {
    const group = props.filter(([name]) => name.startsWith(prefix));
    if (group.length === 0) continue;
    if (lines.length) lines.push('');
    lines.push(`  /* ${title} */`);
    for (const [name, value] of group) lines.push(`  ${name}: ${value};`);
  }
  const grouped = new Set(GROUPS.flatMap(([prefix]) => props.filter(([name]) => name.startsWith(prefix)).map(([name]) => name)));
  const rest = props.filter(([name]) => !grouped.has(name));
  if (rest.length) {
    lines.push('', '  /* Other */');
    for (const [name, value] of rest) lines.push(`  ${name}: ${value};`);
  }

  return [
    '/**',
    ' * @file',
    ...headerLines().map((l) => ` * ${l}`),
    ' *',
    ' * LocalNodes design tokens for the Drupal community site (DES-01): custom',
    ' * properties only. The WCAG AA overrides for socialblue are in',
    ' * css/ln-overrides.css (hand-written, var() only), which loads after this',
    ' * file and after socialblue (see localnodes_platform_css_alter()).',
    ' */',
    '',
    ':root {',
    ...lines,
    '}',
    '',
  ].join('\n');
}
