// The live governance judge (25-27, criterion 6; D-21, D-23). CI only.
//
// Like the live-Sepolia config (playwright.config.ts, 21-45): the same CI-only global setup
// (live-global-setup.ts refuses unless GITHUB_ACTIONS is 'true' and the base URL host is
// localhost or a .test host), one worker, no retries, and nothing recorded (trace, video and
// screenshots off: the browser profile holds the imported wallet). Only the spec and the time
// limits differ: governance-judge.e2e.ts, for up to 60 minutes, since the flow needs about 40
// (voting 10 minutes, the safety check 600 s and the waiting time 300 s). The job allows 90, so
// a stuck test ends with its own error and the failure dump while the job still runs. Every
// action and navigation without a timeout of its own gets one minute, so nothing waits forever.
//
// `npm --prefix tests/e2e-live run judge -- --list` loads this file and the spec with no secret
// in the environment; nothing here reads one.
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';
import { LIVE_BASE_URL } from './playwright.config';

const HERE = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  testDir: '.',
  testMatch: 'governance-judge.e2e.ts',
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 60 * 60_000,
  reporter: [['list']],
  outputDir: join(HERE, '..', '..', 'test-results', 'e2e-live-governance'),
  globalSetup: './live-global-setup.ts',
  use: {
    baseURL: LIVE_BASE_URL,
    trace: 'off',
    video: 'off',
    screenshot: 'off',
    actionTimeout: 60_000,
    navigationTimeout: 60_000,
  },
});
