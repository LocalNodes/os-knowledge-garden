// Fails the a11y run if any AI provider call reached a provider on the
// server while the suite ran (22-30, Codex merge review R1 item 5).
//
// global-setup.ts reset localnodes_a11y_ai_guard's counts; the guard denies
// every provider call with a canned output and counts any call no listener
// answered ("reached"). Anything above 0 means a server-side path got past
// the deny, so the run fails. The counts are printed either way.
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

export default async function globalTeardown(): Promise<void> {
  const out = execFileSync(
    'ddev',
    ['drush', 'php:eval', "print json_encode(\\Drupal::state()->get('localnodes_a11y_ai_guard.counts', []));"],
    { cwd: REPO_ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 120_000 },
  );
  const counts = JSON.parse(out.trim().split('\n').pop() || '{}') as { attempted?: number; denied?: number; reached?: number };
  console.log(`server-side AI calls during the run: ${JSON.stringify(counts)}`);
  if ((counts.reached ?? 0) > 0) {
    throw new Error(`${counts.reached} AI provider call(s) reached a provider on the server during the a11y run; every one must be denied (tests/a11y/README.md).`);
  }
}
