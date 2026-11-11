/**
 * Generator target: the Nuxt sign-up site's mail colours (22.1-14, DES-01, D-03, D-08, WINDOWS 44, inbox Q6 (a)).
 *
 * Renders <sibling>/server/utils/mail-colors.generated.ts in the localnodes-onboarding
 * checkout: a `MAIL_COLORS` const with the nine keys server/utils/mail-copy.ts uses,
 * each value an existing `targets.email` role (no role is added and no approved value
 * changes: D-13). Mail clients cannot read CSS custom properties, so the values are
 * written out as hex, and a palette swap becomes a token change and a regenerate.
 *
 * `sibling = true`, plus an adoption gate: this file is written, or checked, only once
 * the Nuxt repo has adopted it, that is when server/utils/mail-copy.ts imports
 * `./mail-colors.generated` or the generated file already exists. Before that, a plain
 * run and --check both print `SKIP nuxt-mail (not adopted: ...)` and touch nothing, so
 * no fresh3 run creates the file in a checkout that has not wired it (Phase 23 owns the
 * wiring: the import, the generated file and the test re-pin go in one commit there).
 * With --out-dir the file is always rendered, under
 * <dir>/_external/localnodes-onboarding/, like the Nuxt stylesheet target.
 */
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { TokenError, headerLines } from '../lib/tokens.mjs';

export const id = 'nuxt-mail';
export const label = 'Nuxt mail colours (nuxt-mail)';
export const sibling = true;

export const GENERATED_REL = 'server/utils/mail-colors.generated.ts';
export const MAIL_COPY_REL = 'server/utils/mail-copy.ts';
export const path = (repoRoot, siblingDir) => resolve(siblingDir, GENERATED_REL);

/** mail-copy.ts's keys, in its order, and the targets.email role each one reads. */
export const KEY_TO_ROLE = Object.freeze([
  ['canvas', 'body'],
  ['card', 'card'],
  ['border', 'divider'],
  ['heading', 'heading'],
  ['text', 'text'],
  ['button', 'button'],
  ['buttonText', 'button-text'],
  ['link', 'link'],
  ['footer', 'footer-text'],
]);

/** An `import ... from './mail-colors.generated'` (an extension is allowed), outside comments. */
const IMPORT = /\b(?:from|import)\s*['"]\.\/mail-colors\.generated(?:\.[cm]?[jt]s)?['"]/;
const stripComments = (text) => text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

/** Has the Nuxt checkout adopted the generated file? See the module comment. */
export function adopted(siblingDir) {
  if (existsSync(resolve(siblingDir, GENERATED_REL))) return true;
  const mailCopy = resolve(siblingDir, MAIL_COPY_REL);
  return existsSync(mailCopy) && IMPORT.test(stripComments(readFileSync(mailCopy, 'utf8')));
}

export const notAdoptedReason = `not adopted: ${MAIL_COPY_REL} does not import ./mail-colors.generated and ${GENERATED_REL} does not exist`;

/** Only a lowercase 6-digit hex reaches a TypeScript string. */
function hex(value, role) {
  if (typeof value !== 'string' || !/^#[0-9a-f]{6}$/.test(value)) {
    throw new TokenError(`targets.email.${role} resolves to ${JSON.stringify(value)}, which is not a 6-digit hex colour`);
  }
  return value;
}

export function render(resolved) {
  const email = resolved?.targets?.email;
  const lines = KEY_TO_ROLE.map(([key, role]) => `  ${key}: '${hex(email?.[role], role)}'`);
  return [
    ...headerLines().map((l) => `// ${l}`),
    '//',
    '// The LocalNodes mail look (22.1-07), one value per colour role of the mail template',
    '// (targets.email in the token source). Mail clients cannot read CSS custom properties,',
    '// so the values are written out as hex. mail-copy.ts imports MAIL_COLORS from here.',
    '// Keep this the only exporter of MAIL_COLORS under server/utils/: Nitro auto-imports',
    '// every export in that directory, and two exporters of one name collide.',
    'export const MAIL_COLORS = {',
    lines.join(',\n'),
    '} as const',
    '',
  ].join('\n');
}
