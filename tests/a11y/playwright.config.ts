// Accessibility harness for Phase 22 (D-05, UI-SPEC Part 5).
//
// Rerun from the repo root:
//   npx playwright test -c tests/a11y/playwright.config.ts
//   (or: npm run test:a11y)
//
// Uses the installed Google Chrome (channel 'chrome'); no browser download.
// On a host without Google Chrome (desktop-dev, 22-28), set
// LN_BROWSER_CHANNEL=chromium after `npx playwright install chromium`.
// One worker, headless: this runs on an 8 GB machine next to DDEV.
//
// Specs are named *.spec.ts so vitest (tests/**/*.test.ts) never picks them up.
//
// Projects are personas. Add each new spec's file name to the project it runs
// as: anonymous (no session), steward (group manager of "A11y Garden") or
// member (plain member). global-setup.ts seeds the personas, saves their
// sessions under tests/a11y/.auth/ and exports the group id as LN_GROUP.
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';

const AUTH_DIR = join(dirname(fileURLToPath(import.meta.url)), '.auth');

export const BASE_URL = process.env.LN_BASE_URL ?? 'https://fresh3.ddev.site';
// 'chrome' (installed Google Chrome) unless LN_BROWSER_CHANNEL says otherwise;
// 'chromium' means Playwright's own bundled browser.
export const BROWSER_CHANNEL = process.env.LN_BROWSER_CHANNEL ?? 'chrome';

// chat.spec.ts and overview.spec.ts run in two projects each and skip the
// tests that belong to the other persona (22-28). The 22-30 state specs
// (keyboard, reflow, sr-proxy) do the same.
const STATE_SPECS = ['keyboard.spec.ts', 'reflow.spec.ts', 'sr-proxy.spec.ts'];
const ANONYMOUS_SPECS = ['smoke.spec.ts', 'overview.spec.ts'];
const STEWARD_SPECS: string[] = ['upload.spec.ts', 'batch.spec.ts', 'web.spec.ts', 'status.spec.ts', 'chat.spec.ts', ...STATE_SPECS, 'import-run.spec.ts', 'titles.spec.ts'];
const MEMBER_SPECS: string[] = ['source-box.spec.ts', 'chat.spec.ts', 'overview.spec.ts', ...STATE_SPECS, 'server-ai.spec.ts'];
// Phase 21 group fund screens (21-28), on their own lines next to Phase 22's.
STEWARD_SPECS.push('treasury.spec.ts');
MEMBER_SPECS.push('treasury-member.spec.ts');
// Phase 21 page titles in Title Case (Tom, 2026-11-07; 21-29).
STEWARD_SPECS.push('treasury-titles.spec.ts');
// 21-29: the recorded walkthrough and the keyboard checks (steward project;
// the walkthrough's member block switches its own session).
STEWARD_SPECS.push('treasury-walkthrough.spec.ts', 'treasury-keyboard.spec.ts');
// Phase 23 (23-13): the organizer guide, as the steward. Results go to the
// Phase 23 folder through PHASE23_RESULTS_DIR.
STEWARD_SPECS.push('guide.spec.ts');
// 23-26: the site terms and the register form's consent box, as an
// anonymous visitor. Results go to the Phase 23 folder.
ANONYMOUS_SPECS.push('terms.spec.ts');
// 23-29: the Phase 23 keyboard run and screen-reader proxy. They run in the
// onboarding, anonymous and steward projects; each test skips the states that
// belong to another project (helpers/phase23-states.ts).
const PHASE23_STATE_SPECS = ['keyboard-phase23.spec.ts', 'sr-proxy-phase23.spec.ts'];
ANONYMOUS_SPECS.push(...PHASE23_STATE_SPECS);
STEWARD_SPECS.push(...PHASE23_STATE_SPECS);
// Phase 25 group governance screens (25-26). governance.spec.ts runs in the
// member project and switches persona per block; results go to the Phase 25
// folder.
MEMBER_SPECS.push('governance.spec.ts');
// 22.1-17: the DeepChat bar's computed keyboard focus ring, as the member (the chat shows on the group page).
MEMBER_SPECS.push('chat-focus-ring.spec.ts');
// 25-26: the keyboard walk and screen-reader evidence, as the steward (the
// vote blocks switch to the voter).
STEWARD_SPECS.push('governance-keyboard.spec.ts');
// 22.1-15: the "Hacked or Phished?" help page, signed out and as a member.
// Results go to the 22.1 folder.
ANONYMOUS_SPECS.push('safety-help.spec.ts');
MEMBER_SPECS.push('safety-help.spec.ts');

// LN_RECORD=1 records a trace and a video of every test (22-30). The CLI's
// --trace flag also works; there's no CLI flag for video.
const RECORD = process.env.LN_RECORD === '1';

// Phase 23 (23-20): the Nuxt sign-up site's screens, with every sign-up API
// answered by page.route mocks. It runs against the Nuxt dev server
// (NUXT_LAUNCH_OPEN=true npm --prefix ../localnodes-onboarding run dev), not
// Drupal, so it has its own baseURL and no stored session, and only
// onboarding.spec.ts runs in it. Results go to the Phase 23 folder.
export const ONBOARDING_URL = process.env.LN_ONBOARDING_URL ?? 'http://localhost:3000';
const ONBOARDING_SPECS = ['onboarding.spec.ts', ...PHASE23_STATE_SPECS];

// global-setup.ts and global-teardown.ts seed and check the DDEV Drupal site.
// A run of only the onboarding project needs no Drupal and no DDEV, so they are
// skipped when every --project on the command line is "onboarding". Playwright
// runs them for the whole config whichever projects are picked, so the choice
// is made here from the command line.
function selectedProjects(argv: string[]): string[] {
  const names: string[] = [];
  argv.forEach((arg, i) => {
    if (arg === '--project' && argv[i + 1]) names.push(argv[i + 1]);
    else if (arg.startsWith('--project=')) names.push(arg.slice('--project='.length));
  });
  return names;
}
const PICKED = selectedProjects(process.argv);
const ONBOARDING_ONLY = PICKED.length > 0 && PICKED.every((name) => name === 'onboarding');
// onboarding.spec.ts fails when the Nuxt server is down and the project was
// picked by name, and skips in a run of every project. Workers inherit this.
if (PICKED.includes('onboarding')) process.env.LN_ONBOARDING_PICKED = '1';

export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  timeout: 60_000,
  reporter: [['list']],
  outputDir: '../../test-results',
  globalSetup: ONBOARDING_ONLY ? undefined : './global-setup.ts',
  // Fails the run if any AI provider call reached a provider on the server
  // while the suite ran (22-30; tests/a11y/README.md).
  globalTeardown: ONBOARDING_ONLY ? undefined : './global-teardown.ts',
  use: {
    baseURL: BASE_URL,
    channel: BROWSER_CHANNEL === 'chromium' ? undefined : BROWSER_CHANNEL,
    headless: true,
    ignoreHTTPSErrors: true,
    trace: RECORD ? 'on' : 'off',
    screenshot: 'off',
    video: RECORD ? 'on' : 'off',
  },
  projects: [
    {
      name: 'anonymous',
      testMatch: ANONYMOUS_SPECS,
      use: { storageState: { cookies: [], origins: [] } },
    },
    {
      name: 'steward',
      testMatch: STEWARD_SPECS,
      use: { storageState: join(AUTH_DIR, 'steward.json') },
    },
    {
      name: 'member',
      testMatch: MEMBER_SPECS,
      use: { storageState: join(AUTH_DIR, 'member.json') },
    },
    {
      name: 'onboarding',
      testMatch: ONBOARDING_SPECS,
      use: { baseURL: ONBOARDING_URL, storageState: { cookies: [], origins: [] } },
    },
  ],
});
