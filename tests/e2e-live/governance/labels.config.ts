// The labels-only check for the live governance judge (25-27 Task 1, D-23, criterion 4).
//
// It proves, on the lane's fixture-driven DDEV site, that every locator in governance/pages.ts
// (the judge's one page object) resolves in the states the judge passes through. It needs no
// wallet and no network: the site runs 25-26's test-only fixture modules
// (safe_smart_accounts_rpc_fixture, safe_governance_chain_fixture, safe_governance_hub_fixture,
// gas_sponsor_fixture), and tests/a11y/seed-governance.php makes the groups and states.
//
// Based on the a11y config (tests/a11y/playwright.config.ts): the same global setup (personas,
// sessions, the AI-call deny) and teardown, the lane URL from LN_BASE_URL, and Playwright's own
// Chromium with LN_BROWSER_CHANNEL=chromium. Rerun from the repo root:
//   LN_BROWSER_CHANNEL=chromium LN_BASE_URL=<lane site> npx --no-install playwright test \
//     -c tests/e2e-live/governance/labels.config.ts
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';

const HERE = dirname(fileURLToPath(import.meta.url));
const A11Y = join(HERE, '..', '..', 'a11y');

export const BASE_URL = process.env.LN_BASE_URL ?? 'https://fresh3.ddev.site';
const BROWSER_CHANNEL = process.env.LN_BROWSER_CHANNEL ?? 'chrome';

export default defineConfig({
  testDir: '.',
  testMatch: 'labels.spec.ts',
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  timeout: 90_000,
  reporter: [['list']],
  outputDir: join(HERE, '..', '..', '..', 'test-results', 'governance-labels'),
  globalSetup: join(A11Y, 'global-setup.ts'),
  globalTeardown: join(A11Y, 'global-teardown.ts'),
  use: {
    baseURL: BASE_URL,
    channel: BROWSER_CHANNEL === 'chromium' ? undefined : BROWSER_CHANNEL,
    headless: true,
    ignoreHTTPSErrors: true,
    trace: 'off',
    screenshot: 'off',
    video: 'off',
    storageState: join(A11Y, '.auth', 'steward.json'),
  },
});
