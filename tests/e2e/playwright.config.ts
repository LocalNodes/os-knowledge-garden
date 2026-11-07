// Wallet e2e suite for the Phase 21 e2e lane (21-41). Its own config: nothing is shared
// with Phase 22's tests/a11y.
//
// Run from the fresh3-work root, with the suite chain up (21-39):
//   bash tests/e2e/fork/fork.sh up ethereum_sepolia
//   npx --no-install playwright test -c tests/e2e/playwright.config.ts
//   bash tests/e2e/fork/fork.sh down all
//
// It drives https://fresh3.test on the lane site fresh3-e2e (21-40) in Playwright's bundled
// Chromium, headless, with an injected test wallet (wallet/test-wallet.ts). No MetaMask, no
// extension, no /etc/hosts: Chromium maps the .test host to 127.0.0.1 itself.
//
// The config refuses to load with a base URL outside .test and loopback hosts.
// Evidence (ruling R-D): every test records a trace and a video. outputDir is
// LN_E2E_EVIDENCE_DIR when set (resolved against the fresh3-work root), else
// test-results/e2e. Specs are named *.e2e.ts so vitest never picks them up.
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';
import { e2eBaseUrl, isAllowedTestHost } from './wallet/test-wallet';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(HERE, '..', '..');

export const BASE_URL = e2eBaseUrl();
const BASE_HOST = new URL(BASE_URL).hostname;
if (!isAllowedTestHost(BASE_HOST)) {
  throw new Error(`e2e refused: ${BASE_HOST} is not a .test or loopback host`);
}

const NETWORK = process.env.LN_E2E_NETWORK || 'ethereum_sepolia';
const EVIDENCE_DIR = process.env.LN_E2E_EVIDENCE_DIR;
const OUTPUT_DIR = EVIDENCE_DIR
  ? (isAbsolute(EVIDENCE_DIR) ? EVIDENCE_DIR : resolve(REPO_ROOT, EVIDENCE_DIR))
  : join(REPO_ROOT, 'test-results', 'e2e');

const launchArgs = BASE_HOST.endsWith('.test') ? [`--host-resolver-rules=MAP ${BASE_HOST} 127.0.0.1`] : [];

export default defineConfig({
  testDir: '.',
  testMatch: '*.e2e.ts',
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  timeout: 180_000,
  reporter: [
    ['list'],
    ['json', { outputFile: join(HERE, '.state', `report-${NETWORK}.json`) }],
  ],
  outputDir: OUTPUT_DIR,
  globalSetup: './global-setup.ts',
  use: {
    baseURL: BASE_URL,
    headless: true,
    ignoreHTTPSErrors: true,
    trace: 'on',
    video: 'on',
    screenshot: 'only-on-failure',
    launchOptions: { args: launchArgs },
  },
  projects: [
    {
      name: 'e2e',
      use: { storageState: { cookies: [], origins: [] } },
    },
  ],
});
