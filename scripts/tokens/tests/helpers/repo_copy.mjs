/**
 * A private copy of the parts of this repo that the token scripts read and write, for tests that must run a
 * generator in write mode or must control what sits next to the repo.
 *
 * <root>/fresh3 holds scripts/, the localnodes_platform module and the css of the two modules whose stylesheets the
 * gate reads (22.1-17); <root> is where a sibling checkout such as ../localnodes-onboarding would be. The scripts find their own repo root from their location, so a script run
 * from the copy reads and writes the copy only. The copy is removed after the test file's tests (helpers/tmp_dir.mjs).
 */
import { cpSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { testTmpDir } from './tmp_dir.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(here, '../../../..');
// The gate reads the module stylesheets that use --ln-color-* (lib/stylesheet_pairs.mjs, 22.1-17), so their css directories come too.
const PARTS = [
  'scripts',
  'html/modules/custom/localnodes_platform',
  'html/modules/custom/localnodes_import/css',
  'html/modules/custom/social_ai_indexing/css',
];

/** `name` names the temp directory (ln-<name>-test-XXXXXX, helpers/tmp_dir.mjs), which removes it after the file's tests. */
export function copyRepo(name = 'repo-copy') {
  const root = testTmpDir(name);
  const repo = join(root, 'fresh3');
  for (const part of PARTS) cpSync(join(repoRoot, part), join(repo, part), { recursive: true });
  return { root, repo };
}
