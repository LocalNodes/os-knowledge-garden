// ddev and drush in the e2e lane site's dir (21-41).
//
// Every call goes through 21-40's tests/e2e/site/site.sh exec, run from the fresh3-work
// root, so it lands in the lane site (LN_SITE_PROJECT, default fresh3-e2e) and never in
// fresh3-work's own DDEV project. site.sh refuses any project but the lane ones.
import { execFileSync } from 'node:child_process';
import { copyFileSync, mkdirSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(HERE, '..', '..', '..');
const SITE_SH = join(REPO_ROOT, 'tests', 'e2e', 'site', 'site.sh');

function siteSh(args: string[], timeout = 300_000): string {
  try {
    return execFileSync('bash', [SITE_SH, ...args], {
      cwd: REPO_ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout,
    });
  } catch (err) {
    const e = err as { stderr?: string; stdout?: string; message: string };
    throw new Error(`site.sh ${args.join(' ')} failed: ${e.message}\n${e.stdout ?? ''}${e.stderr ?? ''}`);
  }
}

/** The lane site's dir (site.sh dir). */
export function siteDir(): string {
  return siteSh(['dir']).trim();
}

/** `ddev <args>` in the lane site's dir. */
export function siteDdev(args: string[]): string {
  return siteSh(['exec', 'ddev', ...args]);
}

/** `ddev drush <args>` in the lane site's dir. */
export function siteDrush(args: string[]): string {
  return siteDdev(['drush', ...args]);
}

/**
 * Whether the canned-read fixture module is enabled on the lane site. It is `hidden: true`,
 * and `drush pml` leaves hidden modules out, so a pml check never sees it (corrected
 * 2026-11-07, 21-28 open decision 2). core.extension's module list does: an enabled module
 * is an indented `<name>: <weight>` line under `'core.extension:module':`.
 */
export function rpcFixtureEnabled(): boolean {
  return /^\s+safe_smart_accounts_rpc_fixture:/m.test(siteDrush(['config:get', 'core.extension', 'module']));
}

/**
 * Copies a PHP file from this working copy into the site's tests/e2e/.state/scripts/
 * (gitignored there too) and runs it with `ddev drush php:script`. The site's code stays at
 * the committed HEADs; only the script is the working copy's.
 */
export function siteScript(localPath: string, args: string[]): string {
  const name = basename(localPath);
  const dest = join(siteDir(), 'tests', 'e2e', '.state', 'scripts');
  mkdirSync(dest, { recursive: true });
  copyFileSync(localPath, join(dest, name));
  return siteDrush(['php:script', `tests/e2e/.state/scripts/${name}`, '--', ...args]);
}
