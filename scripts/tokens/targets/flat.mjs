/**
 * Generator target: the flat name-to-value file PHP reads (22.1-03, D-08, D-10).
 *
 * Renders html/modules/custom/localnodes_platform/tokens/ln-tokens.flat.json.
 * The install hook _localnodes_platform_configure_theme_shape() reads radius
 * from it; 22.1-06 (DeepChat) and 22.1-07 (email) read their roles from it.
 * JSON has no comments, so the GENERATED header is a `$generated` key.
 *
 * Shape: `$generated`, then color, deepchat, email, font, radius, space, each
 * a flat map of name to resolved value, every map in sorted key order.
 */
import { resolve } from 'node:path';
import { TokenError, headerLines } from '../lib/tokens.mjs';

export const id = 'flat';
export const label = 'flat token file';

export const path = (repoRoot) => resolve(repoRoot, 'html/modules/custom/localnodes_platform/tokens/ln-tokens.flat.json');

const sorted = (map) => Object.fromEntries(Object.keys(map).sort().map((k) => [k, map[k]]));
const scalars = (map) => Object.fromEntries(Object.entries(map).filter(([, v]) => typeof v !== 'object'));

export function render(resolved) {
  const need = (value, where) => {
    if (!value || typeof value !== 'object') throw new TokenError(`${where} is missing from the token source`);
    return value;
  };
  const body = {
    color: sorted(scalars(need(resolved.semantic, 'semantic'))),
    deepchat: sorted(need(resolved.targets?.deepchat, 'targets.deepchat')),
    email: sorted(need(resolved.targets?.email, 'targets.email')),
    font: sorted(need(resolved.primitive?.font, 'primitive.font')),
    radius: sorted(need(resolved.primitive?.radius, 'primitive.radius')),
    space: sorted(need(resolved.primitive?.space, 'primitive.space')),
  };
  return `${JSON.stringify({ $generated: headerLines().join(' '), ...body }, null, 2)}\n`;
}
