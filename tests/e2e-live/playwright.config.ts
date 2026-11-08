// The live-Sepolia MetaMask suite (21-45 Task B). CI only.
//
// One nightly run (.github/workflows/nightly-wallet-e2e.yml, 21-46) drives real MetaMask against
// a site the job builds and starts itself on http://localhost:8080, on live Ethereum Sepolia,
// with a throwaway wallet whose seed exists only as a CI secret. Its global setup refuses to run
// unless GITHUB_ACTIONS is 'true' and the base URL host is localhost or a .test host, so it never
// runs on a developer machine or against a public instance.
//
// Nothing is recorded: trace, video and screenshots are off, because the MetaMask profile in
// this browser was made by importing the seed (wallet-setup/live/live.setup.ts). The workflow
// uploads no artifact either.
//
// `npm --prefix tests/e2e-live run list` loads this file and the spec with no secret in the
// environment; nothing here reads one.
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';

const HERE = dirname(fileURLToPath(import.meta.url));

export const LIVE_BASE_URL = process.env.LN_LIVE_BASE_URL || 'http://localhost:8080';

export default defineConfig({
  testDir: '.',
  testMatch: 'live-sepolia.e2e.ts',
  workers: 1,
  fullyParallel: false,
  retries: 0,
  // Live Sepolia is slow: a block every 12 s, and a deploy, a funding transfer and a send.
  timeout: 600_000,
  reporter: [['list']],
  outputDir: join(HERE, '..', '..', 'test-results', 'e2e-live-sepolia'),
  globalSetup: './live-global-setup.ts',
  use: {
    baseURL: LIVE_BASE_URL,
    trace: 'off',
    video: 'off',
    screenshot: 'off',
  },
});
