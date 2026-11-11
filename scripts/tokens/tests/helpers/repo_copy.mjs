/**
 * A private copy of the parts of this repo that the token scripts read and write, for tests that must run a
 * generator in write mode or must control what sits next to the repo.
 *
 * <root>/fresh3 holds scripts/, the localnodes_platform module and the css of the two modules whose stylesheets the
 * gate reads (22.1-17); <root> is where a sibling checkout such as ../localnodes-onboarding would be. The scripts find their own repo root from their location, so a script run
 * from the copy reads and writes the copy only. The copy is removed when the test process exits.
 */
import { cpSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../../..');
// The gate reads the module stylesheets that use --ln-color-* (lib/stylesheet_pairs.mjs, 22.1-17), so their css directories come too.
const PARTS = [
  'scripts',
  'html/modules/custom/localnodes_platform',
  'html/modules/custom/localnodes_import/css',
  'html/modules/custom/social_ai_indexing/css',
];

export function copyRepo(prefix = 'ln-repo-copy-') {
  const root = mkdtempSync(join(tmpdir(), prefix));
  const repo = join(root, 'fresh3');
  for (const part of PARTS) cpSync(join(repoRoot, part), join(repo, part), { recursive: true });
  process.on('exit', () => rmSync(root, { recursive: true, force: true }));
  return { root, repo };
}
