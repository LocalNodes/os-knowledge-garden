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

// LN_RECORD=1 records a trace and a video of every test (22-30). The CLI's
// --trace flag also works; there's no CLI flag for video.
const RECORD = process.env.LN_RECORD === '1';

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
  globalSetup: './global-setup.ts',
  // Fails the run if any AI provider call reached a provider on the server
  // while the suite ran (22-30; tests/a11y/README.md).
  globalTeardown: './global-teardown.ts',
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
  ],
});
