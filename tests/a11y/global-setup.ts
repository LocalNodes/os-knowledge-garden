// Seeds the a11y personas on the local DDEV site and saves their sessions.
//
// 1. `ddev drush php:script tests/a11y/seed.php` (idempotent) creates the
//    fictional users a11y-steward and a11y-member, the group "A11y Garden" and
//    two fixture groups the steward manages (one with a cover picture, one
//    with no picture or location), and prints LN_GROUP, LN_COVER_GROUP and
//    LN_FOOTER_GROUP=<id>.
// 2. `ddev drush uli --name=<persona> --uri=<base>` gives a one-time login
//    link per persona; visiting it in Chrome logs in, and the session is saved
//    to tests/a11y/.auth/<persona>.json (gitignored, T-22-27).
// 3. The group ids are exported through process.env, which the test workers
//    inherit.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, type FullConfig } from '@playwright/test';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(HERE, '..', '..');
const AUTH_DIR = join(HERE, '.auth');

const PERSONAS = { steward: 'a11y-steward', member: 'a11y-member' } as const;

function ddev(args: string[]): string {
  return execFileSync('ddev', args, {
    cwd: REPO_ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
}

export default async function globalSetup(config: FullConfig): Promise<void> {
  const baseURL =
    (config.projects[0]?.use?.baseURL as string | undefined) ??
    process.env.LN_BASE_URL ??
    'https://fresh3.ddev.site';

  // No live AI on the server (22-30; tests/a11y/README.md): the lane's
  // settings switch on localnodes_a11y_ai_guard's deny, and the guard counts
  // every provider call so global-teardown.ts can check none reached one.
  const guard = ddev([
    'drush',
    'php:eval',
    "print json_encode(['deny' => (bool) \\Drupal\\Core\\Site\\Settings::get('localnodes_a11y_ai_deny', FALSE), 'scan' => (bool) \\Drupal\\Core\\Site\\Settings::get('extension_discovery_scan_tests', FALSE)]);",
  ]);
  const settings = JSON.parse(guard.trim().split('\n').pop()!);
  if (!settings.deny || !settings.scan) {
    throw new Error(
      'The a11y suite denies AI calls on the server, and this site isn\'t set up for it. Add to the site\'s gitignored sites/default/settings.local.php (never the tracked settings.php): ' +
        "$settings['extension_discovery_scan_tests'] = TRUE; $settings['localnodes_a11y_ai_deny'] = TRUE; (tests/a11y/README.md)",
    );
  }
  ddev(['drush', 'pm:enable', 'localnodes_a11y_ai_guard', '-y']);
  ddev(['drush', 'php:eval', "\\Drupal::state()->delete('localnodes_a11y_ai_guard.counts');"]);

  const seedOutput = ddev(['drush', 'php:script', 'tests/a11y/seed.php']);
  const match = seedOutput.match(/^LN_GROUP=(\d+)\s*$/m);
  if (!match) {
    throw new Error(`seed.php did not print LN_GROUP=<id>. Output:\n${seedOutput}`);
  }
  process.env.LN_GROUP = match[1];
  for (const name of ['LN_COVER_GROUP', 'LN_FOOTER_GROUP', 'LN_EVENT', 'LN_EVENT_LONG']) {
    const id = seedOutput.match(new RegExp(`^${name}=(\\d+)\\s*$`, 'm'));
    if (!id) {
      throw new Error(`seed.php did not print ${name}=<id>. Output:\n${seedOutput}`);
    }
    process.env[name] = id[1];
  }

  mkdirSync(AUTH_DIR, { recursive: true });
  const channel = process.env.LN_BROWSER_CHANNEL ?? 'chrome';
  const browser = await chromium.launch({ channel: channel === 'chromium' ? undefined : channel, headless: true });
  try {
    for (const [persona, name] of Object.entries(PERSONAS)) {
      const link = ddev(['drush', 'uli', `--name=${name}`, `--uri=${baseURL}`, '--no-browser'])
        .trim()
        .split('\n')
        .pop()!
        .trim();
      if (!/^https?:\/\//.test(link)) {
        throw new Error(`drush uli returned no login link for ${name}: ${link}`);
      }
      const context = await browser.newContext({ ignoreHTTPSErrors: true });
      const page = await context.newPage();
      await page.goto(link);
      // A used one-time link lands on the user's own pages; the session cookie
      // is what matters. Fail loudly if Drupal did not log the persona in.
      const cookies = await context.cookies();
      if (!cookies.some((c) => /^S?SESS/.test(c.name))) {
        throw new Error(`No Drupal session cookie after the one-time login for ${name}`);
      }
      await context.storageState({ path: join(AUTH_DIR, `${persona}.json`) });
      await context.close();
    }
  } finally {
    await browser.close();
  }
}
