// The local MetaMask recording (21-45 Task A, ruling R-D).
//
// Real MetaMask, driven by Synpress, signs with anvil's public dev mnemonic against the local
// anvil chain with chain id 84532 (base_sepolia), on https://fresh3.test only. Run it from the
// fresh3-work root, holding the fresh3-e2e run lock, headed under xvfb-run:
//   flock tests/e2e/.state/fresh3-e2e.lock bash -c 'bash tests/e2e/fork/fork.sh up base_sepolia &&
//     LN_E2E_RUN_ID=<id> LN_E2E_NETWORK=base_sepolia LN_E2E_EVIDENCE_DIR=<abs dir> \
//     xvfb-run -a npm --prefix tests/e2e-live run local; bash tests/e2e/fork/fork.sh down all'
//
// The browser is launched by the spec's own context fixture (local-anvil.e2e.ts), because
// Synpress 4.1.2's fixture ignores launch arguments and the video option. That fixture adds
// the host block (browser-args.ts), records a video of every page and writes a trace.
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from '@playwright/test';
import { isAllowedTestHost } from '../e2e/wallet/test-wallet';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = join(HERE, '..', '..');

export const BASE_URL = 'https://fresh3.test';
if (!isAllowedTestHost(new URL(BASE_URL).hostname)) {
  throw new Error('local recording refused: the base URL is not a .test or loopback host');
}

// MetaMask's service worker requests are routable (local stubs, local-anvil.e2e.ts) only with
// this Playwright flag; workers inherit it.
process.env.PW_EXPERIMENTAL_SERVICE_WORKER_NETWORK_EVENTS ??= '1';

const EVIDENCE_DIR = process.env.LN_E2E_EVIDENCE_DIR;
const OUTPUT_DIR = EVIDENCE_DIR
  ? (isAbsolute(EVIDENCE_DIR) ? EVIDENCE_DIR : resolve(REPO_ROOT, EVIDENCE_DIR))
  : join(REPO_ROOT, 'test-results', 'e2e-live-local');

export default defineConfig({
  testDir: '.',
  testMatch: 'local-anvil.e2e.ts',
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 600_000,
  reporter: [['list'], ['json', { outputFile: join(OUTPUT_DIR, 'report.json') }]],
  outputDir: OUTPUT_DIR,
  globalSetup: './local-global-setup.ts',
  use: {
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    // The spec's context fixture records both itself; these are the plan's settings, kept so
    // Playwright's own artifacts follow the same rule if it ever drives a context here.
    trace: 'on',
    video: 'on',
    screenshot: 'only-on-failure',
  },
});
