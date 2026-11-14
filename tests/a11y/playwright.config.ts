// Accessibility harness.
//
// Rerun from the repo root:
//   npx playwright test -c tests/a11y/playwright.config.ts
//   (or: npm run test:a11y)
//
// Uses the installed Google Chrome (channel 'chrome'); no browser download.
// On a host without Google Chrome, set
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
// tests that belong to the other persona. The state specs
// (keyboard, reflow, sr-proxy) do the same.
const STATE_SPECS = ['keyboard.spec.ts', 'reflow.spec.ts', 'sr-proxy.spec.ts'];
const ANONYMOUS_SPECS = ['smoke.spec.ts', 'overview.spec.ts'];
const STEWARD_SPECS: string[] = ['upload.spec.ts', 'batch.spec.ts', 'web.spec.ts', 'status.spec.ts', 'chat.spec.ts', ...STATE_SPECS, 'import-run.spec.ts', 'titles.spec.ts'];
const MEMBER_SPECS: string[] = ['source-box.spec.ts', 'chat.spec.ts', 'overview.spec.ts', ...STATE_SPECS, 'server-ai.spec.ts'];
// Group fund screens, on their own lines.
STEWARD_SPECS.push('treasury.spec.ts');
MEMBER_SPECS.push('treasury-member.spec.ts');
// Group fund page titles in Title Case.
STEWARD_SPECS.push('treasury-titles.spec.ts');
// The group fund recorded walkthrough and the keyboard checks (steward project;
// the walkthrough's member block switches its own session).
STEWARD_SPECS.push('treasury-walkthrough.spec.ts', 'treasury-keyboard.spec.ts');
// The organizer guide, as the steward. Results go to the results folder set
// by PHASE23_RESULTS_DIR.
STEWARD_SPECS.push('guide.spec.ts');
// The site terms and the register form's consent box, as an
// anonymous visitor. Results go to the folder set by PHASE23_RESULTS_DIR.
ANONYMOUS_SPECS.push('terms.spec.ts');
// The sign-up and guide keyboard run and screen-reader proxy. They run in the
// onboarding, anonymous and steward projects; each test skips the states that
// belong to another project (helpers/phase23-states.ts).
const PHASE23_STATE_SPECS = ['keyboard-phase23.spec.ts', 'sr-proxy-phase23.spec.ts'];
ANONYMOUS_SPECS.push(...PHASE23_STATE_SPECS);
STEWARD_SPECS.push(...PHASE23_STATE_SPECS);
// Group governance screens. governance.spec.ts runs in the
// member project and switches persona per block; results go to the
// governance results folder.
MEMBER_SPECS.push('governance.spec.ts');
// The DeepChat bar's computed keyboard focus ring, as the member (the chat shows on the group page).
MEMBER_SPECS.push('chat-focus-ring.spec.ts');
// The chat panel's width, the line under the input, and the assistant's name (default and long), at 320 to 1280.
MEMBER_SPECS.push('chat-panel-size.spec.ts');
// Governance: the keyboard walk and screen-reader evidence, as the steward (the
// vote blocks switch to the voter).
STEWARD_SPECS.push('governance-keyboard.spec.ts');
// The "Hacked or Phished?" help page, signed out and as a member.
// Results go to the brand results folder.
ANONYMOUS_SPECS.push('safety-help.spec.ts');
MEMBER_SPECS.push('safety-help.spec.ts');
// The sign-in button on the welcome block, the log-in and the sign-up pages,
// and the top bar's height with it; signed out only.
// On a site with the sign-in off, set LN_WEB3_OFF=1; otherwise a missing
// sign-in fails these tests instead of skipping them.
ANONYMOUS_SPECS.push('sign-in-entry.spec.ts');
// The signed-out welcome block's height, with and without a picture.
ANONYMOUS_SPECS.push('welcome-block.spec.ts');
// The fixed top bar at 320px (one row, Sign in a full target) and main
// content below it at 320, 390 and 1280, signed out and as a member.
ANONYMOUS_SPECS.push('top-bar.spec.ts');
// The "Joined" button's computed colours, as the member (who has joined the a11y group).
MEMBER_SPECS.push('joined-button.spec.ts');
// Screenshots only, skipped unless LN_SHOTS_DIR is set.
MEMBER_SPECS.push('palette-shots.spec.ts');
ANONYMOUS_SPECS.push('palette-shots.spec.ts');
// The app look's axe and sideways-scroll checks.
MEMBER_SPECS.push('app-look.spec.ts');
// The group header's description and its New post and Invite buttons, as
// the member and the steward (each test opens its own persona).
MEMBER_SPECS.push('group-header.spec.ts');
// The date tile on event cards, as the member.
MEMBER_SPECS.push('event-date-tile.spec.ts');
ANONYMOUS_SPECS.push('app-look.spec.ts');
// The group tour's scroll to each tip, with and without reduced motion, as
// the member (who is in the a11y group the spec makes the community group).
MEMBER_SPECS.push('tour-motion.spec.ts');
// The search pill in the top bar: its label, the search form it opens, its
// rings and the bar's fit, signed out and as the member.
ANONYMOUS_SPECS.push('search-pill.spec.ts');
MEMBER_SPECS.push('search-pill.spec.ts');

// LN_RECORD=1 records a trace and a video of every test. The CLI's
// --trace flag also works; there's no CLI flag for video.
const RECORD = process.env.LN_RECORD === '1';

// The Nuxt sign-up site's screens, with every sign-up API
// answered by page.route mocks. It runs against the Nuxt dev server
// (NUXT_LAUNCH_OPEN=true npm --prefix ../localnodes-onboarding run dev), not
// Drupal, so it has its own baseURL and no stored session, and only
// onboarding.spec.ts runs in it. Results go to the folder set by
// PHASE23_RESULTS_DIR.
export const ONBOARDING_URL = process.env.LN_ONBOARDING_URL ?? 'http://localhost:3000';
// The home page, against the built site (landing-v5.spec.ts).
const ONBOARDING_SPECS = ['onboarding.spec.ts', 'landing-v5.spec.ts', ...PHASE23_STATE_SPECS];

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
  // while the suite ran (see tests/a11y/README.md).
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
